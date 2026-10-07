<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use InvalidArgumentException;

final class AuthenticatedStaff
{
    public function __construct(
        public readonly int $id,
        public readonly string $displayName,
        public readonly ?string $username = null,
    ) {
        if ($this->id <= 0) {
            throw new InvalidArgumentException('O identificador STAFF deve ser positivo.');
        }

        if (trim($this->displayName) === '') {
            throw new InvalidArgumentException('A identidade STAFF precisa ter nome ou usuário.');
        }
    }
}
