<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use RuntimeException;

final class HeskReferenceValidationException extends RuntimeException
{
    /**
     * @param list<string> $safeErrors
     * @param list<string> $technicalErrors
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $safeErrors,
        public readonly array $technicalErrors,
    ) {
        parent::__construct("Validação referencial HESK falhou:\n- " . implode("\n- ", $technicalErrors));
    }
}
