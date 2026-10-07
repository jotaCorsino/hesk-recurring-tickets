#!/usr/bin/env php
<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\RecurrenceScheduleCalculator;
use TicketsRecorrentesHesk\Scheduler;
use TicketsRecorrentesHesk\SchedulerCliOptions;

require dirname(__DIR__) . '/src/SchedulerCliOptions.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceRepository.php';
require dirname(__DIR__) . '/src/RecurrenceExecutionRepository.php';
require dirname(__DIR__) . '/src/RecurrenceScheduleCalculator.php';
require dirname(__DIR__) . '/src/Scheduler.php';

try {
    $options = SchedulerCliOptions::parse($argv, getenv('APP_DB_PATH'));
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n\n" . SchedulerCliOptions::usage() . "\n");
    exit(2);
}

if ($options->command === 'help') {
    echo SchedulerCliOptions::usage() . "\n";
    exit(0);
}

try {
    $database = new Database($options->dbPath);
    $connection = $database->connect();
    $migrations = new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations');
    $migrations->assertUpToDate();

    $scheduler = new Scheduler(
        $connection,
        new RecurrenceRepository($connection),
        new RecurrenceExecutionRepository($connection),
        new RecurrenceScheduleCalculator()
    );
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $report = $options->command === 'check'
        ? $scheduler->check($now, $options->limit)
        : $scheduler->run($now, $options->limit);

    if ($options->command === 'check') {
        printCheckReport($report);
    } else {
        printRunReport($report);
    }

    exit($report['errors'] === [] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n");
    exit(1);
}

/** @param array<string, mixed> $report */
function printCheckReport(array $report): void
{
    echo $report['errors'] === [] ? "SCHEDULER CHECK OK\n" : "SCHEDULER CHECK COM ERROS\n";
    echo "Agora UTC: {$report['now_utc']}\n";
    echo "Recorrências vencidas: {$report['due_count']}\n";

    foreach ($report['candidates'] as $candidate) {
        echo "\nID: {$candidate['recurrence_id']}\n";
        echo "Nome: {$candidate['name']}\n";
        echo "scheduled_for: {$candidate['scheduled_for']}\n";
        echo "next_run_at calculado: {$candidate['next_run_at']}\n";
    }

    printErrors($report['errors']);
    echo "\nNenhuma alteração foi realizada.\n";
}

/** @param array<string, mixed> $report */
function printRunReport(array $report): void
{
    echo $report['errors'] === [] ? "SCHEDULER RUN OK\n" : "SCHEDULER RUN COM ERROS\n";
    echo "Agora UTC: {$report['now_utc']}\n";
    echo 'Processadas: ' . count($report['processed']) . "\n";
    echo 'Ignoradas: ' . count($report['skipped']) . "\n";
    echo 'Erros: ' . count($report['errors']) . "\n";

    foreach ($report['processed'] as $processed) {
        echo "\nRecorrência {$processed['recurrence_id']}\n";
        echo "Execution: {$processed['execution_id']}\n";
        echo "scheduled_for: {$processed['scheduled_for']}\n";
        echo "next_run_at: {$processed['next_run_at']}\n";
    }

    foreach ($report['skipped'] as $skipped) {
        echo "\nRecorrência {$skipped['recurrence_id']} ignorada\n";
        echo "Execution existente: {$skipped['execution_id']}\n";
        echo "scheduled_for: {$skipped['scheduled_for']}\n";
        echo "Motivo: {$skipped['reason']}\n";
    }

    printErrors($report['errors']);
}

/** @param list<array<string, mixed>> $errors */
function printErrors(array $errors): void
{
    foreach ($errors as $error) {
        echo "\nERRO na recorrência {$error['recurrence_id']} ({$error['name']})\n";
        echo "scheduled_for: {$error['scheduled_for']}\n";
        echo "Mensagem: {$error['message']}\n";
    }
}
