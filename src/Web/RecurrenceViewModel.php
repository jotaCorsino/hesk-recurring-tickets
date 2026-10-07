<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use TicketsRecorrentesHesk\HeskReferenceData;
use TicketsRecorrentesHesk\RecurrenceValidator;
use Throwable;

final class RecurrenceViewModel
{
    /** @param array<string, mixed> $recurrence
     *  @return array<string, mixed>
     */
    public static function present(array $recurrence, HeskReferenceData $catalog): array
    {
        if (!is_array($recurrence['custom_fields'])) {
            throw new RuntimeException('custom_fields inválidos na recorrência #' . (int) $recurrence['id']);
        }

        foreach ($recurrence['custom_fields'] as $key => $fieldValue) {
            if (!is_string($key) || !preg_match('/^custom(?:[1-9]|[1-9][0-9]|100)$/D', $key) || !is_string($fieldValue)) {
                throw new RuntimeException('custom_fields inválidos na recorrência #' . (int) $recurrence['id']);
            }
        }

        $value = (int) $recurrence['interval_value'];
        $unit = (string) $recurrence['interval_unit'];
        $unitLabel = match ($unit) {
            'day' => $value === 1 ? 'dia' : 'dias',
            'week' => $value === 1 ? 'semana' : 'semanas',
            'month' => $value === 1 ? 'mês' : 'meses',
            'year' => $value === 1 ? 'ano' : 'anos',
            default => null,
        };

        $next = 'Data indisponível';
        $nextLocal = '';

        try {
            $timezone = new DateTimeZone((string) $recurrence['timezone']);
            $instant = new DateTimeImmutable(RecurrenceValidator::normalizeUtc($recurrence['next_run_at'], 'next_run_at'));
            $local = $instant->setTimezone($timezone);
            $next = $local->format('d/m/Y H:i');
            $nextLocal = $local->format('Y-m-d\TH:i');
        } catch (Throwable $error) {
            error_log('UI-001B: data ou timezone inválida na recorrência #' . (int) $recurrence['id'] . ': ' . $error->getMessage());
        }

        return array_merge($recurrence, [
            'status' => $recurrence['enabled'] ? 'Ativa' : 'Inativa',
            'frequency' => $unitLabel === null ? 'Frequência indisponível' : "A cada {$value} {$unitLabel}",
            'next' => $next,
            'next_local' => $nextLocal,
        ], HeskCatalogViewModel::recurrence($catalog, $recurrence));
    }
}
