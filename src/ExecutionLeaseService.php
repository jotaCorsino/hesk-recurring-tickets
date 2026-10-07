<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class ExecutionLeaseService
{
    private const FINISH_RESULTS = ['succeeded', 'failed', 'partial'];

    public function __construct(
        private readonly RecurrenceExecutionRepository $executions,
        private readonly ImmediateTransaction $transaction,
    ) {
    }

    /** @return array<string, mixed> */
    public function claimNext(
        string $worker,
        DateTimeImmutable $now,
        int $leaseSeconds = 300,
    ): array {
        return $this->claim(null, $worker, $now, $leaseSeconds);
    }

    /** @return array<string, mixed> */
    public function claimById(
        int $executionId,
        string $worker,
        DateTimeImmutable $now,
        int $leaseSeconds = 300,
    ): array {
        if ($executionId < 1) {
            throw new DomainException('executionId deve ser inteiro positivo.');
        }

        return $this->claim($executionId, $worker, $now, $leaseSeconds);
    }

    /** @return array<string, mixed> */
    public function renewLease(
        int $executionId,
        string $leaseToken,
        DateTimeImmutable $now,
        int $leaseSeconds = 300,
    ): array {
        $this->validateExecutionId($executionId);
        $this->validateToken($leaseToken);
        $this->validateLeaseSeconds($leaseSeconds);
        $nowUtc = $this->utc($now);
        $leaseExpiresAt = $this->leaseExpiresAt($now, $leaseSeconds);
        $renewed = $this->executions->renewLease(
            $executionId,
            $leaseToken,
            $nowUtc,
            $leaseExpiresAt
        );
        $execution = $this->executions->findById($executionId);

        return [
            'renewed' => $renewed,
            'reason' => $renewed ? 'renewed' : $this->leaseRefusalReason($execution, $nowUtc),
            'execution' => $execution,
        ];
    }

    /** @return array<string, mixed> */
    public function finish(
        int $executionId,
        string $leaseToken,
        string $result,
        ?string $errorMessage,
        DateTimeImmutable $now,
        ?string $errorCode = null,
    ): array {
        $this->validateExecutionId($executionId);
        $this->validateToken($leaseToken);

        if (!in_array($result, self::FINISH_RESULTS, true)) {
            throw new DomainException('result deve ser succeeded, failed ou partial.');
        }

        $errorMessage = $errorMessage === null ? null : trim($errorMessage);

        if ($result === 'failed' && ($errorMessage === null || $errorMessage === '')) {
            throw new DomainException('error_message é obrigatório para resultado failed.');
        }

        if ($result === 'succeeded') {
            $errorMessage = null;
            $errorCode = null;
        } elseif ($errorMessage === '') {
            $errorMessage = null;
        }

        if ($result !== 'succeeded') {
            $errorCode ??= 'UNCLASSIFIED_ERROR';

            if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $errorCode)) {
                throw new DomainException('error_code operacional inválido.');
            }
        }

        $nowUtc = $this->utc($now);
        $finished = $this->executions->finish(
            $executionId,
            $leaseToken,
            $result,
            $errorMessage,
            $nowUtc,
            $errorCode
        );
        $execution = $this->executions->findById($executionId);

        return [
            'finished' => $finished,
            'reason' => $finished ? 'finished' : $this->leaseRefusalReason($execution, $nowUtc),
            'execution' => $execution,
        ];
    }

    /** @return array<string, mixed> */
    public function retry(int $executionId, DateTimeImmutable $now): array
    {
        $this->validateExecutionId($executionId);
        $retried = $this->executions->retry($executionId, $this->utc($now));
        $execution = $this->executions->findById($executionId);

        return [
            'retried' => $retried,
            'reason' => $retried ? 'retried' : $this->retryRefusalReason($execution),
            'execution' => $execution,
        ];
    }

    /** @return array<string, mixed> */
    private function claim(
        ?int $executionId,
        string $worker,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): array {
        $worker = trim($worker);

        if ($worker === '' || strlen($worker) > 255) {
            throw new DomainException('worker deve ter entre 1 e 255 caracteres.');
        }

        $this->validateLeaseSeconds($leaseSeconds);
        $nowUtc = $this->utc($now);
        $leaseExpiresAt = $this->leaseExpiresAt($now, $leaseSeconds);

        return $this->transaction->run(function () use (
            $executionId,
            $worker,
            $nowUtc,
            $leaseExpiresAt,
        ): array {
            $candidate = $this->executions->findClaimable($nowUtc, $executionId);

            if ($candidate === null) {
                $current = $executionId === null
                    ? null
                    : $this->executions->findById($executionId);

                return [
                    'claimed' => false,
                    'reason' => $executionId === null
                        ? 'no_claimable_execution'
                        : $this->claimRefusalReason($current, $nowUtc),
                    'execution' => $current,
                    'lease_token' => null,
                ];
            }

            $leaseToken = bin2hex(random_bytes(32));
            $claimed = $this->executions->claim(
                (int) $candidate['id'],
                $nowUtc,
                $leaseExpiresAt,
                $leaseToken,
                $worker
            );

            if (!$claimed) {
                throw new DomainException('A execution deixou de ser elegível durante o claim.');
            }

            return [
                'claimed' => true,
                'reason' => 'claimed',
                'execution' => $this->executions->findById((int) $candidate['id']),
                'lease_token' => $leaseToken,
            ];
        });
    }

    /** @param array<string, mixed>|null $execution */
    private function claimRefusalReason(?array $execution, string $nowUtc): string
    {
        if ($execution === null) {
            return 'not_found';
        }

        return match ($execution['status']) {
            'succeeded' => 'terminal_succeeded',
            'failed', 'partial' => 'explicit_retry_required',
            'running' => $this->runningRefusalReason($execution, $nowUtc),
            default => 'claim_conflict',
        };
    }

    /** @param array<string, mixed>|null $execution */
    private function leaseRefusalReason(?array $execution, string $nowUtc): string
    {
        if ($execution === null) {
            return 'not_found';
        }

        if ($execution['status'] !== 'running') {
            return $execution['status'] === 'succeeded'
                ? 'terminal_succeeded'
                : 'invalid_status';
        }

        $runningReason = $this->runningRefusalReason($execution, $nowUtc);

        return $runningReason === 'active_lease' ? 'lease_token_mismatch' : $runningReason;
    }

    /** @param array<string, mixed> $execution */
    private function runningRefusalReason(array $execution, string $nowUtc): string
    {
        if ($execution['lease_owner'] === null || $execution['lease_expires_at'] === null) {
            return 'legacy_running_without_lease';
        }

        return strcmp((string) $execution['lease_expires_at'], $nowUtc) <= 0
            ? 'lease_expired'
            : 'active_lease';
    }

    /** @param array<string, mixed>|null $execution */
    private function retryRefusalReason(?array $execution): string
    {
        if ($execution === null) {
            return 'not_found';
        }

        return $execution['status'] === 'succeeded'
            ? 'terminal_succeeded'
            : 'invalid_status';
    }

    private function validateExecutionId(int $executionId): void
    {
        if ($executionId < 1) {
            throw new DomainException('executionId deve ser inteiro positivo.');
        }
    }

    private function validateToken(string $leaseToken): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $leaseToken)) {
            throw new DomainException('leaseToken deve ser um token hexadecimal de 64 caracteres.');
        }
    }

    private function validateLeaseSeconds(int $leaseSeconds): void
    {
        if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
            throw new DomainException('leaseSeconds deve estar entre 30 e 3600.');
        }
    }

    private function leaseExpiresAt(DateTimeImmutable $now, int $leaseSeconds): string
    {
        return $now
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify("+{$leaseSeconds} seconds")
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function utc(DateTimeImmutable $date): string
    {
        return $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
