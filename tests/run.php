<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\CliOptions;
use TicketsRecorrentesHesk\HeskTicketCreator;
use TicketsRecorrentesHesk\OperationalException;

require dirname(__DIR__) . '/src/CliOptions.php';
require dirname(__DIR__) . '/src/OperationalException.php';
require dirname(__DIR__) . '/src/HeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskReferenceData.php';
require dirname(__DIR__) . '/src/HeskReferenceValidationException.php';
require dirname(__DIR__) . '/src/HeskRecurrenceValidator.php';
require dirname(__DIR__) . '/src/NativeHeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskTicketCreator.php';

final class FakeHeskResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public array $rows)
    {
    }
}

function hesk_dbEscape(string $value): string
{
    return $value;
}

function hesk_dbQuery(string $sql): FakeHeskResult
{
    if (str_contains($sql, '`hesk_customers`')) {
        return new FakeHeskResult([[
            'id' => 100,
            'name' => 'Example requester',
            'email' => 'requester@example.test',
            'verified' => '0',
            'verification_token' => null,
        ]]);
    }

    if (str_contains($sql, '`hesk_categories`')) {
        return new FakeHeskResult([['id' => 5, 'name' => 'WORKSTATION']]);
    }

    if (str_contains($sql, '`hesk_permission_group_members`')) {
        return new FakeHeskResult([['user_id' => 4, 'category_id' => 5]]);
    }

    if (str_contains($sql, '`hesk_users`')) {
        return new FakeHeskResult([[
            'id' => 4,
            'name' => 'Example technician',
            'user' => 'example-tech',
            'isadmin' => '0',
            'active' => '1',
            'categories' => '',
        ]]);
    }

    if (str_contains($sql, '`hesk_custom_priorities`')) {
        return new FakeHeskResult([
            ['id' => 0, 'name' => '{"pt":"null"}', 'priority_order' => 0],
            ['id' => 1, 'name' => null, 'priority_order' => 1],
            ['id' => 2, 'name' => '{"en":"Medium"}', 'priority_order' => 2],
            ['id' => 3, 'name' => '{"pt":"Baixa padrão"}', 'priority_order' => 3],
            ['id' => 7, 'name' => '{"pt":"Baixa"}', 'priority_order' => 4],
        ]);
    }

    if (str_contains($sql, '`hesk_custom_statuses`')) {
        return new FakeHeskResult([]);
    }

    if (str_contains($sql, '`hesk_custom_fields`')) {
        return new FakeHeskResult([
            ['id' => 7, 'name' => '{"pt":"Tipo de atendimento"}', 'type' => 'select', 'req' => '2', 'category' => '[5]', 'value' => '{"select_options":["Presencial","Remoto"]}', 'use' => '1', 'place' => '0', 'order' => '1'],
            ['id' => 9, 'name' => '{"pt":"WORKSTATION > Subcategoria"}', 'type' => 'select', 'req' => '2', 'category' => '[5]', 'value' => '{"select_options":["WINDOWS","OFFICE","ANTIVIRUS"]}', 'use' => '1', 'place' => '0', 'order' => '2'],
            ['id' => 10, 'name' => '{"pt":"WORKSTATION > Problema/Requisição"}', 'type' => 'select', 'req' => '2', 'category' => '[5]', 'value' => '{"select_options":["REQ_Manutenção preventiva","REQ_Instalar programa"]}', 'use' => '1', 'place' => '0', 'order' => '3'],
            ['id' => 15, 'name' => '{"pt":"CLIENTE"}', 'type' => 'select', 'req' => '2', 'category' => '[5]', 'value' => '{"select_options":["EXAMPLE_COMPANY","OTHER_EXAMPLE_COMPANY"]}', 'use' => '1', 'place' => '0', 'order' => '4'],
            ['id' => 16, 'name' => '{"pt":"PATRIMONIO"}', 'type' => 'text', 'req' => '0', 'category' => '[5]', 'value' => '[]', 'use' => '1', 'place' => '0', 'order' => '5'],
        ]);
    }

    throw new RuntimeException("SQL inesperado no teste isolado: {$sql}");
}

/** @return array<string, mixed>|false */
function hesk_dbFetchAssoc(FakeHeskResult $result): array|false
{
    return array_shift($result->rows) ?? false;
}

function hesk_mb_strlen(string $value): int
{
    return strlen($value);
}

function hesk_input(string $value): string
{
    return trim($value);
}

function hesk_date(): string
{
    return '02/10/2026 12:00:00';
}

function hesk_createID(): string
{
    return 'ABC-DEF-G123';
}

function hesk_makeURL(string $value): string
{
    return $value;
}

/**
 * @param array<string, mixed> $ticket
 * @return array<string, mixed>
 */
function hesk_newTicket(array $ticket): array
{
    $GLOBALS['new_ticket_calls']++;
    $GLOBALS['created_ticket_data'] = $ticket;

    return [
        'id' => 203,
        'trackid' => $ticket['trackid'],
        'subject' => $ticket['subject'],
        'owner' => $ticket['owner'],
    ];
}

$failures = [];

$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = sprintf('%s: esperado %s, recebido %s', $message, var_export($expected, true), var_export($actual, true));
    }
};

$assertThrows = static function (callable $callback, string $expectedMessage, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = "{$message}: nenhuma exceção foi lançada";
    } catch (InvalidArgumentException $error) {
        if (!str_contains($error->getMessage(), $expectedMessage)) {
            $failures[] = "{$message}: mensagem inesperada: {$error->getMessage()}";
        }
    }
};

$help = CliOptions::parse(['poc-create-ticket.php'], null);
$assertSame('help', $help->mode, 'Sem argumentos deve mostrar ajuda');

$check = CliOptions::parse(
    ['poc-create-ticket.php', '--check', '--hesk-path=/opt/hesk'],
    null
);
$assertSame('check', $check->mode, 'Modo check');
$assertSame('/opt/hesk', $check->heskPath, 'Caminho por argumento');

$execute = CliOptions::parse(['poc-create-ticket.php', '--execute'], '/srv/hesk');
$assertSame('execute', $execute->mode, 'Modo execute');
$assertSame('/srv/hesk', $execute->heskPath, 'Caminho por variável de ambiente');

$assertThrows(
    static fn (): CliOptions => CliOptions::parse(
        ['poc-create-ticket.php', '--check', '--execute', '--hesk-path=/opt/hesk'],
        null
    ),
    'somente um modo',
    'Modos simultâneos devem falhar'
);

$assertThrows(
    static fn (): CliOptions => CliOptions::parse(['poc-create-ticket.php', '--check'], null),
    'Informe a instalação do HESK',
    'Check sem caminho deve falhar'
);

$assertThrows(
    static fn (): CliOptions => CliOptions::parse(
        ['poc-create-ticket.php', '--check', '--hesk-path=/opt/hesk', '--unknown'],
        null
    ),
    'Opção desconhecida',
    'Opção desconhecida deve falhar'
);

$GLOBALS['hesk_settings'] = [
    'hesk_version' => '3.7.12',
    'db_pfix' => 'hesk_',
    'language' => 'pt',
    'customer_accounts' => 0,
    'staff_ticket_formatting' => 0,
];
$GLOBALS['hesklang'] = [
    'critical' => 'Crítica',
    'high' => 'Alta',
    'medium' => 'Média',
    'low' => 'Baixa padrão',
    'open' => 'Novo',
    'wait_reply' => 'Aguardando resposta',
    'replied' => 'Respondido',
    'closed' => 'Resolvido',
    'in_progress' => 'Em andamento',
    'on_hold' => 'Em espera',
    'thist7' => '[%s] Ticket aberto por %s.',
    'thist2' => '[%s] Atribuído a %s por %s.',
];
$GLOBALS['new_ticket_calls'] = 0;
$GLOBALS['created_ticket_data'] = null;

$definition = [
    'customer_id' => 100,
    'customer_name' => 'Example requester',
    'category' => 5,
    'category_name' => 'WORKSTATION',
    'priority_name' => 'Baixa',
    'status' => 0,
    'owner' => 4,
    'owner_name' => 'Example technician',
    'openedby' => 4,
    'openedby_name' => 'Example technician',
    'subject' => '[POC] Manutenção preventiva - WORKSTATION',
    'message' => "Chamado de teste para validação da automação de manutenções preventivas.\nNão executar atendimento.",
    'custom_fields' => [
        'custom7' => 'Presencial',
        'custom9' => 'WINDOWS',
        'custom10' => 'REQ_Manutenção preventiva',
        'custom15' => 'EXAMPLE_COMPANY',
        'custom16' => '',
    ],
];

$creator = new HeskTicketCreator();
$report = $creator->validate($definition);
$assertSame(0, $GLOBALS['new_ticket_calls'], 'Validate não deve criar ticket');
$assertSame(7, $report['priority']['id'], 'Prioridade deve ser resolvida por nome, não por ID fixo');
$assertSame('EXAMPLE_COMPANY', $report['custom_fields']['custom15']['value'], 'Cliente deve ser validado nas opções');

try {
    $creator->validate(array_replace($definition, ['category' => 0, 'owner' => 0]));
    $failures[] = 'Validação de categoria inválida deveria falhar';
} catch (OperationalException $error) {
    $assertSame('HESK_CATEGORY_INVALID', $error->errorCode, 'Primeiro erro conhecido deve definir o código de múltiplas falhas');
    $assertSame(true, str_contains($error->getMessage(), 'Categoria 0 não encontrada.'), 'Detalhe técnico da validação deve permanecer disponível');
    $assertSame(true, str_contains($error->getMessage(), 'Responsável 0'), 'Múltiplos detalhes técnicos devem ser preservados');
}

$created = $creator->create($definition);
$payload = $GLOBALS['created_ticket_data'];
$assertSame(1, $GLOBALS['new_ticket_calls'], 'Execute deve criar exatamente um ticket');
$assertSame(203, $created['id'], 'ID retornado');
$assertSame('ABC-DEF-G123', $created['trackid'], 'Tracking ID deve vir do HESK');
$assertSame(7, $payload['priority'], 'Payload deve usar prioridade resolvida');
$assertSame(4, $payload['owner'], 'Payload deve manter owner');
$assertSame(4, $payload['openedby'], 'Payload deve manter openedby');
$assertSame(4, $payload['assignedby'], 'Payload deve manter assignedby');
$assertSame([], $payload['follower_ids'], 'Payload não deve ter seguidores');
$assertSame('', $payload['attachments'], 'Payload não deve ter anexos');
$assertSame('', $payload['due_date'], 'Payload não deve ter vencimento');
$assertSame('', $payload['custom16'], 'Patrimônio deve permanecer vazio');

$explicit = $creator->create($definition, 'XYZ-123-ABCD');
$assertSame(2, $GLOBALS['new_ticket_calls'], 'Create explícito deve continuar usando hesk_newTicket uma vez');
$assertSame('XYZ-123-ABCD', $explicit['trackid'], 'Create deve preservar o tracking ID explícito');
$assertSame('XYZ-123-ABCD', $GLOBALS['created_ticket_data']['trackid'], 'Payload deve receber o tracking ID explícito');

if ($failures !== []) {
    fwrite(STDERR, "TESTES FALHARAM\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TESTES OK\n";
