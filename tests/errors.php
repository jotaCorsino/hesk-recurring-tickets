<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\OperationalErrorCatalog;
use TicketsRecorrentesHesk\OperationalException;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\ExecutionItemRepository;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceRepository.php';
require dirname(__DIR__) . '/src/RecurrenceExecutionRepository.php';
require dirname(__DIR__) . '/src/ExecutionItemRepository.php';
require dirname(__DIR__) . '/src/OperationalException.php';
require dirname(__DIR__) . '/src/OperationalErrorCatalog.php';

$assertions = 0;
$failures = [];
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if ($expected !== $actual) {
        $failures[] = "{$message}: esperado " . var_export($expected, true) . ', recebido ' . var_export($actual, true);
    }
};

$source = dirname(__DIR__) . '/database/migrations';
$directory = sys_get_temp_dir() . '/tickets-errors-' . bin2hex(random_bytes(8));
$legacyMigrations = $directory . '/legacy-migrations';
mkdir($legacyMigrations, 0700, true);

try {
    foreach (['001_initial_schema.sql', '002_execution_leases.sql', '003_execution_items.sql'] as $file) {
        if (!copy($source . '/' . $file, $legacyMigrations . '/' . $file)) {
            throw new RuntimeException("Não foi possível preparar {$file}.");
        }
    }

    $connection = (new Database($directory . '/legacy.sqlite'))->connect();
    $assertSame(['001_initial_schema', '002_execution_leases', '003_execution_items'],
        (new MigrationRunner($connection, $legacyMigrations))->migrate(), 'Base antiga deve ter somente migrations 001 a 003');

    $recurrence = (new RecurrenceRepository($connection))->create([
        'name' => 'Erro legado isolado', 'enabled' => false, 'timezone' => 'UTC',
        'interval_value' => 1, 'interval_unit' => 'month', 'next_run_at' => '2027-01-01T00:00:00Z',
        'quantity' => 1, 'customer_id' => 100, 'category_id' => 5, 'priority_name' => 'Baixa',
        'status_id' => 0, 'owner_id' => 4, 'openedby_id' => 4, 'subject' => 'Legado',
        'message' => 'Teste local', 'notify_customer' => false, 'custom_fields' => [],
    ]);
    $technical = 'SQLSTATE[HY000] caminho=/segredo/app.sqlite';
    $insertExecution = $connection->prepare(
        "INSERT INTO recurrence_executions
        (recurrence_id, scheduled_for, status, expected_count, created_count, error_message, created_at, updated_at)
        VALUES (:recurrence_id, '2026-01-01T00:00:00Z', 'failed', 1, 0, :technical, :now, :now)"
    );
    $insertExecution->execute(['recurrence_id' => $recurrence['id'], 'technical' => $technical, 'now' => '2026-01-02T00:00:00Z']);
    $executionId = (int) $connection->lastInsertId();
    $insertItem = $connection->prepare(
        "INSERT INTO recurrence_execution_items
        (execution_id, item_index, status, error_message, created_at, updated_at)
        VALUES (:execution_id, 1, 'failed', :technical, :now, :now)"
    );
    $insertItem->execute(['execution_id' => $executionId, 'technical' => $technical, 'now' => '2026-01-02T00:00:00Z']);

    $runner = new MigrationRunner($connection, $source);
    $assertSame(['004_execution_error_codes'], $runner->migrate(), 'Upgrade deve adicionar somente migration 004');
    $assertSame([], $runner->migrate(), 'Migration 004 deve ser idempotente');
    $runner->assertUpToDate();

    $execution = (new RecurrenceExecutionRepository($connection))->findById($executionId);
    $item = (new ExecutionItemRepository($connection))->findByExecution($executionId)[0];
    $assertSame(null, $execution['error_code'], 'Execution legada deve receber código nulo');
    $assertSame(null, $item['error_code'], 'Item legado deve receber código nulo');
    $assertSame($technical, $execution['error_message'], 'Migration deve preservar diagnóstico da execution');
    $assertSame($technical, $item['error_message'], 'Migration deve preservar diagnóstico do item');

    $known = OperationalErrorCatalog::present('HESK_CATEGORY_INVALID');
    $fallback = OperationalErrorCatalog::present(null);
    $assertSame('A categoria configurada não está disponível.', $known['reason'], 'Código conhecido deve produzir motivo curto');
    $assertSame('Confira a categoria da recorrência antes de reprocessar.', $known['next_step'], 'Código conhecido deve orientar próximo passo');
    $assertSame($fallback, OperationalErrorCatalog::present('CODIGO_FUTURO'), 'Código desconhecido deve usar fallback');
    $assertSame($fallback, OperationalErrorCatalog::present($execution['error_code']), 'Legado não deve ser classificado pelo texto');
    $assertSame(false, str_contains(implode(' ', $fallback), $technical), 'Apresentação não deve vazar diagnóstico técnico');
    $assertSame(false, str_contains(implode(' ', $known), $technical), 'Código conhecido não deve vazar diagnóstico técnico');
    $assertSame(1, (new ReflectionMethod(OperationalErrorCatalog::class, 'present'))->getNumberOfParameters(), 'Catálogo só deve receber código');
    $assertSame('UNCLASSIFIED_ERROR', OperationalException::codeOf(new RuntimeException($technical)), 'Exceção desconhecida deve usar fallback estável');
    $assertSame('HESK_CATEGORY_INVALID', OperationalException::codeOf(new OperationalException('HESK_CATEGORY_INVALID', $technical)), 'Exceção estruturada deve carregar código semântico');
    $previous = new RuntimeException('Falha original');
    $structured = new OperationalException('HESK_CATEGORY_INVALID', $technical, $previous);
    $assertSame($previous, $structured->getPrevious(), 'Exceção estruturada deve preservar Throwable anterior');
} finally {
    $connection = null;
    foreach (glob($directory . '/legacy.sqlite*') ?: [] as $file) {
        unlink($file);
    }
    foreach (glob($legacyMigrations . '/*.sql') ?: [] as $file) {
        unlink($file);
    }
    rmdir($legacyMigrations);
    rmdir($directory);
}

if ($failures !== []) {
    fwrite(STDERR, "TESTES ERR-001 FALHARAM\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "TESTES ERR-001 OK ({$assertions} asserções)\n";
