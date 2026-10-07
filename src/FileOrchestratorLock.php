<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use RuntimeException;

final class FileOrchestratorLock implements OrchestratorLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
        if (trim($this->path) === '') {
            throw new RuntimeException('O caminho do lock do orquestrador não pode ficar vazio.');
        }
    }

    public function acquire(): bool
    {
        if (is_resource($this->handle)) {
            return true;
        }

        $directory = dirname($this->path);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('O diretório privado do lock não existe ou não permite escrita.');
        }

        $handle = @fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o lock privado do orquestrador.');
        }

        @chmod($this->path, 0660);

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
