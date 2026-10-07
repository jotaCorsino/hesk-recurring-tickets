<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

final class RecurrenceRepository
{
    public function __construct(
        private readonly PDO $connection,
        private readonly RecurrenceValidator $validator = new RecurrenceValidator(),
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $validated = $this->validator->validate(array_merge([
            'enabled' => true,
            'notify_customer' => false,
            'custom_fields' => [],
        ], $data));
        $now = self::utcNow();

        $statement = $this->connection->prepare(
            'INSERT INTO recurrences (
                name, enabled, timezone, interval_value, interval_unit, next_run_at,
                quantity, customer_id, category_id, priority_name, status_id,
                owner_id, openedby_id, subject, message, notify_customer,
                custom_fields_json, created_at, updated_at
            ) VALUES (
                :name, :enabled, :timezone, :interval_value, :interval_unit, :next_run_at,
                :quantity, :customer_id, :category_id, :priority_name, :status_id,
                :owner_id, :openedby_id, :subject, :message, :notify_customer,
                :custom_fields_json, :created_at, :updated_at
            )'
        );
        $statement->execute($this->databaseValues($validated, $now, $now));

        $id = (int) $this->connection->lastInsertId();
        $created = $this->findById($id);

        if ($created === null) {
            throw new RuntimeException("A recorrência {$id} foi inserida, mas não pôde ser relida.");
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

        $statement = $this->connection->prepare('SELECT * FROM recurrences WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $statement = $this->connection->query('SELECT * FROM recurrences ORDER BY id');
        $recurrences = [];

        foreach ($statement->fetchAll() as $row) {
            $recurrences[] = $this->hydrate($row);
        }

        return $recurrences;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findDue(string $nowUtc, int $limit = 100): array
    {
        $nowUtc = RecurrenceValidator::normalizeUtc($nowUtc, 'nowUtc');

        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('limit deve estar entre 1 e 1000.');
        }

        $statement = $this->connection->prepare(
            'SELECT * FROM recurrences
             WHERE enabled = 1 AND next_run_at <= :now_utc
             ORDER BY next_run_at, id
             LIMIT :limit'
        );
        $statement->bindValue('now_utc', $nowUtc);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $recurrences = [];

        foreach ($statement->fetchAll() as $row) {
            $recurrences[] = $this->hydrate($row);
        }

        return $recurrences;
    }

    public function advanceNextRunAt(
        int $id,
        string $expectedScheduledFor,
        string $nextRunAt,
    ): bool {
        if ($id < 1) {
            return false;
        }

        $expectedScheduledFor = RecurrenceValidator::normalizeUtc(
            $expectedScheduledFor,
            'expectedScheduledFor'
        );
        $nextRunAt = RecurrenceValidator::normalizeUtc($nextRunAt, 'nextRunAt');

        $statement = $this->connection->prepare(
            'UPDATE recurrences
             SET next_run_at = :next_run_at, updated_at = :updated_at
             WHERE id = :id AND enabled = 1 AND next_run_at = :expected_scheduled_for'
        );
        $statement->execute([
            'next_run_at' => $nextRunAt,
            'updated_at' => self::utcNow(),
            'id' => $id,
            'expected_scheduled_for' => $expectedScheduledFor,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Atualiza uma recorrência com dados completos ou parciais.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $changes): ?array
    {
        $current = $this->findById($id);

        if ($current === null) {
            return null;
        }

        unset($changes['id'], $changes['created_at'], $changes['updated_at']);
        $validated = $this->validator->validate(array_merge($current, $changes));
        $updatedAt = self::utcNow();
        $values = $this->databaseValues($validated, (string) $current['created_at'], $updatedAt);
        $values['id'] = $id;

        $statement = $this->connection->prepare(
            'UPDATE recurrences SET
                name = :name,
                enabled = :enabled,
                timezone = :timezone,
                interval_value = :interval_value,
                interval_unit = :interval_unit,
                next_run_at = :next_run_at,
                quantity = :quantity,
                customer_id = :customer_id,
                category_id = :category_id,
                priority_name = :priority_name,
                status_id = :status_id,
                owner_id = :owner_id,
                openedby_id = :openedby_id,
                subject = :subject,
                message = :message,
                notify_customer = :notify_customer,
                custom_fields_json = :custom_fields_json,
                updated_at = :updated_at
             WHERE id = :id'
        );

        unset($values['created_at']);
        $statement->execute($values);

        return $this->findById($id);
    }

    /** @param array<string, mixed> $recurrence */
    public static function versionFingerprint(array $recurrence): string
    {
        return hash('sha256', json_encode($recurrence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $changes
     * @return array{status: 'updated'|'missing'|'active'|'conflict', recurrence: ?array}
     */
    public function updateInactiveIfCurrent(
        int $id,
        array $changes,
        string $expectedUpdatedAt,
        string $expectedFingerprint,
    ): array {
        return (new ImmediateTransaction($this->connection))->run(function () use (
            $id, $changes, $expectedUpdatedAt, $expectedFingerprint
        ): array {
            $current = $this->findById($id);

            if ($current === null) {
                return ['status' => 'missing', 'recurrence' => null];
            }

            if ($current['enabled']) {
                return ['status' => 'active', 'recurrence' => $current];
            }

            if (!hash_equals((string) $current['updated_at'], $expectedUpdatedAt)
                || !hash_equals(self::versionFingerprint($current), $expectedFingerprint)) {
                return ['status' => 'conflict', 'recurrence' => $current];
            }

            unset($changes['id'], $changes['enabled'], $changes['created_at'], $changes['updated_at']);
            $validated = $this->validator->validate(array_merge($current, $changes, ['enabled' => false]));
            $updatedAt = self::utcNow();

            if ($updatedAt <= $expectedUpdatedAt) {
                $updatedAt = (new DateTimeImmutable($expectedUpdatedAt))
                    ->modify('+1 second')->format('Y-m-d\TH:i:s\Z');
            }

            $values = $this->databaseValues($validated, (string) $current['created_at'], $updatedAt);
            unset($values['created_at'], $values['enabled']);
            $values['id'] = $id;
            $values['expected_updated_at'] = $expectedUpdatedAt;
            $statement = $this->connection->prepare(
                'UPDATE recurrences SET
                    name = :name, timezone = :timezone, interval_value = :interval_value,
                    interval_unit = :interval_unit, next_run_at = :next_run_at,
                    quantity = :quantity, customer_id = :customer_id, category_id = :category_id,
                    priority_name = :priority_name, status_id = :status_id, owner_id = :owner_id,
                    openedby_id = :openedby_id, subject = :subject, message = :message,
                    notify_customer = :notify_customer, custom_fields_json = :custom_fields_json,
                    updated_at = :updated_at
                 WHERE id = :id AND enabled = 0 AND updated_at = :expected_updated_at'
            );
            $statement->execute($values);

            if ($statement->rowCount() !== 1) {
                return ['status' => 'conflict', 'recurrence' => null];
            }

            return ['status' => 'updated', 'recurrence' => $this->findById($id)];
        });
    }

    /**
     * @return array{status: 'updated'|'missing'|'conflict'|'already_in_target_state', recurrence: ?array}
     */
    public function transitionEnabledIfCurrent(
        int $id,
        bool $expectedEnabled,
        string $expectedUpdatedAt,
        string $expectedFingerprint,
    ): array {
        return (new ImmediateTransaction($this->connection))->run(function () use (
            $id, $expectedEnabled, $expectedUpdatedAt, $expectedFingerprint
        ): array {
            $current = $this->findById($id);

            if ($current === null) {
                return ['status' => 'missing', 'recurrence' => null];
            }

            if (!hash_equals((string) $current['updated_at'], $expectedUpdatedAt)
                || !hash_equals(self::versionFingerprint($current), $expectedFingerprint)) {
                return ['status' => 'conflict', 'recurrence' => $current];
            }

            if ($current['enabled'] !== $expectedEnabled) {
                return ['status' => 'already_in_target_state', 'recurrence' => $current];
            }

            $updatedAt = self::utcNow();

            if ($updatedAt <= $expectedUpdatedAt) {
                $updatedAt = (new DateTimeImmutable($expectedUpdatedAt))
                    ->modify('+1 second')->format('Y-m-d\TH:i:s\Z');
            }

            $statement = $this->connection->prepare(
                'UPDATE recurrences
                 SET enabled = :enabled, updated_at = :updated_at
                 WHERE id = :id AND enabled = :expected_enabled AND updated_at = :expected_updated_at'
            );
            $statement->execute([
                'enabled' => $expectedEnabled ? 0 : 1,
                'updated_at' => $updatedAt,
                'id' => $id,
                'expected_enabled' => $expectedEnabled ? 1 : 0,
                'expected_updated_at' => $expectedUpdatedAt,
            ]);

            if ($statement->rowCount() !== 1) {
                return ['status' => 'conflict', 'recurrence' => null];
            }

            return ['status' => 'updated', 'recurrence' => $this->findById($id)];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setEnabled(int $id, bool $enabled): ?array
    {
        if ($this->findById($id) === null) {
            return null;
        }

        $statement = $this->connection->prepare(
            'UPDATE recurrences
             SET enabled = :enabled, updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => self::utcNow(),
            'id' => $id,
        ]);

        return $this->findById($id);
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, int|string>
     */
    private function databaseValues(array $validated, string $createdAt, string $updatedAt): array
    {
        return [
            'name' => $validated['name'],
            'enabled' => $validated['enabled'] ? 1 : 0,
            'timezone' => $validated['timezone'],
            'interval_value' => $validated['interval_value'],
            'interval_unit' => $validated['interval_unit'],
            'next_run_at' => $validated['next_run_at'],
            'quantity' => $validated['quantity'],
            'customer_id' => $validated['customer_id'],
            'category_id' => $validated['category_id'],
            'priority_name' => $validated['priority_name'],
            'status_id' => $validated['status_id'],
            'owner_id' => $validated['owner_id'],
            'openedby_id' => $validated['openedby_id'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'notify_customer' => $validated['notify_customer'] ? 1 : 0,
            'custom_fields_json' => $this->encodeCustomFields($validated['custom_fields']),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param array<string, string> $customFields
     */
    private function encodeCustomFields(array $customFields): string
    {
        if ($customFields === []) {
            return '{}';
        }

        try {
            return json_encode(
                $customFields,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Falha ao serializar custom_fields.', 0, $error);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        try {
            $customFields = json_decode((string) $row['custom_fields_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(
                "custom_fields_json inválido na recorrência {$row['id']}.",
                0,
                $error
            );
        }

        if (!is_array($customFields)) {
            throw new RuntimeException("custom_fields_json não contém um objeto na recorrência {$row['id']}.");
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'enabled' => (bool) $row['enabled'],
            'timezone' => (string) $row['timezone'],
            'interval_value' => (int) $row['interval_value'],
            'interval_unit' => (string) $row['interval_unit'],
            'next_run_at' => (string) $row['next_run_at'],
            'quantity' => (int) $row['quantity'],
            'customer_id' => (int) $row['customer_id'],
            'category_id' => (int) $row['category_id'],
            'priority_name' => (string) $row['priority_name'],
            'status_id' => (int) $row['status_id'],
            'owner_id' => (int) $row['owner_id'],
            'openedby_id' => (int) $row['openedby_id'],
            'subject' => (string) $row['subject'],
            'message' => (string) $row['message'],
            'notify_customer' => (bool) $row['notify_customer'],
            'custom_fields' => $customFields,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function utcNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
