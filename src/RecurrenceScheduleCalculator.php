<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

final class RecurrenceScheduleCalculator
{
    private const INTERVAL_UNITS = ['day', 'week', 'month', 'year'];

    public function calculate(
        string $scheduledForUtc,
        string $timezone,
        int $intervalValue,
        string $intervalUnit,
    ): string {
        if ($intervalValue < 1) {
            throw new DomainException('interval_value deve ser maior que zero.');
        }

        if (!in_array($intervalUnit, self::INTERVAL_UNITS, true)) {
            throw new DomainException('interval_unit deve ser day, week, month ou year.');
        }

        if (!in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new DomainException("timezone não é um identificador PHP/IANA válido: {$timezone}.");
        }

        $normalized = RecurrenceValidator::normalizeUtc($scheduledForUtc, 'scheduled_for');
        $local = (new DateTimeImmutable($normalized))->setTimezone(new DateTimeZone($timezone));

        try {
            $nextLocal = match ($intervalUnit) {
                'day' => $local->add(new DateInterval("P{$intervalValue}D")),
                'week' => $local->add(new DateInterval('P' . ($intervalValue * 7) . 'D')),
                'month' => $this->addMonthsClamped($local, $intervalValue),
                'year' => $this->addYearsClamped($local, $intervalValue),
            };
        } catch (Throwable $error) {
            throw new DomainException(
                "Não foi possível calcular a próxima execução: {$error->getMessage()}",
                0,
                $error
            );
        }

        return $nextLocal
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function addMonthsClamped(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $monthIndex = ((int) $date->format('Y') * 12)
            + ((int) $date->format('n') - 1)
            + $months;
        $targetYear = intdiv($monthIndex, 12);
        $targetMonth = ($monthIndex % 12) + 1;

        return $this->withClampedDate($date, $targetYear, $targetMonth);
    }

    private function addYearsClamped(DateTimeImmutable $date, int $years): DateTimeImmutable
    {
        return $this->withClampedDate(
            $date,
            (int) $date->format('Y') + $years,
            (int) $date->format('n')
        );
    }

    private function withClampedDate(
        DateTimeImmutable $source,
        int $targetYear,
        int $targetMonth,
    ): DateTimeImmutable {
        $timezone = $source->getTimezone();
        $firstDay = DateTimeImmutable::createFromFormat(
            '!Y-n-j H:i:s',
            "{$targetYear}-{$targetMonth}-1 00:00:00",
            $timezone
        );

        if ($firstDay === false) {
            throw new DomainException('O intervalo calculado excede o calendário suportado.');
        }

        $targetDay = min((int) $source->format('j'), (int) $firstDay->format('t'));
        $next = DateTimeImmutable::createFromFormat(
            '!Y-n-j H:i:s',
            sprintf(
                '%d-%d-%d %s',
                $targetYear,
                $targetMonth,
                $targetDay,
                $source->format('H:i:s')
            ),
            $timezone
        );
        $parseState = DateTimeImmutable::getLastErrors();

        if ($next === false
            || (is_array($parseState)
                && ($parseState['warning_count'] > 0 || $parseState['error_count'] > 0))
        ) {
            throw new DomainException('A próxima data civil calculada é inválida.');
        }

        return $next;
    }
}
