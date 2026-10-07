<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use Throwable;
use TicketsRecorrentesHesk\Web\AdminUrl;

final class DeploymentPreflight
{
    private const EXPECTED_MIGRATIONS = [
        '001_initial_schema',
        '002_execution_leases',
        '003_execution_items',
        '004_execution_error_codes',
    ];

    /**
     * @return list<array{status: 'OK'|'AVISO'|'BLOQUEIO', check: string, message: string}>
     */
    public function run(DeploymentCliOptions $options): array
    {
        $results = [];
        $this->checkRuntime($results);
        $this->checkProject($options, $results);
        $this->checkConfiguration($options, $results);
        $this->checkHesk($options, $results);
        $this->checkDatabase($options, $results);
        $this->checkPublicPath($options, $results);
        $this->checkLogPath($options, $results);

        return $results;
    }

    /** @param list<array{status: string, check: string, message: string}> $results */
    public static function hasBlockers(array $results): bool
    {
        foreach ($results as $result) {
            if (($result['status'] ?? null) === 'BLOQUEIO') {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{status: string, check: string, message: string}> $results */
    private function checkRuntime(array &$results): void
    {
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $this->add($results, 'BLOQUEIO', 'php', 'PHP 8.2 ou superior é obrigatório.');
        } else {
            $status = PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 2 ? 'OK' : 'AVISO';
            $message = $status === 'OK'
                ? 'PHP 8.2 compatível.'
                : 'A versão PHP atende ao mínimo, mas difere da 8.2 homologada.';
            $this->add($results, $status, 'php', $message);
        }

        $missing = [];
        foreach (['PDO', 'pdo_sqlite', 'sqlite3', 'mysqli', 'json', 'session'] as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        $this->add(
            $results,
            $missing === [] ? 'OK' : 'BLOQUEIO',
            'php_extensions',
            $missing === [] ? 'Extensões PHP necessárias disponíveis.' : 'Extensões PHP ausentes: ' . implode(', ', $missing) . '.',
        );
    }

    /** @param list<array{status: string, check: string, message: string}> $results */
    private function checkProject(DeploymentCliOptions $options, array &$results): void
    {
        $root = realpath($options->projectPath);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            $this->add($results, 'BLOQUEIO', 'project', 'Raiz privada do projeto inexistente ou ilegível.');
            return;
        }

        $required = [
            'public/index.php', 'src/Database.php', 'src/MigrationRunner.php',
            'bin/recurrence.php', 'bin/scheduler.php', 'bin/worker.php', 'database/migrations',
        ];
        $missing = [];
        foreach ($required as $relative) {
            if (!file_exists($root . '/' . $relative) || !is_readable($root . '/' . $relative)) {
                $missing[] = $relative;
            }
        }
        $this->add(
            $results,
            $missing === [] ? 'OK' : 'BLOQUEIO',
            'project',
            $missing === [] ? 'Estrutura privada e CLIs obrigatórias disponíveis.' : 'Itens do projeto ausentes ou ilegíveis: ' . implode(', ', $missing) . '.',
        );
    }

    /** @param list<array{status: string, check: string, message: string}> $results */
    private function checkConfiguration(DeploymentCliOptions $options, array &$results): void
    {
        try {
            $url = new AdminUrl($options->basePath);
            $this->add(
                $results,
                $url->basePath() === '/recurrent' ? 'OK' : 'AVISO',
                'base_path',
                $url->basePath() === '/recurrent'
                    ? 'Base path /recurrent configurado.'
                    : 'Defina ADMIN_UI_BASE_PATH=/recurrent antes da publicação.',
            );
        } catch (Throwable $error) {
            $this->add($results, 'BLOQUEIO', 'base_path', $error->getMessage());
        }

        foreach (['ADMIN_UI_ENABLED' => $options->uiEnabled, 'ADMIN_UI_WRITE_ENABLED' => $options->uiWriteEnabled] as $name => $value) {
            if (!in_array($value, ['0', '1'], true)) {
                $this->add($results, 'BLOQUEIO', strtolower($name), "{$name} deve ser 0 ou 1.");
            } elseif ($value === '1') {
                $this->add($results, 'AVISO', strtolower($name), "{$name}=1; confirme o gate correspondente somente na etapa prevista.");
            } else {
                $this->add($results, 'OK', strtolower($name), "{$name}=0; barreira fechada.");
            }
        }
    }

    /** @param list<array{status: string, check: string, message: string}> $results */
    private function checkHesk(DeploymentCliOptions $options, array &$results): void
    {
        if ($options->heskPath === '') {
            $this->add($results, 'BLOQUEIO', 'hesk', 'Informe HESK_PATH ou --hesk-path.');
            return;
        }

        $root = realpath($options->heskPath);
        if ($root === false || !is_dir($root)) {
            $this->add($results, 'BLOQUEIO', 'hesk', 'Diretório HESK inexistente.');
            return;
        }

        $required = [
            'hesk_settings.inc.php', 'inc/common.inc.php', 'inc/admin_functions.inc.php',
            'inc/email_functions.inc.php', 'inc/posting_functions.inc.php',
        ];
        foreach ($required as $relative) {
            if (!is_file($root . '/' . $relative) || !is_readable($root . '/' . $relative)) {
                $this->add($results, 'BLOQUEIO', 'hesk', "Arquivo HESK obrigatório ausente ou ilegível: {$relative}.");
                return;
            }
        }

        $settings = file_get_contents($root . '/hesk_settings.inc.php');
        $version = null;
        if (is_string($settings)
            && preg_match('/[\'\"]hesk_version[\'\"]\s*\]\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $settings, $matches)) {
            $version = $matches[1];
        }

        if ($version === null) {
            $this->add($results, 'BLOQUEIO', 'hesk_version', 'Não foi possível confirmar a versão no arquivo de configuração do HESK.');
        } elseif ($version !== '3.7.12') {
            $this->add($results, 'BLOQUEIO', 'hesk_version', "Versão HESK incompatível: {$version}; esperada 3.7.12.");
        } else {
            $this->add($results, 'OK', 'hesk', 'HESK 3.7.12 e arquivos de bootstrap disponíveis.');
        }
    }

    /** @param list<array{status: string, check: string, message: string}> $results */
    private function checkDatabase(DeploymentCliOptions $options, array &$results): void
    {
        if (!is_file($options->dbPath) || !is_readable($options->dbPath)) {
            $this->add($results, 'BLOQUEIO', 'sqlite', 'Banco SQLite inexistente ou ilegível.');
            return;
        }

        if (!is_writable($options->dbPath) || !is_writable(dirname($options->dbPath))) {
            $this->add($results, 'BLOQUEIO', 'sqlite_permissions', 'SQLite e seu diretório precisam permitir escrita ao usuário operacional para WAL.');
        } else {
            $this->add($results, 'OK', 'sqlite_permissions', 'SQLite e diretório permitem a escrita necessária à operação.');
        }

        $database = new Database($options->dbPath);

        try {
            $journalMode = $database->persistedJournalMode();
            $this->add(
                $results,
                $journalMode === 'wal' ? 'OK' : 'BLOQUEIO',
                'sqlite_journal_mode',
                $journalMode === 'wal'
                    ? 'Header SQLite confirma journal_mode=wal (write/read version 2/2).'
                    : 'Header SQLite indica rollback journal (write/read version 1/1); WAL é obrigatório.',
            );
        } catch (Throwable $error) {
            $this->add($results, 'BLOQUEIO', 'sqlite_journal_mode', 'Não foi possível confirmar WAL pelo header: ' . $error->getMessage());
        }

        try {
            $connection = $database->connectImmutableReadOnly();
            $integrity = (string) $connection->query('PRAGMA integrity_check')->fetchColumn();
            $foreignKeyFailures = $connection->query('PRAGMA foreign_key_check')->fetchAll();

            if ($integrity !== 'ok' || $foreignKeyFailures !== []) {
                $this->add($results, 'BLOQUEIO', 'sqlite_integrity', 'O SQLite não passou nas verificações de integridade e foreign keys.');
                return;
            }
            $this->add($results, 'OK', 'sqlite_integrity', 'Integridade SQLite e foreign keys confirmadas em modo imutável.');

            $state = (new MigrationRunner($connection, rtrim($options->projectPath, '/\\') . '/database/migrations'))->inspect();
            if ($state['available'] !== self::EXPECTED_MIGRATIONS) {
                $this->add($results, 'BLOQUEIO', 'migration_files', 'O conjunto de migrations versionadas não corresponde a 001–004.');
            } else {
                $this->add($results, 'OK', 'migration_files', 'Migrations versionadas 001–004 disponíveis.');
            }

            if (!$state['initialized'] || $state['divergent'] !== [] || $state['unknown'] !== []) {
                $details = array_merge($state['divergent'], $state['unknown']);
                $message = !$state['initialized']
                    ? 'Tabela schema_migrations ausente.'
                    : 'Migrations divergentes ou desconhecidas: ' . implode(', ', $details) . '.';
                $this->add($results, 'BLOQUEIO', 'migration_state', $message);
            } elseif ($state['pending'] !== []) {
                $this->add($results, 'AVISO', 'migration_state', 'Migrations pendentes: ' . implode(', ', $state['pending']) . '. Aplicar somente na DEP-001B.');
            } else {
                $this->add($results, 'OK', 'migration_state', 'Banco atualizado e checksums compatíveis.');
            }
        } catch (Throwable $error) {
            $this->add($results, 'BLOQUEIO', 'sqlite', 'Falha na inspeção somente leitura: ' . $error->getMessage());
        }
    }

    /** @param list<array{status: string, check: string, message: string}> $results */
    private function checkPublicPath(DeploymentCliOptions $options, array &$results): void
    {
        if ($options->publicPath === null) {
            $this->add($results, 'AVISO', 'public_path', 'Defina ADMIN_UI_PUBLIC_PATH antes da publicação.');
            return;
        }

        if (!is_dir($options->publicPath)) {
            $this->add($results, 'AVISO', 'public_path', 'O ponto público ainda não existe; crie-o somente na etapa de publicação.');
            return;
        }

        $public = realpath($options->publicPath);
        $projectPublic = realpath(rtrim($options->projectPath, '/\\') . '/public');
        if ($public === false || !is_readable($public)) {
            $this->add($results, 'BLOQUEIO', 'public_path', 'O ponto público é ilegível.');
            return;
        }

        foreach (['storage', 'src', 'database', 'bin', 'tests', 'docs', '.git'] as $private) {
            if (file_exists(rtrim($options->publicPath, '/\\') . '/' . $private)) {
                $this->add($results, 'BLOQUEIO', 'public_separation', "Conteúdo privado exposto no ponto público: {$private}.");
                return;
            }
        }

        if ($projectPublic !== false && $public === $projectPublic) {
            $this->add($results, 'OK', 'public_path', 'O ponto público resolve exclusivamente para public/.');
        } elseif (is_file($public . '/index.php') && is_dir($public . '/assets')) {
            $this->add($results, 'AVISO', 'public_path', 'Entrypoint público mínimo detectado; valide sua origem e atualização no runbook.');
        } else {
            $this->add($results, 'BLOQUEIO', 'public_path', 'O ponto público não contém somente o entrypoint e assets esperados.');
        }
    }

    /** @param list<array{status: string, check: string, message: string}> $results */
    private function checkLogPath(DeploymentCliOptions $options, array &$results): void
    {
        if (!is_dir($options->logPath)) {
            $this->add($results, 'AVISO', 'logs', 'O diretório privado de logs ainda não existe.');
            return;
        }

        if (!is_writable($options->logPath)) {
            $this->add($results, 'BLOQUEIO', 'logs', 'O diretório de logs não permite escrita operacional.');
            return;
        }

        $public = $options->publicPath === null ? null : realpath($options->publicPath);
        $logs = realpath($options->logPath);
        if ($public !== false && $public !== null && $logs !== false && self::inside($logs, $public)) {
            $this->add($results, 'BLOQUEIO', 'logs', 'O diretório de logs está dentro do ponto público.');
            return;
        }

        $this->add($results, 'OK', 'logs', 'Diretório privado de logs disponível para escrita.');
    }

    private static function inside(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path . '/', rtrim($parent, '/\\') . '/');
    }

    /** @param list<array{status: string, check: string, message: string}> $results */
    private function add(array &$results, string $status, string $check, string $message): void
    {
        $results[] = ['status' => $status, 'check' => $check, 'message' => $message];
    }
}
