#!/usr/bin/env php
<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\CliOptions;
use TicketsRecorrentesHesk\HeskBootstrap;
use TicketsRecorrentesHesk\HeskTicketCreator;

require dirname(__DIR__) . '/src/CliOptions.php';
require dirname(__DIR__) . '/src/HeskBootstrap.php';
require dirname(__DIR__) . '/src/OperationalException.php';
require dirname(__DIR__) . '/src/HeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskReferenceData.php';
require dirname(__DIR__) . '/src/HeskReferenceValidationException.php';
require dirname(__DIR__) . '/src/HeskRecurrenceValidator.php';
require dirname(__DIR__) . '/src/NativeHeskCatalogProvider.php';
require dirname(__DIR__) . '/src/HeskTicketCreator.php';

try {
    $options = CliOptions::parse($argv, getenv('HESK_PATH'));
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n\n" . CliOptions::usage() . "\n");
    exit(2);
}

if ($options->mode === 'help') {
    echo CliOptions::usage() . "\n";
    exit(0);
}

try {
    $definitionPath = trim((string) getenv('POC_DEFINITION_PATH'));
    if ($definitionPath === '' || !is_file($definitionPath) || !is_readable($definitionPath)) {
        throw new RuntimeException('Defina POC_DEFINITION_PATH com um JSON de ticket revisado para esta instalação.');
    }
    $definition = json_decode((string) file_get_contents($definitionPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($definition)) {
        throw new RuntimeException('A definição da POC deve ser um objeto JSON.');
    }

    $bootstrap = new HeskBootstrap($options->heskPath);
    $environment = $bootstrap->boot();
    $creator = new HeskTicketCreator();

    if ($options->mode === 'check') {
        $report = $creator->validate($definition);

        echo "CHECK OK\n";
        echo "HESK: {$report['hesk_version']}\n";
        echo "Caminho: {$environment['path']}\n";
        echo "HTTPS CLI: preparado para {$environment['server_name']}\n";
        echo "hesk_newTicket: disponível\n";
        echo "Solicitante: {$report['customer']['id']} - {$report['customer']['name']}\n";
        echo "Categoria: {$report['category']['id']} - {$report['category']['name']}\n";
        echo "Responsável: {$report['owner']['id']} - {$report['owner']['name']}\n";
        echo "Autor interno: {$report['openedby']['id']} - {$report['openedby']['name']}\n";
        echo "Prioridade resolvida: {$report['priority']['id']} - {$report['priority']['name']}\n";
        echo "Status: {$report['status']['id']} - {$report['status']['name']}\n";
        echo "Campos personalizados:\n";

        foreach ($report['custom_fields'] as $key => $field) {
            $value = $field['value'] === '' ? '(vazio)' : $field['value'];
            echo "  {$key} - {$field['name']}: {$value}\n";
        }

        echo "Nenhum ticket foi criado.\n";
        exit(0);
    }

    $created = $creator->create($definition);

    echo "TICKET CRIADO\n";
    echo "ID: {$created['id']}\n";
    echo "Tracking ID: {$created['trackid']}\n";
    echo "Assunto: {$created['subject']}\n";
    echo "Responsável: {$created['owner']}\n";
    echo "Solicitante: {$created['customer_id']}\n";
    echo "Notificação ao solicitante: não enviada pela POC.\n";
} catch (Throwable $error) {
    $label = $options->mode === 'check' ? 'CHECK FALHOU' : 'EXECUÇÃO CANCELADA';
    fwrite(STDERR, "{$label}\nERRO: {$error->getMessage()}\n");

    if ($options->mode === 'check') {
        fwrite(STDERR, "Nenhum ticket foi criado.\n");
    }

    exit(1);
}
