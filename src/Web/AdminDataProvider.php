<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceRepository;
use PDO;
use RuntimeException;
use Throwable;

final class AdminDataProvider
{
    private ?PDO $connection = null;
    private ?RecurrenceRepository $recurrences = null;
    private bool $migrationsVerified = false;

    public function __construct(private readonly string $databasePath)
    {
    }

    public static function fromEnvironment(): self
    {
        $configuredPath = getenv('APP_DB_PATH');
        $path = $configuredPath === false
            ? dirname(__DIR__, 2) . '/storage/app.sqlite'
            : $configuredPath;

        return new self($path);
    }

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        return $this->repository()->findAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->repository()->findById($id);
    }

    public function assertReady(): void
    {
        $this->repository();
    }

    /**
     * Verifica a infraestrutura da aplicação sem criar banco ou aplicar migrations.
     *
     * @return array{database_available: bool, migrations_up_to_date: bool|null}
     */
    public function systemStatus(): array
    {
        try {
            $this->readOnlyConnection();
        } catch (Throwable $error) {
            error_log('UI-002: falha no acesso somente leitura ao SQLite: ' . $error->getMessage());

            return [
                'database_available' => false,
                'migrations_up_to_date' => null,
            ];
        }

        try {
            $this->assertMigrationsUpToDate();
        } catch (Throwable $error) {
            error_log('UI-002: migrations da aplicação requerem atenção: ' . $error->getMessage());

            return [
                'database_available' => true,
                'migrations_up_to_date' => false,
            ];
        }

        return [
            'database_available' => true,
            'migrations_up_to_date' => true,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRecentExecutionHistory(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new RuntimeException('O limite do histórico deve ficar entre 1 e 50.');
        }

        $connection = $this->connection();
        $statement = $connection->prepare(
            'SELECT
                execution.id,
                execution.recurrence_id,
                recurrence.name AS recurrence_name,
                execution.scheduled_for,
                execution.status,
                execution.expected_count,
                execution.created_count,
                execution.attempt_count,
                execution.started_at,
                execution.last_attempt_at,
                execution.finished_at,
                execution.error_code
             FROM recurrence_executions AS execution
             INNER JOIN recurrences AS recurrence ON recurrence.id = execution.recurrence_id
             ORDER BY execution.scheduled_for DESC, execution.id DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $executions = $statement->fetchAll();

        if ($executions === []) {
            return [];
        }

        $executionIds = array_map(static fn (array $row): int => (int) $row['id'], $executions);
        $placeholders = implode(', ', array_fill(0, count($executionIds), '?'));
        $itemsStatement = $connection->prepare(
            'SELECT
                execution_id,
                item_index,
                status,
                hesk_trackid,
                hesk_ticket_id,
                creation_attempts,
                last_attempt_at,
                error_code
             FROM recurrence_execution_items
             WHERE execution_id IN (' . $placeholders . ')
             ORDER BY execution_id DESC, item_index'
        );

        foreach ($executionIds as $index => $executionId) {
            $itemsStatement->bindValue($index + 1, $executionId, PDO::PARAM_INT);
        }

        $itemsStatement->execute();
        $itemsByExecution = [];

        foreach ($itemsStatement->fetchAll() as $item) {
            $itemsByExecution[(int) $item['execution_id']][] = $item;
        }

        return array_map(static function (array $execution) use ($itemsByExecution): array {
            $execution['items'] = $itemsByExecution[(int) $execution['id']] ?? [];
            return $execution;
        }, $executions);
    }

    private function repository(): RecurrenceRepository
    {
        if ($this->recurrences !== null) {
            return $this->recurrences;
        }

        $this->recurrences = new RecurrenceRepository($this->connection());

        return $this->recurrences;
    }

    private function connection(): PDO
    {
        $connection = $this->readOnlyConnection();
        $this->assertMigrationsUpToDate();

        return $connection;
    }

    private function readOnlyConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connection = (new Database($this->databasePath))->connectReadOnly();
        }

        return $this->connection;
    }

    private function assertMigrationsUpToDate(): void
    {
        if ($this->migrationsVerified) {
            return;
        }

        (new MigrationRunner(
            $this->readOnlyConnection(),
            dirname(__DIR__, 2) . '/database/migrations'
        ))->assertUpToDate();
        $this->migrationsVerified = true;
    }
}
