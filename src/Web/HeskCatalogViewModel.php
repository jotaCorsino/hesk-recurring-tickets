<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use TicketsRecorrentesHesk\HeskReferenceData;

final class HeskCatalogViewModel
{
    private const SUPPORTED_CUSTOM_FIELD_TYPES = ['text', 'textarea', 'radio', 'select'];

    /**
     * @param array<string, mixed>|null $values
     * @return array<string, mixed>
     */
    public static function form(HeskReferenceData $catalog, ?array $values, ?int $requestedCategoryId = null): array
    {
        $values ??= [];
        $selectedCategoryId = $requestedCategoryId ?? self::integer($values['category_id'] ?? null, 1);
        $customValues = self::customValues($values['custom_fields'] ?? null);

        return [
            'selected_category_id' => $selectedCategoryId,
            'category_available' => $selectedCategoryId !== null
                && isset($catalog->categories()[$selectedCategoryId]),
            'customers' => self::idOptions(
                $catalog->customers(),
                self::integer($values['customer_id'] ?? null, 1),
            ),
            'categories' => self::idOptions($catalog->categories(), $selectedCategoryId),
            'priorities' => self::nameOptions(
                $catalog->priorities(),
                self::string($values['priority_name'] ?? null),
            ),
            'statuses' => self::idOptions(
                $catalog->statuses(),
                self::integer($values['status_id'] ?? null, 0),
            ),
            'owners' => self::staffOptions(
                $catalog,
                $selectedCategoryId,
                self::integer($values['owner_id'] ?? null, 1),
            ),
            'openedby' => self::staffOptions(
                $catalog,
                $selectedCategoryId,
                self::integer($values['openedby_id'] ?? null, 1),
            ),
            ...self::customFields($catalog, $selectedCategoryId, $customValues),
        ];
    }

    /**
     * @param array<string, mixed> $recurrence
     * @return array<string, mixed>
     */
    public static function recurrence(HeskReferenceData $catalog, array $recurrence): array
    {
        $customerId = (int) ($recurrence['customer_id'] ?? 0);
        $categoryId = (int) ($recurrence['category_id'] ?? 0);
        $ownerId = (int) ($recurrence['owner_id'] ?? 0);
        $priorityName = (string) ($recurrence['priority_name'] ?? '');
        $statusId = (int) ($recurrence['status_id'] ?? -1);

        $customer = $catalog->customers()[$customerId] ?? null;
        $category = $catalog->categories()[$categoryId] ?? null;
        $owner = $catalog->staff()[$ownerId] ?? null;
        $priority = self::recordByName($catalog->priorities(), $priorityName);
        $status = $catalog->statuses()[$statusId] ?? null;

        $customLabels = [];
        foreach (self::customValues($recurrence['custom_fields'] ?? null) as $key => $value) {
            $field = $catalog->customFields()[$key] ?? null;
            $customLabels[$key] = [
                'name' => $field['name'] ?? 'Referência indisponível',
                'value' => $value,
                'available' => $field !== null && in_array($categoryId, $field['category_ids'], true),
            ];
        }

        return [
            'customer_label' => $customer['name'] ?? 'Referência indisponível',
            'category_label' => $category['name'] ?? 'Referência indisponível',
            'owner_label' => self::staffAvailable($owner, $categoryId)
                ? self::staffName($owner)
                : 'Referência indisponível',
            'priority_label' => $priority['name'] ?? 'Referência indisponível',
            'hesk_status_label' => $status['name'] ?? 'Referência indisponível',
            'custom_field_labels' => $customLabels,
            'has_unavailable_reference' => $customer === null
                || $category === null
                || !self::staffAvailable($owner, $categoryId)
                || $priority === null
                || $status === null
                || in_array(false, array_column($customLabels, 'available'), true),
        ];
    }

    /**
     * @param array<int, array{id: int, name: string}> $records
     * @return list<array{value: string, label: string, selected: bool, available: bool}>
     */
    private static function idOptions(array $records, ?int $selected): array
    {
        $options = [];
        foreach ($records as $record) {
            $id = (int) $record['id'];
            $options[] = [
                'value' => (string) $id,
                'label' => self::usableName((string) $record['name']),
                'selected' => $selected === $id,
                'available' => true,
            ];
        }
        if ($selected !== null && !isset($records[$selected])) {
            array_unshift($options, [
                'value' => (string) $selected,
                'label' => 'Referência indisponível',
                'selected' => true,
                'available' => false,
            ]);
        }
        return $options;
    }

    /**
     * @param array<int, array{id: int, name: string}> $records
     * @return list<array{value: string, label: string, selected: bool, available: bool}>
     */
    private static function nameOptions(array $records, ?string $selected): array
    {
        $options = [];
        $selectedFound = false;
        foreach ($records as $record) {
            $name = (string) $record['name'];
            $isSelected = $selected !== null && $name === $selected;
            $selectedFound = $selectedFound || $isSelected;
            $options[] = [
                'value' => $name,
                'label' => self::usableName($name),
                'selected' => $isSelected,
                'available' => true,
            ];
        }
        if ($selected !== null && $selected !== '' && !$selectedFound) {
            array_unshift($options, [
                'value' => $selected,
                'label' => 'Referência indisponível',
                'selected' => true,
                'available' => false,
            ]);
        }
        return $options;
    }

    /**
     * @return list<array{value: string, label: string, selected: bool, available: bool, category_ids: list<int>, is_admin: bool, active: bool}>
     */
    private static function staffOptions(HeskReferenceData $catalog, ?int $categoryId, ?int $selected): array
    {
        $options = [];
        $selectedFound = false;
        foreach ($catalog->staff() as $staff) {
            $id = (int) $staff['id'];
            $isSelected = $selected === $id;
            $eligible = $categoryId !== null && self::staffAvailable($staff, $categoryId);
            if (!$staff['active'] && !$isSelected) {
                continue;
            }
            $selectedFound = $selectedFound || $isSelected;
            $options[] = [
                'value' => (string) $id,
                'label' => $isSelected && !$eligible
                    ? self::staffName($staff) . ' — Referência indisponível'
                    : self::staffName($staff),
                'selected' => $isSelected,
                'available' => $staff['active'] && $eligible,
                'category_ids' => $staff['category_ids'],
                'is_admin' => $staff['is_admin'],
                'active' => $staff['active'],
            ];
        }
        if ($selected !== null && !$selectedFound) {
            array_unshift($options, [
                'value' => (string) $selected,
                'label' => 'Referência indisponível',
                'selected' => true,
                'available' => false,
                'category_ids' => [],
                'is_admin' => false,
                'active' => false,
            ]);
        }
        return $options;
    }

    /**
     * @param array<string, string> $values
     * @return array{custom_fields: list<array<string, mixed>>, obsolete_custom_fields: list<array{key: string, value: string, category_ids: list<int>}>}
     */
    private static function customFields(HeskReferenceData $catalog, ?int $categoryId, array $values): array
    {
        $fields = [];
        $obsolete = [];
        foreach ($catalog->customFields() as $key => $field) {
            $value = $values[$key] ?? '';
            $applies = $categoryId !== null && in_array($categoryId, $field['category_ids'], true);
            $supported = in_array($field['type'], self::SUPPORTED_CUSTOM_FIELD_TYPES, true);
            $fields[] = [
                ...$field,
                'value' => $value,
                'applies' => $applies,
                'supported' => $supported,
                'option_items' => in_array($field['type'], ['radio', 'select'], true)
                    ? self::choiceOptions($field['options'], $value)
                    : [],
            ];
            if ($value !== '' && (!$applies || !$supported)) {
                $obsolete[] = ['key' => $key, 'value' => $value, 'category_ids' => $field['category_ids']];
            }
        }
        foreach ($values as $key => $value) {
            if (!isset($catalog->customFields()[$key])) {
                $obsolete[] = ['key' => $key, 'value' => $value, 'category_ids' => []];
            }
        }
        return ['custom_fields' => $fields, 'obsolete_custom_fields' => $obsolete];
    }

    /** @param list<string> $options @return list<array{value: string, label: string, selected: bool, available: bool}> */
    private static function choiceOptions(array $options, string $selected): array
    {
        $items = [];
        $selectedFound = false;
        foreach ($options as $option) {
            $isSelected = $selected !== '' && $option === $selected;
            $selectedFound = $selectedFound || $isSelected;
            $items[] = [
                'value' => $option,
                'label' => $option,
                'selected' => $isSelected,
                'available' => true,
            ];
        }
        if ($selected !== '' && !$selectedFound) {
            array_unshift($items, [
                'value' => $selected,
                'label' => 'Referência indisponível',
                'selected' => true,
                'available' => false,
            ]);
        }
        return $items;
    }

    /** @param array<string, mixed>|null $staff */
    private static function staffAvailable(?array $staff, int $categoryId): bool
    {
        return $staff !== null
            && $staff['active']
            && ($staff['is_admin'] || in_array($categoryId, $staff['category_ids'], true));
    }

    /** @param array<string, mixed> $staff */
    private static function staffName(array $staff): string
    {
        return self::usableName((string) ($staff['name'] ?? ''));
    }

    /** @param array<int, array{id: int, name: string}> $records @return array{id: int, name: string}|null */
    private static function recordByName(array $records, string $name): ?array
    {
        foreach ($records as $record) {
            if ($record['name'] === $name) {
                return $record;
            }
        }
        return null;
    }

    /** @return array<string, string> */
    private static function customValues(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        return array_filter(
            $values,
            static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function integer(mixed $value, int $minimum): ?int
    {
        if (is_int($value)) {
            return $value >= $minimum ? $value : null;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $integer = (int) $value;
            return $integer >= $minimum ? $integer : null;
        }
        return null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function usableName(string $name): string
    {
        return trim($name) === '' ? 'Referência sem nome' : $name;
    }
}
