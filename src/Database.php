<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private ?PDO $connection = null;
    private ?PDO $readOnlyConnection = null;
    private ?PDO $existingWritableConnection = null;
    private ?PDO $immutableReadOnlyConnection = null;

    public function __construct(private readonly string $path)
    {
        if (trim($this->path) === '') {
            throw new RuntimeException('O caminho do banco SQLite não pode ficar vazio.');
        }
    }

    public function connect(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('A extensão PDO SQLite não está disponível neste PHP.');
        }

        $this->prepareParentDirectory();

        try {
            $connection = new PDO('sqlite:' . $this->path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->exec('PRAGMA busy_timeout = 5000');
            $journalMode = strtolower((string) $connection->query('PRAGMA journal_mode = WAL')->fetchColumn());

            if ($journalMode !== 'wal' && $this->path !== ':memory:') {
                throw new RuntimeException(
                    "SQLite não conseguiu ativar journal_mode=WAL; modo retornado: {$journalMode}."
                );
            }

            if ((int) $connection->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
                throw new RuntimeException('SQLite não confirmou PRAGMA foreign_keys=ON.');
            }

            if ($this->path !== ':memory:' && is_file($this->path)) {
                @chmod($this->path, 0660);
            }

            $this->connection = $connection;

            return $this->connection;
        } catch (PDOException $error) {
            throw new RuntimeException(
                "Não foi possível abrir o banco SQLite em {$this->path}: {$error->getMessage()}",
                0,
                $error
            );
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Lê o modo persistido diretamente do header SQLite, sem abrir conexão.
     */
    public function persistedJournalMode(): string
    {
        $resolvedPath = realpath($this->path);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException("Banco SQLite inexistente ou ilegível: {$this->path}");
        }

        $handle = @fopen($resolvedPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível ler o header do SQLite.');
        }

        try {
            $header = fread($handle, 20);
        } finally {
            fclose($handle);
        }

        if (!is_string($header)
            || strlen($header) !== 20
            || substr($header, 0, 16) !== "SQLite format 3\0") {
            throw new RuntimeException('O arquivo não possui um header SQLite válido.');
        }

        $writeVersion = ord($header[18]);
        $readVersion = ord($header[19]);

        if ($writeVersion === 2 && $readVersion === 2) {
            return 'wal';
        }

        if ($writeVersion === 1 && $readVersion === 1) {
            return 'rollback';
        }

        throw new RuntimeException(
            "Versões de journal não reconhecidas no header SQLite: {$writeVersion}/{$readVersion}."
        );
    }

    public function connectReadOnly(): PDO
    {
        if ($this->readOnlyConnection !== null) {
            return $this->readOnlyConnection;
        }

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('A extensão PDO SQLite não está disponível neste PHP.');
        }

        $resolvedPath = realpath($this->path);

        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException("Banco SQLite inexistente ou ilegível: {$this->path}");
        }

        try {
            $connection = new PDO('sqlite:file:' . self::uriPath($resolvedPath) . '?mode=ro', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec('PRAGMA query_only = ON');
            $connection->exec('PRAGMA busy_timeout = 5000');
            $this->readOnlyConnection = $connection;

            return $connection;
        } catch (PDOException $error) {
            throw new RuntimeException("Não foi possível abrir o banco SQLite em modo somente leitura: {$this->path}", 0, $error);
        }
    }

    /**
     * Abre uma fotografia somente leitura sem criar arquivos WAL/SHM.
     * Deve ser usada apenas com a automação parada e sem WAL pendente.
     */
    public function connectImmutableReadOnly(): PDO
    {
        if ($this->immutableReadOnlyConnection !== null) {
            return $this->immutableReadOnlyConnection;
        }

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('A extensão PDO SQLite não está disponível neste PHP.');
        }

        $resolvedPath = realpath($this->path);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException("Banco SQLite inexistente ou ilegível: {$this->path}");
        }

        $walPath = $resolvedPath . '-wal';
        if (is_file($walPath) && (filesize($walPath) ?: 0) > 0) {
            throw new RuntimeException('Existe WAL pendente; interrompa a automação e faça checkpoint controlado antes do preflight imutável.');
        }

        try {
            $connection = new PDO('sqlite:file:' . self::uriPath($resolvedPath) . '?mode=ro&immutable=1', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec('PRAGMA query_only = ON');
            $this->immutableReadOnlyConnection = $connection;

            return $connection;
        } catch (PDOException $error) {
            throw new RuntimeException("Não foi possível abrir a fotografia SQLite imutável: {$this->path}", 0, $error);
        }
    }

    public function connectExistingWritable(): PDO
    {
        if ($this->existingWritableConnection !== null) {
            return $this->existingWritableConnection;
        }

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('A extensão PDO SQLite não está disponível neste PHP.');
        }

        $resolvedPath = realpath($this->path);

        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath) || !is_writable($resolvedPath)) {
            throw new RuntimeException("Banco SQLite inexistente ou sem permissão de gravação: {$this->path}");
        }

        try {
            $connection = new PDO('sqlite:file:' . self::uriPath($resolvedPath) . '?mode=rw', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->exec('PRAGMA busy_timeout = 5000');

            if ((int) $connection->query('PRAGMA foreign_keys')->fetchColumn() !== 1
                || $connection->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('O banco SQLite não passou na verificação de integridade.');
            }

            $this->existingWritableConnection = $connection;
            return $connection;
        } catch (PDOException $error) {
            throw new RuntimeException("Não foi possível abrir o banco SQLite existente para gravação: {$this->path}", 0, $error);
        }
    }

    private static function uriPath(string $resolvedPath): string
    {
        $uriPath = str_replace('\\', '/', $resolvedPath);

        if (preg_match('/^[A-Za-z]:\//', $uriPath)) {
            $uriPath = '/' . $uriPath;
        }

        return str_replace('%2F', '/', rawurlencode($uriPath));
    }

    /** @return array{foreign_keys: int, journal_mode: string} */
    public function diagnostics(): array
    {
        $connection = $this->connect();

        return [
            'foreign_keys' => (int) $connection->query('PRAGMA foreign_keys')->fetchColumn(),
            'journal_mode' => strtolower((string) $connection->query('PRAGMA journal_mode')->fetchColumn()),
        ];
    }

    private function prepareParentDirectory(): void
    {
        if ($this->path === ':memory:') {
            return;
        }

        $parent = dirname($this->path);

        if (!is_dir($parent) && !@mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException("Não foi possível criar o diretório do SQLite: {$parent}");
        }

        if (!is_writable($parent)) {
            throw new RuntimeException("O diretório do SQLite não permite escrita: {$parent}");
        }
    }
}
