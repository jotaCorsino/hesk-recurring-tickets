<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use InvalidArgumentException;

final class RecurrenceCliOptions
{
    private const COMMANDS = [
        'migrate',
        'create',
        'list',
        'show',
        'enable',
        'disable',
        'update',
        'executions',
    ];

    private function __construct(
        public readonly string $command,
        public readonly string $dbPath,
        public readonly ?string $filePath,
        public readonly ?int $id,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public static function parse(array $arguments, string|false|null $environmentPath = null): self
    {
        array_shift($arguments);
        $dbPath = self::cleanString($environmentPath) ?? dirname(__DIR__) . '/storage/app.sqlite';

        if ($arguments === [] || in_array($arguments[0], ['--help', '-h', 'help'], true)) {
            return new self('help', $dbPath, null, null);
        }

        $command = array_shift($arguments);

        if (!in_array($command, self::COMMANDS, true)) {
            throw new InvalidArgumentException("Comando desconhecido: {$command}");
        }

        $options = [];

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];

            if (in_array($argument, ['--help', '-h'], true)) {
                return new self('help', $dbPath, null, null);
            }

            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException("Argumento inesperado: {$argument}");
            }

            $option = substr($argument, 2);
            $value = null;

            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            } else {
                $index++;

                if (!isset($arguments[$index]) || str_starts_with($arguments[$index], '--')) {
                    throw new InvalidArgumentException("Informe um valor para --{$option}.");
                }

                $value = $arguments[$index];
            }

            if (!in_array($option, ['db-path', 'file', 'id'], true)) {
                throw new InvalidArgumentException("Opção desconhecida: --{$option}");
            }

            if (array_key_exists($option, $options)) {
                throw new InvalidArgumentException("Opção repetida: --{$option}");
            }

            $options[$option] = $value;
        }

        if (isset($options['db-path'])) {
            $dbPath = self::cleanString($options['db-path'])
                ?? throw new InvalidArgumentException('--db-path não pode ficar vazio.');
        }

        $needsFile = in_array($command, ['create', 'update'], true);
        $needsId = in_array($command, ['show', 'enable', 'disable', 'update', 'executions'], true);

        if (!$needsFile && isset($options['file'])) {
            throw new InvalidArgumentException("O comando {$command} não aceita --file.");
        }

        if (!$needsId && isset($options['id'])) {
            throw new InvalidArgumentException("O comando {$command} não aceita --id.");
        }

        $filePath = self::cleanString($options['file'] ?? null);

        if ($needsFile && $filePath === null) {
            throw new InvalidArgumentException("O comando {$command} exige --file.");
        }

        $id = null;

        if ($needsId) {
            $rawId = $options['id'] ?? null;

            if (!is_string($rawId) || !preg_match('/^[1-9][0-9]*$/D', $rawId)) {
                throw new InvalidArgumentException("O comando {$command} exige --id inteiro positivo.");
            }

            $id = (int) $rawId;
        }

        return new self($command, $dbPath, $filePath, $id);
    }

    public static function usage(): string
    {
        return <<<'TEXT'
CFG-001 - administração da persistência de recorrências

Uso:
  php bin/recurrence.php migrate [--db-path=/caminho/app.sqlite]
  php bin/recurrence.php create  [--db-path=/caminho/app.sqlite] --file=/caminho/recurrence.json
  php bin/recurrence.php list    [--db-path=/caminho/app.sqlite]
  php bin/recurrence.php show    [--db-path=/caminho/app.sqlite] --id=1
  php bin/recurrence.php update  [--db-path=/caminho/app.sqlite] --id=1 --file=/caminho/alteracoes.json
  php bin/recurrence.php enable  [--db-path=/caminho/app.sqlite] --id=1
  php bin/recurrence.php disable [--db-path=/caminho/app.sqlite] --id=1
  php bin/recurrence.php executions [--db-path=/caminho/app.sqlite] --id=1

O caminho também pode ser definido por APP_DB_PATH.
Sem configuração, o padrão é storage/app.sqlite dentro do projeto.

Esta CLI administra somente o SQLite. Ela não carrega o HESK e não cria tickets.
TEXT;
    }

    private static function cleanString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
