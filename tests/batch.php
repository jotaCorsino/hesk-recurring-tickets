<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\BatchProcessor;
use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\ExecutionCliOptions;
use TicketsRecorrentesHesk\ExecutionItemRepository;
use TicketsRecorrentesHesk\ExecutionLeaseService;
use TicketsRecorrentesHesk\HeskTicketCreator;
use TicketsRecorrentesHesk\HeskTicketGateway;
use TicketsRecorrentesHesk\OperationalException;
use TicketsRecorrentesHesk\ImmediateTransaction;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\TicketGateway;
use TicketsRecorrentesHesk\WorkerCliOptions;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceRepository.php';
require dirname(__DIR__) . '/src/RecurrenceExecutionRepository.php';
require dirname(__DIR__) . '/src/ExecutionItemRepository.php';
require dirname(__DIR__) . '/src/ImmediateTransaction.php';
require dirname(__DIR__) . '/src/ExecutionLeaseService.php';
require dirname(__DIR__) . '/src/TicketGateway.php';
require dirname(__DIR__) . '/src/OperationalException.php';
require dirname(__DIR__) . '/src/HeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskReferenceData.php';
require dirname(__DIR__) . '/src/HeskReferenceValidationException.php';
require dirname(__DIR__) . '/src/HeskRecurrenceValidator.php';
require dirname(__DIR__) . '/src/NativeHeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskTicketCreator.php';
require dirname(__DIR__) . '/src/HeskTicketGateway.php';
require dirname(__DIR__) . '/src/BatchProcessor.php';
require dirname(__DIR__) . '/src/WorkerCliOptions.php';
require dirname(__DIR__) . '/src/ExecutionCliOptions.php';

final class FakeTicketGateway implements TicketGateway
{
    /** @var array<string, array{id: int, trackid: string}> */
    public array $tickets = [];
    /** @var list<string> */
    public array $generated = [];
    /** @var list<string> */
    public array $createCalls = [];
    /** @var list<string> */
    public array $findCalls = [];
    /** @var list<int> */
    public array $failCreateCalls = [];
    public ?int $crashAfterCreateCall = null;
    public ?Closure $beforeCreate = null;
    public ?Closure $afterCreate = null;
    public ?string $knownFailureCode = null;
    public ?Throwable $validationFailure = null;
    public int $validateCalls = 0;
    private static int $globalTrackingSequence = 0;
    private static int $globalTicketSequence = 100;

    public function validate(array $definition): array
    {
        $this->validateCalls++;

        if ($this->validationFailure !== null) {
            throw $this->validationFailure;
        }

        foreach (['customer_id', 'category', 'priority_name', 'status', 'owner', 'openedby', 'subject', 'message', 'custom_fields'] as $key) {
            if (!array_key_exists($key, $definition)) {
                throw new DomainException("Definição sem {$key}.");
            }
        }

        return ['valid' => true];
    }

    public function generateTrackingId(): string
    {
        self::$globalTrackingSequence++;
        $trackingId = sprintf('BAT-%03d-%04d', 1, self::$globalTrackingSequence);
        $this->generated[] = $trackingId;

        return $trackingId;
    }

    public function findByTrackingId(string $trackingId): ?array
    {
        $this->findCalls[] = $trackingId;

        return $this->tickets[$trackingId] ?? null;
    }

    public function create(array $definition, string $trackingId): array
    {
        $this->createCalls[] = $trackingId;
        $call = count($this->createCalls);

        if ($this->beforeCreate !== null) {
            ($this->beforeCreate)($trackingId, $definition);
        }

        if (in_array($call, $this->failCreateCalls, true)) {
            if ($this->knownFailureCode !== null) {
                throw new OperationalException($this->knownFailureCode, "Falha técnica simulada na criação {$call}.");
            }
            throw new RuntimeException("Falha simulada na criação {$call}.");
        }

        if (isset($this->tickets[$trackingId])) {
            return $this->tickets[$trackingId] + ['created_now' => false];
        }

        $ticket = ['id' => ++self::$globalTicketSequence, 'trackid' => $trackingId];
        $this->tickets[$trackingId] = $ticket;

        if ($this->afterCreate !== null) {
            ($this->afterCreate)($trackingId, $ticket);
        }

        if ($this->crashAfterCreateCall === $call) {
            $this->crashAfterCreateCall = null;
            throw new RuntimeException('Crash simulado depois do HESK e antes do SQLite.');
        }

        return $ticket + ['created_now' => true];
    }

    public function seed(string $trackingId, int $ticketId): void
    {
        $this->tickets[$trackingId] = ['id' => $ticketId, 'trackid' => $trackingId];
    }
}

final class FakeMariaResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public array $rows)
    {
    }
}

function hesk_dbEscape(string $value): string
{
    return str_replace("'", "''", $value);
}

function hesk_dbQuery(string $sql): FakeMariaResult
{
    $GLOBALS['batch_hesk_queries'][] = $sql;

    if (str_contains($sql, 'GET_LOCK')) {
        return new FakeMariaResult([['lock_acquired' => 1]]);
    }

    if (str_contains($sql, 'RELEASE_LOCK')) {
        return new FakeMariaResult([['lock_released' => 1]]);
    }

    if (str_contains($sql, '`hesk_tickets`')) {
        preg_match("/WHERE `trackid`='([^']+)'/", $sql, $matches);
        $trackingId = $matches[1] ?? '';
        $ticket = $GLOBALS['batch_hesk_tickets'][$trackingId] ?? null;

        return new FakeMariaResult($ticket === null ? [] : [$ticket]);
    }

    throw new RuntimeException("SQL HESK inesperado: {$sql}");
}

function hesk_dbNumRows(FakeMariaResult $result): int
{
    return count($result->rows);
}

/** @return array<string, mixed>|false */
function hesk_dbFetchAssoc(FakeMariaResult $result): array|false
{
    return array_shift($result->rows) ?? false;
}

$failures = [];
$assertions = 0;

$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures, &$assertions): void {
    $assertions++;

    if ($expected !== $actual) {
        $failures[] = sprintf(
            '%s: esperado %s, recebido %s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
};

$assertTrue = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;

    if (!$condition) {
        $failures[] = $message;
    }
};

$assertThrows = static function (
    callable $callback,
    string $expectedClass,
    string $message,
    ?string $expectedMessage = null,
) use (&$failures, &$assertions): void {
    $assertions++;

    try {
        $callback();
        $failures[] = "{$message}: nenhuma exceção foi lançada";
    } catch (Throwable $error) {
        if (!$error instanceof $expectedClass) {
            $failures[] = "{$message}: esperado {$expectedClass}, recebido " . $error::class;
            return;
        }

        if ($expectedMessage !== null && !str_contains($error->getMessage(), $expectedMessage)) {
            $failures[] = "{$message}: mensagem inesperada: {$error->getMessage()}";
        }
    }
};

// Os hashes homologados foram calculados com CRLF; aceite também checkouts LF.
$migrationHash = static function (string $path): string {
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Não foi possível ler a migration: {$path}");
    }

    return hash('sha256', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $contents)));
};

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'tickets-recorrentes-batch-' . bin2hex(random_bytes(8));
$databasePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'batch.sqlite';

if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    fwrite(STDERR, "Não foi possível criar o diretório temporário BATCH.\n");
    exit(1);
}

$database = null;
$connection = null;

try {
    $database = new Database($databasePath);
    $connection = $database->connect();
    $migrations = new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations');
    $assertSame(
        ['001_initial_schema', '002_execution_leases', '003_execution_items', '004_execution_error_codes'],
        $migrations->migrate(),
        'A migration 003 deve ser aplicada depois das migrations homologadas'
    );
    $assertSame([], $migrations->migrate(), 'A migration 003 deve ser idempotente');
    $migrations->assertUpToDate();
    $assertSame(4, (int) $connection->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), 'As quatro migrations devem estar registradas');
    $assertSame(
        '387b3354b3cc5644f45028c9c6e172813f511e010935d7fa7c2fc455c98c524e',
        $migrationHash(dirname(__DIR__) . '/database/migrations/001_initial_schema.sql'),
        'A migration 001 deve permanecer inalterada, exceto por LF/CRLF'
    );
    $assertSame(
        '9f6494f76fb26b81bffc5c55aa79fc096f0c83e2eb8890dc82e062815d5e8498',
        $migrationHash(dirname(__DIR__) . '/database/migrations/002_execution_leases.sql'),
        'A migration 002 deve permanecer inalterada, exceto por LF/CRLF'
    );

    $columns = $connection->query('PRAGMA table_info(recurrence_execution_items)')->fetchAll(PDO::FETCH_ASSOC);
    $assertSame(
        ['id', 'execution_id', 'item_index', 'status', 'hesk_trackid', 'hesk_ticket_id', 'creation_attempts', 'last_attempt_at', 'error_message', 'created_at', 'updated_at', 'error_code'],
        array_column($columns, 'name'),
        'A tabela de itens deve possuir o schema previsto'
    );

    $recurrences = new RecurrenceRepository($connection);
    $executions = new RecurrenceExecutionRepository($connection);
    $items = new ExecutionItemRepository($connection);
    $leases = new ExecutionLeaseService($executions, new ImmediateTransaction($connection));
    $clockNow = new DateTimeImmutable('2026-10-02T16:01:00Z');
    $clock = static function () use (&$clockNow): DateTimeImmutable {
        return $clockNow;
    };

    $baseDefinition = [
        'name' => 'Teste BATCH-001',
        'enabled' => true,
        'timezone' => 'America/Sao_Paulo',
        'interval_value' => 1,
        'interval_unit' => 'year',
        'next_run_at' => '2027-10-01T12:00:00Z',
        'quantity' => 3,
        'customer_id' => 100,
        'category_id' => 5,
        'priority_name' => 'Baixa',
        'status_id' => 0,
        'owner_id' => 4,
        'openedby_id' => 4,
        'subject' => '[BATCH-001] Teste isolado',
        'message' => 'Nenhum HESK real é carregado neste teste.',
        'notify_customer' => false,
        'custom_fields' => ['custom15' => 'EXAMPLE_COMPANY'],
    ];

    $createRecurrence = static fn (array $changes = []): array => $recurrences->create(
        array_merge($baseDefinition, $changes)
    );
    $executionSequence = 0;
    $createExecution = static function (array $recurrence, ?int $expected = null) use (
        $executions,
        &$executionSequence,
    ): array {
        $executionSequence++;

        return $executions->create([
            'recurrence_id' => (int) $recurrence['id'],
            'scheduled_for' => sprintf('2026-10-%02dT12:00:00Z', $executionSequence),
            'expected_count' => $expected ?? (int) $recurrence['quantity'],
        ]);
    };
    $claimExecution = static function (array $execution, string $worker = 'batch-worker', int $seconds = 300) use ($leases): array {
        return $leases->claimById(
            (int) $execution['id'],
            $worker,
            new DateTimeImmutable('2026-10-02T16:00:00Z'),
            $seconds
        );
    };
    $makeProcessor = static fn (TicketGateway $gateway): BatchProcessor => new BatchProcessor(
        $recurrences,
        $executions,
        $items,
        $leases,
        $gateway,
        $clock
    );

    // Check é somente leitura.
    $checkRecurrence = $createRecurrence(['name' => 'Check BATCH']);
    $checkExecution = $createExecution($checkRecurrence);
    $checkGateway = new FakeTicketGateway();
    $checkBeforeRecurrence = $recurrences->findById((int) $checkRecurrence['id']);
    $check = $makeProcessor($checkGateway)->check((int) $checkExecution['id']);
    $assertSame(3, $check['expected_count'], 'Check deve informar expected_count');
    $assertSame(0, $check['existing_items'], 'Check não deve materializar itens');
    $assertSame(0, count($checkGateway->createCalls), 'Check não deve criar tickets');
    $assertSame('pending', $executions->findById((int) $checkExecution['id'])['status'], 'Check não deve fazer claim');
    $assertSame(0, $executions->findById((int) $checkExecution['id'])['attempt_count'], 'Check não deve incrementar tentativa');
    $assertSame($checkBeforeRecurrence, $recurrences->findById((int) $checkRecurrence['id']), 'Check não deve alterar next_run_at');

    // Materialização idempotente, snapshot de quantidade e sucesso completo.
    $successRecurrence = $createRecurrence(['name' => 'Lote de três']);
    $successExecution = $createExecution($successRecurrence, 3);
    $successClaim = $claimExecution($successExecution);
    $successToken = (string) $successClaim['lease_token'];
    $firstMaterialization = $items->materialize(
        (int) $successExecution['id'],
        3,
        $successToken,
        '2026-10-02T16:01:00Z'
    );
    $secondMaterialization = $items->materialize(
        (int) $successExecution['id'],
        3,
        $successToken,
        '2026-10-02T16:01:00Z'
    );
    $assertSame([1, 2, 3], array_column($firstMaterialization, 'item_index'), 'expected_count=3 deve gerar índices 1, 2 e 3');
    $assertSame(array_column($firstMaterialization, 'id'), array_column($secondMaterialization, 'id'), 'Segunda materialização deve reutilizar os mesmos itens');
    $recurrences->update((int) $successRecurrence['id'], ['quantity' => 5]);
    $assertSame(3, $executions->findById((int) $successExecution['id'])['expected_count'], 'Alterar quantity não deve mudar expected_count existente');
    $successNextRunAt = $recurrences->findById((int) $successRecurrence['id'])['next_run_at'];

    $successGateway = new FakeTicketGateway();
    $successGateway->beforeCreate = static function (string $trackingId) use ($items, $successExecution, $assertTrue, $assertSame): void {
        $persisted = array_values(array_filter(
            $items->findByExecution((int) $successExecution['id']),
            static fn (array $item): bool => $item['hesk_trackid'] === $trackingId
        ));
        $assertTrue($persisted !== [], 'Tracking ID deve estar persistido antes de create()');
        $assertSame('creating', $persisted[0]['status'] ?? null, 'Item deve estar creating antes da chamada externa');
    };
    $successReport = $makeProcessor($successGateway)->process(
        (int) $successExecution['id'],
        $successToken,
        300
    );
    $successItems = $items->findByExecution((int) $successExecution['id']);
    $assertSame('succeeded', $successReport['final_status'], 'Três itens criados devem concluir a execution');
    $assertSame(3, $successReport['created_now'], 'Três tickets devem ser criados no primeiro processamento');
    $assertSame(3, count($successGateway->createCalls), 'Gateway deve receber uma criação por item');
    $assertSame($successGateway->createCalls, array_column($successItems, 'hesk_trackid'), 'Create deve receber exatamente os tracking IDs persistidos');
    $assertSame([101, 102, 103], array_column($successItems, 'hesk_ticket_id'), 'Itens succeeded devem persistir IDs HESK');
    $assertSame(['succeeded', 'succeeded', 'succeeded'], array_column($successItems, 'status'), 'Todos os itens devem concluir');
    $assertSame(3, $executions->findById((int) $successExecution['id'])['created_count'], 'created_count deve refletir itens succeeded');
    $assertSame(null, $executions->findById((int) $successExecution['id'])['error_code'], 'Sucesso deve limpar código da execution');
    $assertSame([null, null, null], array_column($successItems, 'error_code'), 'Itens succeeded não devem reter códigos de erro');
    $assertSame(3, count($successItems), 'A quantity atualizada não deve criar itens extras');
    $assertSame($successNextRunAt, $recurrences->findById((int) $successRecurrence['id'])['next_run_at'], 'Worker não deve alterar next_run_at');

    // Crash entre HESK e SQLite; retry reconcilia sem segunda criação lógica.
    $crashRecurrence = $createRecurrence(['name' => 'Crash reconciliável', 'quantity' => 1]);
    $crashExecution = $createExecution($crashRecurrence, 1);
    $crashClaim = $claimExecution($crashExecution);
    $crashGateway = new FakeTicketGateway();
    $crashGateway->crashAfterCreateCall = 1;
    $crashProcessor = $makeProcessor($crashGateway);
    $firstCrashReport = $crashProcessor->process(
        (int) $crashExecution['id'],
        (string) $crashClaim['lease_token']
    );
    $crashItemBeforeRetry = $items->findByExecution((int) $crashExecution['id'])[0];
    $assertSame('failed', $firstCrashReport['final_status'], 'Crash após criação deve finalizar a tentativa como failed quando o lease ainda existe');
    $assertSame('UNCLASSIFIED_ERROR', $crashItemBeforeRetry['error_code'], 'Exceção desconhecida deve receber fallback no item');
    $assertSame('BATCH_ITEMS_FAILED', $executions->findById((int) $crashExecution['id'])['error_code'], 'Falha total de item deve ter código agregador');
    $assertSame(1, count($crashGateway->tickets), 'Crash deve deixar somente um ticket lógico no fake HESK');
    $assertSame(1, count($crashGateway->createCalls), 'Primeira tentativa deve chamar create uma vez');
    $assertTrue($crashItemBeforeRetry['hesk_trackid'] !== null, 'Crash deve preservar tracking ID preparado');
    $assertSame(null, $crashItemBeforeRetry['hesk_ticket_id'], 'Crash deve ocorrer antes de persistir o ticket ID no SQLite');

    $assertSame(true, $leases->retry((int) $crashExecution['id'], new DateTimeImmutable('2026-10-02T16:02:00Z'))['retried'], 'Execution failed deve aceitar retry');
    $assertSame(null, $executions->findById((int) $crashExecution['id'])['error_code'], 'Retry deve limpar código da execution');
    $assertSame(null, $executions->findById((int) $crashExecution['id'])['error_message'], 'Retry deve limpar mensagem técnica da execution');
    $crashRetryClaim = $leases->claimById((int) $crashExecution['id'], 'batch-worker-retry', new DateTimeImmutable('2026-10-02T16:03:00Z'));
    $clockNow = new DateTimeImmutable('2026-10-02T16:04:00Z');
    $crashRetryReport = $crashProcessor->process(
        (int) $crashExecution['id'],
        (string) $crashRetryClaim['lease_token']
    );
    $crashItemAfterRetry = $items->findByExecution((int) $crashExecution['id'])[0];
    $assertSame('succeeded', $crashRetryReport['final_status'], 'Retry deve reconciliar ticket criado antes do crash');
    $assertSame(1, $crashRetryReport['reconciled'], 'Retry deve contabilizar reconciliação');
    $assertSame(0, $crashRetryReport['created_now'], 'Reconciliação não deve criar outro ticket');
    $assertSame(1, count($crashGateway->createCalls), 'Retry reconciliado não deve chamar create novamente');
    $assertSame($crashItemBeforeRetry['id'], $crashItemAfterRetry['id'], 'Retry deve reutilizar o mesmo item');
    $assertSame($crashItemBeforeRetry['hesk_trackid'], $crashItemAfterRetry['hesk_trackid'], 'Retry deve reutilizar o tracking ID');
    $assertSame(1, count($crashGateway->tickets), 'Crash e retry devem produzir um único ticket lógico');
    $assertSame(null, $crashItemAfterRetry['error_code'], 'Reconciliação deve limpar código do item');
    $assertSame(null, $crashItemAfterRetry['error_message'], 'Reconciliação deve limpar mensagem técnica do item');

    // Lote parcial, succeeded preservado e conclusão posterior.
    $partialRecurrence = $createRecurrence(['name' => 'Lote parcial', 'quantity' => 3]);
    $partialExecution = $createExecution($partialRecurrence, 3);
    $partialClaim = $claimExecution($partialExecution);
    $partialGateway = new FakeTicketGateway();
    $partialGateway->failCreateCalls = [2, 3];
    $partialGateway->knownFailureCode = 'HESK_TICKET_CREATE_FAILED';
    $partialProcessor = $makeProcessor($partialGateway);
    $clockNow = new DateTimeImmutable('2026-10-02T16:01:00Z');
    $partialReport = $partialProcessor->process(
        (int) $partialExecution['id'],
        (string) $partialClaim['lease_token']
    );
    $partialItemsBefore = $items->findByExecution((int) $partialExecution['id']);
    $assertSame('partial', $partialReport['final_status'], 'Um sucesso e duas falhas devem gerar partial');
    $assertSame('BATCH_PARTIAL_FAILURE', $partialReport['error_code'], 'Relatório operacional deve expor código agregador');
    $assertSame(1, $partialReport['succeeded'], 'Lote parcial deve contar somente succeeded');
    $assertSame(1, $executions->findById((int) $partialExecution['id'])['created_count'], 'created_count parcial deve ser derivado dos itens');
    $assertSame(['succeeded', 'failed', 'failed'], array_column($partialItemsBefore, 'status'), 'Erros devem ficar registrados por item');
    $assertSame('BATCH_PARTIAL_FAILURE', $executions->findById((int) $partialExecution['id'])['error_code'], 'Lote parcial deve ter código agregador');
    $assertSame(['HESK_TICKET_CREATE_FAILED', 'HESK_TICKET_CREATE_FAILED'], array_column(array_slice($partialItemsBefore, 1), 'error_code'), 'Itens failed devem preservar código conhecido da origem');
    $assertTrue(str_contains((string) $partialItemsBefore[1]['error_message'], 'Falha técnica simulada'), 'Item failed deve preservar diagnóstico técnico');
    $firstSucceededId = $partialItemsBefore[0]['id'];
    $firstSucceededTrack = $partialItemsBefore[0]['hesk_trackid'];
    $failedTracks = [$partialItemsBefore[1]['hesk_trackid'], $partialItemsBefore[2]['hesk_trackid']];

    $leases->retry((int) $partialExecution['id'], new DateTimeImmutable('2026-10-02T16:02:00Z'));
    $partialRetryClaim = $leases->claimById((int) $partialExecution['id'], 'batch-worker-retry', new DateTimeImmutable('2026-10-02T16:03:00Z'));
    $partialGateway->failCreateCalls = [];
    $partialGateway->beforeCreate = static function (string $trackingId) use ($items, $partialExecution, $assertSame): void {
        foreach ($items->findByExecution((int) $partialExecution['id']) as $item) {
            if ($item['hesk_trackid'] === $trackingId) {
                $assertSame(null, $item['error_code'], 'markCreating deve limpar código antigo antes de create');
                $assertSame(null, $item['error_message'], 'markCreating deve limpar mensagem antiga antes de create');
            }
        }
    };
    $callsBeforeRetry = count($partialGateway->createCalls);
    $clockNow = new DateTimeImmutable('2026-10-02T16:04:00Z');
    $partialRetryReport = $partialProcessor->process(
        (int) $partialExecution['id'],
        (string) $partialRetryClaim['lease_token']
    );
    $partialItemsAfter = $items->findByExecution((int) $partialExecution['id']);
    $assertSame('succeeded', $partialRetryReport['final_status'], 'Retry deve concluir os itens restantes');
    $assertSame(2, count($partialGateway->createCalls) - $callsBeforeRetry, 'Retry deve processar somente os dois itens restantes');
    $assertSame($firstSucceededId, $partialItemsAfter[0]['id'], 'Item succeeded deve ser preservado');
    $assertSame($firstSucceededTrack, $partialItemsAfter[0]['hesk_trackid'], 'Tracking do item succeeded não deve mudar');
    $assertSame($failedTracks, [$partialItemsAfter[1]['hesk_trackid'], $partialItemsAfter[2]['hesk_trackid']], 'Itens failed devem reutilizar tracking IDs');
    $assertSame([1, 2, 2], array_column($partialItemsAfter, 'creation_attempts'), 'Somente itens restantes devem incrementar tentativas');
    $assertSame(3, $executions->findById((int) $partialExecution['id'])['created_count'], 'Conclusão posterior deve sincronizar created_count');
    $assertSame(null, $executions->findById((int) $partialExecution['id'])['error_code'], 'Sucesso após retry deve limpar código agregador');
    $assertSame(null, $executions->findById((int) $partialExecution['id'])['error_message'], 'Sucesso após retry deve limpar mensagem agregada');
    $assertSame([null, null, null], array_column($partialItemsAfter, 'error_code'), 'Sucesso após retry deve limpar códigos dos itens');

    // Falha total.
    $failedRecurrence = $createRecurrence(['name' => 'Lote falho', 'quantity' => 2]);
    $failedExecution = $createExecution($failedRecurrence, 2);
    $failedClaim = $claimExecution($failedExecution);
    $failedGateway = new FakeTicketGateway();
    $failedGateway->failCreateCalls = [1, 2];
    $clockNow = new DateTimeImmutable('2026-10-02T16:01:00Z');
    $failedReport = $makeProcessor($failedGateway)->process(
        (int) $failedExecution['id'],
        (string) $failedClaim['lease_token']
    );
    $assertSame('failed', $failedReport['final_status'], 'Lote 0/N com falhas deve finalizar failed');
    $assertSame('BATCH_ITEMS_FAILED', $failedReport['error_code'], 'Relatório de falha total deve expor código agregador');
    $assertSame(0, $executions->findById((int) $failedExecution['id'])['created_count'], 'Falha total deve manter created_count zero');
    $assertTrue($executions->findById((int) $failedExecution['id'])['error_message'] !== null, 'Falha total deve registrar erro na execution');
    $assertSame('BATCH_ITEMS_FAILED', $executions->findById((int) $failedExecution['id'])['error_code'], 'Falha total deve persistir código agregador');

    // Item creating preexistente é recuperável por lookup.
    $creatingRecurrence = $createRecurrence(['name' => 'Creating recuperável', 'quantity' => 1]);
    $creatingExecution = $createExecution($creatingRecurrence, 1);
    $creatingClaim = $claimExecution($creatingExecution);
    $creatingToken = (string) $creatingClaim['lease_token'];
    $creatingItem = $items->materialize((int) $creatingExecution['id'], 1, $creatingToken, '2026-10-02T16:01:00Z')[0];
    $items->assignTrackingId((int) $creatingItem['id'], (int) $creatingExecution['id'], 'REC-001-0001', $creatingToken, '2026-10-02T16:01:00Z');
    $items->markCreating((int) $creatingItem['id'], (int) $creatingExecution['id'], $creatingToken, '2026-10-02T16:01:00Z');
    $leases->finish((int) $creatingExecution['id'], $creatingToken, 'failed', 'Interrupção simulada', new DateTimeImmutable('2026-10-02T16:02:00Z'));
    $leases->retry((int) $creatingExecution['id'], new DateTimeImmutable('2026-10-02T16:03:00Z'));
    $creatingRetryClaim = $leases->claimById((int) $creatingExecution['id'], 'creating-retry', new DateTimeImmutable('2026-10-02T16:04:00Z'));
    $creatingGateway = new FakeTicketGateway();
    $creatingGateway->seed('REC-001-0001', 501);
    $clockNow = new DateTimeImmutable('2026-10-02T16:05:00Z');
    $creatingReport = $makeProcessor($creatingGateway)->process((int) $creatingExecution['id'], (string) $creatingRetryClaim['lease_token']);
    $assertSame('succeeded', $creatingReport['final_status'], 'Item creating deve ser recuperado');
    $assertSame(1, $creatingReport['reconciled'], 'Item creating existente no HESK deve ser reconciliado');
    $assertSame([], $creatingGateway->createCalls, 'Reconciliação de creating não deve criar ticket');

    // notify_customer=true é recusado sem criação.
    $notifyRecurrence = $createRecurrence(['name' => 'Notificação bloqueada', 'quantity' => 1, 'notify_customer' => true]);
    $notifyExecution = $createExecution($notifyRecurrence, 1);
    $notifyGateway = new FakeTicketGateway();
    $assertThrows(
        static fn () => $makeProcessor($notifyGateway)->check((int) $notifyExecution['id']),
        DomainException::class,
        'Check deve recusar notify_customer=true',
        'notify_customer=true'
    );
    $notifyClaim = $claimExecution($notifyExecution);
    $clockNow = new DateTimeImmutable('2026-10-02T16:01:00Z');
    $notifyReport = $makeProcessor($notifyGateway)->process((int) $notifyExecution['id'], (string) $notifyClaim['lease_token']);
    $assertSame('failed', $notifyReport['final_status'], 'Worker deve finalizar definição não suportada como failed');
    $assertSame('CONFIGURATION_UNSUPPORTED', $executions->findById((int) $notifyExecution['id'])['error_code'], 'Falha fatal de preparação deve preservar código específico');
    $assertSame([], $notifyGateway->createCalls, 'notify_customer=true não deve criar ticket');

    // Falha fatal desconhecida mantém mensagem técnica, mas não inventa uma causa.
    $unknownRecurrence = $createRecurrence(['name' => 'Falha desconhecida', 'quantity' => 1]);
    $unknownExecution = $createExecution($unknownRecurrence, 1);
    $unknownClaim = $claimExecution($unknownExecution);
    $unknownGateway = new FakeTicketGateway();
    $unknownGateway->validationFailure = new RuntimeException('Falha inesperada em /segredo/hesk.php');
    $unknownReport = $makeProcessor($unknownGateway)->process((int) $unknownExecution['id'], (string) $unknownClaim['lease_token']);
    $unknownSaved = $executions->findById((int) $unknownExecution['id']);
    $assertSame('failed', $unknownReport['final_status'], 'Falha fatal desconhecida deve finalizar execution');
    $assertSame('UNCLASSIFIED_ERROR', $unknownSaved['error_code'], 'Falha fatal desconhecida deve usar fallback');
    $assertSame('Falha inesperada em /segredo/hesk.php', $unknownSaved['error_message'], 'Diagnóstico técnico original deve ser preservado');
    $assertSame([], $unknownGateway->createCalls, 'Falha fatal de validação não deve criar ticket');

    // Token inválido, expirado e antigo não alteram itens.
    $tokenRecurrence = $createRecurrence(['name' => 'Proteção por token', 'quantity' => 1]);
    $tokenExecution = $createExecution($tokenRecurrence, 1);
    $tokenClaimA = $claimExecution($tokenExecution, 'token-a', 30);
    $tokenA = (string) $tokenClaimA['lease_token'];
    $tokenItem = $items->materialize((int) $tokenExecution['id'], 1, $tokenA, '2026-10-02T16:00:01Z')[0];
    $assertSame(false, $items->assignTrackingId((int) $tokenItem['id'], (int) $tokenExecution['id'], 'BAD-001-0001', str_repeat('f', 64), '2026-10-02T16:00:02Z'), 'Token incorreto não deve alterar item');
    $assertSame(false, $items->assignTrackingId((int) $tokenItem['id'], (int) $tokenExecution['id'], 'EXP-001-0001', $tokenA, '2026-10-02T16:00:30Z'), 'Token expirado não deve alterar item');
    $tokenClaimB = $leases->claimById((int) $tokenExecution['id'], 'token-b', new DateTimeImmutable('2026-10-02T16:00:31Z'), 300);
    $assertSame(true, $tokenClaimB['claimed'], 'Lease expirado deve permitir takeover');
    $assertSame(false, $items->assignTrackingId((int) $tokenItem['id'], (int) $tokenExecution['id'], 'OLD-001-001', $tokenA, '2026-10-02T16:00:32Z'), 'Token antigo após takeover não deve alterar item');
    $assertSame(null, $items->findById((int) $tokenItem['id'])['hesk_trackid'], 'Item deve permanecer intacto após tokens recusados');

    // Perda de lease depois da criação interrompe os itens seguintes e permite reconciliação posterior.
    $lossRecurrence = $createRecurrence(['name' => 'Perda de lease', 'quantity' => 2]);
    $lossExecution = $createExecution($lossRecurrence, 2);
    $lossClaim = $claimExecution($lossExecution, 'loss-a', 30);
    $lossGateway = new FakeTicketGateway();
    $clockNow = new DateTimeImmutable('2026-10-02T16:00:01Z');
    $lossGateway->afterCreate = static function () use (&$clockNow): void {
        $clockNow = new DateTimeImmutable('2026-10-02T16:01:00Z');
    };
    $lossProcessor = $makeProcessor($lossGateway);
    $lossReport = $lossProcessor->process((int) $lossExecution['id'], (string) $lossClaim['lease_token'], 30);
    $lossItems = $items->findByExecution((int) $lossExecution['id']);
    $assertSame(true, $lossReport['lease_lost'], 'Perda do lease deve ser reportada');
    $assertSame(1, count($lossGateway->createCalls), 'Perda do lease deve interromper os itens seguintes');
    $assertSame(['creating', 'pending'], array_column($lossItems, 'status'), 'Ticket criado sem confirmação deve permanecer creating e o seguinte pending');
    $assertSame(0, $executions->findById((int) $lossExecution['id'])['created_count'], 'Ticket ainda não reconciliado não deve entrar em created_count');
    $assertSame('running', $executions->findById((int) $lossExecution['id'])['status'], 'Worker sem lease não deve finalizar a execution');
    $assertTrue($executions->findById((int) $lossExecution['id'])['lease_expires_at'] > '2026-10-02T16:00:01Z', 'Heartbeat deve ter renovado o lease antes do item');

    $lossGateway->afterCreate = null;
    $lossClaimB = $leases->claimById((int) $lossExecution['id'], 'loss-b', new DateTimeImmutable('2026-10-02T16:01:01Z'), 300);
    $clockNow = new DateTimeImmutable('2026-10-02T16:02:00Z');
    $lossRetryReport = $lossProcessor->process((int) $lossExecution['id'], (string) $lossClaimB['lease_token'], 300);
    $assertSame('succeeded', $lossRetryReport['final_status'], 'Novo worker deve reconciliar e concluir após perda do lease');
    $assertSame(1, $lossRetryReport['reconciled'], 'Ticket criado antes da perda deve ser reconciliado');
    $assertSame(2, count($lossGateway->tickets), 'Recuperação deve produzir somente os dois tickets lógicos esperados');
    $assertSame(2, $executions->findById((int) $lossExecution['id'])['created_count'], 'Conclusão deve exigir created_count igual ao expected_count');

    // CLI parsing e inspeção read-only.
    $workerCheck = WorkerCliOptions::parse(
        ['worker.php', 'check', '--db-path=C:\tmp\batch.sqlite', '--hesk-path=C:\hesk', '--id=1'],
        null,
        null
    );
    $assertSame('check', $workerCheck->command, 'Worker CLI deve aceitar check');
    $workerRun = WorkerCliOptions::parse(
        ['worker.php', 'run', '--id=1', '--worker=host:123'],
        'C:\tmp\batch.sqlite',
        'C:\hesk'
    );
    $assertSame('host:123', $workerRun->worker, 'Worker CLI deve aceitar identidade do worker');
    $assertSame(300, $workerRun->leaseSeconds, 'Worker CLI deve usar lease padrão');
    $assertThrows(
        static fn () => WorkerCliOptions::parse(['worker.php', 'run', '--id=1', '--worker=w'], null, null),
        InvalidArgumentException::class,
        'Worker CLI deve exigir caminho HESK'
    );
    $assertSame('items', ExecutionCliOptions::parse(['execution.php', 'items', '--id=1'], null)->command, 'CLI de execution deve aceitar inspeção de itens');

    // Gateway real usa named lock e lookup dentro do lock quando o ticket já existe.
    $GLOBALS['hesk_settings'] = ['db_pfix' => 'hesk_'];
    $GLOBALS['batch_hesk_queries'] = [];
    $GLOBALS['batch_hesk_tickets'] = [
        'LOCK-TEST-01' => ['id' => 999, 'trackid' => 'LOCK-TEST-01'],
    ];
    $realGateway = new HeskTicketGateway(new HeskTicketCreator());
    $lockedExisting = $realGateway->create([], 'LOCK-TEST-01');
    $assertSame(false, $lockedExisting['created_now'], 'Gateway deve reconciliar ticket existente dentro do named lock');
    $assertTrue(str_contains($GLOBALS['batch_hesk_queries'][0] ?? '', 'GET_LOCK'), 'Gateway deve obter GET_LOCK antes do lookup/criação');
    $assertTrue(str_contains($GLOBALS['batch_hesk_queries'][1] ?? '', 'SELECT `id`, `trackid`'), 'Gateway deve consultar tracking ID dentro do lock');
    $assertTrue(str_contains($GLOBALS['batch_hesk_queries'][2] ?? '', 'RELEASE_LOCK'), 'Gateway deve liberar o named lock em finally');
} finally {
    unset(
        $lossProcessor,
        $partialProcessor,
        $crashProcessor,
        $successGateway,
        $checkGateway,
        $crashGateway,
        $partialGateway,
        $failedGateway,
        $creatingGateway,
        $notifyGateway,
        $lossGateway,
        $realGateway,
        $makeProcessor,
        $claimExecution,
        $createExecution,
        $createRecurrence,
        $leases,
        $items,
        $executions,
        $recurrences,
        $migrations
    );
    gc_collect_cycles();
    $connection = null;
    $database = null;

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($temporaryDirectory);
}

$assertTrue(!is_file($databasePath), 'O banco temporário BATCH deve ser removido ao final');
$assertTrue(!is_dir($temporaryDirectory), 'O diretório temporário BATCH deve ser removido ao final');

if ($failures !== []) {
    fwrite(STDERR, "TESTES BATCH-001 FALHARAM\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TESTES BATCH-001 OK ({$assertions} asserções)\n";
