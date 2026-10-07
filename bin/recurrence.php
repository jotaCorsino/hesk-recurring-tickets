#!/usr/bin/env php
<?php

declare(strict_types=1);

use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\RecurrenceCliOptions;
use TicketsRecorrentesHesk\RecurrenceExecutionRepository;
use TicketsRecorrentesHesk\RecurrenceRepository;

require dirname(__DIR__) . '/src/RecurrenceCliOptions.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/MigrationRunner.php';
require dirname(__DIR__) . '/src/RecurrenceValidator.php';
require dirname(__DIR__) . '/src/RecurrenceRepository.php';
require dirname(__DIR__) . '/src/RecurrenceExecutionRepository.php';

try {
    $options = RecurrenceCliOptions::parse($argv, getenv('APP_DB_PATH'));
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n\n" . RecurrenceCliOptions::usage() . "\n");
    exit(2);
}

if ($options->command === 'help') {
    echo RecurrenceCliOptions::usage() . "\n";
    exit(0);
}

try {
    $database = new Database($options->dbPath);
    $connection = $database->connect();
    $migrations = new MigrationRunner($connection, dirname(__DIR__) . '/database/migrations');

    if ($options->command === 'migrate') {
        $applied = $migrations->migrate();
        $diagnostics = $database->diagnostics();

        echo "MIGRATE OK\n";
        echo "Banco: {$database->path()}\n";
        echo 'Migrations aplicadas: ' . ($applied === [] ? 'nenhuma' : implode(', ', $applied)) . "\n";
        echo "foreign_keys: {$diagnostics['foreign_keys']}\n";
        echo "journal_mode: {$diagnostics['journal_mode']}\n";
        exit(0);
    }

    $migrations->assertUpToDate();
    $repository = new RecurrenceRepository($connection);

    switch ($options->command) {
        case 'create':
            $created = $repository->create(readJsonObject($options->filePath));
            echo "CREATE OK\n" . encodeJson($created) . "\n";
            break;

        case 'list':
            echo encodeJson($repository->findAll()) . "\n";
            break;

        case 'show':
            echo encodeJson(requireRecurrence($repository, $options->id)) . "\n";
            break;

        case 'executions':
            requireRecurrence($repository, $options->id);
            $executions = new RecurrenceExecutionRepository($connection);
            echo encodeJson($executions->findByRecurrence((int) $options->id)) . "\n";
            break;

        case 'update':
            $updated = $repository->update($options->id, readJsonObject($options->filePath));

            if ($updated === null) {
                throw new RuntimeException("Recorrência {$options->id} não encontrada.");
            }

            echo "UPDATE OK\n" . encodeJson($updated) . "\n";
            break;

        case 'enable':
        case 'disable':
            $enabled = $options->command === 'enable';
            $updated = $repository->setEnabled($options->id, $enabled);

            if ($updated === null) {
                throw new RuntimeException("Recorrência {$options->id} não encontrada.");
            }

            echo strtoupper($options->command) . " OK\n" . encodeJson($updated) . "\n";
            break;
    }
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function readJsonObject(?string $path): array
{
    if ($path === null || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Arquivo JSON inexistente ou ilegível: {$path}");
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Não foi possível ler o arquivo JSON: {$path}");
    }

    try {
        $object = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException("JSON inválido em {$path}: {$error->getMessage()}", 0, $error);
    }

    if (!is_object($object) || !is_array($data)) {
        throw new RuntimeException("O arquivo {$path} deve conter um objeto JSON na raiz.");
    }

    if (property_exists($object, 'custom_fields')) {
        if (!is_object($object->custom_fields)) {
            throw new RuntimeException("custom_fields em {$path} deve ser um objeto JSON.");
        }

        $data['custom_fields'] = get_object_vars($object->custom_fields);
    }

    return $data;
}

/** @param mixed $value */
function encodeJson(mixed $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

/** @return array<string, mixed> */
function requireRecurrence(RecurrenceRepository $repository, ?int $id): array
{
    $recurrence = $id === null ? null : $repository->findById($id);

    if ($recurrence === null) {
        throw new RuntimeException("Recorrência {$id} não encontrada.");
    }

    return $recurrence;
}
