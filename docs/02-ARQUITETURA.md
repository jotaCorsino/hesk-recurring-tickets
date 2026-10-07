# 02 — Arquitetura

## Fluxo

```text
Painel Web → validação HESK → SQLite
Cron → Orchestrator → Scheduler → execution → lease → BatchProcessor
                                                   ↓
                                          HeskTicketGateway
                                                   ↓
                              lookup por tracking ID / hesk_newTicket()
```

O HESK permanece dono dos tickets e de seus cadastros. O SQLite local guarda definições de recorrência, executions e itens do lote. O painel lê os catálogos HESK para selecionar e validar referências; não mantém cópias fixas de IDs. O gateway é substituível por fake nos testes.

## Bootstrap e criação

A CLI recebe `HESK_PATH` e `HESK_SERVER_NAME`. `HeskBootstrap` carrega configuração e funções nativas do HESK 3.7.12, verifica a versão e prepara o contexto HTTPS necessário. `HeskTicketCreator` valida dados e chama `hesk_newTicket()`. O projeto não executa `INSERT` ou `UPDATE` em tickets HESK como mecanismo de criação.

## Agenda

Cada recorrência tem timezone IANA, intervalo civil, próxima execução e quantidade. O scheduler seleciona recorrências vencidas, calcula uma competência por recorrência por ciclo e cria a execution junto do avanço de `next_run_at` em transação SQLite. Limites globais evitam processamento ilimitado de atrasos. `check` não escreve.

## Lease e lote

Uma execution é reivindicada por token aleatório e lease temporário em transação `BEGIN IMMEDIATE`. Heartbeats renovam a posse. `failed` e `partial` requerem retry explícito; `succeeded` é terminal. Um item persistente representa cada ticket esperado. Tracking ID é gravado antes da chamada externa e reaproveitado em retries.

O gateway procura o tracking ID antes de criar, obtém lock nomeado no banco HESK, repete o lookup dentro do lock e usa `hesk_newTicket()` somente se ainda não existe. Após retorno, persiste ID e estado do item no SQLite. Se o processo cair entre a criação HESK e a atualização SQLite, o retry reconcilia pelo mesmo tracking ID. SQLite e MariaDB não oferecem transação distribuída; veja os limites em [lotes](10-BATCH-PROCESSING.md).

## Web

A página pública é `public/index.php`. O painel usa a sessão STAFF nativa para autenticar e uma sessão própria para CSRF. Os gates `ADMIN_UI_ENABLED` e `ADMIN_UI_WRITE_ENABLED` controlam exposição e escrita. POST revalida referências, usa comparação otimista e requer banco existente com migrations compatíveis. Só `public/` deve ser publicado; SQLite, fontes, CLIs e logs ficam privados.
