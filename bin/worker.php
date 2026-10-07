#!/usr/bin/env php
<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\BatchProcessor;
use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\ExecutionItemRepository;
use TicketsRecorrentesHesk\ExecutionLeaseService;
use TicketsRecorrentesHesk\HeskBootstrap;
use TicketsRecorrentesHesk\HeskTicketCreator;
use TicketsRecorrentesHesk\HeskTicketGateway;
use TicketsRecorrentesHesk\ImmediateTransaction;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\WorkerCliOptions;

require dirname(__DIR__) . '/src/WorkerCliOptions.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceRepository.php';
require dirname(__DIR__) . '/src/RecurrenceExecutionRepository.php';
require dirname(__DIR__) . '/src/ExecutionItemRepository.php';
require dirname(__DIR__) . '/src/ImmediateTransaction.php';
require dirname(__DIR__) . '/src/ExecutionLeaseService.php';
require dirname(__DIR__) . '/src/HeskBootstrap.php';
require dirname(__DIR__) . '/src/OperationalException.php';
require dirname(__DIR__) . '/src/HeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskReferenceData.php';
require dirname(__DIR__) . '/src/HeskReferenceValidationException.php';
require dirname(__DIR__) . '/src/HeskRecurrenceValidator.php';
require dirname(__DIR__) . '/src/NativeHeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskTicketCreator.php';
require dirname(__DIR__) . '/src/TicketGateway.php';
require dirname(__DIR__) . '/src/HeskTicketGateway.php';
require dirname(__DIR__) . '/src/BatchProcessor.php';

try {
    $options = WorkerCliOptions::parse($argv, getenv('APP_DB_PATH'), getenv('HESK_PATH'));
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n\n" . WorkerCliOptions::usage() . "\n");
    exit(2);
}

if ($options->command === 'help') {
    echo WorkerCliOptions::usage() . "\n";
    exit(0);
}

try {
    $database = new Database($options->dbPath);
    $connection = $database->connect();
    (new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations'))->assertUpToDate();

    $executionRepository = new RecurrenceExecutionRepository($connection);
    $itemRepository = new ExecutionItemRepository($connection);
    $leaseService = new ExecutionLeaseService(
        $executionRepository,
        new ImmediateTransaction($connection)
    );

    (new HeskBootstrap($options->heskPath))->boot();
    $gateway = new HeskTicketGateway(new HeskTicketCreator());
    $processor = new BatchProcessor(
        new RecurrenceRepository($connection),
        $executionRepository,
        $itemRepository,
        $leaseService,
        $gateway
    );

    if ($options->command === 'check') {
        $report = $processor->check((int) $options->id);
        echo "BATCH CHECK OK\n";
        echo "Execution: {$report['execution']['id']}\n";
        echo "Status: {$report['execution']['status']}\n";
        echo "Recorrência: {$report['recurrence']['id']} - {$report['recurrence']['name']}\n";
        echo "Expected: {$report['expected_count']}\n";
        echo "Itens existentes: {$report['existing_items']}\n";
        echo "notify_customer suportado: sim (configurado como false)\n";
        echo "Nenhum claim foi realizado.\n";
        echo "Nenhum ticket foi criado.\n";
        exit(0);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $claim = $leaseService->claimById(
        (int) $options->id,
        (string) $options->worker,
        $now,
        $options->leaseSeconds
    );

    if (!$claim['claimed']) {
        fwrite(STDERR, "BATCH RUN NAO INICIADO\nMotivo: {$claim['reason']}\n");
        exit(1);
    }

    $report = $processor->process(
        (int) $options->id,
        (string) $claim['lease_token'],
        $options->leaseSeconds
    );

    echo $report['lease_lost'] || $report['final_status'] !== 'succeeded'
        ? "BATCH RUN COM FALHAS\n"
        : "BATCH RUN OK\n";
    echo "Execution: {$report['execution_id']}\n";
    echo "Expected: {$report['expected_count']}\n";
    echo "Succeeded: {$report['succeeded']}\n";
    echo "Failed: {$report['failed']}\n";
    echo "Reconciled: {$report['reconciled']}\n";
    echo "Created now: {$report['created_now']}\n";
    echo "Final status: {$report['final_status']}\n";

    if ($report['error_code'] !== null) {
        echo "Error code: {$report['error_code']}\n";
    }

    if ($report['lease_lost']) {
        echo "Lease perdido: {$report['reason']}\n";
    }

    echo "\nItens:\n";

    foreach ($report['items'] as $item) {
        $ticketId = $item['hesk_ticket_id'] ?? '-';
        $trackingId = $item['hesk_trackid'] ?? '-';
        $errorCode = $item['error_code'] === null ? '' : " / code {$item['error_code']}";
        echo "{$item['item_index']} -> {$item['status']} / ticket {$ticketId} / {$trackingId}{$errorCode}\n";
    }

    foreach ($report['errors'] as $error) {
        echo "ERRO: {$error}\n";
    }

    exit(!$report['lease_lost'] && $report['final_status'] === 'succeeded' ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, "BATCH WORKER FALHOU\nERRO: {$error->getMessage()}\n");
    exit(1);
}
