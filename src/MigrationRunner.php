<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $connection,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * @return list<string> versões aplicadas nesta chamada
     */
    public function migrate(): array
    {
        $this->ensureMigrationTable();
        $applied = $this->appliedMigrations();
        $executed = [];

        foreach ($this->migrationFiles() as $migration) {
            $version = $migration['version'];

            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $migration['checksum'])) {
                    throw new RuntimeException(
                        "A migration {$version} já foi aplicada, mas seu checksum foi alterado."
                    );
                }

                continue;
            }

            $sql = file_get_contents($migration['path']);

            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException("Migration vazia ou ilegível: {$migration['path']}");
            }

            $this->connection->beginTransaction();

            try {
                $this->connection->exec($sql);
                $statement = $this->connection->prepare(
                    'INSERT INTO schema_migrations (version, checksum, applied_at)
                     VALUES (:version, :checksum, :applied_at)'
                );
                $statement->execute([
                    'version' => $version,
                    'checksum' => $migration['checksum'],
                    'applied_at' => self::utcNow(),
                ]);
                $this->connection->commit();
                $executed[] = $version;
            } catch (Throwable $error) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                throw new RuntimeException(
                    "Falha ao aplicar a migration {$version}: {$error->getMessage()}",
                    0,
                    $error
                );
            }
        }

        return $executed;
    }

    public function assertUpToDate(): void
    {
        if (!$this->hasMigrationTable()) {
            throw new RuntimeException('Banco não inicializado. Execute o comando migrate primeiro.');
        }

        $applied = $this->appliedMigrations();
        $pending = [];

        foreach ($this->migrationFiles() as $migration) {
            $version = $migration['version'];

            if (!isset($applied[$version])) {
                $pending[] = $version;
                continue;
            }

            if (!hash_equals($applied[$version], $migration['checksum'])) {
                throw new RuntimeException(
                    "A migration {$version} aplicada não corresponde ao arquivo versionado atual."
                );
            }
        }

        if ($pending !== []) {
            throw new RuntimeException(
                'Existem migrations pendentes: ' . implode(', ', $pending) . '. Execute migrate.'
            );
        }
    }

    /**
     * Inspeciona o estado sem criar tabela nem aplicar migration.
     *
     * @return array{
     *   initialized: bool,
     *   available: list<string>,
     *   applied: list<string>,
     *   pending: list<string>,
     *   divergent: list<string>,
     *   unknown: list<string>
     * }
     */
    public function inspect(): array
    {
        $files = $this->migrationFiles();
        $availableByVersion = [];

        foreach ($files as $migration) {
            $availableByVersion[$migration['version']] = $migration['checksum'];
        }

        if (!$this->hasMigrationTable()) {
            return [
                'initialized' => false,
                'available' => array_keys($availableByVersion),
                'applied' => [],
                'pending' => array_keys($availableByVersion),
                'divergent' => [],
                'unknown' => [],
            ];
        }

        $applied = $this->appliedMigrations();
        $pending = [];
        $divergent = [];

        foreach ($availableByVersion as $version => $checksum) {
            if (!isset($applied[$version])) {
                $pending[] = $version;
            } elseif (!hash_equals($applied[$version], $checksum)) {
                $divergent[] = $version;
            }
        }

        return [
            'initialized' => true,
            'available' => array_keys($availableByVersion),
            'applied' => array_keys($applied),
            'pending' => $pending,
            'divergent' => $divergent,
            'unknown' => array_values(array_diff(array_keys($applied), array_keys($availableByVersion))),
        ];
    }

    private function ensureMigrationTable(): void
    {
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY NOT NULL,
                checksum TEXT NOT NULL,
                applied_at TEXT NOT NULL
            )'
        );
    }

    private function hasMigrationTable(): bool
    {
        $statement = $this->connection->query(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name='schema_migrations' LIMIT 1"
        );

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<string, string> version => checksum
     */
    private function appliedMigrations(): array
    {
        $migrations = [];
        $statement = $this->connection->query(
            'SELECT version, checksum FROM schema_migrations ORDER BY version'
        );

        foreach ($statement->fetchAll() as $row) {
            $migrations[(string) $row['version']] = (string) $row['checksum'];
        }

        return $migrations;
    }

    /**
     * @return list<array{version: string, path: string, checksum: string}>
     */
    private function migrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException("Diretório de migrations não encontrado: {$this->migrationsPath}");
        }

        $paths = glob(rtrim($this->migrationsPath, '/\\') . DIRECTORY_SEPARATOR . '*.sql');

        if ($paths === false) {
            throw new RuntimeException("Não foi possível listar migrations em {$this->migrationsPath}");
        }

        sort($paths, SORT_STRING);
        $migrations = [];

        foreach ($paths as $path) {
            $filename = basename($path);

            if (!preg_match('/^(\d{3}_[a-z0-9_]+)\.sql$/', $filename, $matches)) {
                throw new RuntimeException("Nome de migration inválido: {$filename}");
            }

            $checksum = hash_file('sha256', $path);

            if ($checksum === false) {
                throw new RuntimeException("Não foi possível calcular o checksum de {$filename}");
            }

            $migrations[] = [
                'version' => $matches[1],
                'path' => $path,
                'checksum' => $checksum,
            ];
        }

        return $migrations;
    }

    private static function utcNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
