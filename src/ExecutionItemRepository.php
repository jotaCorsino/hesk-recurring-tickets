<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use DomainException;
use PDO;

final class ExecutionItemRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function materialize(
        int $executionId,
        int $expectedCount,
        string $leaseToken,
        string $nowUtc,
    ): array {
        $this->validateIdentity($executionId, $expectedCount, $leaseToken);
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');

        return (new ImmediateTransaction($this->connection))->run(function () use (
            $executionId,
            $expectedCount,
            $leaseToken,
            $nowUtc,
        ): array {
            if (!$this->ownsActiveLease($executionId, $leaseToken, $nowUtc)) {
                throw new DomainException('O lease não permite materializar itens desta execution.');
            }

            $statement = $this->connection->prepare(
                "INSERT OR IGNORE INTO recurrence_execution_items (
                    execution_id, item_index, status, hesk_trackid, hesk_ticket_id,
                    creation_attempts, last_attempt_at, error_message, created_at, updated_at
                )
                SELECT :execution_id, :item_index, 'pending', NULL, NULL,
                       0, NULL, NULL, :created_at, :updated_at
                WHERE EXISTS (
                    SELECT 1 FROM recurrence_executions
                    WHERE id = :execution_id
                      AND status = 'running'
                      AND lease_token = :lease_token
                      AND lease_expires_at IS NOT NULL
                      AND lease_expires_at > :now_utc
                )"
            );

            for ($itemIndex = 1; $itemIndex <= $expectedCount; $itemIndex++) {
                $statement->execute([
                    'execution_id' => $executionId,
                    'item_index' => $itemIndex,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                    'lease_token' => $leaseToken,
                    'now_utc' => $nowUtc,
                ]);
            }

            if (!$this->ownsActiveLease($executionId, $leaseToken, $nowUtc)) {
                throw new DomainException('O lease foi perdido durante a materialização dos itens.');
            }

            $items = $this->findByExecution($executionId);
            $indexes = array_column($items, 'item_index');

            if ($indexes !== range(1, $expectedCount)) {
                throw new DomainException(
                    'Os itens persistidos não correspondem exatamente ao expected_count da execution.'
                );
            }

            return $items;
        });
    }

    /** @return list<array<string, mixed>> */
    public function findByExecution(int $executionId): array
    {
        if ($executionId < 1) {
            return [];
        }

        $statement = $this->connection->prepare(
            'SELECT * FROM recurrence_execution_items
             WHERE execution_id = :execution_id
             ORDER BY item_index'
        );
        $statement->execute(['execution_id' => $executionId]);

        return array_map(
            fn (array $row): array => $this->hydrate($row),
            $statement->fetchAll()
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $statement = $this->connection->prepare(
            'SELECT * FROM recurrence_execution_items WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function assignTrackingId(
        int $itemId,
        int $executionId,
        string $trackingId,
        string $leaseToken,
        string $nowUtc,
    ): bool {
        if (trim($trackingId) === '') {
            throw new DomainException('trackingId não pode ficar vazio.');
        }

        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            "UPDATE recurrence_execution_items
             SET hesk_trackid = :hesk_trackid,
                 updated_at = :now_utc
             WHERE id = :id
               AND execution_id = :execution_id
               AND hesk_trackid IS NULL
               AND status != 'succeeded'
               AND EXISTS (
                    SELECT 1 FROM recurrence_executions
                    WHERE id = :execution_id
                      AND status = 'running'
                      AND lease_token = :lease_token
                      AND lease_expires_at IS NOT NULL
                      AND lease_expires_at > :now_utc
               )"
        );
        $statement->execute([
            'hesk_trackid' => $trackingId,
            'now_utc' => $nowUtc,
            'id' => $itemId,
            'execution_id' => $executionId,
            'lease_token' => $leaseToken,
        ]);

        return $statement->rowCount() === 1;
    }

    public function markCreating(
        int $itemId,
        int $executionId,
        string $leaseToken,
        string $nowUtc,
    ): bool {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            "UPDATE recurrence_execution_items
             SET status = 'creating',
                 creation_attempts = creation_attempts + 1,
                 last_attempt_at = :now_utc,
                 error_message = NULL,
                 error_code = NULL,
                 updated_at = :now_utc
             WHERE id = :id
               AND execution_id = :execution_id
               AND hesk_trackid IS NOT NULL
               AND status IN ('pending', 'creating', 'failed')
               AND EXISTS (
                    SELECT 1 FROM recurrence_executions
                    WHERE id = :execution_id
                      AND status = 'running'
                      AND lease_token = :lease_token
                      AND lease_expires_at IS NOT NULL
                      AND lease_expires_at > :now_utc
               )"
        );
        $statement->execute([
            'now_utc' => $nowUtc,
            'id' => $itemId,
            'execution_id' => $executionId,
            'lease_token' => $leaseToken,
        ]);

        return $statement->rowCount() === 1;
    }

    public function markSucceeded(
        int $itemId,
        int $executionId,
        string $trackingId,
        int $ticketId,
        string $leaseToken,
        string $nowUtc,
    ): bool {
        if ($ticketId < 1) {
            throw new DomainException('ticketId deve ser inteiro positivo.');
        }

        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            "UPDATE recurrence_execution_items
             SET status = 'succeeded',
                 hesk_ticket_id = :hesk_ticket_id,
                 error_message = NULL,
                 error_code = NULL,
                 updated_at = :now_utc
             WHERE id = :id
               AND execution_id = :execution_id
               AND hesk_trackid = :hesk_trackid
               AND status IN ('pending', 'creating', 'failed')
               AND EXISTS (
                    SELECT 1 FROM recurrence_executions
                    WHERE id = :execution_id
                      AND status = 'running'
                      AND lease_token = :lease_token
                      AND lease_expires_at IS NOT NULL
                      AND lease_expires_at > :now_utc
               )"
        );
        $statement->execute([
            'hesk_ticket_id' => $ticketId,
            'now_utc' => $nowUtc,
            'id' => $itemId,
            'execution_id' => $executionId,
            'hesk_trackid' => $trackingId,
            'lease_token' => $leaseToken,
        ]);

        return $statement->rowCount() === 1;
    }

    public function markFailed(
        int $itemId,
        int $executionId,
        string $errorMessage,
        string $leaseToken,
        string $nowUtc,
        string $errorCode = 'UNCLASSIFIED_ERROR',
    ): bool {
        $errorMessage = trim($errorMessage);

        if ($errorMessage === '') {
            throw new DomainException('errorMessage do item não pode ficar vazio.');
        }

        if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $errorCode)) {
            throw new DomainException('errorCode do item inválido.');
        }

        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            "UPDATE recurrence_execution_items
             SET status = 'failed',
                 error_message = :error_message,
                 error_code = :error_code,
                 updated_at = :now_utc
             WHERE id = :id
               AND execution_id = :execution_id
               AND status != 'succeeded'
               AND EXISTS (
                    SELECT 1 FROM recurrence_executions
                    WHERE id = :execution_id
                      AND status = 'running'
                      AND lease_token = :lease_token
                      AND lease_expires_at IS NOT NULL
                      AND lease_expires_at > :now_utc
               )"
        );
        $statement->execute([
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
            'now_utc' => $nowUtc,
            'id' => $itemId,
            'execution_id' => $executionId,
            'lease_token' => $leaseToken,
        ]);

        return $statement->rowCount() === 1;
    }

    public function countSucceeded(int $executionId): int
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM recurrence_execution_items
             WHERE execution_id = :execution_id AND status = 'succeeded'"
        );
        $statement->execute(['execution_id' => $executionId]);

        return (int) $statement->fetchColumn();
    }

    public function ownsActiveLease(int $executionId, string $leaseToken, string $nowUtc): bool
    {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');
        $statement = $this->connection->prepare(
            "SELECT 1 FROM recurrence_executions
             WHERE id = :execution_id
               AND status = 'running'
               AND lease_token = :lease_token
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at > :now_utc
             LIMIT 1"
        );
        $statement->execute([
            'execution_id' => $executionId,
            'lease_token' => $leaseToken,
            'now_utc' => $nowUtc,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function validateIdentity(int $executionId, int $expectedCount, string $leaseToken): void
    {
        if ($executionId < 1 || $expectedCount < 1) {
            throw new DomainException('executionId e expectedCount devem ser positivos.');
        }

        if (!preg_match('/^[a-f0-9]{64}$/D', $leaseToken)) {
            throw new DomainException('leaseToken inválido para materialização.');
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'execution_id' => (int) $row['execution_id'],
            'item_index' => (int) $row['item_index'],
            'status' => (string) $row['status'],
            'hesk_trackid' => $row['hesk_trackid'] === null ? null : (string) $row['hesk_trackid'],
            'hesk_ticket_id' => $row['hesk_ticket_id'] === null ? null : (int) $row['hesk_ticket_id'],
            'creation_attempts' => (int) $row['creation_attempts'],
            'last_attempt_at' => $row['last_attempt_at'] === null ? null : (string) $row['last_attempt_at'],
            'error_message' => $row['error_message'] === null ? null : (string) $row['error_message'],
            'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
