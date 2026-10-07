<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use DateTimeImmutable;
use TicketsRecorrentesHesk\OperationalErrorCatalog;
use TicketsRecorrentesHesk\RecurrenceValidator;
use Throwable;

final class ExecutionHistoryViewModel
{
    private const EXECUTION_STATUS_LABELS = [
        'pending' => 'Pendente',
        'running' => 'Em andamento',
        'succeeded' => 'Concluída',
        'failed' => 'Falhou',
        'partial' => 'Parcial',
    ];

    private const ITEM_STATUS_LABELS = [
        'pending' => 'Pendente',
        'creating' => 'Criando',
        'succeeded' => 'Concluído',
        'failed' => 'Falhou',
    ];

    /**
     * @param list<array<string, mixed>> $executions
     * @return list<array<string, mixed>>
     */
    public static function presentAll(array $executions): array
    {
        return array_map(self::present(...), $executions);
    }

    /**
     * @param array<string, mixed> $execution
     * @return array<string, mixed>
     */
    private static function present(array $execution): array
    {
        $id = (int) $execution['id'];
        $status = (string) $execution['status'];
        $items = is_array($execution['items']) ? $execution['items'] : [];
        $error = in_array($status, ['failed', 'partial'], true)
            ? OperationalErrorCatalog::present(self::nullableString($execution['error_code']))
            : null;

        return [
            'id' => $id,
            'recurrence_id' => (int) $execution['recurrence_id'],
            'recurrence' => (string) $execution['recurrence_name'],
            'scheduled_for' => self::date($execution['scheduled_for'], $id, 'scheduled_for'),
            'status' => $status,
            'status_label' => self::EXECUTION_STATUS_LABELS[$status] ?? 'Status indisponível',
            'expected_count' => (int) $execution['expected_count'],
            'created_count' => (int) $execution['created_count'],
            'attempt_count' => (int) $execution['attempt_count'],
            'started_at' => self::date($execution['started_at'], $id, 'started_at'),
            'last_attempt_at' => self::date($execution['last_attempt_at'], $id, 'last_attempt_at'),
            'finished_at' => self::date($execution['finished_at'], $id, 'finished_at'),
            'error' => $error,
            'items' => array_map(
                static fn (array $item): array => self::presentItem($item, $id),
                $items
            ),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function presentItem(array $item, int $executionId): array
    {
        $status = (string) $item['status'];

        return [
            'item_index' => (int) $item['item_index'],
            'status' => $status,
            'status_label' => self::ITEM_STATUS_LABELS[$status] ?? 'Status indisponível',
            'hesk_ticket_id' => $item['hesk_ticket_id'] === null ? null : (int) $item['hesk_ticket_id'],
            'hesk_trackid' => self::nullableString($item['hesk_trackid']),
            'creation_attempts' => (int) $item['creation_attempts'],
            'last_attempt_at' => self::date($item['last_attempt_at'], $executionId, 'item.last_attempt_at'),
            'error' => $status === 'failed'
                ? OperationalErrorCatalog::present(self::nullableString($item['error_code']))
                : null,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function date(mixed $value, int $executionId, string $field): string
    {
        if ($value === null) {
            return '—';
        }

        try {
            $normalized = RecurrenceValidator::normalizeUtc($value, $field);
            return (new DateTimeImmutable($normalized))->format('d/m/Y H:i') . ' UTC';
        } catch (Throwable $error) {
            error_log("UI-001E: data inválida em {$field} da execution #{$executionId}: " . $error->getMessage());
            return 'Data indisponível';
        }
    }
}
