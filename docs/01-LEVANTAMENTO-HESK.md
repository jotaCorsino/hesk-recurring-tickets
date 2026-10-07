# 01 — Levantamento genérico do HESK

## Ambiente suportado

A integração foi construída para HESK OSS 3.7.12 e PHP 8.2. O projeto usa SQLite separado do banco HESK; o HESK mantém seu próprio MariaDB/MySQL e a configuração em `hesk_settings.inc.php`. Configure `HESK_PATH`, `HESK_SERVER_NAME` e `HESK_ADMIN_URL` para a sua instalação.

## Fluxo nativo

`admin/new_ticket.php` encaminha para `admin/admin_submit_ticket.php`, que valida solicitante, categoria, permissões e campos personalizados antes de chamar `hesk_newTicket()` em `inc/posting_functions.inc.php`. A automação prepara o contexto necessário na CLI e usa essa função para criar tickets. O projeto não copia nem modifica esses arquivos.

## Descoberta de catálogos

IDs de `customers`, `categories`, `users`, prioridades, status e custom fields são locais. `NativeHeskCatalogProvider` consulta as tabelas pelo prefixo configurado no próprio HESK e entrega dados somente leitura ao validador e ao painel. Não há mapeamento fixo de categoria, técnico ou empresa.

Um exemplo **fictício** usa solicitante `customer_id=100`, categoria `category_id=10`, técnico `owner_id=20`, autor `openedby_id=20` e um campo de empresa com valor `Example Company`. Verifique os valores reais de sua instalação antes de importar ou ativar uma recorrência. O solicitante HESK e a empresa representada em um campo personalizado são conceitos distintos.

## Regras de segurança

- Um STAFF precisa ter acesso à categoria quando for atribuído explicitamente.
- O cliente externo não é notificado por padrão pela automação.
- O gateway consulta tickets por tracking ID para reconciliação e só cria via `hesk_newTicket()`.
- A camada de catálogos e o Web bootstrap não devem executar escrita ou cache incidental no HESK.
