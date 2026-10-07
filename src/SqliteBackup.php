<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use RuntimeException;
use SQLite3;
use Throwable;

class SqliteBackup
{
    /**
     * @return array{
     *   source_path: string,
     *   backup_path: string,
     *   source_sha256: string,
     *   backup_sha256: string,
     *   backup_size: int,
     *   mode: string,
     *   integrity: string,
     *   foreign_key_failures: int
     * }
     */
    public function create(
        string $sourcePath,
        string $backupPath,
        string $projectPath,
        ?string $publicPath = null,
        ?string $heskPath = null,
    ): array {
        if (!extension_loaded('sqlite3')) {
            throw new RuntimeException('A extensão SQLite3 não está disponível neste PHP.');
        }

        $source = realpath($sourcePath);
        if ($source === false || !is_file($source) || !is_readable($source)) {
            throw new RuntimeException("Banco SQLite de origem inexistente ou ilegível: {$sourcePath}");
        }

        if ((new Database($source))->persistedJournalMode() !== 'wal') {
            throw new RuntimeException('O backup operacional exige origem com journal_mode WAL persistido.');
        }

        $walPath = $source . '-wal';
        if (is_file($walPath) && (filesize($walPath) ?: 0) > 0) {
            throw new RuntimeException('Existe WAL pendente; o backup foi recusado sem executar checkpoint automático.');
        }

        $destination = $this->validateDestination($backupPath, $source, $projectPath, $publicPath, $heskPath);
        $sourceHashBefore = hash_file('sha256', $source);
        if ($sourceHashBefore === false) {
            throw new RuntimeException('Não foi possível calcular o SHA-256 do banco de origem.');
        }

        $created = false;
        $sourceDatabase = null;
        $backupDatabase = null;

        try {
            $reservation = @fopen($destination, 'x+b');
            if ($reservation === false) {
                throw new RuntimeException('Não foi possível reservar o destino exclusivo do backup.');
            }
            $created = true;
            fclose($reservation);
            if (!@chmod($destination, 0600)) {
                throw new RuntimeException('Não foi possível restringir o destino do backup a 0600.');
            }

            $sourceDatabase = new SQLite3($source, SQLITE3_OPEN_READONLY);
            $sourceDatabase->enableExceptions(true);
            $sourceDatabase->exec('PRAGMA query_only = ON');
            $backupDatabase = new SQLite3($destination, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $backupDatabase->enableExceptions(true);

            if (!$sourceDatabase->backup($backupDatabase)) {
                throw new RuntimeException('A API nativa do SQLite não concluiu o snapshot.');
            }

            $backupDatabase->close();
            $backupDatabase = null;
            $sourceDatabase->close();
            $sourceDatabase = null;

            clearstatcache(true, $walPath);
            if (is_file($walPath) && (filesize($walPath) ?: 0) > 0) {
                throw new RuntimeException('Surgiu WAL pendente durante o backup; o snapshot foi rejeitado.');
            }

            if (!@chmod($destination, 0600)) {
                throw new RuntimeException('Não foi possível aplicar a permissão final 0600 ao backup.');
            }

            $validation = $this->validateBackup($destination);
            $this->assertNoBackupAuxiliaries($destination);
            clearstatcache(true, $destination);
            $backupHash = hash_file('sha256', $destination);
            $backupSize = filesize($destination);
            $mode = fileperms($destination);

            if ($backupHash === false || $backupSize === false || $mode === false) {
                throw new RuntimeException('Não foi possível medir o backup concluído.');
            }
            if (($mode & 0777) !== 0600) {
                throw new RuntimeException('O backup não permaneceu com permissão 0600.');
            }

            $this->assertNoBackupAuxiliaries($destination);
            $sourceHashAfter = $this->assertFinalSourceState($source, $walPath, $sourceHashBefore);

            return [
                'source_path' => $source,
                'backup_path' => $destination,
                'source_sha256' => $sourceHashAfter,
                'backup_sha256' => $backupHash,
                'backup_size' => $backupSize,
                'mode' => '0600',
                'integrity' => $validation['integrity'],
                'foreign_key_failures' => $validation['foreign_key_failures'],
            ];
        } catch (Throwable $error) {
            if ($backupDatabase instanceof SQLite3) {
                $backupDatabase->close();
            }
            if ($sourceDatabase instanceof SQLite3) {
                $sourceDatabase->close();
            }
            if ($created) {
                foreach ([$destination, $destination . '-wal', $destination . '-shm', $destination . '-journal'] as $incomplete) {
                    if (is_file($incomplete) || is_link($incomplete)) {
                        @unlink($incomplete);
                    }
                }
            }

            if ($error instanceof RuntimeException) {
                throw $error;
            }
            throw new RuntimeException('Falha ao criar o backup SQLite: ' . $error->getMessage(), 0, $error);
        }
    }

    private function validateDestination(
        string $backupPath,
        string $source,
        string $projectPath,
        ?string $publicPath,
        ?string $heskPath,
    ): string {
        if (trim($backupPath) === '' || str_contains($backupPath, "\0")) {
            throw new RuntimeException('Informe um caminho de backup válido.');
        }
        if (!$this->isAbsolute($backupPath)) {
            throw new RuntimeException('O caminho do backup deve ser absoluto.');
        }
        if (file_exists($backupPath) || is_link($backupPath)) {
            throw new RuntimeException('O destino do backup já existe e não será sobrescrito.');
        }

        $parent = realpath(dirname($backupPath));
        if ($parent === false || !is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException('O diretório de destino do backup deve existir e permitir escrita.');
        }
        $destination = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($backupPath);
        if ($destination === $source) {
            throw new RuntimeException('O destino do backup não pode ser o banco de origem.');
        }

        $project = realpath($projectPath);
        if ($project === false || !is_dir($project)) {
            throw new RuntimeException('A raiz privada do projeto não pôde ser validada.');
        }

        $protected = [
            'checkout' => $project,
            'ponto público' => $publicPath === null ? false : realpath($publicPath),
            'HESK' => $heskPath === null || $heskPath === '' ? false : realpath($heskPath),
        ];
        foreach ($protected as $label => $root) {
            if (is_string($root) && $this->inside($destination, $root)) {
                throw new RuntimeException("O destino do backup não pode ficar dentro do {$label}.");
            }
        }

        return $destination;
    }

    /** @return array{integrity: string, foreign_key_failures: int} */
    private function validateBackup(string $path): array
    {
        $connection = (new Database($path))->connectImmutableReadOnly();
        $integrity = (string) $connection->query('PRAGMA integrity_check')->fetchColumn();
        $foreignKeyFailures = count($connection->query('PRAGMA foreign_key_check')->fetchAll());

        if ($integrity !== 'ok' || $foreignKeyFailures !== 0) {
            throw new RuntimeException('O backup não passou nas verificações de integridade e foreign keys.');
        }

        return ['integrity' => $integrity, 'foreign_key_failures' => $foreignKeyFailures];
    }

    private function assertNoBackupAuxiliaries(string $destination): void
    {
        foreach ([$destination . '-wal', $destination . '-shm', $destination . '-journal'] as $auxiliary) {
            clearstatcache(true, $auxiliary);
            if (file_exists($auxiliary) || is_link($auxiliary)) {
                throw new RuntimeException('O snapshot criou um arquivo auxiliar inesperado e foi rejeitado.');
            }
        }
    }

    protected function assertFinalSourceState(string $source, string $walPath, string $expectedHash): string
    {
        clearstatcache(true, $walPath);
        if (is_file($walPath) && (filesize($walPath) ?: 0) > 0) {
            throw new RuntimeException('Surgiu WAL pendente na verificação final; o snapshot foi rejeitado.');
        }

        $sourceHash = hash_file('sha256', $source);
        if ($sourceHash === false || !hash_equals($expectedHash, $sourceHash)) {
            throw new RuntimeException('O banco de origem mudou durante o backup; o snapshot foi rejeitado.');
        }

        return $sourceHash;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function inside(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path, rtrim($parent, '/\\') . DIRECTORY_SEPARATOR);
    }
}
