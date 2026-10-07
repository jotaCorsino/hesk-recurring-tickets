<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use PDO;
use RuntimeException;
use Throwable;

final class ImmediateTransaction
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function run(callable $callback): mixed
    {
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('BEGIN IMMEDIATE não pode iniciar dentro de outra transação.');
        }

        $this->connection->exec('BEGIN IMMEDIATE');

        try {
            $result = $callback();
            $this->connection->exec('COMMIT');

            return $result;
        } catch (Throwable $error) {
            try {
                $this->connection->exec('ROLLBACK');
            } catch (Throwable) {
                // A exceção original tem prioridade se a transação já tiver sido encerrada.
            }

            throw $error;
        }
    }
}
