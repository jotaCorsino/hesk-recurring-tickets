<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use PDO;
use RuntimeException;

final class DeploymentDatabaseStatus
{
    private const COUNTED_TABLES = [
        'recurrences',
        'recurrence_executions',
        'recurrence_execution_items',
    ];

    /**
     * @return array{
     *   path: string,
     *   size: int,
     *   sha256: string,
     *   journal_mode: string,
     *   integrity: list<string>,
     *   foreign_key_failures: int,
     *   migrations: array{
     *     initialized: bool,
     *     available: list<string>,
     *     applied: list<string>,
     *     pending: list<string>,
     *     divergent: list<string>,
     *     unknown: list<string>
     *   },
     *   schema: array{
     *     base: 'OK'|'INCONSISTENTE',
     *     migration_002: 'OK'|'PENDENTE'|'INCONSISTENTE',
     *     migration_003: 'OK'|'PENDENTE'|'INCONSISTENTE',
     *     migration_004: 'OK'|'PENDENTE'|'INCONSISTENTE'
     *   },
     *   counts: array<string, int|null>
     * }
     */
    public function inspect(string $dbPath, string $migrationsPath): array
    {
        $resolvedPath = realpath($dbPath);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException("Banco SQLite inexistente ou ilegível: {$dbPath}");
        }

        $size = filesize($resolvedPath);
        $sha256 = hash_file('sha256', $resolvedPath);
        if ($size === false || $sha256 === false) {
            throw new RuntimeException('Não foi possível medir o banco SQLite.');
        }

        $database = new Database($resolvedPath);
        $journalMode = $database->persistedJournalMode();
        $connection = $database->connectImmutableReadOnly();
        $integrity = array_map(
            static fn (mixed $value): string => (string) $value,
            $connection->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN),
        );
        $foreignKeyFailures = count($connection->query('PRAGMA foreign_key_check')->fetchAll());
        $migrations = (new MigrationRunner($connection, $migrationsPath))->inspect();
        $schema = $this->inspectSchema($connection, $migrations['applied']);
        $counts = [];

        foreach (self::COUNTED_TABLES as $table) {
            $exists = $connection->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1"
            );
            $exists->execute(['name' => $table]);
            $counts[$table] = $exists->fetchColumn() === false
                ? null
                : (int) $connection->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }

        $sha256After = hash_file('sha256', $resolvedPath);
        $walPath = $resolvedPath . '-wal';
        if ($sha256After === false || !hash_equals($sha256, $sha256After)) {
            throw new RuntimeException('O banco SQLite mudou durante a inspeção; descarte o relatório e interrompa a operação.');
        }
        clearstatcache(true, $walPath);
        if (is_file($walPath) && (filesize($walPath) ?: 0) > 0) {
            throw new RuntimeException('Surgiu WAL pendente durante a inspeção; descarte o relatório e interrompa a operação.');
        }

        return [
            'path' => $resolvedPath,
            'size' => $size,
            'sha256' => $sha256,
            'journal_mode' => $journalMode,
            'integrity' => $integrity,
            'foreign_key_failures' => $foreignKeyFailures,
            'migrations' => $migrations,
            'schema' => $schema,
            'counts' => $counts,
        ];
    }

    /** @param array<string, mixed> $status */
    public static function hasBlockers(array $status): bool
    {
        $migrations = is_array($status['migrations'] ?? null) ? $status['migrations'] : [];
        $schema = is_array($status['schema'] ?? null) ? $status['schema'] : [];

        return ($status['journal_mode'] ?? null) !== 'wal'
            || ($status['integrity'] ?? null) !== ['ok']
            || ($status['foreign_key_failures'] ?? 1) !== 0
            || ($migrations['initialized'] ?? false) !== true
            || ($migrations['divergent'] ?? []) !== []
            || ($migrations['unknown'] ?? []) !== []
            || count($schema) !== 4
            || in_array('INCONSISTENTE', $schema, true);
    }

    /**
     * @param list<string> $applied
     * @return array{
     *   base: 'OK'|'INCONSISTENTE',
     *   migration_002: 'OK'|'PENDENTE'|'INCONSISTENTE',
     *   migration_003: 'OK'|'PENDENTE'|'INCONSISTENTE',
     *   migration_004: 'OK'|'PENDENTE'|'INCONSISTENTE'
     * }
     */
    private function inspectSchema(PDO $connection, array $applied): array
    {
        $recurrencesExists = $this->tableExists($connection, 'recurrences');
        $executionsExists = $this->tableExists($connection, 'recurrence_executions');
        $itemsExists = $this->tableExists($connection, 'recurrence_execution_items');

        $executionColumns = $this->columns($connection, 'recurrence_executions');
        $itemColumns = $this->columns($connection, 'recurrence_execution_items');
        $leaseColumns = ['attempt_count', 'lease_token', 'lease_owner', 'lease_expires_at', 'last_attempt_at'];
        $leaseParts = array_map(
            static fn (string $column): bool => in_array($column, $executionColumns, true),
            $leaseColumns,
        );
        $claimIndex = $this->indexExists($connection, 'idx_recurrence_executions_claimable');
        $migration002Parts = [...$leaseParts, $claimIndex];

        $itemsIndex = $this->indexExists($connection, 'idx_recurrence_execution_items_status');
        $migration003Parts = [$itemsExists, $itemsIndex];
        $migration004Parts = [
            in_array('error_code', $executionColumns, true),
            in_array('error_code', $itemColumns, true),
        ];

        return [
            'base' => in_array('001_initial_schema', $applied, true)
                && $recurrencesExists
                && $executionsExists
                    ? 'OK'
                    : 'INCONSISTENTE',
            'migration_002' => $this->migrationSchemaStatus(
                in_array('002_execution_leases', $applied, true),
                $migration002Parts,
            ),
            'migration_003' => $this->migrationSchemaStatus(
                in_array('003_execution_items', $applied, true),
                $migration003Parts,
            ),
            'migration_004' => $this->migrationSchemaStatus(
                in_array('004_execution_error_codes', $applied, true),
                $migration004Parts,
            ),
        ];
    }

    /** @param list<bool> $parts */
    private function migrationSchemaStatus(bool $applied, array $parts): string
    {
        $present = count(array_filter($parts, static fn (bool $part): bool => $part));

        if ($applied) {
            return $present === count($parts) ? 'OK' : 'INCONSISTENTE';
        }

        return $present === 0 ? 'PENDENTE' : 'INCONSISTENTE';
    }

    private function tableExists(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1"
        );
        $statement->execute(['name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<string> */
    private function columns(PDO $connection, string $table): array
    {
        if (!$this->tableExists($connection, $table)) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $connection->query("PRAGMA table_info(`{$table}`)")->fetchAll(),
        );
    }

    private function indexExists(PDO $connection, string $index): bool
    {
        $statement = $connection->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :name LIMIT 1"
        );
        $statement->execute(['name' => $index]);

        return $statement->fetchColumn() !== false;
    }
}
