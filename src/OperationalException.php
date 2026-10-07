<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use DomainException;
use Throwable;

final class OperationalException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $technicalMessage,
        ?Throwable $previous = null,
    ) {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $errorCode)) {
            throw new DomainException('Código operacional inválido.');
        }

        parent::__construct($technicalMessage, 0, $previous);
    }

    public static function codeOf(Throwable $error): string
    {
        return $error instanceof self ? $error->errorCode : 'UNCLASSIFIED_ERROR';
    }
}
