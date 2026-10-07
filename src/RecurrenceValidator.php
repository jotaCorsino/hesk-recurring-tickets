<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

final class RecurrenceValidator
{
    private const INTERVAL_UNITS = ['day', 'week', 'month', 'year'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $errors = [];

        $name = $this->requiredString($input, 'name', $errors);
        $timezone = $this->requiredString($input, 'timezone', $errors);
        $priorityName = $this->requiredString($input, 'priority_name', $errors);
        $subject = $this->requiredString($input, 'subject', $errors);
        $message = $this->requiredString($input, 'message', $errors);

        $enabled = $this->requiredBoolean($input, 'enabled', $errors);
        $notifyCustomer = $this->requiredBoolean($input, 'notify_customer', $errors);

        $intervalValue = $this->integer($input, 'interval_value', 1, $errors);
        $quantity = $this->integer($input, 'quantity', 1, $errors);
        $customerId = $this->integer($input, 'customer_id', 1, $errors);
        $categoryId = $this->integer($input, 'category_id', 1, $errors);
        $statusId = $this->integer($input, 'status_id', 0, $errors);
        $ownerId = $this->integer($input, 'owner_id', 1, $errors);
        $openedById = $this->integer($input, 'openedby_id', 1, $errors);

        $intervalUnit = $this->requiredString($input, 'interval_unit', $errors);

        if ($intervalUnit !== '' && !in_array($intervalUnit, self::INTERVAL_UNITS, true)) {
            $errors[] = 'interval_unit deve ser day, week, month ou year.';
        }

        if ($timezone !== '' && !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            $errors[] = "timezone não é um identificador PHP/IANA válido: {$timezone}.";
        }

        $nextRunAt = '';

        try {
            $nextRunAt = self::normalizeUtc($input['next_run_at'] ?? null, 'next_run_at');
        } catch (DomainException $error) {
            $errors[] = $error->getMessage();
        }

        $customFields = $this->customFields($input['custom_fields'] ?? null, $errors);

        if ($errors !== []) {
            throw new DomainException("Configuração de recorrência inválida:\n- " . implode("\n- ", $errors));
        }

        return [
            'name' => $name,
            'enabled' => $enabled,
            'timezone' => $timezone,
            'interval_value' => $intervalValue,
            'interval_unit' => $intervalUnit,
            'next_run_at' => $nextRunAt,
            'quantity' => $quantity,
            'customer_id' => $customerId,
            'category_id' => $categoryId,
            'priority_name' => $priorityName,
            'status_id' => $statusId,
            'owner_id' => $ownerId,
            'openedby_id' => $openedById,
            'subject' => $subject,
            'message' => $message,
            'notify_customer' => $notifyCustomer,
            'custom_fields' => $customFields,
        ];
    }

    public static function normalizeUtc(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',
            $value
        )) {
            throw new DomainException(
                "{$field} deve usar ISO-8601 com timezone, por exemplo 2027-01-15T12:00:00Z."
            );
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new DomainException("{$field} contém uma data inválida.");
        }

        $parseState = DateTimeImmutable::getLastErrors();

        if (is_array($parseState)
            && ($parseState['warning_count'] > 0 || $parseState['error_count'] > 0)
        ) {
            throw new DomainException("{$field} contém uma data inválida.");
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $errors
     */
    private function requiredString(array $input, string $key, array &$errors): string
    {
        $value = $input[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            $errors[] = "{$key} deve ser um texto não vazio.";
            return '';
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $errors
     */
    private function requiredBoolean(array $input, string $key, array &$errors): bool
    {
        $value = $input[$key] ?? null;

        if (!is_bool($value)) {
            $errors[] = "{$key} deve ser booleano.";
            return false;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $errors
     */
    private function integer(array $input, string $key, int $minimum, array &$errors): int
    {
        $value = $input[$key] ?? null;

        if (!is_int($value) || $value < $minimum) {
            $errors[] = "{$key} deve ser inteiro maior ou igual a {$minimum}.";
            return $minimum;
        }

        return $value;
    }

    /**
     * @param list<string> $errors
     * @return array<string, string>
     */
    private function customFields(mixed $value, array &$errors): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = 'custom_fields deve ser um objeto/mapa JSON.';
            return [];
        }

        $customFields = [];

        foreach ($value as $key => $fieldValue) {
            if (!is_string($key) || !preg_match('/^custom(?:[1-9]|[1-9][0-9]|100)$/D', $key)) {
                $errors[] = "Chave de custom field inválida: {$key}.";
                continue;
            }

            if (!is_string($fieldValue)) {
                $errors[] = "O valor de {$key} deve ser texto.";
                continue;
            }

            $customFields[$key] = $fieldValue;
        }

        ksort($customFields, SORT_NATURAL);

        return $customFields;
    }
}
