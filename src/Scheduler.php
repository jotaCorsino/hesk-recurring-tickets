<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class Scheduler
{
    public function __construct(
        private readonly PDO $connection,
        private readonly RecurrenceRepository $recurrences,
        private readonly RecurrenceExecutionRepository $executions,
        private readonly RecurrenceScheduleCalculator $calculator,
    ) {
    }

    /** @return array<string, mixed> */
    public function check(DateTimeImmutable $now, int $limit = 100): array
    {
        $nowUtc = $this->nowUtc($now);
        $due = $this->recurrences->findDue($nowUtc, $this->validateLimit($limit));
        $candidates = [];
        $errors = [];

        foreach ($due as $recurrence) {
            try {
                $candidates[] = $this->candidate($recurrence);
            } catch (Throwable $error) {
                $errors[] = $this->errorResult($recurrence, $error);
            }
        }

        return [
            'mode' => 'check',
            'now_utc' => $nowUtc,
            'due_count' => count($due),
            'candidates' => $candidates,
            'processed' => [],
            'skipped' => [],
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    public function run(DateTimeImmutable $now, int $limit = 100): array
    {
        $nowUtc = $this->nowUtc($now);
        $due = $this->recurrences->findDue($nowUtc, $this->validateLimit($limit));
        $processed = [];
        $skipped = [];
        $errors = [];

        foreach ($due as $recurrence) {
            $scheduledFor = (string) $recurrence['next_run_at'];

            try {
                $this->connection->beginTransaction();
                $existing = $this->executions->findByRecurrenceAndScheduledFor(
                    (int) $recurrence['id'],
                    $scheduledFor
                );

                if ($existing !== null) {
                    $this->connection->rollBack();
                    $skipped[] = $this->duplicateResult($recurrence, $existing);
                    continue;
                }

                $candidate = $this->candidate($recurrence);
                $execution = $this->executions->create([
                    'recurrence_id' => (int) $recurrence['id'],
                    'scheduled_for' => $scheduledFor,
                    'status' => 'pending',
                    'expected_count' => (int) $recurrence['quantity'],
                    'created_count' => 0,
                    'started_at' => null,
                    'finished_at' => null,
                    'error_message' => null,
                ]);

                if (!$this->recurrences->advanceNextRunAt(
                    (int) $recurrence['id'],
                    $scheduledFor,
                    (string) $candidate['next_run_at']
                )) {
                    throw new RuntimeException(
                        'A recorrência mudou durante o processamento; a transação foi revertida.'
                    );
                }

                $this->connection->commit();
                $processed[] = array_merge($candidate, [
                    'execution_id' => (int) $execution['id'],
                    'status' => (string) $execution['status'],
                ]);
            } catch (Throwable $error) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                $existing = $this->executions->findByRecurrenceAndScheduledFor(
                    (int) $recurrence['id'],
                    $scheduledFor
                );

                if ($existing !== null) {
                    $skipped[] = $this->duplicateResult($recurrence, $existing);
                    continue;
                }

                $errors[] = $this->errorResult($recurrence, $error);
            }
        }

        return [
            'mode' => 'run',
            'now_utc' => $nowUtc,
            'due_count' => count($due),
            'candidates' => [],
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $recurrence
     * @return array<string, int|string>
     */
    private function candidate(array $recurrence): array
    {
        return [
            'recurrence_id' => (int) $recurrence['id'],
            'name' => (string) $recurrence['name'],
            'scheduled_for' => (string) $recurrence['next_run_at'],
            'next_run_at' => $this->calculator->calculate(
                (string) $recurrence['next_run_at'],
                (string) $recurrence['timezone'],
                (int) $recurrence['interval_value'],
                (string) $recurrence['interval_unit']
            ),
        ];
    }

    /**
     * @param array<string, mixed> $recurrence
     * @param array<string, mixed> $execution
     * @return array<string, int|string>
     */
    private function duplicateResult(array $recurrence, array $execution): array
    {
        return [
            'recurrence_id' => (int) $recurrence['id'],
            'name' => (string) $recurrence['name'],
            'execution_id' => (int) $execution['id'],
            'scheduled_for' => (string) $recurrence['next_run_at'],
            'reason' => 'execution_already_exists',
        ];
    }

    /**
     * @param array<string, mixed> $recurrence
     * @return array<string, int|string>
     */
    private function errorResult(array $recurrence, Throwable $error): array
    {
        return [
            'recurrence_id' => (int) $recurrence['id'],
            'name' => (string) $recurrence['name'],
            'scheduled_for' => (string) $recurrence['next_run_at'],
            'message' => $error->getMessage(),
        ];
    }

    private function validateLimit(int $limit): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('limit deve estar entre 1 e 1000.');
        }

        return $limit;
    }

    private function nowUtc(DateTimeImmutable $now): string
    {
        return $now
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
