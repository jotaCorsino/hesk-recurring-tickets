<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use JsonException;
use RuntimeException;

final class NativeHeskCatalogProvider implements HeskCatalogProvider
{
    private const STANDARD_PRIORITIES = [
        0 => 'critical',
        1 => 'high',
        2 => 'medium',
        3 => 'low',
    ];

    private const STANDARD_STATUSES = [
        0 => 'open',
        1 => 'wait_reply',
        2 => 'replied',
        3 => 'closed',
        4 => 'in_progress',
        5 => 'on_hold',
    ];

    private ?HeskReferenceData $cache = null;

    public function load(): HeskReferenceData
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        global $hesk_settings, $hesklang;

        foreach (['hesk_dbEscape', 'hesk_dbQuery', 'hesk_dbFetchAssoc'] as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException("Função HESK necessária ao catálogo não está disponível: {$function}().");
            }
        }
        if (!is_array($hesk_settings) || !is_array($hesklang)) {
            throw new RuntimeException('As configurações e traduções do HESK não estão disponíveis para os catálogos.');
        }

        $prefix = (string) ($hesk_settings['db_pfix'] ?? '');
        if ($prefix === '' || preg_match('/^[A-Za-z0-9_]+$/D', $prefix) !== 1) {
            throw new RuntimeException('O prefixo de tabelas HESK não está disponível ou é inválido.');
        }
        $prefix = \hesk_dbEscape($prefix);
        $language = $hesk_settings['language'] ?? null;
        if (!is_string($language) || trim($language) === '') {
            throw new RuntimeException('O idioma atual do HESK não está disponível para os catálogos.');
        }

        $categories = $this->loadCategories($prefix);
        $customers = $this->loadCustomers($prefix, (int) ($hesk_settings['customer_accounts'] ?? 0) === 1);
        $staff = $this->loadStaff($prefix);
        $priorities = $this->loadPriorities($prefix, $language, $hesklang);
        $statuses = $this->loadStatuses($prefix, $language, $hesklang);
        $customFields = $this->loadCustomFields($prefix, $language, array_keys($categories));

        return $this->cache = new HeskReferenceData(
            $customers,
            $categories,
            $staff,
            $priorities,
            $statuses,
            $customFields,
        );
    }

    /** @return array<int, array{id: int, name: string}> */
    private function loadCustomers(string $prefix, bool $customerAccounts): array
    {
        $ownershipFilter = $customerAccounts
            ? "AND (
                    `customer`.`email` IS NULL
                    OR `customer`.`email` = ''
                    OR NOT EXISTS (
                        SELECT 1
                        FROM `{$prefix}customers` AS `possible_owner`
                        WHERE `possible_owner`.`id` <> `customer`.`id`
                          AND `possible_owner`.`email` = TRIM(`customer`.`email`)
                          AND (
                              `possible_owner`.`verified` IN (1, 2)
                              OR (COALESCE(`possible_owner`.`verified`, 0) = 0
                                  AND `possible_owner`.`verification_token` IS NOT NULL)
                          )
                    )
                )"
            : '';

        $customers = [];
        foreach ($this->rows(
            "SELECT `customer`.`id`, `customer`.`name`
             FROM `{$prefix}customers` AS `customer`
             WHERE `customer`.`id` > 0
               AND COALESCE(`customer`.`verified`, 0) <> 2
               {$ownershipFilter}
             ORDER BY `customer`.`name`, `customer`.`id`"
        ) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $customers[$id] = [
                    'id' => $id,
                    'name' => $this->semanticLabel((string) ($row['name'] ?? '')),
                ];
            }
        }

        return $customers;
    }

    /** @return array<int, array{id: int, name: string}> */
    private function loadCategories(string $prefix): array
    {
        $categories = [];
        foreach ($this->rows("SELECT `id`, `name` FROM `{$prefix}categories` ORDER BY `name`, `id`") as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $categories[$id] = [
                    'id' => $id,
                    'name' => $this->semanticLabel((string) ($row['name'] ?? '')),
                ];
            }
        }
        return $categories;
    }

    /**
     * @return array<int, array{id: int, name: string, user: string, active: bool, is_admin: bool, category_ids: list<int>}>
     */
    private function loadStaff(string $prefix): array
    {
        $groupCategories = [];
        foreach ($this->rows(
            "SELECT `member`.`user_id`, `category`.`category_id`
             FROM `{$prefix}permission_group_members` AS `member`
             INNER JOIN `{$prefix}permission_group_categories` AS `category`
                ON `category`.`group_id` = `member`.`group_id`"
        ) as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $categoryId = (int) ($row['category_id'] ?? 0);
            if ($userId > 0 && $categoryId > 0) {
                $groupCategories[$userId][] = $categoryId;
            }
        }

        $staff = [];
        foreach ($this->rows("SELECT `id`, `name`, `user`, `active`, `isadmin`, `categories` FROM `{$prefix}users` ORDER BY `name`, `id`") as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $direct = array_values(array_filter(
                array_map('intval', explode(',', (string) ($row['categories'] ?? ''))),
                static fn (int $categoryId): bool => $categoryId > 0,
            ));
            $categoryIds = array_values(array_unique(array_merge($direct, $groupCategories[$id] ?? [])));
            sort($categoryIds, SORT_NUMERIC);
            $staff[$id] = [
                'id' => $id,
                'name' => $this->semanticLabel((string) ($row['name'] ?? '')),
                'user' => (string) ($row['user'] ?? ''),
                'active' => (int) ($row['active'] ?? 0) === 1,
                'is_admin' => (int) ($row['isadmin'] ?? 0) === 1,
                'category_ids' => $categoryIds,
            ];
        }
        return $staff;
    }

    /**
     * @param array<string, mixed> $language
     * @return array<int, array{id: int, name: string}>
     */
    private function loadPriorities(string $prefix, string $currentLanguage, array $language): array
    {
        $priorities = [];
        foreach ($this->rows(
            "SELECT `id`, `name`, `priority_order`
             FROM `{$prefix}custom_priorities`
             ORDER BY `priority_order`, `id`"
        ) as $row) {
            $id = (int) ($row['id'] ?? -1);
            if ($id < 0) {
                throw new RuntimeException('O catálogo HESK retornou uma prioridade com ID inválido.');
            }
            $rawName = $row['name'] ?? null;
            if (isset(self::STANDARD_PRIORITIES[$id])) {
                $name = $this->standardPriorityName($id, $rawName, $currentLanguage, $language);
            } else {
                $name = $this->localizedName($rawName, $currentLanguage, "prioridade {$id}");
            }
            $priorities[$id] = ['id' => $id, 'name' => $name];
        }
        return $priorities;
    }

    /** @param array<string, mixed> $language */
    private function standardPriorityName(int $id, mixed $raw, string $currentLanguage, array $language): string
    {
        $default = fn (): string => $this->nativeLabel(
            $language,
            self::STANDARD_PRIORITIES[$id],
            "prioridade {$id}",
        );
        if ($raw === null || $raw === '') {
            return $default();
        }

        $names = $this->jsonArray($raw, "nome de prioridade {$id}");
        $localized = $names[$currentLanguage] ?? null;
        if ($localized === null || (is_string($localized) && strtolower(trim($localized)) === 'null')) {
            return $default();
        }
        if (!is_string($localized) || trim($localized) === '') {
            throw new RuntimeException("O nome de prioridade {$id} não contém uma tradução utilizável.");
        }
        return $this->semanticLabel(trim($localized));
    }

    /**
     * @param array<string, mixed> $language
     * @return array<int, array{id: int, name: string}>
     */
    private function loadStatuses(string $prefix, string $currentLanguage, array $language): array
    {
        $statuses = [];
        foreach (self::STANDARD_STATUSES as $id => $languageKey) {
            $statuses[$id] = [
                'id' => $id,
                'name' => $this->nativeLabel($language, $languageKey, "status {$id}"),
            ];
        }

        foreach ($this->rows(
            "SELECT `id`, `name`
             FROM `{$prefix}custom_statuses`
             ORDER BY `id`"
        ) as $row) {
            $id = (int) ($row['id'] ?? -1);
            if ($id < 0) {
                throw new RuntimeException('O catálogo HESK retornou um status personalizado com ID inválido.');
            }
            $statuses[$id] = [
                'id' => $id,
                'name' => $this->localizedName($row['name'] ?? null, $currentLanguage, "status {$id}"),
            ];
        }
        return $statuses;
    }

    /**
     * @param list<int> $allCategoryIds
     * @return array<string, array{key: string, name: string, type: string, required: bool, category_ids: list<int>, options: list<string>}>
     */
    private function loadCustomFields(string $prefix, string $currentLanguage, array $allCategoryIds): array
    {
        $customFields = [];
        foreach ($this->rows(
            "SELECT `id`, `name`, `type`, `req`, `category`, `value`, `use`, `place`, `order`
             FROM `{$prefix}custom_fields`
             WHERE `use` IN ('1', '2')
             ORDER BY `place`, `order`, `id`"
        ) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || $id > 100) {
                throw new RuntimeException('O catálogo HESK retornou um campo personalizado com ID inválido.');
            }
            $key = 'custom' . $id;
            $type = (string) ($row['type'] ?? '');
            $categoryIds = $this->customFieldCategories($row['category'] ?? null, $allCategoryIds, $key);
            $value = $this->jsonArray($row['value'] ?? null, "valor de {$key}");
            $options = [];
            $optionKey = match ($type) {
                'radio' => 'radio_options',
                'select' => 'select_options',
                default => null,
            };
            if ($optionKey !== null) {
                $rawOptions = $value[$optionKey] ?? null;
                if (!is_array($rawOptions) || !array_is_list($rawOptions)) {
                    throw new RuntimeException("As opções de {$key} estão em formato inesperado.");
                }
                foreach ($rawOptions as $option) {
                    if (!is_string($option)) {
                        throw new RuntimeException("As opções de {$key} estão em formato inesperado.");
                    }
                    $options[] = $option;
                }
            }

            $customFields[$key] = [
                'key' => $key,
                'name' => $this->localizedName($row['name'] ?? null, $currentLanguage, $key),
                'type' => $type,
                'required' => (int) ($row['req'] ?? 0) === 2,
                'category_ids' => $categoryIds,
                'options' => $options,
            ];
        }
        return $customFields;
    }

    /** @param list<int> $allCategoryIds @return list<int> */
    private function customFieldCategories(mixed $raw, array $allCategoryIds, string $key): array
    {
        if ($raw === null || $raw === '') {
            return $allCategoryIds;
        }
        $categories = $this->jsonArray($raw, "categorias de {$key}");
        if ($categories === []) {
            return $allCategoryIds;
        }
        if (!array_is_list($categories)) {
            throw new RuntimeException("As categorias de {$key} estão em formato inesperado.");
        }

        $categoryIds = [];
        foreach ($categories as $categoryId) {
            if ((!is_int($categoryId) && !(is_string($categoryId) && ctype_digit($categoryId))) || (int) $categoryId < 1) {
                throw new RuntimeException("As categorias de {$key} estão em formato inesperado.");
            }
            $categoryIds[] = (int) $categoryId;
        }
        $categoryIds = array_values(array_unique($categoryIds));
        sort($categoryIds, SORT_NUMERIC);
        return $categoryIds;
    }

    private function localizedName(mixed $raw, string $currentLanguage, string $context): string
    {
        $names = $this->jsonArray($raw, "nome de {$context}");
        if (array_key_exists($currentLanguage, $names) && $this->isUsableTranslation($names[$currentLanguage])) {
            return $this->semanticLabel(trim($names[$currentLanguage]));
        }
        foreach ($names as $language => $name) {
            if (is_string($language) && $this->isUsableTranslation($name)) {
                return $this->semanticLabel(trim($name));
            }
        }
        throw new RuntimeException("O nome de {$context} não contém uma tradução utilizável.");
    }

    private function isUsableTranslation(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strtolower(trim($value)) !== 'null';
    }

    /** @param array<string, mixed> $language */
    private function nativeLabel(array $language, string $key, string $context): string
    {
        $label = $language[$key] ?? null;
        if (!is_string($label) || trim($label) === '') {
            throw new RuntimeException("A tradução nativa de {$context} não está disponível.");
        }
        return $this->semanticLabel(trim($label));
    }

    private function semanticLabel(string $label): string
    {
        return html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return array<mixed> */
    private function jsonArray(mixed $raw, string $context): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException("O JSON de {$context} não está disponível.");
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException("O JSON de {$context} é inválido.", 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException("O JSON de {$context} está em formato inesperado.");
        }
        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $result = \hesk_dbQuery($sql);
        $rows = [];
        while (true) {
            $row = \hesk_dbFetchAssoc($result);
            if ($row === false || $row === null) {
                break;
            }
            if (!is_array($row)) {
                throw new RuntimeException('O catálogo HESK retornou uma linha inválida.');
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
