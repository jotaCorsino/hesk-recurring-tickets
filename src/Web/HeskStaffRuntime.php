<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

interface HeskStaffRuntime
{
    public function openStaffSession(): void;

    /**
     * @return array<string, mixed>
     */
    public function sessionData(): array;

    public function validateLoggedIn(): bool;

    public function closeStaffSession(): void;
}
