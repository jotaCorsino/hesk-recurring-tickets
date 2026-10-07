<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use TicketsRecorrentesHesk\Database;
use TicketsRecorrentesHesk\HeskRecurrenceValidator;
use TicketsRecorrentesHesk\HeskReferenceValidationException;
use TicketsRecorrentesHesk\MigrationRunner;
use TicketsRecorrentesHesk\NativeHeskCatalogProvider;
use TicketsRecorrentesHesk\RecurrenceRepository;
use TicketsRecorrentesHesk\RecurrenceValidator;
use Throwable;

final class AdminRecurrenceWriter
{
    private const LABELS = [
        'name' => 'Nome da recorrência', 'timezone' => 'Fuso horário',
        'interval_value' => 'Repetir a cada', 'interval_unit' => 'Unidade',
        'quantity' => 'Quantidade por execução', 'customer_id' => 'ID do solicitante',
        'category_id' => 'ID da categoria', 'priority_name' => 'Prioridade',
        'status_id' => 'Status', 'owner_id' => 'ID do responsável',
        'openedby_id' => 'ID do autor interno', 'subject' => 'Assunto', 'message' => 'Mensagem',
    ];

    public function __construct(
        private readonly string $databasePath,
        private readonly ?HeskRecurrenceValidator $heskValidator = null,
    ) {
    }

    public static function fromEnvironment(?HeskRecurrenceValidator $heskValidator = null): self
    {
        $configuredPath = getenv('APP_DB_PATH');
        return new self(
            $configuredPath === false ? dirname(__DIR__, 2) . '/storage/app.sqlite' : $configuredPath,
            $heskValidator,
        );
    }

    /**
     * @param array<string, mixed> $post
     * @return array{status: 'created'|'updated'|'invalid'|'missing'|'active'|'conflict', id?: int, values: array<string, mixed>, errors: list<string>}
     */
    public function submit(array $post, ?int $id): array
    {
        [$values, $data, $errors] = $this->parse($post);

        if ($id !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $values['updated_at'])
            || !preg_match('/^[a-f0-9]{64}$/D', $values['version']))) {
            $errors[] = 'A versão do formulário é inválida. Recarregue a página.';
        }

        if ($errors !== []) {
            return ['status' => 'invalid', 'values' => $values, 'errors' => $errors];
        }

        $connection = (new Database($this->databasePath))->connectExistingWritable();
        (new MigrationRunner($connection, dirname(__DIR__, 2) . '/database/migrations'))->assertUpToDate();
        $repository = new RecurrenceRepository($connection);

        if ($id === null) {
            try {
                $this->referenceValidator()->validate($data);
            } catch (HeskReferenceValidationException $error) {
                return ['status' => 'invalid', 'values' => $values, 'errors' => $error->safeErrors];
            }
            $created = $repository->create($data);
            return ['status' => 'created', 'id' => (int) $created['id'], 'values' => $values, 'errors' => []];
        }

        $current = $repository->findById($id);
        if ($current === null) {
            return ['status' => 'missing', 'id' => $id, 'values' => $values, 'errors' => []];
        }
        if ($current['enabled']) {
            return ['status' => 'active', 'id' => $id, 'values' => $values, 'errors' => []];
        }
        if (!$this->isCurrent($current, $values['updated_at'], $values['version'])) {
            return ['status' => 'conflict', 'id' => $id, 'values' => $values, 'errors' => []];
        }

        try {
            $this->referenceValidator()->validate(array_merge($current, $data, ['enabled' => false]));
        } catch (HeskReferenceValidationException $error) {
            return ['status' => 'invalid', 'id' => $id, 'values' => $values, 'errors' => $error->safeErrors];
        }

        // O repositório repete estado e versão dentro de BEGIN IMMEDIATE antes
        // do UPDATE, fechando a janela entre a consulta HESK e a escrita.
        $outcome = $repository->updateInactiveIfCurrent(
            $id, $data, $values['updated_at'], $values['version']
        );
        return ['status' => $outcome['status'], 'id' => $id, 'values' => $values, 'errors' => []];
    }

    /** @return array{status: 'updated'|'missing'|'conflict'|'already_in_target_state'|'invalid', recurrence: ?array, errors?: list<string>} */
    public function transitionEnabled(
        int $id,
        bool $expectedEnabled,
        string $expectedUpdatedAt,
        string $expectedFingerprint,
    ): array {
        $connection = (new Database($this->databasePath))->connectExistingWritable();
        (new MigrationRunner($connection, dirname(__DIR__, 2) . '/database/migrations'))->assertUpToDate();

        $repository = new RecurrenceRepository($connection);
        $current = $repository->findById($id);
        if ($current === null) {
            return ['status' => 'missing', 'recurrence' => null];
        }
        if (!$this->isCurrent($current, $expectedUpdatedAt, $expectedFingerprint)) {
            return ['status' => 'conflict', 'recurrence' => $current];
        }
        if ($current['enabled'] !== $expectedEnabled) {
            return ['status' => 'already_in_target_state', 'recurrence' => $current];
        }

        try {
            if (!$expectedEnabled) {
                $this->referenceValidator()->validate($current);
            }
        } catch (HeskReferenceValidationException $error) {
            return ['status' => 'invalid', 'recurrence' => null, 'errors' => $error->safeErrors];
        }

        // A transação repete a versão após a consulta externa; uma mudança
        // concorrente ainda termina em conflito, nunca em sobrescrita.
        return $repository->transitionEnabledIfCurrent(
            $id, $expectedEnabled, $expectedUpdatedAt, $expectedFingerprint
        );
    }

    /**
     * @param array<string, mixed> $post
     * @return array{array<string, mixed>, array<string, mixed>, list<string>}
     */
    private function parse(array $post): array
    {
        $errors = [];
        $values = [];

        foreach (['name', 'timezone', 'interval_value', 'interval_unit', 'next_run_at',
            'quantity', 'customer_id', 'category_id', 'priority_name', 'status_id',
            'owner_id', 'openedby_id', 'subject', 'message', 'updated_at', 'version'] as $key) {
            $raw = $post[$key] ?? '';
            $values[$key] = is_string($raw) ? $raw : '';

            if (!is_string($raw)) {
                $errors[] = (self::LABELS[$key] ?? $key) . ': informe um valor válido.';
            }
        }

        $values['next_local'] = $values['next_run_at'];
        $values['enabled'] = false;
        $notifyRaw = $post['notify_customer'] ?? null;
        $values['notify_customer'] = $notifyRaw === '1';

        if ($notifyRaw !== null && $notifyRaw !== '1') {
            $errors[] = 'notify_customer deve ser uma opção válida.';
        }

        $customRaw = $post['custom_fields'] ?? [];
        $custom = [];

        if (!is_array($customRaw)) {
            $errors[] = 'custom_fields deve ser um mapa de campos.';
        } else {
            foreach ($customRaw as $key => $value) {
                if (!is_string($key) || !preg_match('/^custom(?:[1-9]|[1-9][0-9]|100)$/D', $key)
                    || !is_string($value)) {
                    $errors[] = 'Campo personalizado inválido.';
                    continue;
                }

                if ($value !== '') {
                    $custom[$key] = $value;
                }
            }
        }

        $values['custom_fields'] = $custom;
        $data = [
            'name' => $values['name'],
            'enabled' => false,
            'timezone' => $values['timezone'],
            'interval_unit' => $values['interval_unit'],
            'priority_name' => $values['priority_name'],
            'subject' => $values['subject'],
            'message' => $values['message'],
            'notify_customer' => $values['notify_customer'],
            'custom_fields' => $custom,
        ];

        foreach (['interval_value', 'quantity', 'customer_id', 'category_id', 'status_id', 'owner_id', 'openedby_id'] as $key) {
            $parsed = filter_var($values[$key], FILTER_VALIDATE_INT);

            if ($parsed === false) {
                $errors[] = self::LABELS[$key] . ': informe um número inteiro válido.';
            } else {
                $data[$key] = $parsed;
            }
        }

        try {
            $timezone = new DateTimeZone($values['timezone']);
            $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $values['next_run_at'], $timezone);
            $parseState = DateTimeImmutable::getLastErrors();

            if ($local === false || $local->format('Y-m-d\TH:i') !== $values['next_run_at']
                || (is_array($parseState) && ($parseState['warning_count'] > 0 || $parseState['error_count'] > 0))) {
                $errors[] = 'Informe uma data e hora local válidas.';
            } else {
                $data['next_run_at'] = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            }
        } catch (Throwable) {
            $errors[] = 'Informe um fuso horário e uma data válidos.';
        }

        if ($errors !== []) {
            foreach (['name', 'timezone', 'interval_unit', 'priority_name', 'subject', 'message'] as $key) {
                if (trim($values[$key]) === '') {
                    $errors[] = self::LABELS[$key] . ': campo obrigatório.';
                }
            }
        } else {
            try {
                $data = (new RecurrenceValidator())->validate($data);
            } catch (DomainException $error) {
                $details = preg_replace('/^Configuração de recorrência inválida:\n- /', '', $error->getMessage());
                $errors = array_map(
                    static fn (string $detail): string => strtr($detail, self::LABELS),
                    explode("\n- ", $details)
                );
            }
        }

        return [$values, $data, $errors];
    }

    private function referenceValidator(): HeskRecurrenceValidator
    {
        return $this->heskValidator ?? new HeskRecurrenceValidator(new NativeHeskCatalogProvider());
    }

    /** @param array<string, mixed> $recurrence */
    private function isCurrent(array $recurrence, string $updatedAt, string $fingerprint): bool
    {
        return hash_equals((string) $recurrence['updated_at'], $updatedAt)
            && hash_equals(RecurrenceRepository::versionFingerprint($recurrence), $fingerprint);
    }
}
