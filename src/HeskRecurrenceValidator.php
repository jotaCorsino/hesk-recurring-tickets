<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

final class HeskRecurrenceValidator
{
    private const SUPPORTED_CUSTOM_FIELD_TYPES = ['text', 'textarea', 'radio', 'select'];

    public function __construct(private readonly HeskCatalogProvider $catalogProvider)
    {
    }

    /**
     * @param array<string, mixed> $recurrence
     * @return array{
     *   customer: array{id: int, name: string}, category: array{id: int, name: string},
     *   owner: array{id: int, name: string, user: string}, openedby: array{id: int, name: string, user: string},
     *   priority: array{id: int, name: string}, status: array{id: int, name: string},
     *   custom_fields: array<string, array{name: string, type: string, value: string}>
     * }
     */
    public function validate(array $recurrence): array
    {
        $catalog = $this->catalogProvider->load();
        $safeErrors = [];
        $technicalErrors = [];
        $errorCodes = [];

        $customerId = $this->positiveInteger($recurrence, 'customer_id', $safeErrors, $technicalErrors, $errorCodes, 'HESK_CUSTOMER_INVALID');
        $categoryId = $this->positiveInteger($recurrence, 'category_id', $safeErrors, $technicalErrors, $errorCodes, 'HESK_CATEGORY_INVALID');
        $ownerId = $this->positiveInteger($recurrence, 'owner_id', $safeErrors, $technicalErrors, $errorCodes, 'HESK_OWNER_INVALID');
        $openedById = $this->positiveInteger($recurrence, 'openedby_id', $safeErrors, $technicalErrors, $errorCodes, 'HESK_OPENEDBY_INVALID');

        $customer = $catalog->customers()[$customerId] ?? null;
        $this->validateExpectedLabel($customer, array_key_exists('customer_name', $recurrence), $recurrence['customer_name'] ?? null, 'Solicitante', $customerId, 'HESK_CUSTOMER_INVALID', $safeErrors, $technicalErrors, $errorCodes);

        $category = $catalog->categories()[$categoryId] ?? null;
        $this->validateExpectedLabel($category, array_key_exists('category_name', $recurrence), $recurrence['category_name'] ?? null, 'Categoria', $categoryId, 'HESK_CATEGORY_INVALID', $safeErrors, $technicalErrors, $errorCodes);

        $owner = $catalog->staff()[$ownerId] ?? null;
        $ownerValid = $this->staffCanUseCategory($owner, $categoryId);
        if (!$ownerValid) {
            $safeErrors[] = 'O responsável selecionado não está ativo ou não possui acesso à categoria.';
            $technicalErrors[] = "Responsável {$ownerId} não está ativo ou não possui acesso à categoria {$categoryId}.";
            $errorCodes[] = 'HESK_OWNER_INVALID';
        } else {
            $this->validateExpectedLabel($owner, array_key_exists('owner_name', $recurrence), $recurrence['owner_name'] ?? null, 'Responsável', $ownerId, 'HESK_OWNER_INVALID', $safeErrors, $technicalErrors, $errorCodes);
        }

        $openedBy = $openedById === $ownerId ? $owner : ($catalog->staff()[$openedById] ?? null);
        $openedByValid = $this->staffCanUseCategory($openedBy, $categoryId);
        if (!$openedByValid) {
            $safeErrors[] = 'O autor interno selecionado não está ativo ou não possui acesso à categoria.';
            $technicalErrors[] = "Autor interno {$openedById} não está ativo ou não possui acesso à categoria {$categoryId}.";
            $errorCodes[] = 'HESK_OPENEDBY_INVALID';
        } else {
            $this->validateExpectedLabel($openedBy, array_key_exists('openedby_name', $recurrence), $recurrence['openedby_name'] ?? null, 'Autor interno', $openedById, 'HESK_OPENEDBY_INVALID', $safeErrors, $technicalErrors, $errorCodes);
        }

        $priorityName = is_string($recurrence['priority_name'] ?? null) ? (string) $recurrence['priority_name'] : '';
        $priorityMatches = array_values(array_filter(
            $catalog->priorities(),
            fn (array $priority): bool => $this->sameLabel($priority['name'], $priorityName)
        ));
        $priority = count($priorityMatches) === 1 ? $priorityMatches[0] : null;
        if ($priority === null) {
            $safeErrors[] = 'A prioridade selecionada não está disponível.';
            $technicalErrors[] = sprintf('Prioridade "%s" não encontrada de forma inequívoca.', $priorityName);
            $errorCodes[] = 'HESK_PRIORITY_INVALID';
        }

        $statusId = is_int($recurrence['status_id'] ?? null) ? $recurrence['status_id'] : -1;
        $status = $catalog->statuses()[$statusId] ?? null;
        if ($status === null) {
            $safeErrors[] = 'O status selecionado não está disponível.';
            $technicalErrors[] = "Status {$statusId} não está disponível no HESK.";
            $errorCodes[] = 'HESK_STATUS_INVALID';
        }

        $customFields = $this->validateCustomFields(
            $recurrence['custom_fields'] ?? null,
            $categoryId,
            $catalog,
            $safeErrors,
            $technicalErrors,
            $errorCodes,
        );

        if ($technicalErrors !== []) {
            throw new HeskReferenceValidationException(
                $errorCodes[0] ?? 'UNCLASSIFIED_ERROR',
                array_values(array_unique($safeErrors)),
                $technicalErrors,
            );
        }

        return [
            'customer' => $customer,
            'category' => $category,
            'owner' => ['id' => $ownerId, 'name' => $owner['name'], 'user' => $owner['user']],
            'openedby' => ['id' => $openedById, 'name' => $openedBy['name'], 'user' => $openedBy['user']],
            'priority' => $priority,
            'status' => $status,
            'custom_fields' => $customFields,
        ];
    }

    /** @param array<string, mixed>|null $staff */
    private function staffCanUseCategory(?array $staff, int $categoryId): bool
    {
        return $staff !== null
            && $staff['active']
            && ($staff['is_admin'] || in_array($categoryId, $staff['category_ids'], true));
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $safeErrors
     * @param list<string> $technicalErrors
     * @param list<string> $errorCodes
     */
    private function positiveInteger(array $input, string $key, array &$safeErrors, array &$technicalErrors, array &$errorCodes, string $errorCode): int
    {
        $value = $input[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            $label = match ($key) {
                'customer_id' => 'solicitante', 'category_id' => 'categoria',
                'owner_id' => 'responsável', default => 'autor interno',
            };
            $safeErrors[] = "O {$label} selecionado não está disponível.";
            $technicalErrors[] = "{$key} deve ser um inteiro positivo.";
            $errorCodes[] = $errorCode;
            return 0;
        }
        return $value;
    }

    /**
     * @param array<string, mixed>|null $record
     * @param list<string> $safeErrors
     * @param list<string> $technicalErrors
     * @param list<string> $errorCodes
     */
    private function validateExpectedLabel(?array $record, bool $wasProvided, mixed $expected, string $label, int $id, string $errorCode, array &$safeErrors, array &$technicalErrors, array &$errorCodes): void
    {
        if ($record === null) {
            $safeErrors[] = "O {$this->lower($label)} selecionado não está disponível.";
            $technicalErrors[] = $label === 'Categoria'
                ? "Categoria {$id} não encontrada."
                : "{$label} {$id} não existe ou não pode ser selecionado.";
            $errorCodes[] = $errorCode;
            return;
        }

        if (!$wasProvided) {
            return;
        }

        if (!is_string($expected) || trim($expected) === '' || !$this->sameLabel((string) $record['name'], $expected)) {
            $safeErrors[] = "O {$this->lower($label)} selecionado não corresponde ao cadastro atual.";
            $technicalErrors[] = sprintf('%s %d divergente: encontrado "%s"; esperado "%s".', $label, $id, $record['name'], is_scalar($expected) ? (string) $expected : get_debug_type($expected));
            $errorCodes[] = $errorCode;
        }
    }

    /**
     * @param list<string> $safeErrors
     * @param list<string> $technicalErrors
     * @param list<string> $errorCodes
     * @return array<string, array{name: string, type: string, value: string}>
     */
    private function validateCustomFields(mixed $configured, int $categoryId, HeskReferenceData $catalog, array &$safeErrors, array &$technicalErrors, array &$errorCodes): array
    {
        if (!is_array($configured)) {
            $safeErrors[] = 'Os campos personalizados estão inválidos.';
            $technicalErrors[] = 'A configuração de campos personalizados não é um mapa.';
            $errorCodes[] = 'HESK_CUSTOM_FIELDS_INVALID';
            return [];
        }

        $validated = [];
        foreach ($configured as $key => $value) {
            if (!is_string($key) || !preg_match('/^custom(?:[1-9]|[1-9][0-9]|100)$/D', $key) || !is_string($value)) {
                $safeErrors[] = 'Um campo personalizado está inválido.';
                $technicalErrors[] = 'Identificador ou valor de campo personalizado inválido.';
                $errorCodes[] = 'HESK_CUSTOM_FIELDS_INVALID';
                continue;
            }

            $field = $catalog->customFields()[$key] ?? null;
            if ($field === null) {
                $safeErrors[] = 'Um campo personalizado não está mais disponível.';
                $technicalErrors[] = "{$key} não está ativo no HESK.";
                $errorCodes[] = 'HESK_CUSTOM_FIELDS_INVALID';
                continue;
            }
            if (!in_array($categoryId, $field['category_ids'], true)) {
                $safeErrors[] = 'Um campo personalizado não pertence à categoria selecionada.';
                $technicalErrors[] = "{$key} não pertence à categoria {$categoryId}.";
                $errorCodes[] = 'HESK_CUSTOM_FIELDS_INVALID';
                continue;
            }
            if (!in_array($field['type'], self::SUPPORTED_CUSTOM_FIELD_TYPES, true)) {
                $safeErrors[] = 'Um campo personalizado usa um tipo ainda não suportado.';
                $technicalErrors[] = "{$key} usa o tipo não suportado: {$field['type']}.";
                $errorCodes[] = 'HESK_CUSTOM_FIELDS_INVALID';
                continue;
            }
            if ($field['required'] && trim($value) === '') {
                $safeErrors[] = "O campo {$field['name']} é obrigatório.";
                $technicalErrors[] = "{$key} ({$field['name']}) é obrigatório e está vazio.";
                $errorCodes[] = 'HESK_CUSTOM_FIELDS_INVALID';
                continue;
            }

            $canonicalValue = $value;
            if (in_array($field['type'], ['radio', 'select'], true) && trim($value) !== '') {
                $canonicalValue = $this->canonicalOption($value, $field['options']);
                if ($canonicalValue === null) {
                    $safeErrors[] = "O campo {$field['name']} contém uma opção inválida.";
                    $technicalErrors[] = sprintf('%s (%s) não aceita "%s".', $key, $field['name'], $value);
                    $errorCodes[] = 'HESK_CUSTOM_FIELDS_INVALID';
                    continue;
                }
            }

            $validated[$key] = ['name' => $field['name'], 'type' => $field['type'], 'value' => $canonicalValue];
        }

        foreach ($catalog->customFields() as $key => $field) {
            if ($field['required']
                && in_array($categoryId, $field['category_ids'], true)
                && !array_key_exists($key, $configured)) {
                $safeErrors[] = "O campo {$field['name']} é obrigatório.";
                $technicalErrors[] = "{$key} ({$field['name']}) é obrigatório e não foi informado.";
                $errorCodes[] = 'HESK_CUSTOM_FIELDS_INVALID';
            }
        }
        return $validated;
    }

    /** @param list<string> $options */
    private function canonicalOption(string $expected, array $options): ?string
    {
        foreach ($options as $option) {
            if ($this->sameLabel($option, $expected)) {
                return $option;
            }
        }
        return null;
    }

    private function sameLabel(string $actual, string $expected): bool
    {
        $normalize = static function (string $value): string {
            $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
            return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        };
        return $normalize($actual) === $normalize($expected);
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
