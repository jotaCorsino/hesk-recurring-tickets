<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use RuntimeException;
use Throwable;

final class BatchProcessor
{
    public function __construct(
        private readonly RecurrenceRepository $recurrences,
        private readonly RecurrenceExecutionRepository $executions,
        private readonly ExecutionItemRepository $items,
        private readonly ExecutionLeaseService $leases,
        private readonly TicketGateway $gateway,
        private readonly ?Closure $clock = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function check(int $executionId): array
    {
        $execution = $this->requireExecution($executionId);
        $recurrence = $this->requireRecurrence((int) $execution['recurrence_id']);
        $definition = $this->ticketDefinition($recurrence);
        $validation = $this->gateway->validate($definition);
        $items = $this->items->findByExecution($executionId);

        return [
            'execution' => $execution,
            'recurrence' => $recurrence,
            'expected_count' => (int) $execution['expected_count'],
            'existing_items' => count($items),
            'items' => $items,
            'notify_customer_supported' => true,
            'validation' => $validation,
        ];
    }

    /** @return array<string, mixed> */
    public function process(
        int $executionId,
        string $leaseToken,
        int $leaseSeconds = 300,
    ): array {
        $execution = $this->requireExecution($executionId);
        $expectedCount = (int) $execution['expected_count'];
        $createdNow = 0;
        $reconciled = 0;
        $errors = [];

        try {
            $recurrence = $this->requireRecurrence((int) $execution['recurrence_id']);
            $definition = $this->ticketDefinition($recurrence);
            $this->gateway->validate($definition);
            $nowUtc = $this->utc($this->now());
            $batchItems = $this->items->materialize(
                $executionId,
                $expectedCount,
                $leaseToken,
                $nowUtc
            );
        } catch (Throwable $error) {
            return $this->finalizeAfterFatalError(
                $execution,
                $leaseToken,
                $error,
                $createdNow,
                $reconciled
            );
        }

        foreach ($batchItems as $batchItem) {
            if ($batchItem['status'] === 'succeeded') {
                continue;
            }

            $heartbeatAt = $this->now();
            $renewal = $this->leases->renewLease(
                $executionId,
                $leaseToken,
                $heartbeatAt,
                $leaseSeconds
            );

            if (!$renewal['renewed']) {
                return $this->leaseLostReport(
                    $executionId,
                    $expectedCount,
                    $createdNow,
                    $reconciled,
                    (string) $renewal['reason'],
                    $errors
                );
            }

            $operationNow = $this->utc($heartbeatAt);
            $currentItem = $this->items->findById((int) $batchItem['id']);

            if ($currentItem === null) {
                $errors[] = "Item {$batchItem['id']} deixou de existir.";
                continue;
            }

            try {
                $trackingId = $currentItem['hesk_trackid'];

                if ($trackingId === null) {
                    $trackingId = $this->gateway->generateTrackingId();

                    if (!$this->items->assignTrackingId(
                        (int) $currentItem['id'],
                        $executionId,
                        $trackingId,
                        $leaseToken,
                        $operationNow
                    )) {
                        return $this->mutationRefusedReport(
                            $executionId,
                            $expectedCount,
                            $createdNow,
                            $reconciled,
                            'assign_tracking_id_refused',
                            $errors
                        );
                    }
                }

                $existing = $this->gateway->findByTrackingId($trackingId);

                if ($existing !== null) {
                    $afterLookup = $this->renewAfterExternalCall(
                        $executionId,
                        $leaseToken,
                        $leaseSeconds
                    );

                    if (!$afterLookup['renewed']) {
                        return $this->leaseLostReport(
                            $executionId,
                            $expectedCount,
                            $createdNow,
                            $reconciled,
                            (string) $afterLookup['reason'],
                            $errors
                        );
                    }

                    if (!$this->items->markSucceeded(
                        (int) $currentItem['id'],
                        $executionId,
                        $trackingId,
                        (int) $existing['id'],
                        $leaseToken,
                        $this->utc($this->now())
                    )) {
                        return $this->mutationRefusedReport(
                            $executionId,
                            $expectedCount,
                            $createdNow,
                            $reconciled,
                            'reconciliation_update_refused',
                            $errors
                        );
                    }

                    $reconciled++;
                } else {
                    if (!$this->items->markCreating(
                        (int) $currentItem['id'],
                        $executionId,
                        $leaseToken,
                        $operationNow
                    )) {
                        return $this->mutationRefusedReport(
                            $executionId,
                            $expectedCount,
                            $createdNow,
                            $reconciled,
                            'mark_creating_refused',
                            $errors
                        );
                    }

                    $created = $this->gateway->create($definition, $trackingId);
                    $afterCreate = $this->renewAfterExternalCall(
                        $executionId,
                        $leaseToken,
                        $leaseSeconds
                    );

                    if (!$afterCreate['renewed']) {
                        return $this->leaseLostReport(
                            $executionId,
                            $expectedCount,
                            $createdNow,
                            $reconciled,
                            (string) $afterCreate['reason'],
                            $errors
                        );
                    }

                    if (!$this->items->markSucceeded(
                        (int) $currentItem['id'],
                        $executionId,
                        $trackingId,
                        (int) $created['id'],
                        $leaseToken,
                        $this->utc($this->now())
                    )) {
                        return $this->mutationRefusedReport(
                            $executionId,
                            $expectedCount,
                            $createdNow,
                            $reconciled,
                            'created_ticket_update_refused',
                            $errors
                        );
                    }

                    if ($created['created_now']) {
                        $createdNow++;
                    } else {
                        $reconciled++;
                    }
                }

                if ($this->executions->syncCreatedCount(
                    $executionId,
                    $leaseToken,
                    $this->utc($this->now())
                ) === null) {
                    return $this->mutationRefusedReport(
                        $executionId,
                        $expectedCount,
                        $createdNow,
                        $reconciled,
                        'created_count_update_refused',
                        $errors
                    );
                }
            } catch (Throwable $error) {
                $failureNow = $this->utc($this->now());

                if (!$this->items->markFailed(
                    (int) $currentItem['id'],
                    $executionId,
                    $error->getMessage(),
                    $leaseToken,
                    $failureNow,
                    OperationalException::codeOf($error)
                )) {
                    return $this->leaseLostReport(
                        $executionId,
                        $expectedCount,
                        $createdNow,
                        $reconciled,
                        'item_failure_update_refused',
                        $errors
                    );
                }

                $errors[] = "Item {$currentItem['item_index']}: {$error->getMessage()}";

                if ($this->executions->syncCreatedCount(
                    $executionId,
                    $leaseToken,
                    $failureNow
                ) === null) {
                    return $this->leaseLostReport(
                        $executionId,
                        $expectedCount,
                        $createdNow,
                        $reconciled,
                        'created_count_update_refused',
                        $errors
                    );
                }
            }
        }

        return $this->finishExecution(
            $executionId,
            $expectedCount,
            $leaseToken,
            $createdNow,
            $reconciled,
            $errors
        );
    }

    /** @param array<string, mixed> $recurrence */
    private function ticketDefinition(array $recurrence): array
    {
        if ($recurrence['notify_customer']) {
            throw new OperationalException('CONFIGURATION_UNSUPPORTED',
                'notify_customer=true ainda não é suportado pelo worker BATCH-001.'
            );
        }

        return [
            'customer_id' => (int) $recurrence['customer_id'],
            'category' => (int) $recurrence['category_id'],
            'priority_name' => (string) $recurrence['priority_name'],
            'status' => (int) $recurrence['status_id'],
            'owner' => (int) $recurrence['owner_id'],
            'openedby' => (int) $recurrence['openedby_id'],
            'subject' => (string) $recurrence['subject'],
            'message' => (string) $recurrence['message'],
            'custom_fields' => $recurrence['custom_fields'],
        ];
    }

    /** @return array<string, mixed> */
    private function finishExecution(
        int $executionId,
        int $expectedCount,
        string $leaseToken,
        int $createdNow,
        int $reconciled,
        array $errors,
        ?string $fatalCode = null,
    ): array {
        $finishNow = $this->now();
        $createdCount = $this->executions->syncCreatedCount(
            $executionId,
            $leaseToken,
            $this->utc($finishNow)
        );

        if ($createdCount === null) {
            return $this->leaseLostReport(
                $executionId,
                $expectedCount,
                $createdNow,
                $reconciled,
                'created_count_update_refused',
                $errors
            );
        }

        if ($createdCount === $expectedCount) {
            $finalStatus = 'succeeded';
            $errorMessage = null;
            $errorCode = null;
        } elseif ($createdCount === 0) {
            $finalStatus = 'failed';
            $errorCode = $fatalCode ?? 'BATCH_ITEMS_FAILED';
            $errorMessage = $errors === []
                ? 'Nenhum item do lote foi concluído.'
                : implode(' | ', $errors);
        } else {
            $finalStatus = 'partial';
            $errorCode = $fatalCode ?? 'BATCH_PARTIAL_FAILURE';
            $errorMessage = $errors === []
                ? 'O lote terminou com itens pendentes.'
                : implode(' | ', $errors);
        }

        $finish = $this->leases->finish(
            $executionId,
            $leaseToken,
            $finalStatus,
            $errorMessage,
            $finishNow,
            $errorCode
        );

        if (!$finish['finished']) {
            return $this->leaseLostReport(
                $executionId,
                $expectedCount,
                $createdNow,
                $reconciled,
                (string) $finish['reason'],
                $errors
            );
        }

        return $this->report(
            $executionId,
            $expectedCount,
            $createdNow,
            $reconciled,
            $finalStatus,
            false,
            null,
            $errors
        );
    }

    /** @param array<string, mixed> $execution */
    private function finalizeAfterFatalError(
        array $execution,
        string $leaseToken,
        Throwable $error,
        int $createdNow,
        int $reconciled,
    ): array {
        $errors = [$error->getMessage()];

        return $this->finishExecution(
            (int) $execution['id'],
            (int) $execution['expected_count'],
            $leaseToken,
            $createdNow,
            $reconciled,
            $errors,
            OperationalException::codeOf($error)
        );
    }

    /** @return array<string, mixed> */
    private function renewAfterExternalCall(
        int $executionId,
        string $leaseToken,
        int $leaseSeconds,
    ): array {
        return $this->leases->renewLease(
            $executionId,
            $leaseToken,
            $this->now(),
            $leaseSeconds
        );
    }

    /** @return array<string, mixed> */
    private function mutationRefusedReport(
        int $executionId,
        int $expectedCount,
        int $createdNow,
        int $reconciled,
        string $reason,
        array $errors,
    ): array {
        return $this->leaseLostReport(
            $executionId,
            $expectedCount,
            $createdNow,
            $reconciled,
            $reason,
            $errors
        );
    }

    /** @return array<string, mixed> */
    private function leaseLostReport(
        int $executionId,
        int $expectedCount,
        int $createdNow,
        int $reconciled,
        string $reason,
        array $errors,
    ): array {
        return $this->report(
            $executionId,
            $expectedCount,
            $createdNow,
            $reconciled,
            'running',
            true,
            $reason,
            $errors
        );
    }

    /** @return array<string, mixed> */
    private function report(
        int $executionId,
        int $expectedCount,
        int $createdNow,
        int $reconciled,
        string $finalStatus,
        bool $leaseLost,
        ?string $reason,
        array $errors,
    ): array {
        $items = $this->items->findByExecution($executionId);
        $succeeded = count(array_filter(
            $items,
            static fn (array $item): bool => $item['status'] === 'succeeded'
        ));

        return [
            'execution_id' => $executionId,
            'error_code' => $this->executions->findById($executionId)['error_code'] ?? null,
            'expected_count' => $expectedCount,
            'succeeded' => $succeeded,
            'failed' => $expectedCount - $succeeded,
            'reconciled' => $reconciled,
            'created_now' => $createdNow,
            'final_status' => $finalStatus,
            'lease_lost' => $leaseLost,
            'reason' => $reason,
            'errors' => $errors,
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function requireExecution(int $executionId): array
    {
        $execution = $this->executions->findById($executionId);

        if ($execution === null) {
            throw new DomainException("Execution {$executionId} não encontrada.");
        }

        return $execution;
    }

    /** @return array<string, mixed> */
    private function requireRecurrence(int $recurrenceId): array
    {
        $recurrence = $this->recurrences->findById($recurrenceId);

        if ($recurrence === null) {
            throw new DomainException("Recorrência {$recurrenceId} não encontrada.");
        }

        return $recurrence;
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : ($this->clock)();

        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('O clock do BatchProcessor deve retornar DateTimeImmutable.');
        }

        return $now;
    }

    private function utc(DateTimeImmutable $date): string
    {
        return $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
