<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Web\AdminSession;
use TicketsRecorrentesHesk\Web\AuthenticatedStaff;
use TicketsRecorrentesHesk\Web\HeskStaffAuthenticator;
use TicketsRecorrentesHesk\Web\HeskStaffRuntime;

require_once dirname(__DIR__) . '/src/Web/AdminAuthenticator.php';
require_once dirname(__DIR__) . '/src/Web/AuthenticatedStaff.php';
require_once dirname(__DIR__) . '/src/Web/HeskStaffRuntime.php';
require_once dirname(__DIR__) . '/src/Web/HeskStaffAuthenticator.php';
require_once dirname(__DIR__) . '/src/Web/AdminSession.php';

final class FakeHeskStaffRuntime implements HeskStaffRuntime
{
    public int $openCalls = 0;
    public int $validationCalls = 0;
    public int $closeCalls = 0;

    /**
     * @param array<string, mixed> $initialSession
     * @param array<string, mixed>|null $validatedSession
     */
    public function __construct(
        private readonly array $initialSession,
        private readonly bool $valid = true,
        private readonly ?array $validatedSession = null,
    ) {
    }

    public function openStaffSession(): void
    {
        $this->openCalls++;
    }

    public function sessionData(): array
    {
        if ($this->validationCalls > 0 && $this->validatedSession !== null) {
            return $this->validatedSession;
        }

        return $this->initialSession;
    }

    public function validateLoggedIn(): bool
    {
        $this->validationCalls++;

        return $this->valid;
    }

    public function closeStaffSession(): void
    {
        $this->closeCalls++;
    }
}

$failures = [];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;

    if (!$condition) {
        $failures[] = $message;
    }
};

$staffSession = [
    'id' => '100',
    'session_verify' => 'signed-session-value',
    'name' => 'Atendente HESK',
    'user' => 'atendente',
    'isadmin' => 0,
    'privileges' => '',
    'password_hash' => 'não deve sair do runtime',
];
$runtime = new FakeHeskStaffRuntime($staffSession);
$staff = (new HeskStaffAuthenticator($runtime))->authenticate();
$assert($staff instanceof AuthenticatedStaff, 'Sessão STAFF válida deve autenticar');
$assert($staff?->id === 100 && $staff?->displayName === 'Atendente HESK', 'Identidade mínima deve ser capturada');
$assert($staff?->username === null, 'Username não deve ser mantido quando o nome já identifica o STAFF');
$assert($runtime->validationCalls === 1 && $runtime->closeCalls === 1, 'Sessão válida deve usar validação nativa e sempre fechar');
$assert(!property_exists($staff, 'isadmin') && !property_exists($staff, 'privileges') && !property_exists($staff, 'password_hash'), 'Identidade do painel não deve carregar privilégios ou segredo');

$usernameRuntime = new FakeHeskStaffRuntime(array_replace($staffSession, ['name' => '', 'user' => 'operador']));
$usernameStaff = (new HeskStaffAuthenticator($usernameRuntime))->authenticate();
$assert($usernameStaff?->displayName === 'operador' && $usernameStaff?->username === 'operador', 'Username deve ser fallback quando nome não existir');

$expiredRuntime = new FakeHeskStaffRuntime($staffSession, false);
$assert((new HeskStaffAuthenticator($expiredRuntime))->authenticate() === null, 'Sessão expirada deve ser recusada');
$assert($expiredRuntime->validationCalls === 1 && $expiredRuntime->closeCalls === 1, 'Sessão expirada deve ser validada e fechada');

foreach ([
    'sem id' => array_diff_key($staffSession, ['id' => true]),
    'sem verificador' => array_diff_key($staffSession, ['session_verify' => true]),
    'MFA intermediário' => ['mfa_pending' => true, 'user' => 'atendente'],
    'reset de senha' => array_replace($staffSession, ['password_reset' => 1]),
] as $scenario => $session) {
    $incompleteRuntime = new FakeHeskStaffRuntime($session);
    $assert((new HeskStaffAuthenticator($incompleteRuntime))->authenticate() === null, "Contexto {$scenario} deve ser recusado");
    $assert($incompleteRuntime->validationCalls === 0, "Contexto {$scenario} não deve iniciar validação/login automático");
    $assert($incompleteRuntime->closeCalls === 1, "Contexto {$scenario} deve fechar a sessão STAFF");
}

$identityLostRuntime = new FakeHeskStaffRuntime($staffSession, true, array_diff_key($staffSession, ['session_verify' => true]));
$assert((new HeskStaffAuthenticator($identityLostRuntime))->authenticate() === null, 'Contexto removido durante validação deve ser recusado');
$assert($identityLostRuntime->closeCalls === 1, 'Sessão alterada durante validação deve ser fechada');

$nativeSource = file_get_contents(dirname(__DIR__) . '/src/Web/NativeHeskStaffRuntime.php');
$authSource = file_get_contents(dirname(__DIR__) . '/src/Web/HeskStaffAuthenticator.php');
$assert(is_string($nativeSource) && !str_contains($nativeSource, 'hesk_autoLogin('), 'Integração não deve chamar hesk_autoLogin diretamente');
$assert(is_string($nativeSource) && !str_contains($nativeSource, 'hesk_checkPermission('), 'Integração não deve exigir permissão administrativa');
$assert(is_string($authSource) && !str_contains($authSource, "['isadmin']") && !str_contains($authSource, "['privileges']"), 'Autenticador não deve depender de flags de privilégio');
$assert(is_string($nativeSource) && str_contains($nativeSource, "hesk_session_start('STAFF')"), 'Bootstrap deve selecionar explicitamente a sessão STAFF');

$bootstrapDirectory = sys_get_temp_dir() . '/tickets-auth-bootstrap-' . bin2hex(random_bytes(8));
mkdir($bootstrapDirectory, 0700);

$createBootstrapFixture = static function (string $name, bool $defineDbConnect) use ($bootstrapDirectory): string {
    $fixture = $bootstrapDirectory . '/' . $name;
    mkdir($fixture . '/inc', 0700, true);
    mkdir($fixture . '/sessions', 0700);
    file_put_contents($fixture . '/hesk_settings.inc.php', <<<'PHP'
<?php
$hesk_settings = ['hesk_version' => '3.7.12'];
PHP);
    file_put_contents($fixture . '/inc/common.inc.php', <<<'PHP'
<?php
function hesk_load_database_functions(): void
{
    $GLOBALS['fixture_db_before_loader'] = function_exists('hesk_dbConnect');
    require HESK_PATH . 'inc/database_mysqli.inc.php';
}

function hesk_session_start(string $scope = ''): void
{
    $GLOBALS['fixture_session_scope'] = $scope;
    session_name('HESKSTAFFFIXTURE');
    session_start();
}

function hesk_isLoggedIn(): bool
{
    return true;
}
PHP);
    file_put_contents($fixture . '/inc/admin_functions.inc.php', "<?php\n");
    file_put_contents(
        $fixture . '/inc/database_mysqli.inc.php',
        $defineDbConnect ? "<?php\nfunction hesk_dbConnect(): void {}\n" : "<?php\n"
    );

    return $fixture;
};

$bootstrapRunner = $bootstrapDirectory . '/run-native-bootstrap.php';
file_put_contents($bootstrapRunner, <<<'PHP'
<?php
declare(strict_types=1);

$fixture = $argv[1];
$project = $argv[2];
session_save_path($fixture . '/sessions');

require_once $project . '/src/Web/HeskStaffRuntime.php';
require_once $project . '/src/Web/NativeHeskStaffRuntime.php';

$runtime = new \TicketsRecorrentesHesk\Web\NativeHeskStaffRuntime($fixture);
$result = ['ok' => false];

try {
    $runtime->openStaffSession();
    $result['ok'] = true;
} catch (Throwable $error) {
    $result['error'] = $error->getMessage();
} finally {
    $result['db_before_loader'] = $GLOBALS['fixture_db_before_loader'] ?? null;
    $result['db_after_loader'] = function_exists('hesk_dbConnect');
    $result['session_scope'] = $GLOBALS['fixture_session_scope'] ?? null;
    $runtime->closeStaffSession();
}

echo json_encode($result, JSON_THROW_ON_ERROR);
PHP);

/** @return array{exit: int, output: string, error: string, result: array<string, mixed>} */
$runBootstrapFixture = static function (string $fixture) use ($bootstrapRunner): array {
    $process = proc_open(
        [PHP_BINARY, $bootstrapRunner, $fixture, dirname(__DIR__)],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return ['exit' => -1, 'output' => '', 'error' => 'proc_open indisponível', 'result' => []];
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode($output, true);

    return [
        'exit' => $exit,
        'output' => $output,
        'error' => $error,
        'result' => is_array($decoded) ? $decoded : [],
    ];
};

$orderedBootstrap = $runBootstrapFixture($createBootstrapFixture('ordered', true));
$assert($orderedBootstrap['exit'] === 0 && $orderedBootstrap['error'] === '', 'Fixture nativa ordenada deve executar sem erro PHP');
$assert(($orderedBootstrap['result']['ok'] ?? null) === true, 'Runtime deve aceitar hesk_dbConnect carregada pelo loader');
$assert(($orderedBootstrap['result']['db_before_loader'] ?? null) === false, 'hesk_dbConnect pode estar ausente antes do loader');
$assert(($orderedBootstrap['result']['db_after_loader'] ?? null) === true, 'hesk_dbConnect deve existir depois do loader');
$assert(($orderedBootstrap['result']['session_scope'] ?? null) === 'STAFF', 'Sessão STAFF deve iniciar somente após carregar o driver');

$missingDbBootstrap = $runBootstrapFixture($createBootstrapFixture('missing-db-connect', false));
$assert($missingDbBootstrap['exit'] === 0 && $missingDbBootstrap['error'] === '', 'Fixture sem hesk_dbConnect deve falhar de modo controlado');
$assert(($missingDbBootstrap['result']['db_before_loader'] ?? null) === false
    && ($missingDbBootstrap['result']['db_after_loader'] ?? null) === false, 'Ausência de hesk_dbConnect após o loader deve ser detectável');
$assert(($missingDbBootstrap['result']['ok'] ?? null) === false
    && ($missingDbBootstrap['result']['error'] ?? null) === 'O bootstrap STAFF do HESK está incompleto.', 'Runtime deve recusar driver sem hesk_dbConnect com erro seguro');

$sessionDirectory = sys_get_temp_dir() . '/tickets-auth-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory, 0700);
session_save_path($sessionDirectory);
$_SERVER['HTTPS'] = 'on';
$_SESSION = ['hesk_secret' => 'não pode migrar'];
session_name('HESK_STAFF');
session_id('heskstafftestid');
session_start();
$_SESSION['hesk_secret'] = 'não pode migrar';
session_write_close();

$_SESSION = [];
session_id('');
$scopedPanelSession = new AdminSession('/recurrent/');
$scopedPanelSession->open();
$scopedCookieParameters = session_get_cookie_params();
$assert($scopedCookieParameters['path'] === '/recurrent/', 'Cookie próprio deve aceitar escopo limitado ao base path do painel');
session_write_close();
$_SESSION = [];
session_id('');

$panelSession = new AdminSession();
$panelSession->open();
$cookieParameters = session_get_cookie_params();
$assert(session_name() === AdminSession::SESSION_NAME && session_name() !== 'HESK_STAFF', 'Painel deve usar nome de sessão próprio');
$assert(!isset($_SESSION['hesk_secret']), 'Dados da sessão HESK não devem aparecer na sessão do painel');
$assert((bool) ini_get('session.use_strict_mode') && (bool) ini_get('session.use_only_cookies'), 'Sessão do painel deve usar modo estrito e somente cookies');
$assert($cookieParameters['httponly'] && $cookieParameters['secure'] && $cookieParameters['samesite'] === 'Lax' && $cookieParameters['path'] === '/', 'Cookie do painel deve ter atributos seguros');
$assert(strlen($panelSession->token()) === 64, 'Sessão própria deve continuar fornecendo CSRF');
session_write_close();

$sessionFlowFixture = $bootstrapDirectory . '/session-flow';
mkdir($sessionFlowFixture . '/inc', 0700, true);
mkdir($sessionFlowFixture . '/sessions', 0700);
file_put_contents($sessionFlowFixture . '/hesk_settings.inc.php', <<<'PHP'
<?php
$hesk_settings = ['hesk_version' => '3.7.12'];
PHP);
file_put_contents($sessionFlowFixture . '/inc/common.inc.php', <<<'PHP'
<?php
function hesk_load_database_functions(): void
{
    require_once HESK_PATH . 'inc/database_mysqli.inc.php';
}

function hesk_session_start(string $scope = ''): void
{
    session_name('HESKSTAFFSESSIONFLOW');
    $cookie = $_COOKIE['HESKSTAFFSESSIONFLOW'] ?? null;

    if (is_string($cookie)) {
        session_id($cookie);
    }

    session_start();
}

function hesk_isLoggedIn(): bool
{
    return ($_SESSION['logged_in'] ?? false) === true;
}
PHP);
file_put_contents($sessionFlowFixture . '/inc/admin_functions.inc.php', "<?php\n");
file_put_contents($sessionFlowFixture . '/inc/database_mysqli.inc.php', <<<'PHP'
<?php
function hesk_dbConnect(): void {}
PHP);

$sessionFlowRunner = $bootstrapDirectory . '/run-session-flow.php';
file_put_contents($sessionFlowRunner, <<<'PHP'
<?php
declare(strict_types=1);

$fixture = $argv[1];
$project = $argv[2];
$action = $argv[3];
$cookies = json_decode($argv[4] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$submittedCsrf = $argv[5] ?? '';

session_save_path($fixture . '/sessions');
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_COOKIE = is_array($cookies) ? $cookies : [];

if ($action === 'seed-staff' || $action === 'logout-staff' || $action === 'inspect-staff') {
    session_name('HESKSTAFFSESSIONFLOW');
    session_id((string) ($_COOKIE['HESKSTAFFSESSIONFLOW'] ?? 'staffsessionflowid'));
    session_start();

    if ($action === 'inspect-staff') {
        $result = [
            'staff_id' => session_id(),
            'hesk_secret' => $_SESSION['hesk_secret'] ?? null,
            'session_verify' => $_SESSION['session_verify'] ?? null,
            'logged_in' => $_SESSION['logged_in'] ?? null,
            'session_keys' => array_keys($_SESSION),
        ];
        session_write_close();
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit;
    }

    $_SESSION = $action === 'seed-staff'
        ? [
            'id' => '100',
            'session_verify' => 'signed-session-value',
            'name' => 'Atendente HESK',
            'logged_in' => true,
            'hesk_secret' => 'não pode migrar',
        ]
        : [];
    $id = session_id();
    session_write_close();
    echo json_encode(['staff_cookie' => $id], JSON_THROW_ON_ERROR);
    exit;
}

require_once $project . '/src/Web/AdminAuthenticator.php';
require_once $project . '/src/Web/AuthenticatedStaff.php';
require_once $project . '/src/Web/HeskStaffRuntime.php';
require_once $project . '/src/Web/HeskStaffAuthenticator.php';
require_once $project . '/src/Web/NativeHeskStaffRuntime.php';
require_once $project . '/src/Web/AdminSession.php';

$authenticator = new \TicketsRecorrentesHesk\Web\HeskStaffAuthenticator(
    new \TicketsRecorrentesHesk\Web\NativeHeskStaffRuntime($fixture)
);
$staff = $authenticator->authenticate();

if ($staff === null) {
    echo json_encode(['status' => 401], JSON_THROW_ON_ERROR);
    exit;
}

$session = new \TicketsRecorrentesHesk\Web\AdminSession('/recurrent/');
$token = $session->token();
$status = $submittedCsrf === '' || $session->validToken($submittedCsrf) ? 200 : 403;
$result = [
    'status' => $status,
    'admin_id' => session_id(),
    'admin_name' => session_name(),
    'csrf' => $token,
    'session_keys' => array_keys($_SESSION),
    'cookie' => session_get_cookie_params(),
    'strict_mode' => (bool) ini_get('session.use_strict_mode'),
    'cookies_only' => (bool) ini_get('session.use_only_cookies'),
];
session_write_close();

echo json_encode($result, JSON_THROW_ON_ERROR);
PHP);

/**
 * @param array<string, string> $cookies
 * @return array{exit: int, error: string, result: array<string, mixed>}
 */
$runSessionFlow = static function (string $action, array $cookies = [], string $csrf = '') use ($sessionFlowRunner, $sessionFlowFixture): array {
    $process = proc_open(
        [PHP_BINARY, $sessionFlowRunner, $sessionFlowFixture, dirname(__DIR__), $action, json_encode($cookies, JSON_THROW_ON_ERROR), $csrf],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return ['exit' => -1, 'error' => 'proc_open indisponível', 'result' => []];
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode($output, true);

    return [
        'exit' => $exit,
        'error' => $error,
        'result' => is_array($decoded) ? $decoded : [],
    ];
};

$staffSeed = $runSessionFlow('seed-staff');
$staffCookie = (string) ($staffSeed['result']['staff_cookie'] ?? '');
$collisionRequest = $runSessionFlow('request', [
    'HESKSTAFFSESSIONFLOW' => $staffCookie,
    AdminSession::SESSION_NAME => $staffCookie,
]);
$staffAfterCollision = $runSessionFlow('inspect-staff', ['HESKSTAFFSESSIONFLOW' => $staffCookie]);
$firstRequest = $runSessionFlow('request', ['HESKSTAFFSESSIONFLOW' => $staffCookie]);
$adminCookie = (string) ($firstRequest['result']['admin_id'] ?? '');
$firstCsrf = (string) ($firstRequest['result']['csrf'] ?? '');
$secondRequest = $runSessionFlow('request', [
    'HESKSTAFFSESSIONFLOW' => $staffCookie,
    AdminSession::SESSION_NAME => $adminCookie,
], $firstCsrf);
$invalidCsrfRequest = $runSessionFlow('request', [
    'HESKSTAFFSESSIONFLOW' => $staffCookie,
    AdminSession::SESSION_NAME => $adminCookie,
], str_repeat('0', 64));
$forgedAdminRequest = $runSessionFlow('request', [
    'HESKSTAFFSESSIONFLOW' => $staffCookie,
    AdminSession::SESSION_NAME => 'forgedadminsessionid',
]);
$runSessionFlow('logout-staff', ['HESKSTAFFSESSIONFLOW' => $staffCookie]);
$loggedOutRequest = $runSessionFlow('request', [
    'HESKSTAFFSESSIONFLOW' => $staffCookie,
    AdminSession::SESSION_NAME => $adminCookie,
], $firstCsrf);

$assert($staffSeed['exit'] === 0 && $staffSeed['error'] === '' && $staffCookie !== '', 'Fixture deve criar sessão STAFF transportável');
$assert($collisionRequest['exit'] === 0 && $collisionRequest['error'] === ''
    && ($collisionRequest['result']['status'] ?? null) === 200, 'Colisão deliberada de SID deve manter autenticação STAFF e abrir o painel');
$assert(($collisionRequest['result']['admin_id'] ?? null) !== $staffCookie, 'SID administrativo final deve ser diferente do SID STAFF colidido');
$assert(!in_array('hesk_secret', $collisionRequest['result']['session_keys'] ?? [], true), 'Sessão administrativa não deve carregar dados do storage STAFF colidido');
$staffKeysAfterCollision = $staffAfterCollision['result']['session_keys'] ?? [];
$assert($staffAfterCollision['exit'] === 0 && $staffAfterCollision['error'] === ''
    && ($staffAfterCollision['result']['staff_id'] ?? null) === $staffCookie
    && ($staffAfterCollision['result']['hesk_secret'] ?? null) === 'não pode migrar'
    && ($staffAfterCollision['result']['session_verify'] ?? null) === 'signed-session-value'
    && ($staffAfterCollision['result']['logged_in'] ?? null) === true, 'Storage STAFF original deve permanecer íntegro após rejeição da colisão');
$assert(array_filter($staffKeysAfterCollision, static fn (mixed $key): bool => is_string($key) && str_starts_with($key, 'admin_ui_')) === [], 'Storage STAFF não pode receber chaves administrativas');
$assert($firstRequest['exit'] === 0 && $firstRequest['error'] === ''
    && ($firstRequest['result']['status'] ?? null) === 200, 'Primeira requisição autenticada deve abrir o painel');
$assert($adminCookie !== '' && strlen($firstCsrf) === 64, 'Primeira requisição deve criar cookie administrativo e CSRF reais');
$assert(($firstRequest['result']['admin_name'] ?? null) === AdminSession::SESSION_NAME, 'Sessão do painel deve permanecer separada da sessão HESK');
$assert(!in_array('hesk_secret', $firstRequest['result']['session_keys'] ?? [], true), 'Segredos HESK não podem migrar para a sessão administrativa');
$assert($secondRequest['exit'] === 0 && $secondRequest['error'] === ''
    && ($secondRequest['result']['status'] ?? null) === 200, 'CSRF transportado da primeira requisição deve ser aceito');
$assert(($secondRequest['result']['admin_id'] ?? null) === $adminCookie, 'Cookie administrativo deve reabrir exatamente o mesmo ID após o bootstrap HESK');
$assert(($secondRequest['result']['csrf'] ?? null) === $firstCsrf, 'CSRF deve permanecer estável entre GET e POST simulados em processos separados');
$assert(($secondRequest['result']['strict_mode'] ?? null) === true
    && ($secondRequest['result']['cookies_only'] ?? null) === true, 'Persistência deve preservar strict mode e cookies only');
$flowCookie = $secondRequest['result']['cookie'] ?? [];
$assert(($flowCookie['path'] ?? null) === '/recurrent/' && ($flowCookie['secure'] ?? null) === true
    && ($flowCookie['httponly'] ?? null) === true && ($flowCookie['samesite'] ?? null) === 'Lax', 'Cookie persistente deve conservar os atributos de produção');
$assert(($invalidCsrfRequest['result']['status'] ?? null) === 403, 'CSRF inválido deve continuar recusado com 403');
$assert(($forgedAdminRequest['result']['status'] ?? null) === 200
    && ($forgedAdminRequest['result']['admin_id'] ?? null) !== 'forgedadminsessionid', 'Strict mode deve substituir ID administrativo inexistente');
$assert(($loggedOutRequest['result']['status'] ?? null) === 401, 'Cookie administrativo não pode substituir sessão STAFF expirada');

foreach (glob($sessionDirectory . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($sessionDirectory);

$bootstrapItems = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($bootstrapDirectory, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($bootstrapItems as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($bootstrapDirectory);

if ($failures !== []) {
    fwrite(STDERR, "Falhas nos testes de autenticação:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Testes de autenticação concluídos: {$assertions} asserções.\n");
