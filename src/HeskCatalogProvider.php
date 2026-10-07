<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

interface HeskCatalogProvider
{
    public function load(): HeskReferenceData;
}
