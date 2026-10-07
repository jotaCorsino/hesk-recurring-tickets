<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\HeskCatalogProvider;
use TicketsRecorrentesHesk\HeskRecurrenceValidator;
use TicketsRecorrentesHesk\HeskReferenceData;
use TicketsRecorrentesHesk\HeskReferenceValidationException;
use TicketsRecorrentesHesk\HeskTicketCreator;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\NativeHeskCatalogProvider;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\Web\AdminRecurrenceWriter;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/ImmediateTransaction.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceRepository.php';
require dirname(__DIR__) . '/src/OperationalException.php';
require dirname(__DIR__) . '/src/HeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskReferenceData.php';
require dirname(__DIR__) . '/src/HeskReferenceValidationException.php';
require dirname(__DIR__) . '/src/HeskRecurrenceValidator.php';
require dirname(__DIR__) . '/src/NativeHeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskTicketCreator.php';
require dirname(__DIR__) . '/src/Web/AdminRecurrenceWriter.php';

final class MutableCatalogProvider implements HeskCatalogProvider
{
    public int $loads = 0;

    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes = [])
    {
    }

    public function load(): HeskReferenceData
    {
        $this->loads++;
        $base = [
            'customers' => [100 => ['id' => 100, 'name' => 'Example requester']],
            'categories' => [5 => ['id' => 5, 'name' => 'WORKSTATION']],
            'staff' => [
                3 => ['id' => 3, 'name' => 'Autor', 'user' => 'autor', 'active' => true, 'is_admin' => false, 'category_ids' => [5]],
                4 => ['id' => 4, 'name' => 'Responsável', 'user' => 'responsavel', 'active' => true, 'is_admin' => false, 'category_ids' => [5]],
                6 => ['id' => 6, 'name' => 'Inativo', 'user' => 'inativo', 'active' => false, 'is_admin' => false, 'category_ids' => [5]],
                7 => ['id' => 7, 'name' => 'Sem acesso', 'user' => 'semacesso', 'active' => true, 'is_admin' => false, 'category_ids' => [8]],
            ],
            'priorities' => [7 => ['id' => 7, 'name' => 'Baixa']],
            'statuses' => [0 => ['id' => 0, 'name' => 'Novo']],
            'custom_fields' => [
                'custom7' => ['key' => 'custom7', 'name' => 'Atendimento', 'type' => 'radio', 'required' => true, 'category_ids' => [5], 'options' => ['Presencial', 'Remoto']],
                'custom15' => ['key' => 'custom15', 'name' => 'Cliente', 'type' => 'select', 'required' => true, 'category_ids' => [5], 'options' => ['EXAMPLE_COMPANY', 'OTHER_EXAMPLE_COMPANY']],
                'custom20' => ['key' => 'custom20', 'name' => 'Observação', 'type' => 'textarea', 'required' => false, 'category_ids' => [5], 'options' => []],
                'custom30' => ['key' => 'custom30', 'name' => 'Outra categoria', 'type' => 'text', 'required' => false, 'category_ids' => [8], 'options' => []],
                'custom40' => ['key' => 'custom40', 'name' => 'Arquivo', 'type' => 'file', 'required' => false, 'category_ids' => [5], 'options' => []],
            ],
        ];
        $data = array_replace($base, $this->changes);
        return new HeskReferenceData(
            $data['customers'], $data['categories'], $data['staff'],
            $data['priorities'], $data['statuses'], $data['custom_fields'],
        );
    }
}

final class CatalogFakeResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public array $rows, public mixed $terminator)
    {
    }
}

function hesk_dbEscape(string $value): string
{
    return $value;
}

function hesk_dbQuery(string $sql): CatalogFakeResult
{
    $GLOBALS['native_catalog_queries'][] = $sql;
    if (str_contains($sql, '`hesk_customers` AS `customer`')) {
        $rows = array_values(array_filter(
            $GLOBALS['native_catalog_rows']['customers'],
            static fn (array $row): bool => (int) $row['id'] > 0 && (int) ($row['verified'] ?? 0) !== 2,
        ));
        if (str_contains($sql, 'AS `possible_owner`')) {
            $allCustomers = $GLOBALS['native_catalog_rows']['customers'];
            $rows = array_values(array_filter($rows, static function (array $customer) use ($allCustomers): bool {
                if ($customer['email'] === null || $customer['email'] === '') {
                    return true;
                }
                foreach ($allCustomers as $possibleOwner) {
                    if ((int) $possibleOwner['id'] === (int) $customer['id']
                        || strcasecmp((string) $possibleOwner['email'], trim((string) $customer['email'])) !== 0) {
                        continue;
                    }
                    $verified = (int) ($possibleOwner['verified'] ?? 0);
                    if (in_array($verified, [1, 2], true)
                        || ($verified === 0 && $possibleOwner['verification_token'] !== null)) {
                        return false;
                    }
                }
                return true;
            }));
        }
        return new CatalogFakeResult($rows, $GLOBALS['native_catalog_terminator']);
    }
    foreach ($GLOBALS['native_catalog_rows'] as $table => $rows) {
        if (str_contains($sql, "`hesk_{$table}`")) {
            return new CatalogFakeResult($rows, $GLOBALS['native_catalog_terminator']);
        }
    }
    throw new RuntimeException("SQL inesperado na fixture de catálogos: {$sql}");
}

function hesk_dbFetchAssoc(CatalogFakeResult $result): mixed
{
    return $result->rows !== [] ? array_shift($result->rows) : $result->terminator;
}

function hesk_newTicket(array $ticket): array
{
    $GLOBALS['catalog_ticket_calls']++;
    return $ticket;
}

function hesk_mb_strlen(string $value): int
{
    return strlen($value);
}

$failures = [];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$GLOBALS['hesk_settings'] = [
    'db_pfix' => 'hesk_',
    'language' => 'pt',
    'customer_accounts' => 0,
];
$GLOBALS['hesklang'] = [
    'critical' => 'Crítica &amp; urgente',
    'high' => 'Alta',
    'medium' => 'Média',
    'low' => 'Baixa',
    'open' => 'Aberto',
    'wait_reply' => 'Aguardando resposta',
    'replied' => 'Respondido',
    'closed' => 'Resolvido',
    'in_progress' => 'Em andamento',
    'on_hold' => 'Em espera',
];
$GLOBALS['native_catalog_rows'] = [
    'categories' => [
        ['id' => 5, 'name' => 'WORKSTATION &amp; NOC'],
        ['id' => 8, 'name' => 'SERVIDORES'],
    ],
    'customers' => [
        ['id' => 100, 'name' => 'Histórico em conflito', 'email' => 'owner@example.test', 'verified' => '0', 'verification_token' => null],
        ['id' => 22, 'name' => 'Conta pendente', 'email' => 'pending@example.test', 'verified' => '2', 'verification_token' => 'pending'],
        ['id' => 23, 'name' => 'Histórico &amp; isolado', 'email' => 'single@example.test', 'verified' => '0', 'verification_token' => null],
        ['id' => 24, 'name' => 'Proprietário verificado', 'email' => 'OWNER@example.test', 'verified' => '1', 'verification_token' => null],
        ['id' => 25, 'name' => 'Proprietário por token', 'email' => 'token@example.test', 'verified' => '0', 'verification_token' => 'token'],
        ['id' => 26, 'name' => 'Histórico do token', 'email' => 'token@example.test', 'verified' => '0', 'verification_token' => null],
        ['id' => 27, 'name' => 'Owner duplicado A', 'email' => 'duplicate@example.test', 'verified' => '1', 'verification_token' => null],
        ['id' => 28, 'name' => 'Owner duplicado B', 'email' => 'DUPLICATE@example.test', 'verified' => '0', 'verification_token' => 'token-2'],
        ['id' => 29, 'name' => 'Histórico com conta pendente', 'email' => 'pending@example.test', 'verified' => '0', 'verification_token' => null],
        ['id' => 30, 'name' => 'Histórico com espaços', 'email' => '  spaced@example.test  ', 'verified' => '0', 'verification_token' => null],
        ['id' => 31, 'name' => 'Owner sem espaços', 'email' => 'spaced@example.test', 'verified' => '1', 'verification_token' => null],
        ['id' => 32, 'name' => 'Histórico sem e-mail', 'email' => null, 'verified' => '0', 'verification_token' => null],
        ['id' => 33, 'name' => 'Owner com e-mail vazio', 'email' => '', 'verified' => '1', 'verification_token' => null],
        ['id' => 34, 'name' => 'Histórico com e-mail vazio', 'email' => '', 'verified' => '0', 'verification_token' => null],
    ],
    'permission_group_members' => [
        ['user_id' => 3, 'category_id' => 5],
    ],
    'users' => [
        ['id' => 1, 'name' => 'Administrador', 'user' => 'admin', 'active' => '1', 'isadmin' => '1', 'categories' => ''],
        ['id' => 3, 'name' => 'Por grupo', 'user' => 'grupo', 'active' => '1', 'isadmin' => '0', 'categories' => ''],
        ['id' => 4, 'name' => 'Direto &quot;N1&quot;', 'user' => 'direto', 'active' => '1', 'isadmin' => '0', 'categories' => '5,8'],
        ['id' => 6, 'name' => 'Inativo', 'user' => 'inativo', 'active' => '0', 'isadmin' => '0', 'categories' => '5'],
    ],
    'custom_priorities' => [
        ['id' => 0, 'name' => '{"pt":"null","en":"Critical"}', 'priority_order' => '0'],
        ['id' => 1, 'name' => null, 'priority_order' => '1'],
        ['id' => 2, 'name' => '{"en":"Medium"}', 'priority_order' => '2'],
        ['id' => 3, 'name' => '{"pt":"Baixa"}', 'priority_order' => '3'],
        ['id' => 7, 'name' => '{"en":"Custom fallback","pt":"Personalizada &gt; N1"}', 'priority_order' => '4'],
    ],
    'custom_statuses' => [
        ['id' => 6, 'name' => '{"pt":"Aguardando &amp; fornecedor","en":"Waiting vendor"}'],
    ],
    'custom_fields' => [
        ['id' => 7, 'name' => '{"pt":"UTM &gt; Subcategoria"}', 'type' => 'radio', 'req' => '2', 'category' => '[]', 'value' => '{"radio_options":["Presencial","Remoto"]}', 'use' => '1', 'place' => '0', 'order' => '1'],
        ['id' => 15, 'name' => '{"en":"Customer","pt":"Cliente"}', 'type' => 'select', 'req' => '2', 'category' => '["5"]', 'value' => '{"select_options":["EXAMPLE_COMPANY","OTHER_EXAMPLE_COMPANY"]}', 'use' => '2', 'place' => '0', 'order' => '2'],
        ['id' => 20, 'name' => '{"en":"Note &amp; &quot;quoted&quot; &#039;apostrophe&#039;"}', 'type' => 'textarea', 'req' => '0', 'category' => '[8]', 'value' => '[]', 'use' => '1', 'place' => '0', 'order' => '3'],
        ['id' => 40, 'name' => '{"pt":"&lt;script&gt;alert(1)&lt;/script&gt;"}', 'type' => 'file', 'req' => '0', 'category' => '[5]', 'value' => '[]', 'use' => '1', 'place' => '0', 'order' => '4'],
    ],
];
$GLOBALS['native_catalog_queries'] = [];
$GLOBALS['native_catalog_terminator'] = null;
$settingsBeforeCatalogLoad = $GLOBALS['hesk_settings'];

$assert(!function_exists('hesk_get_selectable_customer_by_id'), 'Fixture não deve oferecer helper de solicitante ausente no bootstrap administrativo');
$assert(!function_exists('hesk_is_valid_priority_id'), 'Fixture não deve oferecer helper nativo de prioridade');
$assert(!function_exists('hesk_is_custom_field_in_category'), 'Fixture não deve oferecer helper nativo de aplicabilidade');

$nativeCatalog = (new NativeHeskCatalogProvider())->load();
$assert(count($nativeCatalog->categories()) === 2, 'Provedor deve encerrar normalmente quando hesk_dbFetchAssoc retorna null');
$assert($GLOBALS['hesk_settings'] === $settingsBeforeCatalogLoad, 'Provedor não deve modificar hesk_settings');
$assert(isset($nativeCatalog->customers()[100], $nativeCatalog->customers()[23], $nativeCatalog->customers()[24], $nativeCatalog->customers()[25], $nativeCatalog->customers()[26], $nativeCatalog->customers()[27], $nativeCatalog->customers()[28], $nativeCatalog->customers()[29], $nativeCatalog->customers()[30], $nativeCatalog->customers()[31], $nativeCatalog->customers()[32], $nativeCatalog->customers()[33], $nativeCatalog->customers()[34]), 'Sem contas, históricos e owners duplicados devem permanecer disponíveis');
$assert(!isset($nativeCatalog->customers()[22]), 'Conta com verified=2 não deve ser selecionável');
$assert(array_keys($nativeCatalog->customers()[100]) === ['id', 'name'], 'Catálogo público de solicitantes não deve expor e-mail ou estado de verificação');
$assert($nativeCatalog->categories()[5]['name'] === 'WORKSTATION & NOC' && $nativeCatalog->categories()[8]['name'] === 'SERVIDORES', 'Categorias devem vir da tabela nativa em ordem determinística e com labels semânticos');
$assert($nativeCatalog->customers()[23]['name'] === 'Histórico & isolado'
    && $nativeCatalog->staff()[4]['name'] === 'Direto "N1"', 'Solicitantes e STAFF devem normalizar entidades HTML nos nomes');
$assert($nativeCatalog->staff()[4]['category_ids'] === [5, 8], 'STAFF deve preservar categorias diretas');
$assert($nativeCatalog->staff()[3]['category_ids'] === [5], 'STAFF deve herdar categorias de grupos');
$assert($nativeCatalog->staff()[1]['is_admin'] === true, 'Administrador deve ser identificado para acesso a qualquer categoria');
$assert($nativeCatalog->staff()[6]['active'] === false, 'STAFF inativo deve permanecer inelegível');
$assert($nativeCatalog->priorities()[0]['name'] === 'Crítica & urgente' && $nativeCatalog->priorities()[7]['name'] === 'Personalizada > N1', 'Prioridades devem combinar rótulo padrão e tradução customizada normalizados');
$assert($nativeCatalog->priorities()[1]['name'] === 'Alta' && $nativeCatalog->priorities()[3]['name'] === 'Baixa', 'Prioridades padrão ausentes da tabela devem receber tradução nativa');
$assert($nativeCatalog->statuses()[0]['name'] === 'Aberto' && $nativeCatalog->statuses()[6]['name'] === 'Aguardando & fornecedor', 'Status padrão e personalizado devem compor o catálogo com labels semânticos');
$assert($nativeCatalog->customFields()['custom7']['category_ids'] === [5, 8], 'Campo global deve valer para todas as categorias existentes');
$assert($nativeCatalog->customFields()['custom7']['required'] === true && $nativeCatalog->customFields()['custom7']['options'] === ['Presencial', 'Remoto'], 'Radio obrigatório deve preservar opções');
$assert($nativeCatalog->customFields()['custom7']['name'] === 'UTM > Subcategoria', 'Nome de custom field deve ser texto semântico sem entidades HTML');
$assert($nativeCatalog->customFields()['custom15']['category_ids'] === [5] && $nativeCatalog->customFields()['custom15']['options'][0] === 'EXAMPLE_COMPANY', 'Select limitado deve preservar categoria e opções');
$assert($nativeCatalog->customFields()['custom20']['name'] === 'Note & "quoted" \'apostrophe\'', 'Entidades de ampersand e aspas devem ser decodificadas nos labels');
$assert($nativeCatalog->customFields()['custom40']['name'] === '<script>alert(1)</script>', 'Entidades potencialmente perigosas devem ser normalizadas como texto semântico interno');
$assert($nativeCatalog->customFields()['custom40']['type'] === 'file', 'Tipo não suportado deve permanecer identificável para rejeição pelo validador');
$assert(count($GLOBALS['native_catalog_queries']) === 7, 'Provedor deve reconstruir catálogos com sete consultas controladas');
$assert(array_reduce(
    $GLOBALS['native_catalog_queries'],
    static fn (bool $onlySelect, string $sql): bool => $onlySelect && preg_match('/^\s*SELECT\b/i', $sql) === 1,
    true,
), 'Todas as consultas do provedor devem ser somente SELECT');
$assert((bool) array_filter($GLOBALS['native_catalog_queries'], static fn (string $sql): bool => str_contains($sql, 'ORDER BY `priority_order`, `id`')), 'Prioridades customizadas devem respeitar priority_order');
$assert((bool) array_filter($GLOBALS['native_catalog_queries'], static fn (string $sql): bool => str_contains($sql, 'ORDER BY `place`, `order`, `id`')), 'Campos personalizados devem respeitar place e order');

$GLOBALS['hesk_settings']['customer_accounts'] = 1;
$accountCatalog = (new NativeHeskCatalogProvider())->load();
$assert(!isset($accountCatalog->customers()[100], $accountCatalog->customers()[26], $accountCatalog->customers()[29]), 'Com contas, históricos conflitantes devem ser removidos, inclusive quando o owner tem verified=2');
$assert(isset($accountCatalog->customers()[23], $accountCatalog->customers()[24], $accountCatalog->customers()[25]), 'Com contas, histórico isolado e proprietários possíveis devem permanecer selecionáveis');
$assert(!isset($accountCatalog->customers()[27], $accountCatalog->customers()[28]), 'Dois possible owners com o mesmo e-mail e IDs distintos devem ser ambos excluídos');
$assert(!isset($accountCatalog->customers()[30]) && isset($accountCatalog->customers()[31]), 'TRIM no e-mail histórico deve encontrar o owner equivalente sem espaços');
$assert(isset($accountCatalog->customers()[32], $accountCatalog->customers()[33]), 'Customer com e-mail NULL deve permanecer selecionável mesmo com possible owner de e-mail vazio');
$assert(isset($accountCatalog->customers()[34], $accountCatalog->customers()[33]), 'Customer com e-mail vazio deve permanecer selecionável mesmo com outro possible owner vazio');
$customerQueries = array_values(array_filter(
    $GLOBALS['native_catalog_queries'],
    static fn (string $sql): bool => str_contains($sql, '`hesk_customers` AS `customer`'),
));
$assert(count($customerQueries) === 2 && !str_contains($customerQueries[0], 'AS `possible_owner`') && str_contains($customerQueries[1], 'AS `possible_owner`'), 'Filtro de ownership deve ser aplicado apenas quando customer_accounts está habilitado');
$assert(str_contains($customerQueries[1], '`possible_owner`.`id` <> `customer`.`id`')
    && str_contains($customerQueries[1], '`possible_owner`.`email` = TRIM(`customer`.`email`)')
    && str_contains($customerQueries[1], '`possible_owner`.`verified` IN (1, 2)')
    && str_contains($customerQueries[1], '`possible_owner`.`verification_token` IS NOT NULL'), 'SELECT deve reproduzir a identificação nativa de outro possible owner');
$assert(str_contains($customerQueries[1], '`customer`.`email` IS NULL'), 'SELECT deve preservar a exceção nativa para e-mail NULL');
$assert(str_contains($customerQueries[1], "`customer`.`email` = ''"), 'SELECT deve preservar a exceção nativa para e-mail vazio');

$GLOBALS['native_catalog_terminator'] = false;
$falseTerminatedCatalog = (new NativeHeskCatalogProvider())->load();
$assert(count($falseTerminatedCatalog->categories()) === 2, 'Provedor deve preservar compatibilidade com término por false');

$GLOBALS['native_catalog_terminator'] = 'fim-inválido';
try {
    (new NativeHeskCatalogProvider())->load();
    $assert(false, 'Retorno inesperado do fetch deve causar falha controlada');
} catch (RuntimeException $error) {
    $assert($error->getMessage() === 'O catálogo HESK retornou uma linha inválida.', 'Retorno não-array diferente de null/false deve continuar inválido');
}
$GLOBALS['native_catalog_terminator'] = null;

$rowsBeforeDynamicChange = $GLOBALS['native_catalog_rows'];
$GLOBALS['native_catalog_rows']['categories'][0]['name'] = 'ESTAÇÕES RENOMEADA';
$GLOBALS['native_catalog_rows']['categories'][] = ['id' => 77, 'name' => 'CLOUD DINÂMICA'];
$GLOBALS['native_catalog_rows']['customers'][] = [
    'id' => 88, 'name' => 'Novo solicitante dinâmico', 'email' => null,
    'verified' => '0', 'verification_token' => null,
];
$GLOBALS['native_catalog_rows']['users'][] = [
    'id' => 12, 'name' => 'Novo STAFF dinâmico', 'user' => 'staff12',
    'active' => '1', 'isadmin' => '0', 'categories' => '77',
];
$GLOBALS['native_catalog_rows']['custom_priorities'][] = [
    'id' => 12, 'name' => '{"pt":"Nova prioridade dinâmica"}', 'priority_order' => '9',
];
$GLOBALS['native_catalog_rows']['custom_statuses'][] = [
    'id' => 12, 'name' => '{"pt":"Novo status dinâmico"}',
];
$GLOBALS['native_catalog_rows']['custom_fields'][] = [
    'id' => 31, 'name' => '{"pt":"Ambiente dinâmico"}', 'type' => 'select', 'req' => '2',
    'category' => '[77]', 'value' => '{"select_options":["Produção","Teste"]}',
    'use' => '1', 'place' => '0', 'order' => '5',
];
$freshDynamicCatalog = (new NativeHeskCatalogProvider())->load();
$assert($freshDynamicCatalog->categories()[5]['name'] === 'ESTAÇÕES RENOMEADA'
    && $freshDynamicCatalog->categories()[77]['name'] === 'CLOUD DINÂMICA', 'Nova requisição deve refletir categoria adicionada ou renomeada');
$assert($freshDynamicCatalog->customers()[88]['name'] === 'Novo solicitante dinâmico'
    && $freshDynamicCatalog->staff()[12]['category_ids'] === [77], 'Nova requisição deve refletir solicitante e STAFF adicionados');
$assert($freshDynamicCatalog->priorities()[12]['name'] === 'Nova prioridade dinâmica'
    && $freshDynamicCatalog->statuses()[12]['name'] === 'Novo status dinâmico', 'Nova requisição deve refletir prioridade e status adicionados');
$assert($freshDynamicCatalog->customFields()['custom31']['category_ids'] === [77]
    && $freshDynamicCatalog->customFields()['custom31']['options'] === ['Produção', 'Teste'], 'Novo custom field suportado deve aparecer somente pela mudança dos dados HESK');
$assert(!isset($nativeCatalog->categories()[77], $nativeCatalog->customers()[88], $nativeCatalog->customFields()['custom31']), 'Cache do catálogo deve permanecer limitado à instância da requisição');
$GLOBALS['native_catalog_rows'] = $rowsBeforeDynamicChange;

$providerSource = file_get_contents(dirname(__DIR__) . '/src/NativeHeskCatalogProvider.php');
$assert(is_string($providerSource), 'Fonte do provedor deve estar disponível para proteção estrutural');
foreach (['customer_accounts.inc.php', 'priorities.inc.php', 'statuses.inc.php', 'custom_fields.inc.php', 'email_functions.inc.php', 'posting_functions.inc.php'] as $forbiddenInclude) {
    $assert(!str_contains((string) $providerSource, $forbiddenInclude), "Provedor não deve carregar {$forbiddenInclude}");
}
foreach (['mkdir(', 'file_put_contents(', 'unlink(', 'rename(', 'touch('] as $forbiddenWrite) {
    $assert(!str_contains((string) $providerSource, $forbiddenWrite), "Provedor não deve executar {$forbiddenWrite}");
}

$originalPriorityName = $GLOBALS['native_catalog_rows']['custom_priorities'][4]['name'];
$GLOBALS['native_catalog_rows']['custom_priorities'][4]['name'] = '{invalid';
try {
    (new NativeHeskCatalogProvider())->load();
    $assert(false, 'JSON inválido de catálogo deve causar falha controlada');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'JSON de nome de prioridade 7 é inválido'), 'Falha de JSON deve identificar o contexto sem expor o valor bruto');
    $assert(!str_contains($error->getMessage(), '{invalid'), 'Falha de JSON não deve expor o conteúdo bruto');
}
$GLOBALS['native_catalog_rows']['custom_priorities'][4]['name'] = $originalPriorityName;

$originalFieldName = $GLOBALS['native_catalog_rows']['custom_fields'][0]['name'];
$GLOBALS['native_catalog_rows']['custom_fields'][0]['name'] = '{"pt":"null"}';
try {
    (new NativeHeskCatalogProvider())->load();
    $assert(false, 'Nome sem tradução utilizável deve causar falha controlada');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'não contém uma tradução utilizável'), 'Falha deve explicar ausência de tradução utilizável');
}
$GLOBALS['native_catalog_rows']['custom_fields'][0]['name'] = $originalFieldName;

$originalFieldCategory = $GLOBALS['native_catalog_rows']['custom_fields'][0]['category'];
$categoryCases = [
    ['raw' => null, 'expected' => [5, 8], 'label' => 'NULL'],
    ['raw' => '', 'expected' => [5, 8], 'label' => 'string vazia'],
    ['raw' => '[]', 'expected' => [5, 8], 'label' => 'lista JSON vazia'],
    ['raw' => '[5]', 'expected' => [5], 'label' => 'lista JSON específica'],
];
foreach ($categoryCases as $case) {
    $GLOBALS['native_catalog_rows']['custom_fields'][0]['category'] = $case['raw'];
    $caseCatalog = (new NativeHeskCatalogProvider())->load();
    $assert(
        $caseCatalog->customFields()['custom7']['category_ids'] === $case['expected'],
        "Categoria {$case['label']} deve reproduzir a aplicabilidade nativa",
    );
}

$GLOBALS['native_catalog_rows']['custom_fields'][0]['category'] = '{invalid';
try {
    (new NativeHeskCatalogProvider())->load();
    $assert(false, 'JSON inválido de categorias deve causar falha controlada');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'JSON de categorias de custom7 é inválido'), 'Falha deve identificar JSON inválido de categorias sem expor o conteúdo bruto');
}

$GLOBALS['native_catalog_rows']['custom_fields'][0]['category'] = '{"categoria":5}';
try {
    (new NativeHeskCatalogProvider())->load();
    $assert(false, 'Categorias em formato inesperado devem causar falha controlada');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'categorias de custom7 estão em formato inesperado'), 'Falha deve identificar categorias em formato inesperado');
}
$GLOBALS['native_catalog_rows']['custom_fields'][0]['category'] = $originalFieldCategory;

$originalFieldValue = $GLOBALS['native_catalog_rows']['custom_fields'][0]['value'];
$GLOBALS['native_catalog_rows']['custom_fields'][0]['value'] = '"texto"';
try {
    (new NativeHeskCatalogProvider())->load();
    $assert(false, 'Valor JSON em formato inesperado deve causar falha controlada');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'JSON de valor de custom7 está em formato inesperado'), 'Falha deve identificar valor em formato inesperado');
}
$GLOBALS['native_catalog_rows']['custom_fields'][0]['value'] = $originalFieldValue;
$GLOBALS['hesk_settings']['customer_accounts'] = 0;

$base = [
    'customer_id' => 100, 'category_id' => 5, 'priority_name' => 'Baixa', 'status_id' => 0,
    'owner_id' => 4, 'openedby_id' => 3,
    'custom_fields' => ['custom7' => ' presencial ', 'custom15' => 'example_company', 'custom20' => 'Texto'],
];

$validateFailure = static function (array $changes, array $catalogChanges = []) use ($base, $assert): HeskReferenceValidationException {
    try {
        (new HeskRecurrenceValidator(new MutableCatalogProvider($catalogChanges)))->validate(array_replace($base, $changes));
    } catch (HeskReferenceValidationException $error) {
        $assert($error->safeErrors !== [], 'Falha referencial deve oferecer mensagem segura');
        return $error;
    }
    throw new RuntimeException('A validação deveria ter falhado.');
};

$provider = new MutableCatalogProvider();
$catalog = $provider->load();
$assert(count($catalog->customers()) === 1 && count($catalog->categories()) === 1, 'Catálogos devem expor solicitantes e categorias');
$assert(count($catalog->staff()) === 4 && $catalog->staff()[6]['active'] === false, 'Catálogo STAFF deve preservar estado ativo');
$assert(count($catalog->priorities()) === 1 && count($catalog->statuses()) === 1, 'Catálogos devem expor prioridades e status');
$assert(count($catalog->customFields()) === 5, 'Catálogo deve expor metadados de campos ativos');

$validator = new HeskRecurrenceValidator($provider);
$valid = $validator->validate($base);
$assert($valid['customer']['id'] === 100 && $valid['category']['id'] === 5, 'Referências válidas devem ser resolvidas');
$assert($valid['owner']['id'] === 4 && $valid['openedby']['id'] === 3, 'STAFF ativo com acesso deve ser aceito');
$assert($valid['priority']['id'] === 7 && $valid['status']['id'] === 0, 'Prioridade por nome e status por ID devem ser resolvidos');
$assert($valid['custom_fields']['custom7']['value'] === 'Presencial'
    && $valid['custom_fields']['custom15']['value'] === 'EXAMPLE_COMPANY', 'Radio/select devem retornar opção canônica');

$assert($validateFailure([], ['customers' => []])->errorCode === 'HESK_CUSTOMER_INVALID', 'Solicitante inválido deve ser recusado');
$assert($validateFailure([], ['categories' => []])->errorCode === 'HESK_CATEGORY_INVALID', 'Categoria inválida deve ser recusada');
$assert($validateFailure(['owner_id' => 6])->errorCode === 'HESK_OWNER_INVALID', 'STAFF inativo deve ser recusado');
$assert($validateFailure(['owner_id' => 7])->errorCode === 'HESK_OWNER_INVALID', 'STAFF sem acesso à categoria deve ser recusado');
$assert($validateFailure(['priority_name' => 'Urgente'])->errorCode === 'HESK_PRIORITY_INVALID', 'Prioridade inválida deve ser recusada');
$assert($validateFailure(['status_id' => 99])->errorCode === 'HESK_STATUS_INVALID', 'Status inválido deve ser recusado');
$assert($validateFailure(['custom_fields' => $base['custom_fields'] + ['custom9' => 'x']])->errorCode === 'HESK_CUSTOM_FIELDS_INVALID', 'Campo inativo/ausente deve ser recusado');
$assert($validateFailure(['custom_fields' => $base['custom_fields'] + ['custom30' => 'x']])->errorCode === 'HESK_CUSTOM_FIELDS_INVALID', 'Campo fora da categoria deve ser recusado');
$assert($validateFailure(['custom_fields' => array_replace($base['custom_fields'], ['custom7' => ''])])->errorCode === 'HESK_CUSTOM_FIELDS_INVALID', 'Campo obrigatório vazio deve ser recusado');
$missingRequired = $base['custom_fields'];
unset($missingRequired['custom15']);
$assert($validateFailure(['custom_fields' => $missingRequired])->errorCode === 'HESK_CUSTOM_FIELDS_INVALID', 'Campo obrigatório ausente deve ser recusado');
$assert($validateFailure(['custom_fields' => array_replace($base['custom_fields'], ['custom7' => 'Telefone'])])->errorCode === 'HESK_CUSTOM_FIELDS_INVALID', 'Radio inválido deve ser recusado');
$assert($validateFailure(['custom_fields' => array_replace($base['custom_fields'], ['custom15' => 'OUTRA'])])->errorCode === 'HESK_CUSTOM_FIELDS_INVALID', 'Select inválido deve ser recusado');
$assert($validateFailure(['custom_fields' => $base['custom_fields'] + ['custom40' => 'arquivo.pdf']])->errorCode === 'HESK_CUSTOM_FIELDS_INVALID', 'Tipo não suportado não deve ser aceito silenciosamente');

$GLOBALS['hesk_settings'] = ['hesk_version' => '3.7.12', 'staff_ticket_formatting' => 0];
$GLOBALS['catalog_ticket_calls'] = 0;
$ticketCreator = new HeskTicketCreator($validator);
$ticketReport = $ticketCreator->validate([
    'customer_id' => 100, 'category' => 5, 'priority_name' => 'Baixa', 'status' => 0,
    'owner' => 4, 'openedby' => 3, 'subject' => 'Teste', 'message' => 'Mensagem',
    'custom_fields' => $base['custom_fields'],
]);
$assert($ticketReport['priority']['id'] === 7, 'HeskTicketCreator deve reutilizar o validador referencial');
$assert($GLOBALS['catalog_ticket_calls'] === 0, 'Validação de catálogos nunca deve criar ticket');

$temporaryDirectory = sys_get_temp_dir() . '/tickets-catalogs-' . bin2hex(random_bytes(8));
mkdir($temporaryDirectory, 0700);
$databasePath = $temporaryDirectory . '/catalogs.sqlite';
$connection = (new Database($databasePath))->connect();
(new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations'))->migrate();
$repository = new RecurrenceRepository($connection);
$recurrence = $repository->create([
    'name' => 'Catálogos', 'enabled' => false, 'timezone' => 'UTC', 'interval_value' => 1,
    'interval_unit' => 'month', 'next_run_at' => '2027-01-01T00:00:00Z', 'quantity' => 1,
] + $base + ['subject' => 'Teste', 'message' => 'Mensagem', 'notify_customer' => false]);

$post = [
    'name' => 'Catálogos', 'timezone' => 'UTC', 'interval_value' => '1', 'interval_unit' => 'month',
    'next_run_at' => '2027-01-01T00:00', 'quantity' => '1', 'customer_id' => '100', 'category_id' => '5',
    'priority_name' => 'Baixa', 'status_id' => '0', 'owner_id' => '4', 'openedby_id' => '3',
    'subject' => 'Teste', 'message' => 'Mensagem', 'custom_fields' => $base['custom_fields'],
];

$invalidProvider = new MutableCatalogProvider(['customers' => []]);
$writer = new AdminRecurrenceWriter($databasePath, new HeskRecurrenceValidator($invalidProvider));
$beforeCount = count($repository->findAll());
$createResult = $writer->submit($post, null);
$assert($createResult['status'] === 'invalid' && count($repository->findAll()) === $beforeCount, 'Criação inválida deve ser recusada antes do SQLite');
$assert(!str_contains(implode(' ', $createResult['errors']), 'customer_id'), 'HTML deve receber somente erro referencial seguro');

$current = $repository->findById((int) $recurrence['id']);
$editPost = $post + [
    'updated_at' => $current['updated_at'],
    'version' => RecurrenceRepository::versionFingerprint($current),
];
$editResult = $writer->submit($editPost, (int) $recurrence['id']);
$assert($editResult['status'] === 'invalid' && $repository->findById((int) $recurrence['id']) === $current, 'Edição inválida deve ser recusada antes do SQLite');

$invalidProvider->loads = 0;
$staleEdit = array_replace($editPost, ['version' => str_repeat('0', 64)]);
$staleResult = $writer->submit($staleEdit, (int) $recurrence['id']);
$assert($staleResult['status'] === 'conflict' && $invalidProvider->loads === 0, 'Concorrência deve ser verificada antes do HESK na edição');

$invalidProvider->loads = 0;
$staleActivation = $writer->transitionEnabled(
    (int) $recurrence['id'], false, $current['updated_at'], str_repeat('0', 64)
);
$assert($staleActivation['status'] === 'conflict' && $invalidProvider->loads === 0, 'Concorrência deve ser verificada antes do HESK na ativação');

$activation = $writer->transitionEnabled(
    (int) $recurrence['id'], false, $current['updated_at'], RecurrenceRepository::versionFingerprint($current)
);
$assert($activation['status'] === 'invalid' && $repository->findById((int) $recurrence['id'])['enabled'] === false, 'Ativação deve revalidar e recusar referência que ficou inválida');
$assert($invalidProvider->loads === 1, 'Ativação atual deve consultar o catálogo uma vez');

$repository->setEnabled((int) $recurrence['id'], true);
$active = $repository->findById((int) $recurrence['id']);
$invalidProvider->loads = 0;
$pause = $writer->transitionEnabled(
    (int) $recurrence['id'], true, $active['updated_at'], RecurrenceRepository::versionFingerprint($active)
);
$assert($pause['status'] === 'updated' && $repository->findById((int) $recurrence['id'])['enabled'] === false, 'Pausa deve funcionar com referências HESK inválidas');
$assert($invalidProvider->loads === 0, 'Pausa não deve consultar catálogos HESK');
$assert($GLOBALS['catalog_ticket_calls'] === 0, 'CRUD e transições nunca devem criar ticket HESK');

unset($repository, $connection);
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($temporaryDirectory);

if ($failures !== []) {
    fwrite(STDERR, "TESTES DE CATÁLOGOS FALHARAM\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TESTES DE CATÁLOGOS OK ({$assertions} asserções)\n";
