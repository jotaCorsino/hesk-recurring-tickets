<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use InvalidArgumentException;

final class OrchestratorCliOptions
{
    private function __construct(
        public readonly string $command,
        public readonly string $dbPath,
        public readonly string $heskPath,
        public readonly string $lockPath,
        public readonly int $schedulerLimit,
        public readonly int $executionLimit,
        public readonly int $leaseSeconds,
        public readonly string $worker,
    ) {
    }

    /** @param list<string> $arguments */
    public static function parse(
        array $arguments,
        string|false|null $environmentDbPath = null,
        string|false|null $environmentHeskPath = null,
        string|false|null $environmentLockPath = null,
    ): self {
        array_shift($arguments);
        $dbPath = self::clean($environmentDbPath) ?? dirname(__DIR__) . '/storage/app.sqlite';
        $heskPath = self::clean($environmentHeskPath) ?? '';
        $lockPath = self::clean($environmentLockPath) ?? dirname(__DIR__) . '/storage/orchestrator.lock';

        if ($arguments === [] || in_array($arguments[0], ['help', '--help', '-h'], true)) {
            return new self('help', $dbPath, $heskPath, $lockPath, 10, 10, 300, 'orchestrator');
        }

        $command = array_shift($arguments);

        if (!in_array($command, ['check', 'run'], true)) {
            throw new InvalidArgumentException("Comando desconhecido: {$command}");
        }

        $options = [];

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];

            if (in_array($argument, ['--help', '-h'], true)) {
                return new self('help', $dbPath, $heskPath, $lockPath, 10, 10, 300, 'orchestrator');
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

            $allowed = ['db-path', 'hesk-path', 'lock-path', 'scheduler-limit', 'execution-limit', 'lease-seconds', 'worker'];

            if (!in_array($option, $allowed, true)) {
                throw new InvalidArgumentException("Opção desconhecida: --{$option}");
            }

            if (array_key_exists($option, $options)) {
                throw new InvalidArgumentException("Opção repetida: --{$option}");
            }

            $options[$option] = $value;
        }

        foreach (['db-path', 'hesk-path', 'lock-path'] as $name) {
            if (isset($options[$name])) {
                $value = self::clean($options[$name])
                    ?? throw new InvalidArgumentException("--{$name} não pode ficar vazio.");

                match ($name) {
                    'db-path' => $dbPath = $value,
                    'hesk-path' => $heskPath = $value,
                    'lock-path' => $lockPath = $value,
                };
            }
        }

        $schedulerLimit = self::integer($options['scheduler-limit'] ?? '10', 'scheduler-limit', 1, 1000);
        $executionLimit = self::integer($options['execution-limit'] ?? '10', 'execution-limit', 1, 100);
        $leaseSeconds = self::integer($options['lease-seconds'] ?? '300', 'lease-seconds', 30, 3600);
        $worker = self::clean($options['worker'] ?? null) ?? 'orchestrator:' . getmypid();

        if (strlen($worker) > 255) {
            throw new InvalidArgumentException('--worker deve ter no máximo 255 caracteres.');
        }

        if ($command === 'check') {
            foreach (['hesk-path', 'lock-path', 'execution-limit', 'lease-seconds', 'worker'] as $runOnly) {
                if (array_key_exists($runOnly, $options)) {
                    throw new InvalidArgumentException("O comando check não aceita --{$runOnly}.");
                }
            }
        } elseif ($heskPath === '') {
            throw new InvalidArgumentException('Informe a instalação do HESK por --hesk-path ou HESK_PATH.');
        }

        return new self(
            $command,
            $dbPath,
            $heskPath,
            $lockPath,
            $schedulerLimit,
            $executionLimit,
            $leaseSeconds,
            $worker
        );
    }

    public static function usage(): string
    {
        return <<<'TEXT'
DEP-001D - orquestrador do ciclo recorrente

Uso:
  php bin/orchestrator.php check [--db-path=/caminho/app.sqlite] [--scheduler-limit=10]
  php bin/orchestrator.php run --hesk-path=/caminho/hesk [--db-path=/caminho/app.sqlite] [--lock-path=/caminho/orchestrator.lock] [--scheduler-limit=10] [--execution-limit=10] [--lease-seconds=300] [--worker=hostname:pid]

APP_DB_PATH, HESK_PATH e ORCHESTRATOR_LOCK_PATH também são aceitos.
check é estritamente somente leitura e não carrega o HESK.
run usa lock global local não bloqueante, agenda vencimentos e processa uma seleção finita.
TEXT;
    }

    private static function integer(mixed $value, string $name, int $minimum, int $maximum): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new InvalidArgumentException("--{$name} deve estar entre {$minimum} e {$maximum}.");
        }

        $number = (int) $value;

        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException("--{$name} deve estar entre {$minimum} e {$maximum}.");
        }

        return $number;
    }

    private static function clean(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
