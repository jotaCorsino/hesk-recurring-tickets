<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use InvalidArgumentException;

final class SchedulerCliOptions
{
    private const COMMANDS = ['check', 'run'];

    private function __construct(
        public readonly string $command,
        public readonly string $dbPath,
        public readonly int $limit,
    ) {
    }

    /** @param list<string> $arguments */
    public static function parse(array $arguments, string|false|null $environmentPath = null): self
    {
        array_shift($arguments);
        $dbPath = self::cleanString($environmentPath) ?? dirname(__DIR__) . '/storage/app.sqlite';

        if ($arguments === [] || in_array($arguments[0], ['--help', '-h', 'help'], true)) {
            return new self('help', $dbPath, 100);
        }

        $command = array_shift($arguments);

        if (!in_array($command, self::COMMANDS, true)) {
            throw new InvalidArgumentException("Comando desconhecido: {$command}");
        }

        $options = [];

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];

            if (in_array($argument, ['--help', '-h'], true)) {
                return new self('help', $dbPath, 100);
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

            if (!in_array($option, ['db-path', 'limit'], true)) {
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

        $rawLimit = $options['limit'] ?? '100';

        if (!is_string($rawLimit) || !preg_match('/^[1-9][0-9]*$/D', $rawLimit)) {
            throw new InvalidArgumentException('--limit deve ser um inteiro entre 1 e 1000.');
        }

        $limit = (int) $rawLimit;

        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('--limit deve estar entre 1 e 1000.');
        }

        return new self($command, $dbPath, $limit);
    }

    public static function usage(): string
    {
        return <<<'TEXT'
SCH-001 - scheduler de recorrências

Uso:
  php bin/scheduler.php check [--db-path=/caminho/app.sqlite] [--limit=100]
  php bin/scheduler.php run   [--db-path=/caminho/app.sqlite] [--limit=100]

O caminho também pode ser definido por APP_DB_PATH.
O limite padrão é 100 e deve estar entre 1 e 1000 recorrências.

check não grava dados. run registra executions pending e avança next_run_at.
Esta CLI não carrega o HESK e não cria tickets.
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
