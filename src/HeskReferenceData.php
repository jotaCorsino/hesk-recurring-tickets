<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

final class HeskReferenceData
{
    /**
     * @param array<int, array{id: int, name: string}> $customers
     * @param array<int, array{id: int, name: string}> $categories
     * @param array<int, array{id: int, name: string, user: string, active: bool, is_admin: bool, category_ids: list<int>}> $staff
     * @param array<int, array{id: int, name: string}> $priorities
     * @param array<int, array{id: int, name: string}> $statuses
     * @param array<string, array{key: string, name: string, type: string, required: bool, category_ids: list<int>, options: list<string>}> $customFields
     */
    public function __construct(
        private readonly array $customers,
        private readonly array $categories,
        private readonly array $staff,
        private readonly array $priorities,
        private readonly array $statuses,
        private readonly array $customFields,
    ) {
    }

    /** @return array<int, array{id: int, name: string}> */
    public function customers(): array
    {
        return $this->customers;
    }

    /** @return array<int, array{id: int, name: string}> */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @return array<int, array{id: int, name: string, user: string, active: bool, is_admin: bool, category_ids: list<int>}> */
    public function staff(): array
    {
        return $this->staff;
    }

    /** @return array<int, array{id: int, name: string}> */
    public function priorities(): array
    {
        return $this->priorities;
    }

    /** @return array<int, array{id: int, name: string}> */
    public function statuses(): array
    {
        return $this->statuses;
    }

    /** @return array<string, array{key: string, name: string, type: string, required: bool, category_ids: list<int>, options: list<string>}> */
    public function customFields(): array
    {
        return $this->customFields;
    }
}
