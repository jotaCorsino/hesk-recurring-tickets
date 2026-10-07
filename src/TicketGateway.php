<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

interface TicketGateway
{
    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function validate(array $definition): array;

    public function generateTrackingId(): string;

    /** @return array{id: int, trackid: string}|null */
    public function findByTrackingId(string $trackingId): ?array;

    /**
     * Cria ou reconcilia sob a proteção de concorrência do gateway.
     *
     * @param array<string, mixed> $definition
     * @return array{id: int, trackid: string, created_now: bool}
     */
    public function create(array $definition, string $trackingId): array;
}
