<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class Orchestrator
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_OPERATIONAL_FAILURE = 1;
    public const EXIT_USAGE_OR_CONFIGURATION = 2;
    public const EXIT_LOCK_BUSY = 3;

    /**
     * @param Closure(): BatchProcessor $processorFactory
     * @param null|Closure(): DateTimeImmutable $clock
     */
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly RecurrenceExecutionRepository $executions,
        private readonly ExecutionLeaseService $leases,
        private readonly OrchestratorLock $lock,
        private readonly Closure $processorFactory,
        private readonly ?Closure $clock = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function check(DateTimeImmutable $now, int $schedulerLimit = 10): array
    {
        $nowUtc = $this->utc($now);
        $scheduler = $this->scheduler->check($now, $schedulerLimit);

        return [
            'mode' => 'check',
            'now_utc' => $nowUtc,
            'due_recurrences' => (int) $scheduler['due_count'],
            'potential_executions' => $this->executions->countClaimable($nowUtc),
            'scheduler_errors' => count($scheduler['errors']),
            'exit_code' => $scheduler['errors'] === []
                ? self::EXIT_SUCCESS
                : self::EXIT_OPERATIONAL_FAILURE,
        ];
    }

    /** @return array<string, mixed> */
    public function run(
        DateTimeImmutable $now,
        int $schedulerLimit,
        int $executionLimit,
        string $worker,
        int $leaseSeconds = 300,
    ): array {
        $report = $this->baseReport('run', $now);

        try {
            if (!$this->lock->acquire()) {
                $report['lock_busy'] = true;
                $report['exit_code'] = self::EXIT_LOCK_BUSY;
                return $report;
            }
        } catch (Throwable) {
            $report['errors'][] = 'ORCHESTRATOR_LOCK_ERROR';
            $report['exit_code'] = self::EXIT_OPERATIONAL_FAILURE;
            return $report;
        }

        try {
            $scheduler = $this->scheduler->run($now, $schedulerLimit);
            $report['scheduled'] = count($scheduler['processed']);
            $report['scheduler_skipped'] = count($scheduler['skipped']);
            $report['scheduler_errors'] = count($scheduler['errors']);

            if ($scheduler['errors'] !== []) {
                $report['errors'][] = 'SCHEDULER_PARTIAL_FAILURE';
            }

            $ids = $this->executions->findClaimableIds(
                $this->utc($this->now()),
                $executionLimit
            );
            $report['selected'] = count($ids);

            if ($ids === []) {
                $report['exit_code'] = $report['errors'] === []
                    ? self::EXIT_SUCCESS
                    : self::EXIT_OPERATIONAL_FAILURE;
                return $report;
            }

            try {
                $processor = ($this->processorFactory)();
            } catch (Throwable) {
                $report['errors'][] = 'WORKER_BOOTSTRAP_ERROR';
                $report['exit_code'] = self::EXIT_OPERATIONAL_FAILURE;
                return $report;
            }

            foreach ($ids as $executionId) {
                $claim = null;

                try {
                    $claim = $this->leases->claimById(
                        $executionId,
                        $worker,
                        $this->now(),
                        $leaseSeconds
                    );

                    if (!$claim['claimed']) {
                        $report['claim_skipped']++;
                        continue;
                    }

                    $result = $processor->process(
                        $executionId,
                        (string) $claim['lease_token'],
                        $leaseSeconds
                    );
                    $report['processed']++;
                    $report['tickets_created'] += (int) $result['created_now'];
                    $report['tickets_reconciled'] += (int) $result['reconciled'];

                    if ($result['lease_lost'] || $result['final_status'] !== 'succeeded') {
                        $report['failed']++;
                        $report['errors'][] = 'EXECUTION_PROCESSING_FAILED';
                    } else {
                        $report['succeeded']++;
                    }
                } catch (Throwable) {
                    if (is_array($claim)
                        && $claim['claimed'] === true
                        && is_string($claim['lease_token'])) {
                        try {
                            $this->leases->finish(
                                $executionId,
                                $claim['lease_token'],
                                'failed',
                                'O orquestrador interrompeu esta execução por uma falha interna.',
                                $this->now(),
                                'ORCHESTRATOR_PROCESSING_ERROR'
                            );
                        } catch (Throwable) {
                            // O relatório permanece seguro; o lease limita a recuperação posterior.
                        }
                    }

                    $report['failed']++;
                    $report['errors'][] = 'EXECUTION_PROCESSING_ERROR';
                }
            }

            $report['errors'] = array_values(array_unique($report['errors']));
            $report['exit_code'] = $report['errors'] === []
                ? self::EXIT_SUCCESS
                : self::EXIT_OPERATIONAL_FAILURE;
            return $report;
        } catch (Throwable) {
            $report['errors'][] = 'ORCHESTRATOR_RUNTIME_ERROR';
            $report['errors'] = array_values(array_unique($report['errors']));
            $report['exit_code'] = self::EXIT_OPERATIONAL_FAILURE;
            return $report;
        } finally {
            $this->lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function baseReport(string $mode, DateTimeImmutable $now): array
    {
        return [
            'mode' => $mode,
            'now_utc' => $this->utc($now),
            'lock_busy' => false,
            'scheduled' => 0,
            'scheduler_skipped' => 0,
            'scheduler_errors' => 0,
            'selected' => 0,
            'claim_skipped' => 0,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'tickets_created' => 0,
            'tickets_reconciled' => 0,
            'errors' => [],
            'exit_code' => self::EXIT_SUCCESS,
        ];
    }

    private function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : ($this->clock)();

        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('O clock do Orchestrator deve retornar DateTimeImmutable.');
        }

        return $now;
    }
}
