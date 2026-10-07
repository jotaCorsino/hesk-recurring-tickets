# 03 — Roadmap técnico

A tabela descreve capacidades implementadas no código. Cada instalação requer sua própria validação operacional antes de ativar o Cron.

| Área | Capacidade | Estado do código |
|---|---|---|
| POC | Bootstrap CLI e criação via `hesk_newTicket()` | Implementado |
| CFG | SQLite, migrations e CRUD de recorrências | Implementado |
| SCH | Agenda civil com timezone e catch-up limitado | Implementado |
| SAFE | Claim, lease, heartbeat, stale takeover e retry explícito | Implementado |
| BATCH | Item por ticket, tracking ID estável, lookup e reconciliação | Implementado |
| ERR | Códigos de erro operacionais e apresentação segura | Implementado |
| UI | Painel STAFF, catálogos dinâmicos, CSRF e controle otimista | Implementado |
| DEP | Preflight, backup e instalação pública limitada a `public/` | Implementado |
| OPS | Orquestrador com lock e limites por ciclo | Implementado |

## Antes de produção

1. Validar compatibilidade de funções e schema com o HESK local.
2. Configurar caminhos, URL de login, host e banco privado.
3. Revisar IDs e opções de cada recorrência usando os catálogos locais.
4. Executar testes, preflight, backup e ensaio de migrations em cópia.
5. Publicar somente `public/`, testar autenticação STAFF e gates Web.
6. Testar um ciclo controlado em ambiente de teste e então configurar um Cron único.

## Evolução

Há espaço para conteúdo individual por item, resolução administrativa de executions legadas sem lease, paginação do histórico Web e autorização STAFF por papel. Esses itens não devem ser presumidos implementados.
