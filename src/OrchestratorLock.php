<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

interface OrchestratorLock
{
    public function acquire(): bool;

    public function release(): void;
}
