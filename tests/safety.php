<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\ExecutionCliOptions;
use TicketsRecorrentesHesk\ExecutionLeaseService;
use TicketsRecorrentesHesk\ImmediateTransaction;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\RecurrenceRepository;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceRepository.php';
require dirname(__DIR__) . '/src/RecurrenceExecutionRepository.php';
require dirname(__DIR__) . '/src/ImmediateTransaction.php';
require dirname(__DIR__) . '/src/ExecutionLeaseService.php';
require dirname(__DIR__) . '/src/ExecutionCliOptions.php';

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

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'tickets-recorrentes-safe-' . bin2hex(random_bytes(8));
$legacyMigrations = $temporaryDirectory . DIRECTORY_SEPARATOR . 'migrations';
$databasePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'safe.sqlite';

if (!mkdir($legacyMigrations, 0700, true) && !is_dir($legacyMigrations)) {
    fwrite(STDERR, "Não foi possível criar o diretório temporário de testes.\n");
    exit(1);
}

$migration001 = dirname(__DIR__) . '/database/migrations/001_initial_schema.sql';
$temporaryMigration001 = $legacyMigrations . DIRECTORY_SEPARATOR . '001_initial_schema.sql';

if (!copy($migration001, $temporaryMigration001)) {
    fwrite(STDERR, "Não foi possível preparar a migration inicial para o teste.\n");
    exit(1);
}

$databaseA = null;
$databaseB = null;
$connectionA = null;
$connectionB = null;
$repositoryA = null;
$repositoryB = null;
$serviceA = null;
$serviceB = null;
$legacyRunner = null;
$runner = null;

try {
    $databaseA = new Database($databasePath);
    $connectionA = $databaseA->connect();
    $legacyRunner = new MigrationRunner($connectionA, $legacyMigrations);
    $assertSame(['001_initial_schema'], $legacyRunner->migrate(), 'O cenário legado deve aplicar apenas a migration 001');

    $recurrences = new RecurrenceRepository($connectionA);
    $recurrence = $recurrences->create([
        'name' => 'Homologação SAFE-001',
        'enabled' => true,
        'timezone' => 'America/Sao_Paulo',
        'interval_value' => 1,
        'interval_unit' => 'month',
        'next_run_at' => '2026-11-01T12:00:00Z',
        'quantity' => 1,
        'customer_id' => 100,
        'category_id' => 5,
        'priority_name' => 'Baixa',
        'status_id' => 0,
        'owner_id' => 4,
        'openedby_id' => 4,
        'subject' => '[SAFE-001] Teste isolado',
        'message' => 'Nenhum ticket deve ser criado por este teste.',
        'notify_customer' => false,
        'custom_fields' => [],
    ]);

    $connectionA->prepare(
        "INSERT INTO recurrence_executions (
            recurrence_id, scheduled_for, status, expected_count, created_count,
            started_at, finished_at, error_message, created_at, updated_at
        ) VALUES (:recurrence_id, :scheduled_for, 'pending', 1, 0, NULL, NULL, NULL, :now, :now)"
    )->execute([
        'recurrence_id' => $recurrence['id'],
        'scheduled_for' => '2026-10-01T12:00:00Z',
        'now' => '2026-10-01T12:00:00Z',
    ]);

    $runner = new MigrationRunner($connectionA, dirname(__DIR__) . '/database/migrations');
    $assertSame(
        ['002_execution_leases', '003_execution_items', '004_execution_error_codes'],
        $runner->migrate(),
        'Upgrade de banco existente deve aplicar as migrations posteriores à 001'
    );
    $assertSame([], $runner->migrate(), 'As migrations posteriores devem ser idempotentes');
    $runner->assertUpToDate();
    $assertSame(4, (int) $connectionA->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), 'As quatro migrations devem estar registradas');

    $columns = $connectionA->query('PRAGMA table_info(recurrence_executions)')->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');

    foreach (['attempt_count', 'lease_token', 'lease_owner', 'lease_expires_at', 'last_attempt_at'] as $column) {
        $assertTrue(in_array($column, $columnNames, true), "A migration 002 deve adicionar {$column}");
    }

    $indexes = $connectionA->query('PRAGMA index_list(recurrence_executions)')->fetchAll(PDO::FETCH_ASSOC);
    $assertTrue(
        in_array('idx_recurrence_executions_claimable', array_column($indexes, 'name'), true),
        'A migration 002 deve criar o índice de claim'
    );

    $repositoryA = new RecurrenceExecutionRepository($connectionA);
    $legacyExecution = $repositoryA->findById(1);
    $assertSame(0, $legacyExecution['attempt_count'] ?? null, 'Registro anterior à migration deve receber attempt_count zero');
    $assertTrue(array_key_exists('lease_owner', $legacyExecution ?? []), 'Registro anterior deve expor o campo lease_owner');
    $assertSame(null, $legacyExecution['lease_owner'], 'Registro anterior deve ficar sem lease owner');
    $assertTrue(array_key_exists('lease_expires_at', $legacyExecution ?? []), 'Registro anterior deve expor o campo lease_expires_at');
    $assertSame(null, $legacyExecution['lease_expires_at'], 'Registro anterior deve ficar sem expiração');
    $assertSame(null, $legacyExecution['error_code'], 'Registro anterior deve receber error_code nulo');
    $assertTrue(array_key_exists('last_attempt_at', $legacyExecution ?? []), 'Registro anterior deve expor o campo last_attempt_at');
    $assertSame(null, $legacyExecution['last_attempt_at'], 'Registro anterior deve ficar sem last_attempt_at');
    $assertTrue(!array_key_exists('lease_token', $legacyExecution ?? []), 'Leitura genérica não deve expor lease_token');

    $databaseB = new Database($databasePath);
    $connectionB = $databaseB->connect();
    (new MigrationRunner($connectionB, dirname(__DIR__) . '/database/migrations'))->assertUpToDate();
    $repositoryB = new RecurrenceExecutionRepository($connectionB);
    $serviceA = new ExecutionLeaseService($repositoryA, new ImmediateTransaction($connectionA));
    $serviceB = new ExecutionLeaseService($repositoryB, new ImmediateTransaction($connectionB));

    $recurrenceBefore = $recurrences->findById((int) $recurrence['id']);
    $claimA = $serviceA->claimById(1, 'worker-a', new DateTimeImmutable('2026-10-02T16:00:00Z'), 300);
    $tokenA = (string) $claimA['lease_token'];
    $assertSame(true, $claimA['claimed'], 'O primeiro worker deve obter o claim');
    $assertSame('running', $claimA['execution']['status'], 'Claim deve mover pending para running');
    $assertSame(1, $claimA['execution']['attempt_count'], 'Claim deve incrementar attempt_count');
    $assertSame('worker-a', $claimA['execution']['lease_owner'], 'Claim deve registrar o worker');
    $assertSame('2026-10-02T16:05:00Z', $claimA['execution']['lease_expires_at'], 'Claim deve calcular a expiração do lease');
    $assertSame('2026-10-02T16:00:00Z', $claimA['execution']['last_attempt_at'], 'Claim deve registrar last_attempt_at');
    $assertSame('2026-10-02T16:00:00Z', $claimA['execution']['started_at'], 'Claim deve registrar started_at');
    $assertTrue((bool) preg_match('/^[a-f0-9]{64}$/D', $tokenA), 'Claim deve devolver token aleatório hexadecimal de 64 caracteres');
    $assertTrue(!array_key_exists('lease_token', $claimA['execution']), 'O objeto execution do claim não deve duplicar o token');
    $assertSame($recurrenceBefore, $recurrences->findById((int) $recurrence['id']), 'Claim não deve alterar a recorrência');

    $claimBActive = $serviceB->claimById(1, 'worker-b', new DateTimeImmutable('2026-10-02T16:00:01Z'), 300);
    $assertSame(false, $claimBActive['claimed'], 'Uma segunda conexão não deve obter execution com lease ativo');
    $assertSame('active_lease', $claimBActive['reason'], 'A recusa concorrente deve informar lease ativo');
    $assertSame(1, $repositoryB->findById(1)['attempt_count'] ?? null, 'Claim recusado não deve incrementar attempt_count');

    $heartbeat = $serviceA->renewLease(1, $tokenA, new DateTimeImmutable('2026-10-02T16:01:00Z'), 300);
    $assertSame(true, $heartbeat['renewed'], 'Owner com token válido deve renovar o lease');
    $assertSame('2026-10-02T16:06:00Z', $heartbeat['execution']['lease_expires_at'], 'Heartbeat deve estender a expiração');

    $wrongToken = ($tokenA[0] === 'a' ? 'b' : 'a') . substr($tokenA, 1);
    $wrongHeartbeat = $serviceB->renewLease(1, $wrongToken, new DateTimeImmutable('2026-10-02T16:02:00Z'), 300);
    $assertSame(false, $wrongHeartbeat['renewed'], 'Token incorreto não deve renovar o lease');
    $assertSame('lease_token_mismatch', $wrongHeartbeat['reason'], 'Token incorreto deve ter motivo explícito');

    $expiredHeartbeat = $serviceA->renewLease(1, $tokenA, new DateTimeImmutable('2026-10-02T16:06:00Z'), 300);
    $assertSame(false, $expiredHeartbeat['renewed'], 'Lease no instante exato da expiração não deve renovar');
    $assertSame('lease_expired', $expiredHeartbeat['reason'], 'Heartbeat expirado deve informar expiração');

    $takeover = $serviceB->claimById(1, 'worker-b', new DateTimeImmutable('2026-10-02T16:07:00Z'), 300);
    $tokenB = (string) $takeover['lease_token'];
    $assertSame(true, $takeover['claimed'], 'Outro worker deve poder assumir lease expirado');
    $assertSame(2, $takeover['execution']['attempt_count'], 'Takeover deve registrar nova tentativa');
    $assertSame('worker-b', $takeover['execution']['lease_owner'], 'Takeover deve trocar o owner');
    $assertTrue($tokenB !== $tokenA, 'Takeover deve gerar token novo');

    $oldHeartbeat = $serviceA->renewLease(1, $tokenA, new DateTimeImmutable('2026-10-02T16:08:00Z'), 300);
    $assertSame(false, $oldHeartbeat['renewed'], 'Token antigo não deve renovar após takeover');
    $assertSame('lease_token_mismatch', $oldHeartbeat['reason'], 'Token antigo deve ser distinguido do lease atual');
    $oldFinish = $serviceA->finish(1, $tokenA, 'succeeded', null, new DateTimeImmutable('2026-10-02T16:08:00Z'));
    $assertSame(false, $oldFinish['finished'], 'Token antigo não deve finalizar após takeover');

    $success = $serviceB->finish(1, $tokenB, 'succeeded', null, new DateTimeImmutable('2026-10-02T16:08:00Z'));
    $assertSame(true, $success['finished'], 'Token atual deve finalizar a execution');
    $assertSame('succeeded', $success['execution']['status'], 'Finalização com sucesso deve ser terminal');
    $assertSame(null, $success['execution']['lease_owner'], 'Finalização deve limpar o owner');
    $assertSame(null, $success['execution']['lease_expires_at'], 'Finalização deve limpar a expiração');
    $assertSame(null, $success['execution']['error_message'], 'Sucesso deve limpar a mensagem de erro');
    $assertSame(null, $success['execution']['error_code'], 'Sucesso deve limpar o código de erro');
    $assertSame('2026-10-02T16:08:00Z', $success['execution']['finished_at'], 'Finalização deve registrar finished_at');
    $assertSame('terminal_succeeded', $serviceA->claimById(1, 'worker-a', new DateTimeImmutable('2026-10-02T16:09:00Z'))['reason'], 'Execution succeeded não deve ser reclamada');
    $assertSame('terminal_succeeded', $serviceA->retry(1, new DateTimeImmutable('2026-10-02T16:09:00Z'))['reason'], 'Execution succeeded não deve aceitar retry');

    $failedExecution = $repositoryA->create([
        'recurrence_id' => (int) $recurrence['id'],
        'scheduled_for' => '2026-10-02T12:00:00Z',
        'expected_count' => 1,
    ]);
    $failedClaim = $serviceA->claimById((int) $failedExecution['id'], 'worker-a', new DateTimeImmutable('2026-10-02T17:00:00Z'));
    $failedToken = (string) $failedClaim['lease_token'];
    $failedFinish = $serviceA->finish((int) $failedExecution['id'], $failedToken, 'failed', 'Falha controlada', new DateTimeImmutable('2026-10-02T17:01:00Z'));
    $assertSame(true, $failedFinish['finished'], 'Falha deve poder finalizar com token válido');
    $assertSame('failed', $failedFinish['execution']['status'], 'Resultado failed deve ser persistido');
    $assertSame('Falha controlada', $failedFinish['execution']['error_message'], 'Falha deve persistir a mensagem obrigatória');
    $assertSame('UNCLASSIFIED_ERROR', $failedFinish['execution']['error_code'], 'Finish manual sem código deve usar fallback estável');
    $assertSame('explicit_retry_required', $serviceB->claimById((int) $failedExecution['id'], 'worker-b', new DateTimeImmutable('2026-10-02T17:02:00Z'))['reason'], 'Failed não deve voltar automaticamente para processamento');

    $failedRetry = $serviceA->retry((int) $failedExecution['id'], new DateTimeImmutable('2026-10-02T17:03:00Z'));
    $assertSame(true, $failedRetry['retried'], 'Failed deve aceitar retry explícito');
    $assertSame((int) $failedExecution['id'], $failedRetry['execution']['id'], 'Retry deve reutilizar a mesma linha');
    $assertSame(1, $failedRetry['execution']['attempt_count'], 'Retry não deve apagar o contador de tentativas');
    $assertSame('pending', $failedRetry['execution']['status'], 'Retry deve voltar para pending');
    $assertSame(null, $failedRetry['execution']['started_at'], 'Retry deve limpar started_at');
    $assertSame(null, $failedRetry['execution']['finished_at'], 'Retry deve limpar finished_at');
    $assertSame(null, $failedRetry['execution']['error_message'], 'Retry deve limpar error_message');
    $assertSame(null, $failedRetry['execution']['error_code'], 'Retry deve limpar error_code');
    $secondFailedClaim = $serviceA->claimById((int) $failedExecution['id'], 'worker-a', new DateTimeImmutable('2026-10-02T17:04:00Z'));
    $assertSame(2, $secondFailedClaim['execution']['attempt_count'], 'Novo claim após retry deve incrementar a tentativa preservada');
    $assertSame('invalid_status', $serviceA->retry((int) $failedExecution['id'], new DateTimeImmutable('2026-10-02T17:05:00Z'))['reason'], 'Running não deve aceitar retry');

    $partialExecution = $repositoryA->create([
        'recurrence_id' => (int) $recurrence['id'],
        'scheduled_for' => '2026-10-03T12:00:00Z',
        'expected_count' => 2,
    ]);
    $partialClaim = $serviceA->claimById((int) $partialExecution['id'], 'worker-a', new DateTimeImmutable('2026-10-02T18:00:00Z'));
    $partialFinish = $serviceA->finish(
        (int) $partialExecution['id'],
        (string) $partialClaim['lease_token'],
        'partial',
        'Um item pendente',
        new DateTimeImmutable('2026-10-02T18:01:00Z')
    );
    $assertSame('partial', $partialFinish['execution']['status'], 'Resultado partial deve ser persistido');
    $assertSame(true, $serviceA->retry((int) $partialExecution['id'], new DateTimeImmutable('2026-10-02T18:02:00Z'))['retried'], 'Partial deve aceitar retry explícito');

    $legacyRunning = $repositoryA->create([
        'recurrence_id' => (int) $recurrence['id'],
        'scheduled_for' => '2026-10-04T12:00:00Z',
        'status' => 'running',
        'expected_count' => 1,
        'started_at' => '2026-10-01T12:00:00Z',
    ]);
    $legacyClaim = $serviceA->claimById((int) $legacyRunning['id'], 'worker-a', new DateTimeImmutable('2026-10-02T19:00:00Z'));
    $assertSame(false, $legacyClaim['claimed'], 'Running legado sem lease não deve ser roubado automaticamente');
    $assertSame('legacy_running_without_lease', $legacyClaim['reason'], 'Running legado deve produzir motivo auditável');

    $pendingExecution = $repositoryA->create([
        'recurrence_id' => (int) $recurrence['id'],
        'scheduled_for' => '2026-10-05T12:00:00Z',
        'expected_count' => 1,
    ]);
    $assertSame('invalid_status', $serviceA->retry((int) $pendingExecution['id'], new DateTimeImmutable('2026-10-02T19:00:00Z'))['reason'], 'Pending não deve aceitar retry');
    $assertTrue(count($repositoryA->findAll('pending')) >= 2, 'Filtro de list deve retornar somente pendentes existentes');
    $assertTrue(!array_key_exists('lease_token', $repositoryA->findById((int) $pendingExecution['id']) ?? []), 'Show genérico não deve expor token');

    $assertThrows(
        static fn () => $serviceA->finish((int) $pendingExecution['id'], str_repeat('a', 64), 'failed', null, new DateTimeImmutable('2026-10-02T19:00:00Z')),
        DomainException::class,
        'Resultado failed deve exigir mensagem',
        'obrigatório'
    );
    $assertThrows(
        static fn () => $serviceA->claimById((int) $pendingExecution['id'], '', new DateTimeImmutable('2026-10-02T19:00:00Z')),
        DomainException::class,
        'Worker vazio deve ser rejeitado'
    );
    $assertThrows(
        static fn () => $serviceA->claimById((int) $pendingExecution['id'], 'worker', new DateTimeImmutable('2026-10-02T19:00:00Z'), 29),
        DomainException::class,
        'Lease abaixo do mínimo deve ser rejeitado'
    );
    $assertThrows(
        static fn () => $serviceA->renewLease((int) $pendingExecution['id'], 'invalido', new DateTimeImmutable('2026-10-02T19:00:00Z')),
        DomainException::class,
        'Token malformado deve ser rejeitado'
    );

    $assertSame('help', ExecutionCliOptions::parse(['execution.php'], null)->command, 'CLI sem argumentos deve mostrar ajuda');
    $assertSame('worker-cli', ExecutionCliOptions::parse(['execution.php', 'claim', '--worker=worker-cli'], null)->worker, 'CLI deve aceitar worker no claim');
    $assertThrows(
        static fn () => ExecutionCliOptions::parse(['execution.php', 'claim', '--worker=w', '--lease-seconds=10'], null),
        InvalidArgumentException::class,
        'CLI deve validar o limite do lease'
    );
    $assertThrows(
        static fn () => ExecutionCliOptions::parse(['execution.php', 'finish', '--id=1', '--token=' . str_repeat('a', 64), '--result=failed'], null),
        InvalidArgumentException::class,
        'CLI deve exigir erro para failed'
    );

    $assertSame(false, function_exists('hesk_newTicket'), 'O teste SAFE-001 não deve carregar nem chamar a API do HESK');
    $assertSame(0, (int) $connectionA->query('SELECT SUM(created_count) FROM recurrence_executions')->fetchColumn(), 'SAFE-001 não deve registrar ticket criado');
    $assertSame($recurrenceBefore, $recurrences->findById((int) $recurrence['id']), 'Todo o fluxo de leases deve preservar a recorrência');
} finally {
    $serviceA = null;
    $serviceB = null;
    $repositoryA = null;
    $repositoryB = null;
    $recurrences = null;
    $connectionA = null;
    $connectionB = null;
    $databaseA = null;
    $databaseB = null;
    $legacyRunner = null;
    $runner = null;

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    if (is_file($temporaryMigration001)) {
        @unlink($temporaryMigration001);
    }

    @rmdir($legacyMigrations);
    @rmdir($temporaryDirectory);
}

$assertTrue(!is_file($databasePath), 'O banco temporário SAFE deve ser removido ao final');
$assertTrue(!is_dir($temporaryDirectory), 'O diretório temporário SAFE deve ser removido ao final');

if ($failures !== []) {
    fwrite(STDERR, "TESTES SAFE-001 FALHARAM\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TESTES SAFE-001 OK ({$assertions} asserções)\n";
