<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use Throwable;

final class HeskTicketGateway implements TicketGateway
{
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(private readonly HeskTicketCreator $creator)
    {
    }

    /** @return array<string, mixed> */
    public function validate(array $definition): array
    {
        return $this->creator->validate($definition);
    }

    public function generateTrackingId(): string
    {
        return $this->creator->generateTrackingId();
    }

    /** @return array{id: int, trackid: string}|null */
    public function findByTrackingId(string $trackingId): ?array
    {
        global $hesk_settings;

        $escapedPrefix = \hesk_dbEscape((string) $hesk_settings['db_pfix']);
        $escapedTrackingId = \hesk_dbEscape($trackingId);
        $result = \hesk_dbQuery(
            "SELECT `id`, `trackid` FROM `{$escapedPrefix}tickets`
             WHERE `trackid`='{$escapedTrackingId}'
             ORDER BY `id`
             LIMIT 2"
        );
        $count = \hesk_dbNumRows($result);

        if ($count > 1) {
            throw new OperationalException('HESK_TRACKING_INCONSISTENT',
                "O HESK contém mais de um ticket com o tracking ID {$trackingId}."
            );
        }

        if ($count === 0) {
            return null;
        }

        $ticket = \hesk_dbFetchAssoc($result);

        if (!is_array($ticket) || (int) ($ticket['id'] ?? 0) < 1) {
            throw new OperationalException('HESK_TICKET_RECONCILIATION_FAILED', 'O lookup HESK retornou um ticket inválido.');
        }

        return [
            'id' => (int) $ticket['id'],
            'trackid' => (string) $ticket['trackid'],
        ];
    }

    /** @return array{id: int, trackid: string, created_now: bool} */
    public function create(array $definition, string $trackingId): array
    {
        $lockKey = 'tickets-recorrentes:' . $trackingId;
        $this->acquireLock($lockKey);
        $primaryError = null;

        try {
            $existing = $this->findByTrackingId($trackingId);

            if ($existing !== null) {
                return $existing + ['created_now' => false];
            }

            $created = $this->creator->create($definition, $trackingId);

            if (!hash_equals($trackingId, (string) $created['trackid'])) {
                throw new OperationalException('HESK_TRACKING_INCONSISTENT', 'O ticket criado divergiu do tracking ID persistido.');
            }

            $persisted = $this->findByTrackingId($trackingId);

            if ($persisted === null || $persisted['id'] !== (int) $created['id']) {
                throw new OperationalException('HESK_TICKET_RECONCILIATION_FAILED', 'O ticket criado não pôde ser reconciliado no HESK.');
            }

            return $persisted + ['created_now' => true];
        } catch (Throwable $error) {
            $primaryError = $error;
            throw $error;
        } finally {
            try {
                $this->releaseLock($lockKey);
            } catch (Throwable $releaseError) {
                if ($primaryError === null) {
                    throw $releaseError;
                }
            }
        }
    }

    private function acquireLock(string $lockKey): void
    {
        $escaped = \hesk_dbEscape($lockKey);
        $result = \hesk_dbQuery(
            "SELECT GET_LOCK('{$escaped}', " . self::LOCK_TIMEOUT_SECONDS . ') AS `lock_acquired`'
        );
        $row = \hesk_dbFetchAssoc($result);

        if (!is_array($row) || (int) ($row['lock_acquired'] ?? 0) !== 1) {
            throw new OperationalException('HESK_LOCK_FAILED', "Não foi possível obter o lock HESK para {$lockKey}.");
        }
    }

    private function releaseLock(string $lockKey): void
    {
        $escaped = \hesk_dbEscape($lockKey);
        $result = \hesk_dbQuery(
            "SELECT RELEASE_LOCK('{$escaped}') AS `lock_released`"
        );
        $row = \hesk_dbFetchAssoc($result);

        if (!is_array($row) || (int) ($row['lock_released'] ?? 0) !== 1) {
            throw new OperationalException('HESK_LOCK_FAILED', "Não foi possível liberar o lock HESK para {$lockKey}.");
        }
    }
}
