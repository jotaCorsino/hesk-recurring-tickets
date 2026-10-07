<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

interface AdminAuthenticator
{
    public function authenticate(): ?AuthenticatedStaff;
}
