<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

final class HeskTicketCreator
{
    private readonly HeskRecurrenceValidator $referenceValidator;

    public function __construct(?HeskRecurrenceValidator $referenceValidator = null)
    {
        $this->referenceValidator = $referenceValidator
            ?? new HeskRecurrenceValidator(new NativeHeskCatalogProvider());
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function validate(array $definition): array
    {
        global $hesk_settings;

        $errors = [];
        $errorCodes = [];

        if (!function_exists('hesk_newTicket')) {
            $errors[] = 'hesk_newTicket() não está disponível.';
            $errorCodes[] = 'CONFIGURATION_UNSUPPORTED';
        }

        $references = null;
        try {
            $referenceInput = [
                'customer_id' => $definition['customer_id'] ?? null,
                'category_id' => $definition['category'] ?? null,
                'owner_id' => $definition['owner'] ?? null,
                'openedby_id' => $definition['openedby'] ?? null,
                'priority_name' => $definition['priority_name'] ?? null,
                'status_id' => $definition['status'] ?? null,
                'custom_fields' => $definition['custom_fields'] ?? null,
            ];
            foreach (['customer_name', 'category_name', 'owner_name', 'openedby_name'] as $label) {
                if (array_key_exists($label, $definition)) {
                    $referenceInput[$label] = $definition[$label];
                }
            }
            $references = $this->referenceValidator->validate($referenceInput);
        } catch (HeskReferenceValidationException $error) {
            array_push($errors, ...$error->technicalErrors);
            $errorCodes[] = $error->errorCode;
        }

        $subject = isset($definition['subject']) && is_string($definition['subject'])
            ? trim($definition['subject'])
            : '';
        $message = isset($definition['message']) && is_string($definition['message'])
            ? trim($definition['message'])
            : '';

        if ($subject === '') {
            $errors[] = 'O assunto da baseline está vazio.';
            $errorCodes[] = 'CONFIGURATION_UNSUPPORTED';
        } elseif (\hesk_mb_strlen($subject) > 255) {
            $errors[] = 'O assunto da baseline excede 255 caracteres.';
            $errorCodes[] = 'CONFIGURATION_UNSUPPORTED';
        }

        if ($message === '') {
            $errors[] = 'A mensagem da baseline está vazia.';
            $errorCodes[] = 'CONFIGURATION_UNSUPPORTED';
        }

        if (($hesk_settings['staff_ticket_formatting'] ?? 0) === 2 && !class_exists('DOMDocument')) {
            $errors[] = 'O modo de formatação HTML do HESK exige a extensão DOM do PHP.';
            $errorCodes[] = 'CONFIGURATION_UNSUPPORTED';
        }

        if ($errors !== []) {
            throw new OperationalException(
                $errorCodes[0] ?? 'UNCLASSIFIED_ERROR',
                "Validação da POC falhou:\n- " . implode("\n- ", $errors)
            );
        }

        return [
            'hesk_version' => (string) ($hesk_settings['hesk_version'] ?? 'desconhecida'),
            'hesk_new_ticket' => true,
            'customer' => $references['customer'],
            'category' => $references['category'],
            'owner' => $references['owner'],
            'openedby' => $references['openedby'],
            'priority' => $references['priority'],
            'status' => $references['status'],
            'custom_fields' => $references['custom_fields'],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{id: int, trackid: string, subject: string, owner: int, customer_id: int}
     */
    public function create(array $definition, ?string $trackingId = null): array
    {
        global $hesk_settings, $hesklang;

        $validation = $this->validate($definition);
        $openedBy = $validation['openedby'];
        $owner = $validation['owner'];

        $history = sprintf(
            $hesklang['thist7'],
            \hesk_date(),
            addslashes($openedBy['name']) . ' (' . $openedBy['user'] . ')'
        );
        $history .= sprintf(
            $hesklang['thist2'],
            \hesk_date(),
            addslashes($owner['name']) . ' (' . $owner['user'] . ')',
            addslashes($openedBy['name']) . ' (' . $openedBy['user'] . ')'
        );

        [$message, $messageHtml] = $this->prepareMessage((string) $definition['message']);

        $trackingId = $trackingId ?? $this->generateTrackingId();
        $this->validateTrackingId($trackingId);

        $ticketData = [
            'customer_id' => $validation['customer']['id'],
            'follower_ids' => [],
            'category' => $validation['category']['id'],
            'priority' => $validation['priority']['id'],
            'status' => $validation['status']['id'],
            'subject' => \hesk_input((string) $definition['subject']),
            'message' => $message,
            'message_html' => $messageHtml,
            'trackid' => $trackingId,
            'history' => $history,
            'openedby' => $validation['openedby']['id'],
            'owner' => $validation['owner']['id'],
            'assignedby' => $validation['openedby']['id'],
            'attachments' => '',
            'due_date' => '',
        ];

        // hesk_newTicket() devolve todos os campos ativos no resultado; por isso
        // cada campo carregado pelo HESK precisa existir no payload.
        foreach (($hesk_settings['custom_fields'] ?? []) as $key => $_field) {
            $ticketData[$key] = '';
        }

        foreach ($validation['custom_fields'] as $key => $field) {
            $ticketData[$key] = $this->prepareCustomFieldValue((string) $field['value']);
        }

        // Replica o fluxo administrativo: tickets abertos por staff não registram
        // o IP da estação/servidor que executou o comando.
        $hesk_settings['client_IP'] = '';

        $created = \hesk_newTicket($ticketData);

        if (!is_array($created) || empty($created['id']) || empty($created['trackid'])) {
            throw new OperationalException(
                'HESK_TICKET_CREATE_FAILED',
                'hesk_newTicket() não retornou a identificação do ticket criado.'
            );
        }

        if (!hash_equals($trackingId, (string) $created['trackid'])) {
            throw new OperationalException('HESK_TRACKING_INCONSISTENT',
                "hesk_newTicket() retornou tracking ID divergente do identificador preparado."
            );
        }

        return [
            'id' => (int) $created['id'],
            'trackid' => (string) $created['trackid'],
            'subject' => (string) $created['subject'],
            'owner' => (int) $created['owner'],
            'customer_id' => (int) $ticketData['customer_id'],
        ];
    }

    public function generateTrackingId(): string
    {
        $trackingId = \hesk_createID();

        if (!is_string($trackingId) || trim($trackingId) === '') {
            throw new OperationalException(
                'HESK_TRACKING_GENERATION_FAILED',
                'hesk_createID() não conseguiu gerar um tracking ID.'
            );
        }

        $this->validateTrackingId($trackingId);

        return $trackingId;
    }

    private function validateTrackingId(string $trackingId): void
    {
        if ($trackingId !== trim($trackingId)
            || !preg_match('/^[A-Z0-9-]{1,32}$/D', $trackingId)) {
            throw new OperationalException('HESK_TRACKING_INCONSISTENT', 'Tracking ID HESK inválido.');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function prepareMessage(string $rawMessage): array
    {
        global $hesk_settings;

        $message = \hesk_input($rawMessage);
        $messageHtml = $message;

        if ((int) ($hesk_settings['staff_ticket_formatting'] ?? 0) === 2) {
            $messageHtml = \hesk_html_entity_decode($messageHtml);

            require_once HESK_PATH . 'inc/htmlpurifier/HeskHTMLPurifier.php';
            require_once HESK_PATH . 'inc/html2text/html2text.php';

            $purifier = new \HeskHTMLPurifier($hesk_settings['cache_dir']);
            $messageHtml = $purifier->heskPurify($messageHtml);
            $message = \convert_html_to_text($messageHtml);
            $message = \fix_newlines($message);
            $message = nl2br(\hesk_htmlspecialchars($message));
        } else {
            $message = nl2br(\hesk_makeURL($message));
            $messageHtml = $message;
        }

        return [$message, $messageHtml];
    }

    private function prepareCustomFieldValue(string $value): string
    {
        return \hesk_makeURL(nl2br(\hesk_input($value)));
    }

}
