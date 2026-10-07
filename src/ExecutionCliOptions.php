<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use InvalidArgumentException;

final class ExecutionCliOptions
{
    private const COMMANDS = ['list', 'show', 'items', 'claim', 'heartbeat', 'finish', 'retry'];
    private const STATUSES = ['pending', 'running', 'succeeded', 'failed', 'partial'];
    private const RESULTS = ['succeeded', 'failed', 'partial'];

    private function __construct(
        public readonly string $command,
        public readonly string $dbPath,
        public readonly ?string $status,
        public readonly ?int $id,
        public readonly ?string $worker,
        public readonly int $leaseSeconds,
        public readonly ?string $token,
        public readonly ?string $result,
        public readonly ?string $errorMessage,
    ) {
    }

    /** @param list<string> $arguments */
    public static function parse(array $arguments, string|false|null $environmentPath = null): self
    {
        array_shift($arguments);
        $dbPath = self::cleanString($environmentPath) ?? dirname(__DIR__) . '/storage/app.sqlite';

        if ($arguments === [] || in_array($arguments[0], ['--help', '-h', 'help'], true)) {
            return new self('help', $dbPath, null, null, null, 300, null, null, null);
        }

        $command = array_shift($arguments);

        if (!in_array($command, self::COMMANDS, true)) {
            throw new InvalidArgumentException("Comando desconhecido: {$command}");
        }

        $options = [];

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];

            if (in_array($argument, ['--help', '-h'], true)) {
                return new self('help', $dbPath, null, null, null, 300, null, null, null);
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

            if (!in_array(
                $option,
                ['db-path', 'status', 'id', 'worker', 'lease-seconds', 'token', 'result', 'error'],
                true
            )) {
                throw new InvalidArgumentException("Opção desconhecida: --{$option}");
            }

            if (array_key_exists($option, $options)) {
                throw new InvalidArgumentException("Opção repetida: --{$option}");
            }

            $options[$option] = $value;
        }

        $allowed = [
            'list' => ['db-path', 'status'],
            'show' => ['db-path', 'id'],
            'items' => ['db-path', 'id'],
            'claim' => ['db-path', 'id', 'worker', 'lease-seconds'],
            'heartbeat' => ['db-path', 'id', 'token', 'lease-seconds'],
            'finish' => ['db-path', 'id', 'token', 'result', 'error'],
            'retry' => ['db-path', 'id'],
        ];

        foreach (array_keys($options) as $option) {
            if (!in_array($option, $allowed[$command], true)) {
                throw new InvalidArgumentException("O comando {$command} não aceita --{$option}.");
            }
        }

        if (isset($options['db-path'])) {
            $dbPath = self::cleanString($options['db-path'])
                ?? throw new InvalidArgumentException('--db-path não pode ficar vazio.');
        }

        $status = self::cleanString($options['status'] ?? null);

        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('--status possui valor inválido.');
        }

        $id = null;

        if (isset($options['id'])) {
            if (!preg_match('/^[1-9][0-9]*$/D', $options['id'])) {
                throw new InvalidArgumentException('--id deve ser um inteiro positivo.');
            }

            $id = (int) $options['id'];
        }

        if (in_array($command, ['show', 'items', 'heartbeat', 'finish', 'retry'], true) && $id === null) {
            throw new InvalidArgumentException("O comando {$command} exige --id.");
        }

        $worker = self::cleanString($options['worker'] ?? null);

        if ($command === 'claim' && $worker === null) {
            throw new InvalidArgumentException('O comando claim exige --worker.');
        }

        if ($worker !== null && strlen($worker) > 255) {
            throw new InvalidArgumentException('--worker deve ter no máximo 255 caracteres.');
        }

        $rawLeaseSeconds = $options['lease-seconds'] ?? '300';

        if (!preg_match('/^[1-9][0-9]*$/D', $rawLeaseSeconds)) {
            throw new InvalidArgumentException('--lease-seconds deve ser um inteiro entre 30 e 3600.');
        }

        $leaseSeconds = (int) $rawLeaseSeconds;

        if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('--lease-seconds deve estar entre 30 e 3600.');
        }

        $token = self::cleanString($options['token'] ?? null);

        if (in_array($command, ['heartbeat', 'finish'], true)) {
            if ($token === null || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
                throw new InvalidArgumentException('--token deve ser hexadecimal com 64 caracteres.');
            }
        }

        $result = self::cleanString($options['result'] ?? null);

        if ($command === 'finish' && !in_array($result, self::RESULTS, true)) {
            throw new InvalidArgumentException(
                'O comando finish exige --result=succeeded, failed ou partial.'
            );
        }

        $errorMessage = self::cleanString($options['error'] ?? null);

        if ($result === 'failed' && $errorMessage === null) {
            throw new InvalidArgumentException('--error é obrigatório para --result=failed.');
        }

        if ($result === 'succeeded' && $errorMessage !== null) {
            throw new InvalidArgumentException('--error não é aceito para --result=succeeded.');
        }

        return new self(
            $command,
            $dbPath,
            $status,
            $id,
            $worker,
            $leaseSeconds,
            $token,
            $result,
            $errorMessage
        );
    }

    public static function usage(): string
    {
        return <<<'TEXT'
SAFE-001 - administração de executions e leases

Uso:
  php bin/execution.php list [--db-path=/caminho/app.sqlite] [--status=pending]
  php bin/execution.php show [--db-path=/caminho/app.sqlite] --id=1
  php bin/execution.php items [--db-path=/caminho/app.sqlite] --id=1
  php bin/execution.php claim [--db-path=/caminho/app.sqlite] --worker=nome [--id=1] [--lease-seconds=300]
  php bin/execution.php heartbeat [--db-path=/caminho/app.sqlite] --id=1 --token=<TOKEN> [--lease-seconds=300]
  php bin/execution.php finish [--db-path=/caminho/app.sqlite] --id=1 --token=<TOKEN> --result=succeeded
  php bin/execution.php finish [--db-path=/caminho/app.sqlite] --id=1 --token=<TOKEN> --result=failed --error="mensagem"
  php bin/execution.php retry [--db-path=/caminho/app.sqlite] --id=1

Status para list: pending, running, succeeded, failed ou partial.
Lease padrão: 300 segundos; mínimo 30 e máximo 3600.
O caminho também pode ser definido por APP_DB_PATH.

Esta CLI não carrega o HESK, não cria tickets, não cria executions e não altera next_run_at.
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
