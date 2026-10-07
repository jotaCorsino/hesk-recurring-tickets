<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use InvalidArgumentException;

final class DeploymentCliOptions
{
    private function __construct(
        public readonly string $command,
        public readonly string $projectPath,
        public readonly string $dbPath,
        public readonly string $heskPath,
        public readonly ?string $publicPath,
        public readonly string $basePath,
        public readonly string $logPath,
        public readonly string $uiEnabled,
        public readonly string $uiWriteEnabled,
        public readonly ?string $backupPath,
    ) {
    }

    /** @param list<string> $arguments */
    public static function parse(array $arguments): self
    {
        array_shift($arguments);
        $projectPath = dirname(__DIR__);

        if ($arguments === [] || in_array($arguments[0], ['help', '--help', '-h'], true)) {
            return new self('help', $projectPath, '', '', null, '', '', '0', '0', null);
        }

        $command = array_shift($arguments);
        if (!in_array($command, ['check', 'database-status', 'backup'], true)) {
            throw new InvalidArgumentException("Comando desconhecido: {$command}");
        }

        $allowedOptions = match ($command) {
            'check' => ['project-path', 'db-path', 'hesk-path', 'public-path', 'base-path', 'log-path'],
            'database-status' => ['project-path', 'db-path'],
            'backup' => ['project-path', 'db-path', 'backup-path', 'hesk-path', 'public-path'],
        };

        $options = [];
        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];

            if (in_array($argument, ['--help', '-h'], true)) {
                return new self('help', $projectPath, '', '', null, '', '', '0', '0', null);
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

            if (!in_array($option, $allowedOptions, true)) {
                throw new InvalidArgumentException("Opção desconhecida: --{$option}");
            }
            if (array_key_exists($option, $options)) {
                throw new InvalidArgumentException("Opção repetida: --{$option}");
            }
            $options[$option] = self::nonEmpty($value, "--{$option} não pode ficar vazio.");
        }

        $projectPath = $options['project-path'] ?? $projectPath;
        $dbPath = $options['db-path'] ?? self::environment('APP_DB_PATH') ?? $projectPath . '/storage/app.sqlite';
        $heskPath = $options['hesk-path'] ?? self::environment('HESK_PATH') ?? '';
        $publicPath = $options['public-path'] ?? self::environment('ADMIN_UI_PUBLIC_PATH');
        $basePath = $options['base-path'] ?? self::environment('ADMIN_UI_BASE_PATH') ?? '';
        $logPath = $options['log-path'] ?? self::environment('APP_LOG_PATH') ?? $projectPath . '/storage/logs';
        $backupPath = $options['backup-path'] ?? null;

        if ($command === 'backup' && $backupPath === null) {
            throw new InvalidArgumentException('Informe explicitamente --backup-path para o comando backup.');
        }

        return new self(
            $command,
            $projectPath,
            $dbPath,
            $heskPath,
            $publicPath,
            $basePath,
            $logPath,
            self::environment('ADMIN_UI_ENABLED') ?? '0',
            self::environment('ADMIN_UI_WRITE_ENABLED') ?? '0',
            $backupPath,
        );
    }

    public static function usage(): string
    {
        return <<<'TEXT'
DEP-001 - ferramentas controladas de implantação

Uso:
  php bin/deployment.php check --hesk-path=/caminho/hesk [opções]
  php bin/deployment.php database-status --db-path=/caminho/app.sqlite [opções]
  php bin/deployment.php backup --db-path=/caminho/app.sqlite --backup-path=/caminho/privado/backup.sqlite [opções]

Opções:
  --project-path=PATH  Raiz privada do projeto (padrão: raiz deste checkout)
  --db-path=PATH       SQLite (padrão: APP_DB_PATH ou storage/app.sqlite)
  --hesk-path=PATH     HESK (padrão: HESK_PATH)
  --public-path=PATH   Ponto público /recurrent (ou ADMIN_UI_PUBLIC_PATH)
  --base-path=PATH     Prefixo URL (ou ADMIN_UI_BASE_PATH)
  --log-path=PATH      Diretório privado de logs (ou APP_LOG_PATH)
  --backup-path=PATH   Destino explícito e inexistente do backup

check e database-status não criam diretórios, não alteram o SQLite e não
aplicam migrations. backup cria somente o arquivo de destino solicitado.
Nenhum comando executa scheduler/worker ou carrega o bootstrap do HESK.
TEXT;
    }

    private static function environment(string $name): ?string
    {
        $value = getenv($name);
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function nonEmpty(mixed $value, string $message): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($message);
        }
        return trim($value);
    }
}
