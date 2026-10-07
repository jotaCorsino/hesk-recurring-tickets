#!/usr/bin/env php
<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\ExecutionCliOptions;
use TicketsRecorrentesHesk\ExecutionItemRepository;
use TicketsRecorrentesHesk\ExecutionLeaseService;
use TicketsRecorrentesHesk\ImmediateTransaction;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;

require dirname(__DIR__) . '/src/ExecutionCliOptions.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceExecutionRepository.php';
require dirname(__DIR__) . '/src/ExecutionItemRepository.php';
require dirname(__DIR__) . '/src/ImmediateTransaction.php';
require dirname(__DIR__) . '/src/ExecutionLeaseService.php';

try {
    $options = ExecutionCliOptions::parse($argv, getenv('APP_DB_PATH'));
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n\n" . ExecutionCliOptions::usage() . "\n");
    exit(2);
}

if ($options->command === 'help') {
    echo ExecutionCliOptions::usage() . "\n";
    exit(0);
}

try {
    $database = new Database($options->dbPath);
    $connection = $database->connect();
    (new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations'))->assertUpToDate();
    $repository = new RecurrenceExecutionRepository($connection);

    if ($options->command === 'list') {
        echo executionJson($repository->findAll($options->status)) . "\n";
        exit(0);
    }

    if ($options->command === 'show') {
        echo executionJson(requireExecution($repository, $options->id)) . "\n";
        exit(0);
    }

    if ($options->command === 'items') {
        requireExecution($repository, $options->id);
        echo executionJson(
            (new ExecutionItemRepository($connection))->findByExecution((int) $options->id)
        ) . "\n";
        exit(0);
    }

    $service = new ExecutionLeaseService(
        $repository,
        new ImmediateTransaction($connection)
    );
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    switch ($options->command) {
        case 'claim':
            $result = $options->id === null
                ? $service->claimNext((string) $options->worker, $now, $options->leaseSeconds)
                : $service->claimById(
                    $options->id,
                    (string) $options->worker,
                    $now,
                    $options->leaseSeconds
                );

            if (!$result['claimed']) {
                executionRefused('CLAIM', (string) $result['reason']);
            }

            $execution = $result['execution'];
            echo "CLAIM OK\n";
            echo "Execution: {$execution['id']}\n";
            echo "Status: {$execution['status']}\n";
            echo "Worker: {$execution['lease_owner']}\n";
            echo "Attempt: {$execution['attempt_count']}\n";
            echo "Lease token: {$result['lease_token']}\n";
            echo "Lease expires at: {$execution['lease_expires_at']}\n";
            break;

        case 'heartbeat':
            $result = $service->renewLease(
                (int) $options->id,
                (string) $options->token,
                $now,
                $options->leaseSeconds
            );

            if (!$result['renewed']) {
                executionRefused('HEARTBEAT', (string) $result['reason']);
            }

            echo "HEARTBEAT OK\n";
            echo "Execution: {$result['execution']['id']}\n";
            echo "Lease expires at: {$result['execution']['lease_expires_at']}\n";
            break;

        case 'finish':
            $result = $service->finish(
                (int) $options->id,
                (string) $options->token,
                (string) $options->result,
                $options->errorMessage,
                $now
            );

            if (!$result['finished']) {
                executionRefused('FINISH', (string) $result['reason']);
            }

            echo "FINISH OK\n";
            echo "Execution: {$result['execution']['id']}\n";
            echo "Status: {$result['execution']['status']}\n";
            echo "Finished at: {$result['execution']['finished_at']}\n";
            break;

        case 'retry':
            $result = $service->retry((int) $options->id, $now);

            if (!$result['retried']) {
                executionRefused('RETRY', (string) $result['reason']);
            }

            echo "RETRY OK\n";
            echo "Execution: {$result['execution']['id']}\n";
            echo "Status: {$result['execution']['status']}\n";
            echo "Attempt count preservado: {$result['execution']['attempt_count']}\n";
            break;
    }
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n");
    exit(1);
}

/** @return array<string, mixed> */
function requireExecution(RecurrenceExecutionRepository $repository, ?int $id): array
{
    $execution = $id === null ? null : $repository->findById($id);

    if ($execution === null) {
        throw new RuntimeException("Execution {$id} não encontrada.");
    }

    return $execution;
}

/** @param mixed $value */
function executionJson(mixed $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function executionRefused(string $operation, string $reason): never
{
    fwrite(STDERR, "{$operation} NAO REALIZADO\nMotivo: {$reason}\n");
    exit(1);
}
