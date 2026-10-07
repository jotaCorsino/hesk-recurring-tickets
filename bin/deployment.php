<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\DeploymentCliOptions;
use TicketsRecorrentesHesk\DeploymentDatabaseStatus;
use TicketsRecorrentesHesk\DeploymentPreflight;
use TicketsRecorrentesHesk\SqliteBackup;

require_once dirname(__DIR__) . '/src/DeploymentCliOptions.php';
require_once dirname(__DIR__) . '/src/DeploymentPreflight.php';
require_once dirname(__DIR__) . '/src/DeploymentDatabaseStatus.php';
require_once dirname(__DIR__) . '/src/SqliteBackup.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/MigrationRunner.php';
require_once dirname(__DIR__) . '/src/Web/AdminUrl.php';

try {
    $options = DeploymentCliOptions::parse($argv);
    if ($options->command === 'help') {
        fwrite(STDOUT, DeploymentCliOptions::usage() . PHP_EOL);
        exit(0);
    }

    if ($options->command === 'check') {
        $results = (new DeploymentPreflight())->run($options);
        foreach ($results as $result) {
            fwrite(STDOUT, sprintf('[%s] %s: %s%s', $result['status'], $result['check'], $result['message'], PHP_EOL));
        }
        exit(DeploymentPreflight::hasBlockers($results) ? 1 : 0);
    }

    if ($options->command === 'database-status') {
        $status = (new DeploymentDatabaseStatus())->inspect(
            $options->dbPath,
            rtrim($options->projectPath, '/\\') . '/database/migrations',
        );
        $migrations = $status['migrations'];
        $formatList = static fn (array $items): string => $items === [] ? 'nenhuma' : implode(', ', $items);

        fwrite(STDOUT, "DATABASE STATUS\n");
        fwrite(STDOUT, "Banco: {$status['path']}\n");
        fwrite(STDOUT, "Tamanho: {$status['size']} bytes\n");
        fwrite(STDOUT, "SHA-256: {$status['sha256']}\n");
        fwrite(STDOUT, "journal_mode persistido: {$status['journal_mode']}\n");
        fwrite(STDOUT, 'integrity_check: ' . implode('; ', $status['integrity']) . "\n");
        fwrite(STDOUT, "foreign_key_check: {$status['foreign_key_failures']} falha(s)\n");
        foreach (['available' => 'disponíveis', 'applied' => 'aplicadas', 'pending' => 'pendentes', 'divergent' => 'divergentes', 'unknown' => 'desconhecidas'] as $key => $label) {
            fwrite(STDOUT, "Migrations {$label}: " . $formatList($migrations[$key]) . "\n");
        }
        fwrite(STDOUT, "Schema base: {$status['schema']['base']}\n");
        fwrite(STDOUT, "Migration 002 schema: {$status['schema']['migration_002']}\n");
        fwrite(STDOUT, "Migration 003 schema: {$status['schema']['migration_003']}\n");
        fwrite(STDOUT, "Migration 004 schema: {$status['schema']['migration_004']}\n");
        fwrite(STDOUT, "recurrences: {$status['counts']['recurrences']}\n");
        fwrite(STDOUT, "recurrence_executions: {$status['counts']['recurrence_executions']}\n");
        $items = $status['counts']['recurrence_execution_items'];
        fwrite(STDOUT, 'recurrence_execution_items: ' . ($items === null ? 'não disponível (migration 003 pendente)' : (string) $items) . "\n");
        exit(DeploymentDatabaseStatus::hasBlockers($status) ? 1 : 0);
    }

    $backup = (new SqliteBackup())->create(
        $options->dbPath,
        (string) $options->backupPath,
        $options->projectPath,
        $options->publicPath,
        $options->heskPath,
    );
    fwrite(STDOUT, "BACKUP OK\n");
    fwrite(STDOUT, "Origem: {$backup['source_path']}\n");
    fwrite(STDOUT, "Backup: {$backup['backup_path']}\n");
    fwrite(STDOUT, "SHA-256 origem: {$backup['source_sha256']}\n");
    fwrite(STDOUT, "SHA-256 backup: {$backup['backup_sha256']}\n");
    fwrite(STDOUT, "Tamanho backup: {$backup['backup_size']} bytes\n");
    fwrite(STDOUT, "Permissão: {$backup['mode']}\n");
    fwrite(STDOUT, "integrity_check: {$backup['integrity']}\n");
    fwrite(STDOUT, "foreign_key_check: {$backup['foreign_key_failures']} falha(s)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BLOQUEIO: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}
