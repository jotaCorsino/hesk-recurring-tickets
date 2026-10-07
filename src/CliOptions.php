<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use InvalidArgumentException;

final class CliOptions
{
    private function __construct(
        public readonly string $mode,
        public readonly ?string $heskPath,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public static function parse(array $arguments, string|false|null $environmentPath = null): self
    {
        array_shift($arguments);

        $mode = null;
        $heskPath = self::cleanPath($environmentPath);

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];

            if ($argument === '--help' || $argument === '-h') {
                return new self('help', $heskPath);
            }

            if ($argument === '--check' || $argument === '--execute') {
                $requestedMode = substr($argument, 2);

                if ($mode !== null && $mode !== $requestedMode) {
                    throw new InvalidArgumentException('Use somente um modo: --check ou --execute.');
                }

                $mode = $requestedMode;
                continue;
            }

            if (str_starts_with($argument, '--hesk-path=')) {
                $heskPath = self::cleanPath(substr($argument, strlen('--hesk-path=')));
                continue;
            }

            if ($argument === '--hesk-path') {
                $index++;

                if (!isset($arguments[$index])) {
                    throw new InvalidArgumentException('Informe um valor após --hesk-path.');
                }

                $heskPath = self::cleanPath($arguments[$index]);
                continue;
            }

            throw new InvalidArgumentException("Opção desconhecida: {$argument}");
        }

        if ($mode === null) {
            return new self('help', $heskPath);
        }

        if ($heskPath === null) {
            throw new InvalidArgumentException(
                'Informe a instalação do HESK com --hesk-path=/caminho/do/hesk ou pela variável HESK_PATH.'
            );
        }

        return new self($mode, $heskPath);
    }

    public static function usage(): string
    {
        return <<<'TEXT'
POC-001 - criação de ticket HESK via CLI

Uso:
  php bin/poc-create-ticket.php --check --hesk-path=/caminho/do/hesk
  php bin/poc-create-ticket.php --execute --hesk-path=/caminho/do/hesk

Também é possível definir o caminho pela variável HESK_PATH.
Defina HESK_SERVER_NAME e POC_DEFINITION_PATH (JSON revisado) no ambiente.

Modos:
  --check     Valida HESK, banco e configuração. Não cria ticket.
  --execute   Valida tudo e cria exatamente um ticket da definição JSON.
  --help, -h  Exibe esta ajuda.

Sem --execute, nenhum ticket é criado.
TEXT;
    }

    private static function cleanPath(string|false|null $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path);

        return $path === '' ? null : $path;
    }
}
