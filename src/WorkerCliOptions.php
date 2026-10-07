<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use InvalidArgumentException;

final class WorkerCliOptions
{
    private function __construct(
        public readonly string $command,
        public readonly string $dbPath,
        public readonly string $heskPath,
        public readonly ?int $id,
        public readonly ?string $worker,
        public readonly int $leaseSeconds,
    ) {
    }

    /** @param list<string> $arguments */
    public static function parse(
        array $arguments,
        string|false|null $environmentDbPath = null,
        string|false|null $environmentHeskPath = null,
    ): self {
        array_shift($arguments);
        $dbPath = self::cleanString($environmentDbPath)
            ?? dirname(__DIR__) . '/storage/app.sqlite';
        $heskPath = self::cleanString($environmentHeskPath) ?? '';

        if ($arguments === [] || in_array($arguments[0], ['--help', '-h', 'help'], true)) {
            return new self('help', $dbPath, $heskPath, null, null, 300);
        }

        $command = array_shift($arguments);

        if (!in_array($command, ['check', 'run'], true)) {
            throw new InvalidArgumentException("Comando desconhecido: {$command}");
        }

        $options = [];

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];

            if (in_array($argument, ['--help', '-h'], true)) {
                return new self('help', $dbPath, $heskPath, null, null, 300);
            }

            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException("Argumento inesperado: {$argument}");
            }

            $option = substr($argument, 2);

            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            } else {
                $index++;

                if (!isset($arguments[$index]) || str_starts_with($arguments[$index], '--')) {
                    throw new InvalidArgumentException("Informe um valor para --{$option}.");
                }

                $value = $arguments[$index];
            }

            if (!in_array($option, ['db-path', 'hesk-path', 'id', 'worker', 'lease-seconds'], true)) {
                throw new InvalidArgumentException("Opção desconhecida: --{$option}");
            }

            if (array_key_exists($option, $options)) {
                throw new InvalidArgumentException("Opção repetida: --{$option}");
            }

            $options[$option] = $value;
        }

        $allowed = $command === 'check'
            ? ['db-path', 'hesk-path', 'id']
            : ['db-path', 'hesk-path', 'id', 'worker', 'lease-seconds'];

        foreach (array_keys($options) as $option) {
            if (!in_array($option, $allowed, true)) {
                throw new InvalidArgumentException("O comando {$command} não aceita --{$option}.");
            }
        }

        $dbPath = isset($options['db-path'])
            ? (self::cleanString($options['db-path'])
                ?? throw new InvalidArgumentException('--db-path não pode ficar vazio.'))
            : $dbPath;
        $heskPath = isset($options['hesk-path'])
            ? (self::cleanString($options['hesk-path'])
                ?? throw new InvalidArgumentException('--hesk-path não pode ficar vazio.'))
            : $heskPath;

        if ($heskPath === '') {
            throw new InvalidArgumentException('Informe a instalação do HESK por --hesk-path ou HESK_PATH.');
        }

        $rawId = $options['id'] ?? null;

        if (!is_string($rawId) || !preg_match('/^[1-9][0-9]*$/D', $rawId)) {
            throw new InvalidArgumentException("O comando {$command} exige --id inteiro positivo.");
        }

        $worker = self::cleanString($options['worker'] ?? null);

        if ($command === 'run' && $worker === null) {
            throw new InvalidArgumentException('O comando run exige --worker.');
        }

        if ($worker !== null && strlen($worker) > 255) {
            throw new InvalidArgumentException('--worker deve ter no máximo 255 caracteres.');
        }

        $rawLeaseSeconds = $options['lease-seconds'] ?? '300';

        if (!is_string($rawLeaseSeconds)
            || !preg_match('/^[1-9][0-9]*$/D', $rawLeaseSeconds)
            || (int) $rawLeaseSeconds < 30
            || (int) $rawLeaseSeconds > 3600) {
            throw new InvalidArgumentException('--lease-seconds deve estar entre 30 e 3600.');
        }

        return new self(
            $command,
            $dbPath,
            $heskPath,
            (int) $rawId,
            $worker,
            (int) $rawLeaseSeconds
        );
    }

    public static function usage(): string
    {
        return <<<'TEXT'
BATCH-001 - worker de lotes recorrentes

Uso:
  php bin/worker.php check --db-path=/caminho/app.sqlite --hesk-path=/caminho/hesk --id=1
  php bin/worker.php run --db-path=/caminho/app.sqlite --hesk-path=/caminho/hesk --id=1 --worker=hostname:pid [--lease-seconds=300]

O caminho do SQLite também pode vir de APP_DB_PATH e o HESK de HESK_PATH.
Lease padrão: 300 segundos; mínimo 30 e máximo 3600.
check não faz claim, não materializa itens e não cria tickets.
run nunca imprime o lease token e não envia notificação ao solicitante.
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
