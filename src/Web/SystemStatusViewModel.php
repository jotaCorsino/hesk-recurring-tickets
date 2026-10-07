<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

final class SystemStatusViewModel
{
    /**
     * @param array{database_available: bool, migrations_up_to_date: bool|null} $infrastructure
     * @return array{
     *   php_version: string,
     *   panel: array{label: string, tone: string},
     *   write: array{label: string, tone: string},
     *   database: array{label: string, tone: string},
     *   migrations: array{label: string, tone: string}
     * }
     */
    public static function present(
        string $phpVersion,
        bool $panelEnabled,
        bool $writeEnabled,
        array $infrastructure,
    ): array {
        $migrations = match ($infrastructure['migrations_up_to_date']) {
            true => ['label' => 'Atualizadas', 'tone' => 'succeeded'],
            false => ['label' => 'Requerem atenção', 'tone' => 'failed'],
            null => ['label' => 'Verificação indisponível', 'tone' => 'inactive'],
        };

        return [
            'php_version' => $phpVersion,
            'panel' => $panelEnabled
                ? ['label' => 'Habilitado', 'tone' => 'succeeded']
                : ['label' => 'Desabilitado', 'tone' => 'inactive'],
            'write' => $writeEnabled
                ? ['label' => 'Habilitada', 'tone' => 'active']
                : ['label' => 'Somente leitura', 'tone' => 'inactive'],
            'database' => $infrastructure['database_available']
                ? ['label' => 'Disponível', 'tone' => 'succeeded']
                : ['label' => 'Indisponível', 'tone' => 'failed'],
            'migrations' => $migrations,
        ];
    }
}
