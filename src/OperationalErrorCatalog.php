<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

final class OperationalErrorCatalog
{
    /** @return array{reason: string, next_step: string} */
    public static function present(?string $errorCode): array
    {
        return match ($errorCode) {
            'HESK_CUSTOMER_INVALID' => ['reason' => 'O solicitante configurado não está disponível.', 'next_step' => 'Confira o solicitante da recorrência antes de reprocessar.'],
            'HESK_CATEGORY_INVALID' => ['reason' => 'A categoria configurada não está disponível.', 'next_step' => 'Confira a categoria da recorrência antes de reprocessar.'],
            'HESK_OWNER_INVALID' => ['reason' => 'O responsável não está disponível para a categoria.', 'next_step' => 'Confira o responsável e seu acesso à categoria.'],
            'HESK_OPENEDBY_INVALID' => ['reason' => 'O autor interno não está disponível para a categoria.', 'next_step' => 'Confira o autor interno e seu acesso à categoria.'],
            'HESK_PRIORITY_INVALID' => ['reason' => 'A prioridade configurada não está disponível.', 'next_step' => 'Confira a prioridade no HESK.'],
            'HESK_STATUS_INVALID' => ['reason' => 'O status configurado não está disponível.', 'next_step' => 'Confira o status no HESK.'],
            'HESK_CUSTOM_FIELDS_INVALID' => ['reason' => 'Um campo personalizado não foi aceito.', 'next_step' => 'Confira os campos da recorrência e as opções da categoria.'],
            'CONFIGURATION_UNSUPPORTED' => ['reason' => 'A configuração ainda não é suportada.', 'next_step' => 'Revise os parâmetros da recorrência antes de reprocessar.'],
            'HESK_TRACKING_GENERATION_FAILED' => ['reason' => 'Não foi possível preparar a identificação do ticket.', 'next_step' => 'Consulte o diagnóstico técnico antes de reprocessar.'],
            'HESK_TRACKING_INCONSISTENT' => ['reason' => 'A identificação do ticket está inconsistente.', 'next_step' => 'Confira o tracking ID no HESK antes de reprocessar.'],
            'HESK_TICKET_CREATE_FAILED' => ['reason' => 'A criação do ticket não foi confirmada.', 'next_step' => 'Confira o tracking ID no HESK antes de reprocessar.'],
            'HESK_TICKET_RECONCILIATION_FAILED' => ['reason' => 'O ticket criado não pôde ser confirmado.', 'next_step' => 'Confira o tracking ID no HESK antes de reprocessar.'],
            'HESK_LOCK_FAILED' => ['reason' => 'A proteção de criação do ticket falhou.', 'next_step' => 'Consulte o diagnóstico técnico antes de reprocessar.'],
            'BATCH_ITEMS_FAILED' => ['reason' => 'Um ou mais itens do lote falharam.', 'next_step' => 'Confira os itens com falha antes de reprocessar.'],
            'BATCH_PARTIAL_FAILURE' => ['reason' => 'Parte dos tickets do lote foi criada.', 'next_step' => 'Confira os itens pendentes e os tracking IDs antes de reprocessar.'],
            default => ['reason' => 'A execução terminou com uma falha não classificada.', 'next_step' => 'Consulte o diagnóstico técnico antes de reprocessar.'],
        };
    }
}
