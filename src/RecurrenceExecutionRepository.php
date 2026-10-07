<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use DomainException;
use PDO;

final class RecurrenceExecutionRepository
{
    private const STATUSES = ['pending', 'running', 'succeeded', 'failed', 'partial'];

    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $validated = $this->validate(array_merge([
            'status' => 'pending',
            'created_count' => 0,
            'started_at' => null,
            'finished_at' => null,
            'error_message' => null,
            'error_code' => null,
        ], $data));
        $now = self::utcNow();

        $statement = $this->connection->prepare(
            'INSERT INTO recurrence_executions (
                recurrence_id, scheduled_for, status, expected_count, created_count,
                started_at, finished_at, error_message, error_code, created_at, updated_at
            ) VALUES (
                :recurrence_id, :scheduled_for, :status, :expected_count, :created_count,
                :started_at, :finished_at, :error_message, :error_code, :created_at, :updated_at
            )'
        );
        $statement->execute(array_merge($validated, [
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $id = (int) $this->connection->lastInsertId();
        $created = $this->findById($id);

        if ($created === null) {
            throw new DomainException("A execução {$id} foi inserida, mas não pôde ser relida.");
        }

        return $created;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $statement = $this->connection->prepare(
            'SELECT * FROM recurrence_executions WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $status = null): array
    {
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new DomainException('Status de execution inválido.');
        }

        if ($status === null) {
            $statement = $this->connection->query(
                'SELECT * FROM recurrence_executions ORDER BY scheduled_for, id'
            );
        } else {
            $statement = $this->connection->prepare(
                'SELECT * FROM recurrence_executions
                 WHERE status = :status
                 ORDER BY scheduled_for, id'
            );
            $statement->execute(['status' => $status]);
        }

        $executions = [];

        foreach ($statement->fetchAll() as $row) {
            $executions[] = $this->hydrate($row);
        }

        return $executions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByRecurrence(int $recurrenceId): array
    {
        if ($recurrenceId < 1) {
            return [];
        }

        $statement = $this->connection->prepare(
            'SELECT * FROM recurrence_executions
             WHERE recurrence_id = :recurrence_id
             ORDER BY scheduled_for, id'
        );
        $statement->execute(['recurrence_id' => $recurrenceId]);
        $executions = [];

        foreach ($statement->fetchAll() as $row) {
            $executions[] = $this->hydrate($row);
        }

        return $executions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRecurrenceAndScheduledFor(
        int $recurrenceId,
        string $scheduledFor,
    ): ?array {
        if ($recurrenceId < 1) {
            return null;
        }

        $scheduledFor = RecurrenceValidator::normalizeUtc($scheduledFor, 'scheduledFor');
        $statement = $this->connection->prepare(
            'SELECT * FROM recurrence_executions
             WHERE recurrence_id = :recurrence_id AND scheduled_for = :scheduled_for
             LIMIT 1'
        );
        $statement->execute([
            'recurrence_id' => $recurrenceId,
            'scheduled_for' => $scheduledFor,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Deve ser chamado dentro de BEGIN IMMEDIATE.
     *
     * @return array<string, mixed>|null
     */
    public function findClaimable(string $nowUtc, ?int $executionId = null): ?array
    {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $idFilter = $executionId === null ? '' : ' AND id = :id';
        $statement = $this->connection->prepare(
            "SELECT * FROM recurrence_executions
             WHERE (
                status = 'pending'
                OR (
                    status = 'running'
                    AND lease_token IS NOT NULL
                    AND lease_owner IS NOT NULL
                    AND lease_expires_at IS NOT NULL
                    AND lease_expires_at <= :now_utc
                )
             ){$idFilter}
             ORDER BY scheduled_for, id
             LIMIT 1"
        );
        $statement->bindValue('now_utc', $nowUtc);

        if ($executionId !== null) {
            $statement->bindValue('id', $executionId, PDO::PARAM_INT);
        }

        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Retorna uma fotografia finita e determinística das execuções elegíveis.
     * O claim efetivo continua sendo feito, uma a uma, por ExecutionLeaseService.
     *
     * @return list<int>
     */
    public function findClaimableIds(string $nowUtc, int $limit): array
    {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');

        if ($limit < 1 || $limit > 100) {
            throw new DomainException('limit de executions deve estar entre 1 e 100.');
        }

        $statement = $this->connection->prepare(
            "SELECT id FROM recurrence_executions
             WHERE (
                status = 'pending'
                OR (
                    status = 'running'
                    AND lease_token IS NOT NULL
                    AND lease_owner IS NOT NULL
                    AND lease_expires_at IS NOT NULL
                    AND lease_expires_at <= :now_utc
                )
             )
             ORDER BY scheduled_for, id
             LIMIT :limit"
        );
        $statement->bindValue('now_utc', $nowUtc);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function countClaimable(string $nowUtc): int
    {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM recurrence_executions
             WHERE (
                status = 'pending'
                OR (
                    status = 'running'
                    AND lease_token IS NOT NULL
                    AND lease_owner IS NOT NULL
                    AND lease_expires_at IS NOT NULL
                    AND lease_expires_at <= :now_utc
                )
             )"
        );
        $statement->execute(['now_utc' => $nowUtc]);

        return (int) $statement->fetchColumn();
    }

    /** Deve ser chamado dentro de BEGIN IMMEDIATE. */
    public function claim(
        int $executionId,
        string $nowUtc,
        string $leaseExpiresAt,
        string $leaseToken,
        string $leaseOwner,
    ): bool {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $leaseExpiresAt = RecurrenceValidator::normalizeUtc($leaseExpiresAt, 'leaseExpiresAt');
        $statement = $this->connection->prepare(
            "UPDATE recurrence_executions
             SET status = 'running',
                 attempt_count = attempt_count + 1,
                 lease_token = :lease_token,
                 lease_owner = :lease_owner,
                 lease_expires_at = :lease_expires_at,
                 last_attempt_at = :now_utc,
                 started_at = :now_utc,
                 finished_at = NULL,
                 error_message = NULL,
                 error_code = NULL,
                 updated_at = :now_utc
             WHERE id = :id
               AND (
                    status = 'pending'
                    OR (
                        status = 'running'
                        AND lease_token IS NOT NULL
                        AND lease_owner IS NOT NULL
                        AND lease_expires_at IS NOT NULL
                        AND lease_expires_at <= :now_utc
                    )
               )"
        );
        $statement->execute([
            'lease_token' => $leaseToken,
            'lease_owner' => $leaseOwner,
            'lease_expires_at' => $leaseExpiresAt,
            'now_utc' => $nowUtc,
            'id' => $executionId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function renewLease(
        int $executionId,
        string $leaseToken,
        string $nowUtc,
        string $leaseExpiresAt,
    ): bool {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $leaseExpiresAt = RecurrenceValidator::normalizeUtc($leaseExpiresAt, 'leaseExpiresAt');
        $statement = $this->connection->prepare(
            "UPDATE recurrence_executions
             SET lease_expires_at = :lease_expires_at,
                 updated_at = :now_utc
             WHERE id = :id
               AND status = 'running'
               AND lease_token = :lease_token
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at > :now_utc"
        );
        $statement->execute([
            'lease_expires_at' => $leaseExpiresAt,
            'now_utc' => $nowUtc,
            'id' => $executionId,
            'lease_token' => $leaseToken,
        ]);

        return $statement->rowCount() === 1;
    }

    public function finish(
        int $executionId,
        string $leaseToken,
        string $result,
        ?string $errorMessage,
        string $nowUtc,
        ?string $errorCode = null,
    ): bool {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            'UPDATE recurrence_executions
             SET status = :result,
                 lease_token = NULL,
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 finished_at = :now_utc,
                 error_message = :error_message,
                 error_code = :error_code,
                 updated_at = :now_utc
             WHERE id = :id
               AND status = \'running\'
               AND lease_token = :lease_token
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at > :now_utc'
        );
        $statement->execute([
            'result' => $result,
            'now_utc' => $nowUtc,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
            'id' => $executionId,
            'lease_token' => $leaseToken,
        ]);

        return $statement->rowCount() === 1;
    }

    public function retry(int $executionId, string $nowUtc): bool
    {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            "UPDATE recurrence_executions
             SET status = 'pending',
                 lease_token = NULL,
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 started_at = NULL,
                 finished_at = NULL,
                 error_message = NULL,
                 error_code = NULL,
                 updated_at = :now_utc
             WHERE id = :id AND status IN ('failed', 'partial')"
        );
        $statement->execute([
            'now_utc' => $nowUtc,
            'id' => $executionId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function ownsActiveLease(int $executionId, string $leaseToken, string $nowUtc): bool
    {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            "SELECT 1 FROM recurrence_executions
             WHERE id = :id
               AND status = 'running'
               AND lease_token = :lease_token
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at > :now_utc
             LIMIT 1"
        );
        $statement->execute([
            'id' => $executionId,
            'lease_token' => $leaseToken,
            'now_utc' => $nowUtc,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function syncCreatedCount(
        int $executionId,
        string $leaseToken,
        string $nowUtc,
    ): ?int {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            "UPDATE recurrence_executions
             SET created_count = (
                    SELECT COUNT(*) FROM recurrence_execution_items
                    WHERE execution_id = :id AND status = 'succeeded'
                 ),
                 updated_at = :now_utc
             WHERE id = :id
               AND status = 'running'
               AND lease_token = :lease_token
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at > :now_utc
               AND (
                    SELECT COUNT(*) FROM recurrence_execution_items
                    WHERE execution_id = :id AND status = 'succeeded'
               ) <= expected_count"
        );
        $statement->execute([
            'id' => $executionId,
            'now_utc' => $nowUtc,
            'lease_token' => $leaseToken,
        ]);

        if ($statement->rowCount() !== 1) {
            return null;
        }

        $execution = $this->findById($executionId);

        return $execution === null ? null : (int) $execution['created_count'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int|string|null>
     */
    private function validate(array $data): array
    {
        $errors = [];

        $recurrenceId = $this->integer($data, 'recurrence_id', 1, $errors);
        $expectedCount = $this->integer($data, 'expected_count', 1, $errors);
        $createdCount = $this->integer($data, 'created_count', 0, $errors);

        if ($createdCount > $expectedCount) {
            $errors[] = 'created_count não pode ser maior que expected_count.';
        }

        $status = $data['status'] ?? null;

        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            $errors[] = 'status de execução inválido.';
            $status = 'pending';
        }

        $scheduledFor = $this->normalizeRequiredDate($data['scheduled_for'] ?? null, 'scheduled_for', $errors);
        $startedAt = $this->normalizeOptionalDate($data['started_at'], 'started_at', $errors);
        $finishedAt = $this->normalizeOptionalDate($data['finished_at'], 'finished_at', $errors);
        $errorMessage = $data['error_message'];
        $errorCode = $data['error_code'];

        if ($errorMessage !== null && !is_string($errorMessage)) {
            $errors[] = 'error_message deve ser texto ou null.';
            $errorMessage = null;
        }

        if ($errorCode !== null && (!is_string($errorCode) || !preg_match('/^[A-Z][A-Z0-9_]*$/D', $errorCode))) {
            $errors[] = 'error_code deve ser código semântico ou null.';
            $errorCode = null;
        }

        if ($errors !== []) {
            throw new DomainException("Execução de recorrência inválida:\n- " . implode("\n- ", $errors));
        }

        return [
            'recurrence_id' => $recurrenceId,
            'scheduled_for' => $scheduledFor,
            'status' => $status,
            'expected_count' => $expectedCount,
            'created_count' => $createdCount,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $errors
     */
    private function integer(array $data, string $key, int $minimum, array &$errors): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value) || $value < $minimum) {
            $errors[] = "{$key} deve ser inteiro maior ou igual a {$minimum}.";
            return $minimum;
        }

        return $value;
    }

    /** @param list<string> $errors */
    private function normalizeRequiredDate(mixed $value, string $key, array &$errors): string
    {
        try {
            return RecurrenceValidator::normalizeUtc($value, $key);
        } catch (DomainException $error) {
            $errors[] = $error->getMessage();
            return '';
        }
    }

    /** @param list<string> $errors */
    private function normalizeOptionalDate(mixed $value, string $key, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->normalizeRequiredDate($value, $key, $errors);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'recurrence_id' => (int) $row['recurrence_id'],
            'scheduled_for' => (string) $row['scheduled_for'],
            'status' => (string) $row['status'],
            'expected_count' => (int) $row['expected_count'],
            'created_count' => (int) $row['created_count'],
            'started_at' => $row['started_at'] === null ? null : (string) $row['started_at'],
            'finished_at' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
            'error_message' => $row['error_message'] === null ? null : (string) $row['error_message'],
            'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
            'attempt_count' => (int) $row['attempt_count'],
            'lease_owner' => $row['lease_owner'] === null ? null : (string) $row['lease_owner'],
            'lease_expires_at' => $row['lease_expires_at'] === null
                ? null
                : (string) $row['lease_expires_at'],
            'last_attempt_at' => $row['last_attempt_at'] === null
                ? null
                : (string) $row['last_attempt_at'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function utcNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
