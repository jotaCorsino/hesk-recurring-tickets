#!/usr/bin/env php
<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\BatchProcessor;
use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\ExecutionItemRepository;
use TicketsRecorrentesHesk\ExecutionLeaseService;
use TicketsRecorrentesHesk\FileOrchestratorLock;
use TicketsRecorrentesHesk\HeskBootstrap;
use TicketsRecorrentesHesk\HeskTicketCreator;
use TicketsRecorrentesHesk\HeskTicketGateway;
use TicketsRecorrentesHesk\ImmediateTransaction;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\Orchestrator;
use TicketsRecorrentesHesk\OrchestratorCliOptions;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\RecurrenceScheduleCalculator;
use TicketsRecorrentesHesk\Scheduler;

foreach ([
    'OrchestratorCliOptions', 'Database', 'MigrationRunner', 'RecurrenceValidator',
    'RecurrenceRepository', 'RecurrenceExecutionRepository', 'ExecutionItemRepository',
    'ImmediateTransaction', 'ExecutionLeaseService', 'RecurrenceScheduleCalculator',
    'Scheduler', 'OrchestratorLock', 'FileOrchestratorLock', 'Orchestrator', 'HeskBootstrap',
    'OperationalException', 'HeskCatalogProvider', 'HeskReferenceData',
    'HeskReferenceValidationException', 'HeskRecurrenceValidator', 'NativeHeskCatalogProvider',
    'HeskTicketCreator', 'TicketGateway', 'HeskTicketGateway', 'BatchProcessor',
] as $source) {
    require dirname(__DIR__) . "/src/{$source}.php";
}

try {
    $options = OrchestratorCliOptions::parse(
        $argv,
        getenv('APP_DB_PATH'),
        getenv('HESK_PATH'),
        getenv('ORCHESTRATOR_LOCK_PATH')
    );
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO DE CONFIGURACAO: {$error->getMessage()}\n\n" . OrchestratorCliOptions::usage() . "\n");
    exit(Orchestrator::EXIT_USAGE_OR_CONFIGURATION);
}

if ($options->command === 'help') {
    echo OrchestratorCliOptions::usage() . "\n";
    exit(Orchestrator::EXIT_SUCCESS);
}

try {
    $database = new Database($options->dbPath);
    $connection = $options->command === 'check'
        ? $database->connectReadOnly()
        : $database->connectExistingWritable();
    (new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations'))->assertUpToDate();

    $recurrences = new RecurrenceRepository($connection);
    $executions = new RecurrenceExecutionRepository($connection);
    $items = new ExecutionItemRepository($connection);
    $leases = new ExecutionLeaseService($executions, new ImmediateTransaction($connection));
    $scheduler = new Scheduler(
        $connection,
        $recurrences,
        $executions,
        new RecurrenceScheduleCalculator()
    );
    $factory = static function () use ($options, $recurrences, $executions, $items, $leases): BatchProcessor {
        (new HeskBootstrap($options->heskPath))->boot();

        return new BatchProcessor(
            $recurrences,
            $executions,
            $items,
            $leases,
            new HeskTicketGateway(new HeskTicketCreator())
        );
    };
    $orchestrator = new Orchestrator(
        $scheduler,
        $executions,
        $leases,
        new FileOrchestratorLock($options->lockPath),
        $factory
    );
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $report = $options->command === 'check'
        ? $orchestrator->check($now, $options->schedulerLimit)
        : $orchestrator->run(
            $now,
            $options->schedulerLimit,
            $options->executionLimit,
            $options->worker,
            $options->leaseSeconds
        );

    echo "ORCHESTRATOR " . strtoupper($report['mode']) . "\n";
    echo "Agora UTC: {$report['now_utc']}\n";

    if ($report['mode'] === 'check') {
        echo "Recorrências vencidas: {$report['due_recurrences']}\n";
        echo "Execuções potencialmente elegíveis: {$report['potential_executions']}\n";
        echo "Erros do scheduler: {$report['scheduler_errors']}\n";
        echo "Nenhuma alteração foi realizada.\n";
    } else {
        echo 'Lock ocupado: ' . ($report['lock_busy'] ? 'sim' : 'não') . "\n";
        echo "Agendadas: {$report['scheduled']}\n";
        echo "Ignoradas pelo scheduler: {$report['scheduler_skipped']}\n";
        echo "Erros do scheduler: {$report['scheduler_errors']}\n";
        echo "Selecionadas: {$report['selected']}\n";
        echo "Recusadas por claim/lease: {$report['claim_skipped']}\n";
        echo "Processadas: {$report['processed']}\n";
        echo "Concluídas: {$report['succeeded']}\n";
        echo "Com falha: {$report['failed']}\n";
        echo "Tickets criados: {$report['tickets_created']}\n";
        echo "Tickets reconciliados: {$report['tickets_reconciled']}\n";
        echo 'Códigos de erro: ' . ($report['errors'] === [] ? '-' : implode(',', $report['errors'])) . "\n";
    }

    echo "Exit code: {$report['exit_code']}\n";
    exit((int) $report['exit_code']);
} catch (Throwable) {
    fwrite(STDERR, "ORCHESTRATOR FALHOU\nCodigo: ORCHESTRATOR_CONFIGURATION_ERROR\n");
    exit(Orchestrator::EXIT_USAGE_OR_CONFIGURATION);
}
