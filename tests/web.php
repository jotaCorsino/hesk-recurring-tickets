<?php

declare(strict_types=1);

putenv('HESK_ADMIN_URL=https://helpdesk.example.com/admin/');

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\HeskCatalogProvider;
use TicketsRecorrentesHesk\HeskRecurrenceValidator;
use TicketsRecorrentesHesk\HeskReferenceData;
use TicketsRecorrentesHesk\Web\AdminRecurrenceWriter;
use TicketsRecorrentesHesk\Web\AdminAuthenticator;
use TicketsRecorrentesHesk\Web\AuthenticatedStaff;
use TicketsRecorrentesHesk\Web\HeskCatalogViewModel;

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/MigrationRunner.php';
require_once dirname(__DIR__) . '/src/RecurrenceValidator.php';
require_once dirname(__DIR__) . '/src/RecurrenceRepository.php';
require_once dirname(__DIR__) . '/src/HeskCatalogProvider.php';
require_once dirname(__DIR__) . '/src/HeskReferenceData.php';
require_once dirname(__DIR__) . '/src/HeskReferenceValidationException.php';
require_once dirname(__DIR__) . '/src/HeskRecurrenceValidator.php';
require_once dirname(__DIR__) . '/src/NativeHeskCatalogProvider.php';
require_once dirname(__DIR__) . '/src/Web/AdminRecurrenceWriter.php';
require_once dirname(__DIR__) . '/src/Web/AdminAuthenticator.php';
require_once dirname(__DIR__) . '/src/Web/AuthenticatedStaff.php';
require_once dirname(__DIR__) . '/src/Web/HeskCatalogViewModel.php';

final class FakeWebAuthenticator implements AdminAuthenticator
{
    public int $calls = 0;

    public function __construct(
        private readonly ?AuthenticatedStaff $staff = new AuthenticatedStaff(7, 'Técnico de teste', 'tecnico'),
    ) {
    }

    public function authenticate(): ?AuthenticatedStaff
    {
        $this->calls++;

        return $this->staff;
    }
}

final class FakeWebCatalogProvider implements HeskCatalogProvider
{
    public function __construct(
        private readonly bool $customerAvailable = true,
        private readonly ?HeskReferenceData $referenceData = null,
    )
    {
    }

    public function load(): HeskReferenceData
    {
        if ($this->referenceData !== null) {
            return $this->referenceData;
        }

        return new HeskReferenceData(
            $this->customerAvailable
                ? [100 => ['id' => 100, 'name' => 'Automação'], 202 => ['id' => 202, 'name' => 'Cliente 202']]
                : [],
            [5 => ['id' => 5, 'name' => 'WORKSTATION']],
            [
                3 => ['id' => 3, 'name' => 'Autor', 'user' => 'autor', 'active' => true, 'is_admin' => false, 'category_ids' => [5]],
                4 => ['id' => 4, 'name' => 'Responsável', 'user' => 'responsavel', 'active' => true, 'is_admin' => false, 'category_ids' => [5]],
                7 => ['id' => 7, 'name' => 'Responsável 7', 'user' => 'responsavel7', 'active' => true, 'is_admin' => false, 'category_ids' => [5]],
            ],
            [7 => ['id' => 7, 'name' => 'Baixa']],
            [0 => ['id' => 0, 'name' => 'Novo']],
            [
                'custom7' => ['key' => 'custom7', 'name' => 'Atendimento', 'type' => 'select', 'required' => false, 'category_ids' => [5], 'options' => ['Presencial']],
                'custom9' => ['key' => 'custom9', 'name' => 'Sistema', 'type' => 'select', 'required' => false, 'category_ids' => [5], 'options' => ['WINDOWS']],
                'custom10' => ['key' => 'custom10', 'name' => 'Tipo', 'type' => 'select', 'required' => false, 'category_ids' => [5], 'options' => ['REQ_Manutenção preventiva']],
                'custom15' => ['key' => 'custom15', 'name' => 'Cliente', 'type' => 'select', 'required' => false, 'category_ids' => [5], 'options' => ['EXAMPLE_COMPANY']],
                'custom20' => ['key' => 'custom20', 'name' => 'Interno', 'type' => 'text', 'required' => false, 'category_ids' => [5], 'options' => []],
            ],
        );
    }
}

final class FailingWebCatalogProvider implements HeskCatalogProvider
{
    public function load(): HeskReferenceData
    {
        throw new RuntimeException('SQLSTATE[HY000] /private/example/hesk_settings.inc.php senha=segredo');
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

/** @param array<string, mixed> $post
 *  @return array{status: int, body: string}
 */
$request = static function (?string $page, ?string $mode = null, ?string $enabled = '1', string $method = 'GET', ?string $recurrenceId = null, ?string $writeEnabled = null, array $post = [], ?AdminAuthenticator $authenticator = null, ?HeskCatalogProvider $catalogProvider = null, ?string $categoryId = null): array {
    if ($enabled === null) {
        putenv('ADMIN_UI_ENABLED');
    } else {
        putenv('ADMIN_UI_ENABLED=' . $enabled);
    }

    if ($writeEnabled === null) {
        putenv('ADMIN_UI_WRITE_ENABLED');
    } else {
        putenv('ADMIN_UI_WRITE_ENABLED=' . $writeEnabled);
    }

    $_GET = [];
    $_POST = $post;

    if ($page !== null) {
        $_GET['page'] = $page;
    }

    if ($mode !== null) {
        $_GET['mode'] = $mode;
    }

    if ($recurrenceId !== null) {
        $_GET['id'] = $recurrenceId;
    }

    if ($categoryId !== null) {
        $_GET['category_id'] = $categoryId;
    }

    $_SERVER['REQUEST_METHOD'] = $method;
    $adminAuthenticator = $authenticator ?? new FakeWebAuthenticator();
    $adminCatalogProvider = $catalogProvider ?? new FakeWebCatalogProvider();
    $configuredDatabasePath = getenv('APP_DB_PATH');
    $adminRecurrenceWriter = new AdminRecurrenceWriter(
        $configuredDatabasePath === false ? dirname(__DIR__) . '/storage/app.sqlite' : $configuredDatabasePath,
        new HeskRecurrenceValidator($adminCatalogProvider),
    );
    http_response_code(200);
    ob_start();
    require dirname(__DIR__) . '/public/index.php';
    $body = ob_get_clean();

    return ['status' => http_response_code(), 'body' => $body === false ? '' : $body];
};

$assertStructure = static function (string $html, string $name) use ($assert): void {
    $assert(str_starts_with(ltrim($html), '<!doctype html>'), "{$name}: doctype ausente");
    $assert(substr_count($html, '<html ') === 1 && substr_count($html, '</html>') === 1, "{$name}: html inválido");
    $assert(substr_count($html, '<main ') === 1 && substr_count($html, '</main>') === 1, "{$name}: main inválido");
    preg_match_all('/\bid="([^"]+)"/', $html, $ids);
    $assert(count($ids[1]) === count(array_unique($ids[1])), "{$name}: IDs HTML duplicados");
    preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/', $html, $labels);

    foreach ($labels[1] as $id) {
        $assert(in_array($id, $ids[1], true), "{$name}: label sem controle {$id}");
    }
};

$testDirectory = sys_get_temp_dir() . '/tickets-web-' . bin2hex(random_bytes(8));
mkdir($testDirectory, 0700);
session_save_path($testDirectory);
$databasePath = $testDirectory . '/ui.sqlite';
putenv('APP_DB_PATH=' . $databasePath);

$disabledAuthenticator = new FakeWebAuthenticator();
$blocked = $request(null, enabled: null, authenticator: $disabledAuthenticator);
$assert($blocked['status'] === 404 && !str_contains($blocked['body'], '<html'), 'Sem ADMIN_UI_ENABLED deve retornar 404 sem HTML');
$assert(!is_file($databasePath), 'Gate bloqueado não deve criar banco');
$disabled = $request('recurrences', enabled: '0', authenticator: $disabledAuthenticator);
$assert($disabled['status'] === 404 && !is_file($databasePath), 'ADMIN_UI_ENABLED=0 não deve abrir banco');
$assert($disabledAuthenticator->calls === 0, 'Gate geral deve ser verificado antes da autenticação STAFF');
putenv('ADMIN_UI_BASE_PATH=/recurrent/../storage');
$invalidBasePath = $request('recurrences');
$assert($invalidBasePath['status'] === 503 && !str_contains($invalidBasePath['body'], 'storage'), 'Base path inválido deve falhar fechado sem expor configuração');
putenv('ADMIN_UI_BASE_PATH');

$unauthenticated = new FakeWebAuthenticator(null);
$restricted = $request('recurrences', authenticator: $unauthenticated);
$assert($restricted['status'] === 401, 'Sessão STAFF ausente deve retornar 401');
$assert(str_contains($restricted['body'], 'Acesso restrito à equipe'), 'Sessão ausente deve exibir orientação curta');
$assert(str_contains($restricted['body'], 'href="https://helpdesk.example.com/admin/"'), 'Tela restrita deve apontar ao login administrativo exato do HESK');
$assert(!str_contains($restricted['body'], '<form') && !str_contains($restricted['body'], 'name="password"'), 'Tela restrita não deve criar login local');
$assert(!is_file($databasePath), 'Usuário não autenticado não deve abrir o banco da aplicação');
putenv('ADMIN_UI_BASE_PATH=/recurrent');
$restrictedSubdirectory = $request('recurrences', authenticator: $unauthenticated);
$assert(str_contains($restrictedSubdirectory['body'], 'href="/recurrent/assets/css/admin.css"'), 'CSS da autenticação deve respeitar /recurrent');
$assert(str_contains($restrictedSubdirectory['body'], 'src="/recurrent/assets/logo-recurring-tickets.svg"'), 'Assets da autenticação devem respeitar /recurrent');
putenv('ADMIN_UI_BASE_PATH');
$restrictedExecutions = $request('executions', authenticator: $unauthenticated);
$assert($restrictedExecutions['status'] === 401 && !str_contains($restrictedExecutions['body'], 'execuções recentes'), 'Usuário não autenticado não deve ler Execuções');
$restrictedPost = $request('recurrence-state', method: 'POST', recurrenceId: '1', writeEnabled: '1', post: ['_csrf' => 'x'], authenticator: $unauthenticated);
$assert($restrictedPost['status'] === 401 && !is_file($databasePath), 'POST protegido deve exigir STAFF antes de escrita e CSRF');

$escapedStaff = new FakeWebAuthenticator(new AuthenticatedStaff(8, '<Admin & Suporte>', 'admin-secret'));
$identityResponse = $request('system', authenticator: $escapedStaff);
$assert($identityResponse['status'] === 200 && str_contains($identityResponse['body'], '&lt;Admin &amp; Suporte&gt;'), 'Identidade STAFF deve aparecer com escape');
$assert(!str_contains($identityResponse['body'], 'admin-secret'), 'Username não deve ser exposto quando o nome está disponível');
$assert(str_contains($identityResponse['body'], 'Equipe HESK'), 'Painel autenticado deve identificar discretamente a equipe');
$assert($escapedStaff->calls === 1, 'STAFF autenticado deve acessar sem verificação de privilégio administrativo');

$uninitialized = $request('recurrences');
$assert($uninitialized['status'] === 503, 'Banco ausente deve retornar 503');
$assert(str_contains($uninitialized['body'], 'Dados indisponíveis'), 'Erro de banco deve ter página administrativa');
$assert(!str_contains($uninitialized['body'], $testDirectory), 'Erro não deve revelar caminho do banco');
$assert(!is_file($databasePath), 'Leitura web não deve criar banco ausente');

$seed = static function (string $path): array {
    $connection = (new Database($path))->connect();
    (new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations'))->migrate();
    $repository = new RecurrenceRepository($connection);
    $base = [
        'name' => 'Preventiva <Estações>',
        'enabled' => true,
        'timezone' => 'America/Sao_Paulo',
        'interval_value' => 3,
        'interval_unit' => 'month',
        'next_run_at' => '2027-10-01T12:00:00Z',
        'quantity' => 10,
        'customer_id' => 100,
        'category_id' => 5,
        'priority_name' => 'Baixa',
        'status_id' => 0,
        'owner_id' => 4,
        'openedby_id' => 3,
        'subject' => 'Manutenção preventiva',
        'message' => 'Conferir estações',
        'notify_customer' => false,
        'custom_fields' => ['custom7' => 'Presencial', 'custom15' => 'EXAMPLE_COMPANY', 'custom20' => 'Valor <interno>'],
    ];
    $records = [];

    foreach ([
        [],
        ['name' => 'Revisão inativa', 'enabled' => false, 'interval_value' => 2, 'interval_unit' => 'week', 'timezone' => 'UTC', 'next_run_at' => '2027-12-01T00:00:00Z', 'customer_id' => 202, 'owner_id' => 7],
        ['name' => 'Revisão diária', 'interval_value' => 1, 'interval_unit' => 'day'],
        ['name' => 'Revisão mensal', 'interval_value' => 1, 'interval_unit' => 'month'],
        ['name' => 'Revisão anual', 'interval_value' => 1, 'interval_unit' => 'year'],
    ] as $changes) {
        $records[] = $repository->create(array_replace($base, $changes));
    }

    return $records;
};

$setupConnection = (new Database($databasePath))->connect();
(new MigrationRunner($setupConnection, dirname(__DIR__) . '/database/migrations'))->migrate();
unset($setupConnection);
$empty = $request('recurrences');
$assert($empty['status'] === 200 && str_contains($empty['body'], 'Nenhuma recorrência cadastrada'), 'Banco vazio deve exibir estado vazio');
$assert(str_contains($empty['body'], '0 modelos') && !str_contains($empty['body'], 'Preventiva de estações'), 'Banco vazio não deve usar DemoData');
$assertStructure($empty['body'], 'lista vazia');
$emptyExecutions = $request('executions');
$assert($emptyExecutions['status'] === 200 && str_contains($emptyExecutions['body'], 'Nenhuma execução registrada até o momento.'), 'Histórico vazio deve retornar 200 com orientação clara');
$assert(str_contains($emptyExecutions['body'], '0 execuções') && !str_contains($emptyExecutions['body'], 'Dados de exemplo'), 'Histórico vazio deve permanecer real e somente leitura');

$records = $seed($databasePath);
$firstId = (string) $records[0]['id'];
$secondId = (string) $records[1]['id'];
$beforeHash = hash_file('sha256', $databasePath);
$list = $request('recurrences');
$assert($list['status'] === 200 && str_contains($list['body'], '5 modelos'), 'Lista real deve mostrar cinco recorrências');
$assert(str_contains($list['body'], 'Preventiva &lt;Estações&gt;') && !str_contains($list['body'], '<h3>Preventiva <Estações></h3>'), 'Nome real deve ser escapado');
$assert(str_contains($list['body'], 'badge--active') && str_contains($list['body'], 'badge--inactive'), 'Status ativo/inativo deve ser apresentado');
foreach (['A cada 3 meses', 'A cada 2 semanas', 'A cada 1 dia', 'A cada 1 mês', 'A cada 1 ano'] as $frequency) {
    $assert(str_contains($list['body'], $frequency), "Frequência {$frequency} ausente");
}
$assert(str_contains($list['body'], '01/10/2027 09:00'), 'Data UTC deve ser convertida para America/Sao_Paulo');
$assert(str_contains($list['body'], '01/12/2027 00:00'), 'Data UTC deve ser preservada na recorrência UTC inativa');
$assert(str_contains($list['body'], '<strong>Solicitante:</strong> Automação')
    && str_contains($list['body'], '<strong>Responsável:</strong> Responsável'), 'Lista deve apresentar nomes atuais do catálogo HESK');
$assert(str_contains($list['body'], '<strong>Solicitante:</strong> Cliente 202')
    && str_contains($list['body'], '<strong>Responsável:</strong> Responsável 7'), 'Referências variáveis devem ser resolvidas pelo catálogo');
$assert(str_contains($list['body'], '10 tickets por execução') && str_contains($list['body'], 'Somente leitura'), 'Quantidade real e estado somente leitura devem aparecer');
$assert(str_contains($list['body'], 'recurrence-form&amp;id=' . $firstId) && substr_count($list['body'], '>Editar</a>') === 5, 'Editar deve apontar para cada ID real');
$assert(!str_contains($list['body'], 'Dados de exemplo'), 'Lista real não deve ser rotulada como demonstrativa');
$assertStructure($list['body'], 'lista real');

$form = $request('recurrence-form', recurrenceId: $firstId);
$assert($form['status'] === 200 && str_contains($form['body'], 'value="Preventiva &lt;Estações&gt;"'), 'Formulário deve carregar nome real');
$assert(str_contains($form['body'], '<fieldset class="form-readonly" disabled>') && str_contains($form['body'], 'type="submit" disabled'), 'Sem gate de escrita o formulário deve bloquear edição e salvamento');
foreach (['America/Sao_Paulo', '2027-10-01T09:00', 'value="10"', 'id="ticket-customer" name="customer_id"', 'value="100" selected', 'id="ticket-category" name="category_id"', 'value="5" selected', 'id="ticket-owner" name="owner_id"', 'value="4" selected', 'id="ticket-openedby" name="openedby_id"', 'value="3" selected', 'Baixa', 'Manutenção preventiva', 'Conferir estações'] as $value) {
    $assert(str_contains($form['body'], $value), "Campo persistido {$value} ausente no formulário");
}
$assert(str_contains($form['body'], 'name="custom_fields[custom20]"') && str_contains($form['body'], 'Valor &lt;interno&gt;'), 'Custom fields adicionais devem aparecer com escape');
$assert(str_contains($form['body'], 'name="notify_customer" type="checkbox" value="1"  disabled'), 'notify_customer=false deve ficar desmarcado e desabilitado');
$assertStructure($form['body'], 'formulário real');

$inactiveForm = $request('recurrence-form', 'view', recurrenceId: $secondId);
$assert($inactiveForm['status'] === 200 && str_contains($inactiveForm['body'], 'value="Revisão inativa"'), 'Visualizar deve carregar a recorrência inativa');
$assert(!str_contains($inactiveForm['body'], 'name="enabled" type="checkbox" checked'), 'enabled=false deve ficar desmarcado');
$newForm = $request('recurrence-form');
$assert($newForm['status'] === 200 && str_contains($newForm['body'], 'Dados em modo somente leitura.'), 'Sem gate de escrita a criação deve permanecer bloqueada');
$assert(!str_contains($newForm['body'], 'value="Preventiva'), 'Novo formulário não deve usar DemoData');
$missing = $request('recurrence-form', recurrenceId: '999999');
$assert($missing['status'] === 404 && str_contains($missing['body'], 'Recorrência não encontrada'), 'ID inexistente deve retornar 404 administrativo');
$assertStructure($missing['body'], '404 de recorrência');
$assert(!str_contains($missing['body'], 'Fatal error') && !str_contains($missing['body'], $testDirectory), '404 não deve expor stack trace ou path');

foreach (['recurrences', 'recurrence-form', 'executions', 'system'] as $route) {
    $post = $request($route, method: 'POST', recurrenceId: $route === 'recurrence-form' ? $firstId : null);
    $assert($post['status'] === ($route === 'recurrence-form' ? 403 : 405), "POST {$route} deve ser recusado");
}
$assert(hash_file('sha256', $databasePath) === $beforeHash, 'GET e POST web não devem alterar o SQLite');
$readOnly = (new Database($databasePath))->connectReadOnly();
try {
    $readOnly->exec('UPDATE recurrences SET enabled = 0 WHERE id = ' . $firstId);
    $assert(false, 'Conexão web deve rejeitar UPDATE');
} catch (PDOException) {
    $assert(true, 'Conexão web rejeitou UPDATE');
}
unset($readOnly);
$assert(!function_exists('hesk_newTicket'), 'Camada web não deve carregar criador de tickets HESK');

$historyPath = $testDirectory . '/history.sqlite';
$historyRecords = $seed($historyPath);
$historyConnection = (new Database($historyPath))->connect();
$executionInsert = $historyConnection->prepare(
    'INSERT INTO recurrence_executions (
        recurrence_id, scheduled_for, status, expected_count, created_count,
        started_at, finished_at, error_message, created_at, updated_at,
        attempt_count, lease_token, lease_owner, lease_expires_at, last_attempt_at, error_code
     ) VALUES (
        :recurrence_id, :scheduled_for, :status, :expected_count, :created_count,
        :started_at, :finished_at, :error_message, :created_at, :updated_at,
        :attempt_count, :lease_token, :lease_owner, :lease_expires_at, :last_attempt_at, :error_code
     )'
);
$technicalSecret = 'SQLSTATE[HY000] /private/example/segredo.php senha=supersecreta';

for ($index = 1; $index <= 55; $index++) {
    $scheduled = sprintf('2026-01-01T00:00:%02dZ', $index);
    $status = 'pending';
    $expectedCount = 1;
    $createdCount = 0;
    $attemptCount = 0;
    $startedAt = null;
    $lastAttemptAt = null;
    $finishedAt = null;
    $errorMessage = null;
    $errorCode = null;
    $leaseToken = null;
    $leaseOwner = null;
    $leaseExpiresAt = null;

    if ($index === 54) {
        $scheduled = '2026-01-01T00:00:55Z';
        $status = 'running';
        $expectedCount = 2;
        $attemptCount = 1;
        $startedAt = $lastAttemptAt = '2026-01-01T00:01:00Z';
        $leaseToken = 'secret-token-ui-001e';
        $leaseOwner = 'worker-secret-ui-001e';
        $leaseExpiresAt = '2026-01-01T00:10:00Z';
    } elseif ($index === 53) {
        $status = 'succeeded';
        $expectedCount = $createdCount = 2;
        $attemptCount = 1;
        $startedAt = '2026-01-01T00:00:53Z';
        $lastAttemptAt = $finishedAt = '2026-01-01T00:01:53Z';
    } elseif ($index === 52) {
        $status = 'failed';
        $expectedCount = 2;
        $attemptCount = 2;
        $startedAt = '2026-01-01T00:00:52Z';
        $lastAttemptAt = $finishedAt = '2026-01-01T00:01:52Z';
        $errorMessage = $technicalSecret;
        $errorCode = 'HESK_CATEGORY_INVALID';
    } elseif ($index === 51) {
        $status = 'partial';
        $expectedCount = 2;
        $createdCount = 1;
        $attemptCount = 2;
        $startedAt = '2026-01-01T00:00:51Z';
        $lastAttemptAt = $finishedAt = '2026-01-01T00:01:51Z';
        $errorMessage = $technicalSecret;
        $errorCode = 'BATCH_PARTIAL_FAILURE';
    } elseif ($index === 50) {
        $status = 'failed';
        $attemptCount = 1;
        $errorMessage = $technicalSecret;
        $errorCode = 'FUTURE_UNKNOWN_CODE';
    } elseif ($index === 49) {
        $status = 'failed';
        $attemptCount = 1;
        $errorMessage = $technicalSecret;
    } elseif ($index === 48) {
        $startedAt = 'data-legada-invalida';
    }

    $executionInsert->execute([
        'recurrence_id' => $index === 54 ? $historyRecords[1]['id'] : $historyRecords[0]['id'],
        'scheduled_for' => $scheduled,
        'status' => $status,
        'expected_count' => $expectedCount,
        'created_count' => $createdCount,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
        'error_message' => $errorMessage,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:02:00Z',
        'attempt_count' => $attemptCount,
        'lease_token' => $leaseToken,
        'lease_owner' => $leaseOwner,
        'lease_expires_at' => $leaseExpiresAt,
        'last_attempt_at' => $lastAttemptAt,
        'error_code' => $errorCode,
    ]);
}

$itemInsert = $historyConnection->prepare(
    'INSERT INTO recurrence_execution_items (
        execution_id, item_index, status, hesk_trackid, hesk_ticket_id,
        creation_attempts, last_attempt_at, error_message, created_at, updated_at, error_code
     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$itemRows = [
    [54, 1, 'creating', 'RUN-0001', null, 1, '2026-01-01T00:01:00Z', null, null],
    [54, 2, 'pending', null, null, 0, null, null, null],
    [53, 1, 'succeeded', 'TST-001-ABCD', 201, 1, '2026-01-01T00:01:53Z', null, null],
    [53, 2, 'succeeded', 'TST-002-EFGH', 202, 1, '2026-01-01T00:01:53Z', null, null],
    [52, 1, 'failed', 'FAIL-0001', null, 2, '2026-01-01T00:01:52Z', $technicalSecret, 'HESK_CUSTOM_FIELDS_INVALID'],
    [52, 2, 'pending', null, null, 0, null, null, null],
    [51, 1, 'succeeded', 'PART-0001', 46, 1, '2026-01-01T00:01:51Z', null, null],
    [51, 2, 'failed', 'PART-0002', null, 2, '2026-01-01T00:01:51Z', $technicalSecret, 'HESK_TICKET_CREATE_FAILED'],
];

foreach ($itemRows as $row) {
    $itemInsert->execute([...array_slice($row, 0, 8), '2026-01-01T00:00:00Z', '2026-01-01T00:02:00Z', $row[8]]);
}

unset($itemInsert, $executionInsert, $historyConnection);
putenv('APP_DB_PATH=' . $historyPath);
$historyHash = hash_file('sha256', $historyPath);
$historyCounts = (new Database($historyPath))->connectReadOnly();
$executionCountBefore = (int) $historyCounts->query('SELECT count(*) FROM recurrence_executions')->fetchColumn();
$itemCountBefore = (int) $historyCounts->query('SELECT count(*) FROM recurrence_execution_items')->fetchColumn();
unset($historyCounts);
$executions = $request('executions');
$assert($executions['status'] === 200 && str_contains($executions['body'], 'Somente leitura'), 'Execuções reais devem carregar em modo somente leitura');
$assertStructure($executions['body'], 'Histórico real');
$assert(substr_count($executions['body'], '<li class="execution-entry">') === 50, 'Histórico deve renderizar exatamente as 50 executions mais recentes');
$assert(!str_contains($executions['body'], 'Execução #5 <span') && str_contains($executions['body'], 'Execução #6 <span'), 'Limite deve excluir as cinco executions mais antigas');
$newestExecution = strpos($executions['body'], 'Execução #55');
$sameScheduleExecution = strpos($executions['body'], 'Execução #54');
$assert($newestExecution !== false && $sameScheduleExecution !== false && $newestExecution < $sameScheduleExecution, 'Ordenação deve usar scheduled_for DESC e id DESC');
$assert(str_contains($executions['body'], 'Preventiva &lt;Estações&gt;') && str_contains($executions['body'], 'Recorrência #' . $historyRecords[0]['id']), 'Histórico deve mostrar o nome e ID reais da recorrência');
foreach (['pending', 'running', 'succeeded', 'failed', 'partial'] as $status) {
    $assert(str_contains($executions['body'], 'badge--' . $status), "Badge real {$status} ausente");
}
$assert(str_contains($executions['body'], 'Os itens deste lote ainda não foram materializados.'), 'Execution pendente sem itens deve mostrar estado vazio do lote');
$assert(str_contains($executions['body'], '2 / 2') && str_contains($executions['body'], 'Ticket HESK #201')
    && str_contains($executions['body'], 'Tracking TST-001-ABCD'), 'Execution concluída deve mostrar progresso e identidades HESK reais');
$assert(str_contains($executions['body'], 'badge--creating') && str_contains($executions['body'], 'Tracking RUN-0001'), 'Itens devem ser associados à execution correta em uma leitura agrupada');
$assert(str_contains($executions['body'], 'A categoria configurada não está disponível.')
    && str_contains($executions['body'], 'Confira a categoria da recorrência antes de reprocessar.'), 'Falha conhecida deve usar motivo e próximo passo do catálogo');
$assert(str_contains($executions['body'], 'Um campo personalizado não foi aceito.')
    && str_contains($executions['body'], 'Confira os campos da recorrência e as opções da categoria.'), 'Item falho deve usar seu próprio error_code');
$assert(str_contains($executions['body'], 'Parte dos tickets do lote foi criada.')
    && str_contains($executions['body'], 'A criação do ticket não foi confirmada.'), 'Execution parcial e item falho devem ter apresentações seguras independentes');
$assert(substr_count($executions['body'], 'A execução terminou com uma falha não classificada.') >= 2, 'Códigos desconhecido e nulo devem usar o fallback do catálogo');
$assert(!str_contains($executions['body'], 'SQLSTATE') && !str_contains($executions['body'], '/private/example')
    && !str_contains($executions['body'], 'supersecreta'), 'error_message técnico sensível nunca deve aparecer no HTML');
$assert(!str_contains($executions['body'], 'secret-token-ui-001e') && !str_contains($executions['body'], 'worker-secret-ui-001e')
    && !str_contains($executions['body'], 'lease_expires_at'), 'Dados de lease nunca devem chegar à apresentação');
$assert(str_contains($executions['body'], '01/01/2026 00:00 UTC') && str_contains($executions['body'], 'Data indisponível'), 'Datas devem usar UTC e valores legados inválidos não devem derrubar a página');
$assert(!str_contains($executions['body'], 'Prévia com dados fictícios') && !str_contains($executions['body'], 'exemplos'), 'Histórico real não deve manter textos demonstrativos');
$assert(!str_contains($executions['body'], 'href="https://private-host.invalid/'), 'Identificadores HESK não devem criar links');
$assert(hash_file('sha256', $historyPath) === $historyHash, 'GET do histórico não deve modificar o SQLite');
$historyCountsAfter = (new Database($historyPath))->connectReadOnly();
$assert((int) $historyCountsAfter->query('SELECT count(*) FROM recurrence_executions')->fetchColumn() === $executionCountBefore
    && (int) $historyCountsAfter->query('SELECT count(*) FROM recurrence_execution_items')->fetchColumn() === $itemCountBefore, 'GET não deve criar execution nem item');
unset($historyCountsAfter);
$rawHistory = (new \TicketsRecorrentesHesk\Web\AdminDataProvider($historyPath))->findRecentExecutionHistory();
$assert(count($rawHistory) === 50 && count($rawHistory[1]['items']) === 2, 'Provider deve limitar executions e agrupar itens sem consulta por execution');
$assert(!array_key_exists('error_message', $rawHistory[2]) && !array_key_exists('lease_token', $rawHistory[1])
    && !array_key_exists('lease_owner', $rawHistory[1]) && !array_key_exists('lease_expires_at', $rawHistory[1]), 'Provider não deve enviar diagnóstico técnico nem lease ao view model');
$assert(!function_exists('hesk_newTicket'), 'Histórico web não deve carregar nem chamar HESK');
putenv('APP_DB_PATH=' . $databasePath);
$systemHash = hash_file('sha256', $databasePath);
$system = $request('system');
$assert($system['status'] === 200
    && str_contains($system['body'], 'Estado da aplicação e do ambiente.')
    && str_contains($system['body'], htmlspecialchars(PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'Sistema deve apresentar PHP real com escape');
$assert(str_contains($system['body'], 'Painel administrativo')
    && str_contains($system['body'], 'Habilitado'), 'Sistema deve apresentar o gate real do painel');
$assert(str_contains($system['body'], 'Escrita Web')
    && str_contains($system['body'], 'Somente leitura'), 'Sistema deve apresentar a escrita Web desabilitada');
$assert(str_contains($system['body'], 'Banco da aplicação')
    && str_contains($system['body'], 'Disponível')
    && str_contains($system['body'], 'Atualizadas'), 'Sistema deve consultar SQLite e migrations reais');
foreach (['prévia', 'UI-001A · prévia', 'Dados de exemplo', 'Não conectado', 'Não consultado'] as $legacySystemText) {
    $assert(!str_contains($system['body'], $legacySystemText), "Sistema não deve manter o texto legado {$legacySystemText}");
}
$systemWriteEnabled = $request('system', writeEnabled: '1');
$assert($systemWriteEnabled['status'] === 200
    && str_contains($systemWriteEnabled['body'], 'Habilitada'), 'Sistema deve refletir a escrita Web habilitada');
$assert(hash_file('sha256', $databasePath) === $systemHash, 'Sistema não deve modificar o SQLite');
$systemSources = (string) file_get_contents(dirname(__DIR__) . '/src/Web/AdminDataProvider.php')
    . (string) file_get_contents(dirname(__DIR__) . '/src/Web/AdminUi.php')
    . (string) file_get_contents(dirname(__DIR__) . '/src/Web/views/pages/system.php');
$assert(str_contains($systemSources, "\$escape(\$systemStatus['php_version'])"), 'Template Sistema deve escapar a versão do PHP');
$assert(!preg_match('/\b(?:shell_exec|exec|system|passthru|proc_open)\s*\(/', $systemSources), 'Sistema não deve inspecionar processos ou shell');
$assert(!str_contains($systemSources, '->migrate('), 'Sistema não deve aplicar migrations');
$home = $request(null);
$assert($home['status'] === 200 && str_contains($home['body'], '<h1>Recorrências</h1>'), 'Página inicial deve continuar Recorrências');
$assert(str_contains($home['body'], 'logo-recurring-tickets.svg') && str_contains($home['body'], 'favicon-recurring-tickets.svg'), 'Identidade neutra deve permanecer');
putenv('ADMIN_UI_BASE_PATH=/recurrent');
$subdirectoryHome = $request(null);
$subdirectoryForm = $request('recurrence-form', writeEnabled: '1');
$assert(str_contains($subdirectoryHome['body'], 'href="/recurrent/index.php?page=executions"')
    && str_contains($subdirectoryHome['body'], 'href="/recurrent/assets/css/admin.css"'), 'Navegação e assets autenticados devem respeitar /recurrent');
$assert(str_contains($subdirectoryForm['body'], 'action="/recurrent/index.php?page=recurrence-form"')
    && str_contains($subdirectoryForm['body'], 'src="/recurrent/assets/js/recurrence-form.js"'), 'Formulário e JavaScript devem respeitar /recurrent');
putenv('ADMIN_UI_BASE_PATH');
$assert($request('overview')['status'] === 200, 'Rota antiga overview deve apontar à lista real');
$assert($request('does-not-exist')['status'] === 404, 'Rota desconhecida deve retornar 404');
$assert($request('recurrence-form', 'delete')['status'] === 404, 'Modo desconhecido deve retornar 404');

$tokenFrom = static function (string $html): string {
    return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $match) ? $match[1] : '';
};
$hiddenFrom = static function (string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $match) ? $match[1] : '';
};
$stateFieldsFrom = static function (string $html, string $id) use ($hiddenFrom): array {
    if (!preg_match('/<form class="state-action-form" action="index\.php\?page=recurrence-state&amp;id='
        . preg_quote($id, '/') . '" method="post">(.*?)<\/form>/s', $html, $match)) {
        return [];
    }

    return [
        '_csrf' => $hiddenFrom($match[1], '_csrf'),
        'expected_enabled' => $hiddenFrom($match[1], 'expected_enabled'),
        'updated_at' => $hiddenFrom($match[1], 'updated_at'),
        'version' => $hiddenFrom($match[1], 'version'),
        'return_to' => $hiddenFrom($match[1], 'return_to'),
    ];
};
$writeForm = $request('recurrence-form', writeEnabled: '1');
$csrf = $tokenFrom($writeForm['body']);
$assert($writeForm['status'] === 200 && $csrf !== '', 'GET de criação com escrita habilitada deve oferecer CSRF');
$assert(str_contains($writeForm['body'], '<fieldset class="form-readonly" >') && str_contains($writeForm['body'], 'type="submit" >'), 'Formulário de criação deve ser editável');
$assert(!str_contains($writeForm['body'], 'name="enabled"'), 'Situação não pode ser enviada pelo formulário');
$cookie = session_get_cookie_params();
$assert($cookie['httponly'] === true && $cookie['samesite'] === 'Lax', 'Cookie de sessão deve usar HttpOnly e SameSite');
$validPost = [
    '_csrf' => $csrf,
    'name' => 'Web <seguro>',
    'enabled' => '1',
    'timezone' => 'America/Sao_Paulo',
    'interval_value' => '3',
    'interval_unit' => 'month',
    'next_run_at' => '2027-10-01T09:00',
    'quantity' => '2',
    'customer_id' => '100',
    'category_id' => '5',
    'priority_name' => 'Baixa',
    'status_id' => '0',
    'owner_id' => '4',
    'openedby_id' => '3',
    'subject' => 'Manutenção web',
    'message' => 'Mensagem web',
    'custom_fields' => ['custom9' => 'WINDOWS', 'custom10' => 'REQ_Manutenção preventiva', 'custom15' => 'EXAMPLE_COMPANY'],
];
$writeOffHash = hash_file('sha256', $databasePath);
$writeOff = $request('recurrence-form', method: 'POST', writeEnabled: '0', post: $validPost);
$assert($writeOff['status'] === 403 && hash_file('sha256', $databasePath) === $writeOffHash, 'Gate de escrita ausente deve recusar POST sem alterar banco');
$assert($request('recurrence-form', method: 'POST', writeEnabled: '1', post: array_diff_key($validPost, ['_csrf' => true]))['status'] === 403, 'CSRF ausente deve retornar 403');
$assert($request('recurrence-form', method: 'POST', writeEnabled: '1', post: array_replace($validPost, ['_csrf' => str_repeat('0', 64)]))['status'] === 403, 'CSRF inválido deve retornar 403');
$assert((new RecurrenceRepository((new Database($databasePath))->connectReadOnly()))->findAll() !== [], 'Tentativas recusadas não devem indisponibilizar leitura');
$infrastructureFailure = (new \TicketsRecorrentesHesk\Web\AdminUi(
    true,
    writeEnabled: true,
    writer: new AdminRecurrenceWriter($databasePath, new HeskRecurrenceValidator(new FailingWebCatalogProvider())),
    authenticator: new FakeWebAuthenticator(),
))->respond('recurrence-form', 'new', 'POST', null, $validPost);
$assert($infrastructureFailure['status'] === 503
    && !str_contains($infrastructureFailure['body'], 'SQLSTATE')
    && !str_contains($infrastructureFailure['body'], 'hesk_settings'), 'Falha de infraestrutura HESK deve retornar 503 sem detalhe técnico');
$directResponse = (new \TicketsRecorrentesHesk\Web\AdminUi(
    true,
    writeEnabled: true,
    writer: new AdminRecurrenceWriter($databasePath, new HeskRecurrenceValidator(new FakeWebCatalogProvider())),
    authenticator: new FakeWebAuthenticator(),
    adminUrl: new \TicketsRecorrentesHesk\Web\AdminUrl('/recurrent'),
))->respond('recurrence-form', 'new', 'POST', null, $validPost);
$assert($directResponse['status'] === 303 && str_starts_with($directResponse['location'] ?? '', '/recurrent/index.php?page=recurrence-form&mode=edit&id='), 'Criação deve usar PRG 303 dentro do base path');
$readRepository = new RecurrenceRepository((new Database($databasePath))->connectReadOnly());
$afterCreate = $readRepository->findAll();
$assert(count($afterCreate) === 6, 'POST válido deve criar exatamente uma recorrência');
$created = $afterCreate[5];
$createdId = (string) $created['id'];
$assert($created['enabled'] === false && $created['notify_customer'] === false, 'Nova recorrência deve nascer inativa e sem notificação');
$assert($created['next_run_at'] === '2027-10-01T12:00:00Z' && $created['quantity'] === 2 && $created['customer_id'] === 100, 'Tipos e horário local devem ser convertidos antes da persistência');
$assert($created['custom_fields'] === $validPost['custom_fields'], 'Custom fields devem ser persistidos corretamente');
$assert((int) (new Database($databasePath))->connectReadOnly()->query('SELECT count(*) FROM recurrence_executions')->fetchColumn() === 0, 'Criação web não deve gerar execution');
$createdForm = $request('recurrence-form', 'edit', recurrenceId: $createdId, writeEnabled: '1');
$assert($createdForm['status'] === 200 && str_contains($createdForm['body'], 'Recorrência criada com sucesso.'), 'Redirect deve mostrar confirmação curta');
$assert(str_contains($createdForm['body'], 'value="Web &lt;seguro&gt;"') && $hiddenFrom($createdForm['body'], 'version') !== '', 'Edição deve apresentar dado escapado e versão');
$assertStructure($createdForm['body'], 'formulário editável');

$invalidCases = [
    ['name' => ''],
    ['customer_id' => '0'],
    ['quantity' => '2.5'],
    ['timezone' => 'Invalid/Zone'],
    ['interval_unit' => 'hour'],
    ['next_run_at' => '2027-02-30T09:00'],
    ['custom_fields' => ['badfield' => 'x']],
];
foreach ($invalidCases as $index => $changes) {
    $invalid = $request('recurrence-form', method: 'POST', writeEnabled: '1', post: array_replace($validPost, $changes));
    $assert($invalid['status'] === 422 && str_contains($invalid['body'], 'Revise os dados informados'), "Entrada inválida {$index} deve retornar 422 com resumo");
    $assert(count((new RecurrenceRepository((new Database($databasePath))->connectReadOnly()))->findAll()) === 6, "Entrada inválida {$index} não deve persistir parcialmente");
}
$escapedInvalid = $request('recurrence-form', method: 'POST', writeEnabled: '1', post: array_replace($validPost, ['name' => '<script>alert(1)</script>', 'quantity' => 'x']));
$assert(str_contains($escapedInvalid['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($escapedInvalid['body'], '<script>'), '422 deve preservar valores com escape HTML');

$editPost = array_replace($validPost, [
    'name' => 'Web editada',
    'quantity' => '4',
    'notify_customer' => '1',
    'updated_at' => $hiddenFrom($createdForm['body'], 'updated_at'),
    'version' => $hiddenFrom($createdForm['body'], 'version'),
]);
$edit = $request('recurrence-form', 'edit', method: 'POST', recurrenceId: $createdId, writeEnabled: '1', post: $editPost);
$assert($edit['status'] === 303, 'Edição inativa deve redirecionar com HTTP 303');
$edited = (new RecurrenceRepository((new Database($databasePath))->connectReadOnly()))->findById((int) $createdId);
$assert($edited['name'] === 'Web editada' && $edited['quantity'] === 4 && $edited['notify_customer'] === true, 'Edição deve persistir campos permitidos');
$assert($edited['enabled'] === false && $edited['created_at'] === $created['created_at'] && $edited['updated_at'] !== $created['updated_at'], 'Edição deve preservar enabled e created_at e avançar updated_at');
$editedForm = $request('recurrence-form', 'edit', recurrenceId: $createdId, writeEnabled: '1');
$assert(str_contains($editedForm['body'], 'Recorrência atualizada com sucesso.'), 'PRG da edição deve mostrar confirmação');
$conflict = $request('recurrence-form', 'edit', method: 'POST', recurrenceId: $createdId, writeEnabled: '1', post: $editPost);
$assert($conflict['status'] === 409 && str_contains($conflict['body'], 'Recarregue a página'), 'Versão antiga deve retornar conflito 409');
$assert((new RecurrenceRepository((new Database($databasePath))->connectReadOnly()))->findById((int) $createdId)['name'] === 'Web editada', 'Conflito deve preservar alteração mais recente');
$external = (new RecurrenceRepository((new Database($databasePath))->connect()))->update((int) $createdId, ['subject' => 'Alteração externa']);
$externalConflictPost = array_replace($editPost, [
    'updated_at' => $hiddenFrom($editedForm['body'], 'updated_at'),
    'version' => $hiddenFrom($editedForm['body'], 'version'),
]);
$externalConflict = $request('recurrence-form', 'edit', method: 'POST', recurrenceId: $createdId, writeEnabled: '1', post: $externalConflictPost);
$assert($externalConflict['status'] === 409 && $external['subject'] === 'Alteração externa', 'Edição externa deve invalidar a versão apresentada');
$assert((new RecurrenceRepository((new Database($databasePath))->connectReadOnly()))->findById((int) $createdId)['subject'] === 'Alteração externa', 'Conflito com CLI deve preservar mudança externa');
$activeWriteForm = $request('recurrence-form', 'edit', recurrenceId: $firstId, writeEnabled: '1');
$assert(str_contains($activeWriteForm['body'], 'Pause esta recorrência') && str_contains($activeWriteForm['body'], '<fieldset class="form-readonly" disabled>'), 'Recorrência ativa deve permanecer somente leitura');
$activePost = $request('recurrence-form', 'edit', method: 'POST', recurrenceId: $firstId, writeEnabled: '1', post: $editPost);
$assert($activePost['status'] === 403 && (new RecurrenceRepository((new Database($databasePath))->connectReadOnly()))->findById((int) $firstId)['name'] === $records[0]['name'], 'POST não pode alterar recorrência ativa');
$viewWrite = $request('recurrence-form', 'view', recurrenceId: $createdId, writeEnabled: '1');
$assert(str_contains($viewWrite['body'], '<fieldset class="form-readonly" disabled>') && !str_contains($viewWrite['body'], 'name="_csrf"'), 'mode=view deve continuar somente leitura');
$assert($request('recurrence-form', 'view', method: 'POST', recurrenceId: $createdId, writeEnabled: '1', post: $editPost)['status'] === 403, 'POST em mode=view deve ser recusado');
$assert(!function_exists('hesk_newTicket'), 'CRUD web não deve carregar HESK nem criar tickets');

// UI-001D: a ação de estado é separada da edição de configuração.
$dueConnection = (new Database($databasePath))->connect();
$dueRepository = new RecurrenceRepository($dueConnection);
$dueRepository->update((int) $secondId, ['next_run_at' => '2020-01-01T00:00:00Z']);
unset($dueRepository, $dueConnection);
$stateRepository = new RecurrenceRepository((new Database($databasePath))->connectReadOnly());
$inactiveBefore = $stateRepository->findById((int) $secondId);
$readOnlyStateList = $request('recurrences');
$assert($stateFieldsFrom($readOnlyStateList['body'], $secondId) === [], 'Sem write gate a lista não deve oferecer POST de ativação');
$stateList = $request('recurrences', writeEnabled: '1');
$activateFields = $stateFieldsFrom($stateList['body'], $secondId);
$pauseFields = $stateFieldsFrom($stateList['body'], $firstId);
$assert(($activateFields['expected_enabled'] ?? null) === '0', 'Lista deve oferecer Ativar para inativa com estado esperado');
$assert(($pauseFields['expected_enabled'] ?? null) === '1', 'Lista deve oferecer Pausar para ativa com estado esperado');
$assert(str_contains($stateList['body'], '>Ativar</button>') && str_contains($stateList['body'], '>Pausar</button>')
    && !str_contains($stateList['body'], 'href="index.php?page=recurrence-state'), 'Ações devem ser formulários POST, nunca links GET');
$assert($activateFields['_csrf'] !== '' && $activateFields['updated_at'] === $inactiveBefore['updated_at']
    && $activateFields['version'] === RecurrenceRepository::versionFingerprint($inactiveBefore), 'Formulário da lista deve incluir CSRF e versão atual');
$getState = $request('recurrence-state', recurrenceId: $secondId, writeEnabled: '1');
$assert($getState['status'] === 405 && $stateRepository->findById((int) $secondId) === $inactiveBefore, 'GET de estado deve retornar 405 sem alterar dados');
$assert($request('recurrence-state', method: 'PUT', recurrenceId: $secondId, writeEnabled: '1')['status'] === 405, 'Método inadequado deve retornar 405');
$assert($request('recurrence-state', enabled: null, method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $activateFields)['status'] === 404, 'Gate geral desligado deve ocultar a rota de estado');
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '0', post: $activateFields)['status'] === 403, 'Write gate desligado deve impedir ativação');
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: array_diff_key($activateFields, ['_csrf' => true]))['status'] === 403, 'Ativação sem CSRF deve retornar 403');
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: array_replace($activateFields, ['_csrf' => str_repeat('0', 64)]))['status'] === 403, 'Ativação com CSRF inválido deve retornar 403');
$assert($request('recurrence-state', method: 'POST', recurrenceId: '999999', writeEnabled: '1', post: $activateFields)['status'] === 404, 'ID inexistente deve retornar 404');
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: array_replace($activateFields, ['version' => 'invalid']))['status'] === 409, 'Versão inválida deve exigir recarga com 409');
$assert($stateRepository->findById((int) $secondId) === $inactiveBefore, 'Requisições recusadas não devem alterar a recorrência');

$inactiveStateForm = $request('recurrence-form', 'edit', recurrenceId: $secondId, writeEnabled: '1');
$assert(str_contains($inactiveStateForm['body'], '>Ativar recorrência</button>')
    && str_contains($inactiveStateForm['body'], '<fieldset class="form-readonly" >'), 'Ficha inativa deve oferecer ativação separada e manter configuração editável');
$invalidActivation = (new \TicketsRecorrentesHesk\Web\AdminUi(
    true,
    writeEnabled: true,
    writer: new AdminRecurrenceWriter($databasePath, new HeskRecurrenceValidator(new FakeWebCatalogProvider(false))),
    authenticator: new FakeWebAuthenticator(),
))->respond('recurrence-state', 'new', 'POST', $secondId, $activateFields);
$assert($invalidActivation['status'] === 422
    && str_contains($invalidActivation['body'], 'solicitante selecionado não está disponível')
    && !str_contains($invalidActivation['body'], 'customer_id'), 'Ativação inválida deve retornar 422 com mensagem segura');
$assert($stateRepository->findById((int) $secondId) === $inactiveBefore, 'Falha referencial na ativação não deve alterar o SQLite');
$activate = (new \TicketsRecorrentesHesk\Web\AdminUi(
    true,
    writeEnabled: true,
    writer: new AdminRecurrenceWriter($databasePath, new HeskRecurrenceValidator(new FakeWebCatalogProvider())),
    authenticator: new FakeWebAuthenticator(),
))->respond(
    'recurrence-state', 'new', 'POST', $secondId, $activateFields
);
$assert($activate['status'] === 303 && ($activate['location'] ?? null) === 'index.php?page=recurrences', 'Ativação pela lista deve usar PRG 303 para lista');
$activeAfter = $stateRepository->findById((int) $secondId);
$activeConfig = $activeAfter;
unset($activeConfig['enabled'], $activeConfig['updated_at']);
$inactiveConfig = $inactiveBefore;
unset($inactiveConfig['enabled'], $inactiveConfig['updated_at']);
$assert($activeAfter['enabled'] === true && $activeAfter['updated_at'] > $inactiveBefore['updated_at'], 'Ativação deve mudar estado e avançar updated_at');
$assert($activeConfig === $inactiveConfig && $activeAfter['next_run_at'] === '2020-01-01T00:00:00Z', 'Ativação vencida deve preservar agenda, configuração e created_at');
$stateListAfter = $request('recurrences', writeEnabled: '1');
$assert(str_contains($stateListAfter['body'], 'Recorrência ativada com sucesso.')
    && ($stateFieldsFrom($stateListAfter['body'], $secondId)['expected_enabled'] ?? null) === '1', 'Lista deve refletir estado ativo e confirmação');
$activeStateForm = $request('recurrence-form', 'edit', recurrenceId: $secondId, writeEnabled: '1');
$assert(str_contains($activeStateForm['body'], '>Pausar recorrência</button>')
    && str_contains($activeStateForm['body'], '<fieldset class="form-readonly" disabled>')
    && str_contains($activeStateForm['body'], 'type="submit" disabled'), 'Ficha ativa deve oferecer pausa e bloquear edição da configuração');
$assert($request('recurrence-form', 'edit', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $editPost)['status'] === 403, 'POST de configuração ativa deve continuar bloqueado');
$staleActivate = $request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $activateFields);
$assert($staleActivate['status'] === 409 && str_contains($staleActivate['body'], 'Recarregue os dados'), 'POST repetido ou versão antiga deve retornar 409');
$assert($stateRepository->findById((int) $secondId) === $activeAfter, 'POST repetido não deve alterar recorrência ativa');

$pauseFromForm = $stateFieldsFrom($activeStateForm['body'], $secondId);
$assert(($pauseFromForm['expected_enabled'] ?? null) === '1' && $pauseFromForm['return_to'] === 'form', 'Ficha deve enviar estado/versão e voltar para a ficha');
$pauseFromForm['_csrf'] = str_repeat('0', 64);
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $pauseFromForm)['status'] === 403, 'Pausa com CSRF inválido deve retornar 403');
$pauseFromForm['_csrf'] = $activateFields['_csrf'];
$pause = (new \TicketsRecorrentesHesk\Web\AdminUi(
    true,
    writeEnabled: true,
    writer: new AdminRecurrenceWriter($databasePath, new HeskRecurrenceValidator(new FailingWebCatalogProvider())),
    authenticator: new FakeWebAuthenticator(),
))->respond(
    'recurrence-state', 'new', 'POST', $secondId, $pauseFromForm
);
$assert($pause['status'] === 303 && ($pause['location'] ?? null) === 'index.php?page=recurrence-form&mode=edit&id=' . $secondId, 'Pausa pela ficha deve usar PRG 303 para ficha');
$assert($pause['status'] === 303, 'Pausa deve permanecer independente dos catálogos HESK indisponíveis');
$inactiveAfter = $stateRepository->findById((int) $secondId);
$pausedConfig = $inactiveAfter;
unset($pausedConfig['enabled'], $pausedConfig['updated_at']);
$assert($inactiveAfter['enabled'] === false && $inactiveAfter['updated_at'] > $activeAfter['updated_at'], 'Pausa deve mudar estado e avançar updated_at');
$assert($pausedConfig === $inactiveConfig && $inactiveAfter['next_run_at'] === '2020-01-01T00:00:00Z', 'Pausa deve preservar agenda e demais campos');
$pausedForm = $request('recurrence-form', 'edit', recurrenceId: $secondId, writeEnabled: '1');
$assert(str_contains($pausedForm['body'], 'Recorrência pausada com sucesso.')
    && str_contains($pausedForm['body'], '>Ativar recorrência</button>')
    && str_contains($pausedForm['body'], '<fieldset class="form-readonly" >'), 'Ficha deve refletir pausa, confirmação e edição liberada');
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $pauseFromForm)['status'] === 409, 'Pausa repetida deve retornar 409');

$currentPaused = $stateRepository->findById((int) $secondId);
$already = (new RecurrenceRepository((new Database($databasePath))->connectExistingWritable()))->transitionEnabledIfCurrent(
    (int) $secondId, true, $currentPaused['updated_at'], RecurrenceRepository::versionFingerprint($currentPaused)
);
$assert($already['status'] === 'already_in_target_state' && $stateRepository->findById((int) $secondId) === $currentPaused, 'Estado esperado incorreto não deve provocar transição');
$beforeConfigEdit = $stateFieldsFrom($request('recurrences', writeEnabled: '1')['body'], $secondId);
(new RecurrenceRepository((new Database($databasePath))->connect()))->update((int) $secondId, ['subject' => 'Configuração alterada por CLI']);
$editedExternally = $stateRepository->findById((int) $secondId);
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $beforeConfigEdit)['status'] === 409, 'Edição externa da configuração deve invalidar ação de estado antiga');
$assert($stateRepository->findById((int) $secondId) === $editedExternally, 'Conflito de configuração deve preservar mudança externa');
$beforeExternal = $stateFieldsFrom($request('recurrences', writeEnabled: '1')['body'], $secondId);
(new RecurrenceRepository((new Database($databasePath))->connect()))->setEnabled((int) $secondId, true);
$changedExternally = $stateRepository->findById((int) $secondId);
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $beforeExternal)['status'] === 409, 'Estado alterado por CLI após GET deve retornar 409');
$assert($stateRepository->findById((int) $secondId) === $changedExternally, 'Conflito externo deve preservar alteração mais recente');
$assert((int) (new Database($databasePath))->connectReadOnly()->query('SELECT count(*) FROM recurrence_executions')->fetchColumn() === 0, 'Ativação e pausa não podem criar execution');
$assert(!function_exists('hesk_newTicket'), 'UI-001D não deve carregar HESK nem criar ticket');

// UI-001G2: a apresentação muda somente pela troca do catálogo injetado.
$dynamicPath = $testDirectory . '/dynamic.sqlite';
$dynamicConnection = (new Database($dynamicPath))->connect();
(new MigrationRunner($dynamicConnection, dirname(__DIR__) . '/database/migrations'))->migrate();
unset($dynamicConnection);
$dynamicData = new HeskReferenceData(
    [88 => ['id' => 88, 'name' => 'Solicitante <Dinâmico>']],
    [77 => ['id' => 77, 'name' => 'CLOUD & Serviços'], 78 => ['id' => 78, 'name' => 'Arquivo']],
    [
        91 => ['id' => 91, 'name' => 'Analista <Cloud>', 'user' => 'cloud', 'active' => true, 'is_admin' => false, 'category_ids' => [77]],
        92 => ['id' => 92, 'name' => 'Administrador geral', 'user' => 'admin', 'active' => true, 'is_admin' => true, 'category_ids' => []],
        93 => ['id' => 93, 'name' => 'Analista de arquivo', 'user' => 'arquivo', 'active' => true, 'is_admin' => false, 'category_ids' => [78]],
        95 => ['id' => 95, 'name' => 'Analista inativo', 'user' => 'inativo', 'active' => false, 'is_admin' => false, 'category_ids' => [77]],
    ],
    [12 => ['id' => 12, 'name' => 'Urgente "Especial"']],
    [14 => ['id' => 14, 'name' => 'Em análise <novo>']],
    [
        'custom31' => ['key' => 'custom31', 'name' => 'UTM > Subcategoria', 'type' => 'select', 'required' => true, 'category_ids' => [77], 'options' => ['Produção & Azul', 'Teste']],
        'custom32' => ['key' => 'custom32', 'name' => 'Modalidade', 'type' => 'radio', 'required' => true, 'category_ids' => [77], 'options' => ['Remoto', 'Presencial']],
        'custom33' => ['key' => 'custom33', 'name' => 'Identificador', 'type' => 'text', 'required' => true, 'category_ids' => [77], 'options' => []],
        'custom34' => ['key' => 'custom34', 'name' => 'Observações', 'type' => 'textarea', 'required' => false, 'category_ids' => [77], 'options' => []],
        'custom35' => ['key' => 'custom35', 'name' => 'Empresa atendida', 'type' => 'select', 'required' => false, 'category_ids' => [77, 78], 'options' => ['Cliente Novo & Cia']],
        'custom37' => ['key' => 'custom37', 'name' => '<script>alert(1)</script>', 'type' => 'text', 'required' => false, 'category_ids' => [77], 'options' => []],
    ],
);
$dynamicCatalog = new FakeWebCatalogProvider(referenceData: $dynamicData);
putenv('APP_DB_PATH=' . $dynamicPath);
$dynamicNew = $request('recurrence-form', writeEnabled: '1', catalogProvider: $dynamicCatalog, categoryId: '77');
$dynamicCsrf = $tokenFrom($dynamicNew['body']);
$assert($dynamicNew['status'] === 200 && $dynamicCsrf !== '', 'Formulário dinâmico deve carregar com CSRF');
$assert(str_contains($dynamicNew['body'], 'Solicitante &lt;Dinâmico&gt;')
    && str_contains($dynamicNew['body'], 'CLOUD &amp; Serviços')
    && str_contains($dynamicNew['body'], 'Urgente &quot;Especial&quot;')
    && str_contains($dynamicNew['body'], 'Em análise &lt;novo&gt;')
    && str_contains($dynamicNew['body'], 'Analista &lt;Cloud&gt;'), 'Solicitante, categoria, prioridade, status e STAFF devem vir do catálogo com escape');
$assert(str_contains($dynamicNew['body'], 'name="custom_fields[custom31]"')
    && str_contains($dynamicNew['body'], 'name="custom_fields[custom32]" type="radio"')
    && str_contains($dynamicNew['body'], 'name="custom_fields[custom33]" type="text"')
    && str_contains($dynamicNew['body'], '<textarea id="field-custom34" name="custom_fields[custom34]"')
    && str_contains($dynamicNew['body'], 'Cliente Novo &amp; Cia'), 'Select, radio, text, textarea e campo global devem ser renderizados pela metadata');
$assert(str_contains($dynamicNew['body'], 'UTM &gt; Subcategoria')
    && !str_contains($dynamicNew['body'], 'UTM &amp;gt; Subcategoria')
    && str_contains($dynamicNew['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
    && !str_contains($dynamicNew['body'], '<script>alert(1)</script>'), 'UI deve escapar labels semânticos uma vez e impedir HTML executável');
$assert(substr_count($dynamicNew['body'], 'data-required="1"') >= 3
    && str_contains($dynamicNew['body'], 'data-category-ids="77,78"'), 'Obrigatoriedade e categorias reais devem chegar à apresentação');
$assert(str_contains($dynamicNew['body'], 'value="93"  disabled hidden data-reference-available="0"')
    && str_contains($dynamicNew['body'], 'Administrador geral'), 'STAFF deve respeitar categoria e administração');
$inactiveStaffView = HeskCatalogViewModel::form($dynamicData, ['category_id' => 77, 'owner_id' => 95]);
$inactiveOwner = array_values(array_filter(
    $inactiveStaffView['owners'],
    static fn (array $option): bool => $option['value'] === '95',
))[0] ?? null;
$assert(is_array($inactiveOwner)
    && $inactiveOwner['active'] === false
    && $inactiveOwner['available'] === false
    && str_contains($inactiveOwner['label'], 'Referência indisponível'), 'STAFF inativo persistido deve ser preservado somente como referência indisponível');

$dynamicPost = [
    '_csrf' => $dynamicCsrf,
    'name' => 'Rotina dinâmica',
    'timezone' => 'UTC',
    'interval_value' => '1',
    'interval_unit' => 'month',
    'next_run_at' => '2028-01-10T10:00',
    'quantity' => '1',
    'customer_id' => '88',
    'category_id' => '77',
    'priority_name' => 'Urgente "Especial"',
    'status_id' => '14',
    'owner_id' => '91',
    'openedby_id' => '92',
    'subject' => 'Assunto dinâmico',
    'message' => 'Mensagem dinâmica',
    'custom_fields' => [
        'custom31' => 'Produção & Azul',
        'custom32' => 'Remoto',
        'custom33' => 'ativo-77',
        'custom34' => "Linha 1\nLinha 2",
        'custom35' => 'Cliente Novo & Cia',
    ],
];
$dynamicInvalid = $request(
    'recurrence-form',
    method: 'POST',
    writeEnabled: '1',
    post: array_replace($dynamicPost, [
        'name' => '<script>alert(1)</script>',
        'quantity' => 'inválida',
        'custom_fields' => array_replace($dynamicPost['custom_fields'], ['custom33' => '<img src=x onerror=alert(1)>']),
    ]),
    catalogProvider: $dynamicCatalog,
);
$assert($dynamicInvalid['status'] === 422
    && str_contains($dynamicInvalid['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
    && str_contains($dynamicInvalid['body'], '&lt;img src=x onerror=alert(1)&gt;')
    && str_contains($dynamicInvalid['body'], 'value="Produção &amp; Azul" selected')
    && str_contains($dynamicInvalid['body'], 'value="Remoto" checked'), 'Erro 422 deve reconstruir o catálogo, preservar valores e escapar todo conteúdo');
$dynamicCreate = $request('recurrence-form', method: 'POST', writeEnabled: '1', post: $dynamicPost, catalogProvider: $dynamicCatalog);
$assert($dynamicCreate['status'] === 303, 'Criação com IDs nunca vistos deve usar o mesmo fluxo sem regra específica');
$dynamicRecord = (new RecurrenceRepository((new Database($dynamicPath))->connectReadOnly()))->findAll()[0];
$assert($dynamicRecord['category_id'] === 77 && $dynamicRecord['customer_id'] === 88
    && $dynamicRecord['owner_id'] === 91 && $dynamicRecord['openedby_id'] === 92
    && $dynamicRecord['custom_fields'] === $dynamicPost['custom_fields'], 'Criação dinâmica deve persistir IDs e custom fields reais sem tradução hardcoded');
$dynamicId = (string) $dynamicRecord['id'];
$dynamicEdit = $request('recurrence-form', 'edit', recurrenceId: $dynamicId, writeEnabled: '1', catalogProvider: $dynamicCatalog);
$assert(str_contains($dynamicEdit['body'], 'value="Produção &amp; Azul" selected')
    && str_contains($dynamicEdit['body'], 'value="Remoto" checked')
    && str_contains($dynamicEdit['body'], 'value="ativo-77"')
    && str_contains($dynamicEdit['body'], "Linha 1\nLinha 2"), 'Edição deve preencher select, radio, text e textarea persistidos');

$changedData = new HeskReferenceData(
    [
        88 => ['id' => 88, 'name' => 'Solicitante renomeado'],
        89 => ['id' => 89, 'name' => 'Novo solicitante'],
    ],
    [77 => ['id' => 77, 'name' => 'CLOUD RENOMEADA'], 78 => ['id' => 78, 'name' => 'Arquivo']],
    [
        91 => ['id' => 91, 'name' => 'Analista renomeado', 'user' => 'cloud', 'active' => true, 'is_admin' => false, 'category_ids' => [77]],
        92 => ['id' => 92, 'name' => 'Administrador geral', 'user' => 'admin', 'active' => true, 'is_admin' => true, 'category_ids' => []],
        94 => ['id' => 94, 'name' => 'Novo STAFF', 'user' => 'novo', 'active' => true, 'is_admin' => false, 'category_ids' => [77]],
    ],
    [
        12 => ['id' => 12, 'name' => 'Urgente "Especial"'],
        15 => ['id' => 15, 'name' => 'Nova prioridade'],
    ],
    [
        14 => ['id' => 14, 'name' => 'Em análise <novo>'],
        16 => ['id' => 16, 'name' => 'Novo status'],
    ],
    [
        'custom31' => ['key' => 'custom31', 'name' => 'Ambiente renomeado', 'type' => 'select', 'required' => true, 'category_ids' => [77], 'options' => ['Nova opção']],
        'custom32' => ['key' => 'custom32', 'name' => 'Modalidade', 'type' => 'radio', 'required' => true, 'category_ids' => [77], 'options' => ['Remoto', 'Presencial']],
        'custom33' => ['key' => 'custom33', 'name' => 'Identificador', 'type' => 'text', 'required' => false, 'category_ids' => [77], 'options' => []],
        'custom34' => ['key' => 'custom34', 'name' => 'Observações', 'type' => 'textarea', 'required' => false, 'category_ids' => [77], 'options' => []],
        'custom35' => ['key' => 'custom35', 'name' => 'Empresa atendida', 'type' => 'select', 'required' => false, 'category_ids' => [77, 78], 'options' => ['Cliente Novo & Cia', 'Outra empresa']],
        'custom36' => ['key' => 'custom36', 'name' => 'Campo recém-criado', 'type' => 'select', 'required' => false, 'category_ids' => [77], 'options' => ['Valor novo']],
    ],
);
$changedCatalog = new FakeWebCatalogProvider(referenceData: $changedData);
$changedForm = $request('recurrence-form', 'edit', recurrenceId: $dynamicId, writeEnabled: '1', catalogProvider: $changedCatalog);
$assert(str_contains($changedForm['body'], 'Solicitante renomeado')
    && str_contains($changedForm['body'], 'Novo solicitante')
    && str_contains($changedForm['body'], 'CLOUD RENOMEADA')
    && str_contains($changedForm['body'], 'Novo STAFF')
    && str_contains($changedForm['body'], 'Nova prioridade')
    && str_contains($changedForm['body'], 'Novo status')
    && str_contains($changedForm['body'], 'Campo recém-criado')
    && str_contains($changedForm['body'], 'Outra empresa'), 'Mudanças exclusivas no catálogo devem aparecer sem alterar a UI');
$assert(str_contains($changedForm['body'], 'value="Produção &amp; Azul" selected data-reference-available="0"')
    && str_contains($changedForm['body'], '>Referência indisponível</option>'), 'Opção removida deve permanecer identificável e inválida na edição');
$changedList = $request('recurrences', catalogProvider: $changedCatalog);
$assert(str_contains($changedList['body'], '<strong>Solicitante:</strong> Solicitante renomeado')
    && str_contains($changedList['body'], '<strong>Categoria:</strong> CLOUD RENOMEADA')
    && str_contains($changedList['body'], '<strong>Responsável:</strong> Analista renomeado'), 'Listagem deve resolver nomes atuais do catálogo sem alterar o modelo SQLite');

$transferredFields = $changedData->customFields();
$transferredFields['custom31']['category_ids'] = [78];
$transferredCatalog = new FakeWebCatalogProvider(referenceData: new HeskReferenceData(
    $changedData->customers(),
    $changedData->categories(),
    $changedData->staff(),
    $changedData->priorities(),
    $changedData->statuses(),
    $transferredFields,
));
$transferredForm = $request('recurrence-form', 'edit', recurrenceId: $dynamicId, writeEnabled: '1', catalogProvider: $transferredCatalog);
$assert(str_contains($transferredForm['body'], 'data-obsolete-field data-field-key="custom31"')
    && str_contains($transferredForm['body'], 'custom31: Produção &amp; Azul'), 'Campo transferido de categoria deve aparecer como referência indisponível sem quebrar a ficha');

$unavailableCatalog = new FakeWebCatalogProvider(referenceData: new HeskReferenceData([], [], [], [], [], []));
$unavailableList = $request('recurrences', catalogProvider: $unavailableCatalog);
$unavailableForm = $request('recurrence-form', 'edit', recurrenceId: $dynamicId, writeEnabled: '1', catalogProvider: $unavailableCatalog);
$assert($unavailableList['status'] === 200 && substr_count($unavailableList['body'], 'Referência indisponível') >= 3,
    'Referências removidas não podem derrubar a listagem');
$assert($unavailableForm['status'] === 200
    && str_contains($unavailableForm['body'], 'value="88" selected data-reference-available="0"')
    && str_contains($unavailableForm['body'], 'value="77" selected data-reference-available="0"')
    && str_contains($unavailableForm['body'], 'data-obsolete-field'), 'Ficha deve preservar referências removidas com indicação segura');

$uiSources = '';
foreach ([
    dirname(__DIR__) . '/src/Web/AdminUi.php',
    dirname(__DIR__) . '/src/Web/HeskCatalogViewModel.php',
    dirname(__DIR__) . '/src/Web/RecurrenceViewModel.php',
    dirname(__DIR__) . '/src/Web/views/pages/recurrence-form.php',
    dirname(__DIR__) . '/public/assets/js/recurrence-form.js',
] as $sourcePath) {
    $uiSources .= (string) file_get_contents($sourcePath);
}
foreach (['WORKSTATION', 'custom9', 'custom10', 'custom15', 'EXAMPLE_COMPANY', 'REQ_Manutenção preventiva'] as $forbiddenHardcode) {
    $assert(!str_contains($uiSources, $forbiddenHardcode), "UI não pode depender do dado observado {$forbiddenHardcode}");
}
$assert(str_contains($uiSources, 'data-active=')
    && str_contains($uiSources, "option.dataset.active === '1'"), 'Filtro progressivo de STAFF deve preservar a restrição de atividade');
putenv('APP_DB_PATH=' . $databasePath);

$legacyConnection = (new Database($databasePath))->connect();
$legacyConnection->exec("UPDATE recurrences SET timezone = 'Invalid/Legacy' WHERE id = {$firstId}");
unset($legacyConnection);
$invalidTimezone = $request('recurrences');
$assert($invalidTimezone['status'] === 200 && str_contains($invalidTimezone['body'], 'Data indisponível'), 'Timezone legada inválida não deve derrubar a lista');
$assert(!str_contains($invalidTimezone['body'], 'Invalid/Legacy'), 'Erro de timezone não deve vazar na listagem');

$missingPath = $testDirectory . '/missing.sqlite';
putenv('APP_DB_PATH=' . $missingPath);
$missingDatabase = $request('recurrences');
$assert($missingDatabase['status'] === 503 && !is_file($missingPath), 'Banco ausente deve falhar sem ser criado');
$assert(!str_contains($missingDatabase['body'], $missingPath), 'Erro de banco ausente não deve expor path');
$missingExecutions = $request('executions');
$assert($missingExecutions['status'] === 503 && !is_file($missingPath), 'Histórico deve retornar 503 sem criar banco ausente');
$assert(!str_contains($missingExecutions['body'], $missingPath), 'Histórico não deve expor o path do banco ausente');
$missingSystem = $request('system');
$assert($missingSystem['status'] === 200
    && str_contains($missingSystem['body'], 'Indisponível')
    && str_contains($missingSystem['body'], 'Verificação indisponível')
    && !is_file($missingPath), 'Sistema deve degradar com segurança sem criar banco ausente');
$assert(!str_contains($missingSystem['body'], $missingPath)
    && !str_contains($missingSystem['body'], 'SQLSTATE'), 'Sistema não deve expor detalhes do banco ausente');
$assert($request('recurrence-form')['status'] === 503, 'Nova ficha também deve verificar o banco');
$missingPost = $request('recurrence-form', method: 'POST', writeEnabled: '1', post: $validPost);
$assert($missingPost['status'] === 503 && !is_file($missingPath), 'POST não deve criar banco ausente');
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $activateFields)['status'] === 503 && !is_file($missingPath), 'Ativação não deve criar banco ausente');
try {
    (new Database($missingPath))->connectExistingWritable();
    $assert(false, 'Conexão writable não pode criar banco ausente');
} catch (RuntimeException) {
    $assert(!is_file($missingPath), 'mode=rw deve manter banco ausente');
}
$corruptPath = $testDirectory . '/corrupt.sqlite';
file_put_contents($corruptPath, 'not a sqlite database');
putenv('APP_DB_PATH=' . $corruptPath);
$corruptDatabase = $request('recurrences');
$assert($corruptDatabase['status'] === 503 && str_contains($corruptDatabase['body'], 'Dados indisponíveis'), 'Banco corrompido deve falhar de modo controlado');
$assert(!str_contains($corruptDatabase['body'], 'not a database'), 'Erro SQL não deve aparecer no HTML');
$corruptExecutions = $request('executions');
$assert($corruptExecutions['status'] === 503 && !str_contains($corruptExecutions['body'], 'not a database'), 'Histórico em banco corrompido deve retornar 503 sem detalhe técnico');
$corruptHash = hash_file('sha256', $corruptPath);
$corruptSystem = $request('system');
$assert($corruptSystem['status'] === 200
    && str_contains($corruptSystem['body'], 'Requerem atenção'), 'Sistema deve sinalizar schema ilegível sem detalhe técnico');
$assert(!str_contains($corruptSystem['body'], 'not a database')
    && hash_file('sha256', $corruptPath) === $corruptHash, 'Sistema não deve vazar erro nem modificar banco corrompido');
$assert($request('recurrence-form', method: 'POST', writeEnabled: '1', post: $validPost)['status'] === 503, 'POST em banco corrompido deve retornar 503');
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $activateFields)['status'] === 503, 'Ativação em banco corrompido deve retornar 503');
$outdatedPath = $testDirectory . '/outdated.sqlite';
$outdatedConnection = (new Database($outdatedPath))->connect();
$partialMigrations = $testDirectory . '/partial-migrations';
mkdir($partialMigrations, 0700);
copy(dirname(__DIR__) . '/database/migrations/001_initial_schema.sql', $partialMigrations . '/001_initial_schema.sql');
(new MigrationRunner($outdatedConnection, $partialMigrations))->migrate();
unset($outdatedConnection);
putenv('APP_DB_PATH=' . $outdatedPath);
$outdatedDatabase = $request('recurrences');
$assert($outdatedDatabase['status'] === 503, 'Migrations pendentes devem bloquear a listagem');
$outdatedExecutions = $request('executions');
$assert($outdatedExecutions['status'] === 503 && str_contains($outdatedExecutions['body'], 'Dados indisponíveis'), 'Migrations pendentes devem bloquear o histórico com 503 seguro');
$outdatedHash = hash_file('sha256', $outdatedPath);
$outdatedSystem = $request('system');
$assert($outdatedSystem['status'] === 200
    && str_contains($outdatedSystem['body'], 'Disponível')
    && str_contains($outdatedSystem['body'], 'Requerem atenção'), 'Sistema deve sinalizar migrations pendentes com segurança');
$assert(!str_contains($outdatedSystem['body'], '002_execution_leases')
    && hash_file('sha256', $outdatedPath) === $outdatedHash, 'Sistema não deve expor migration pendente nem alterar o banco');
$outdatedPost = $request('recurrence-form', method: 'POST', writeEnabled: '1', post: $validPost);
$assert($outdatedPost['status'] === 503, 'POST não deve aplicar migrations pendentes');
$assert($request('recurrence-state', method: 'POST', recurrenceId: $secondId, writeEnabled: '1', post: $activateFields)['status'] === 503, 'Ativação não deve aplicar migrations pendentes');
$outdatedRead = (new Database($outdatedPath))->connectReadOnly();
$assert((int) $outdatedRead->query('SELECT count(*) FROM schema_migrations')->fetchColumn() === 1, 'Web não deve aplicar migrations 002, 003 ou 004');

$divergentPath = $testDirectory . '/divergent.sqlite';
$divergentConnection = (new Database($divergentPath))->connect();
(new MigrationRunner($divergentConnection, dirname(__DIR__) . '/database/migrations'))->migrate();
$divergentConnection->exec("UPDATE schema_migrations SET checksum = 'invalid' WHERE version = '004_execution_error_codes'");
unset($divergentConnection);
$divergentHash = hash_file('sha256', $divergentPath);
putenv('APP_DB_PATH=' . $divergentPath);
$divergentSystem = $request('system');
$assert($divergentSystem['status'] === 200
    && str_contains($divergentSystem['body'], 'Requerem atenção'), 'Sistema deve sinalizar migration incompatível com segurança');
$assert(!str_contains($divergentSystem['body'], '004_execution_error_codes')
    && !str_contains($divergentSystem['body'], 'invalid')
    && hash_file('sha256', $divergentPath) === $divergentHash, 'Sistema não deve expor checksum nem alterar migration incompatível');

putenv('APP_DB_PATH');
putenv('ADMIN_UI_ENABLED');
putenv('ADMIN_UI_WRITE_ENABLED');
session_write_close();
$_SERVER['HTTPS'] = 'on';
session_id('');
(new \TicketsRecorrentesHesk\Web\AdminSession())->token();
$assert(session_get_cookie_params()['secure'] === true, 'Cookie de sessão deve usar Secure sob HTTPS');
session_write_close();
unset($_SERVER['HTTPS']);
@unlink($partialMigrations . '/001_initial_schema.sql');
@rmdir($partialMigrations);
foreach (glob($testDirectory . '/*') ?: [] as $path) {
    @unlink($path);
}
@rmdir($testDirectory);

if ($failures !== []) {
    fwrite(STDERR, "TESTES WEB FALHARAM\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TESTES WEB OK ({$assertions} asserções)\n";
