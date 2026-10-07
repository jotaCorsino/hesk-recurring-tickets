# 14 — Manual técnico e operacional

## Rotina

O painel lista recorrências, permite criar, editar, ativar e pausar, mostra as 50 executions recentes e o estado básico do sistema. A autenticação usa STAFF do HESK. Confirme solicitante, categoria, técnico, prioridade, status, campos personalizados, timezone, frequência, quantidade e notificação antes de ativar. A pausa não apaga executions existentes.

Um único Cron chama o orquestrador. Cada ciclo seleciona trabalho finito, obtém lock, calcula vencimentos, materializa itens e usa lease por execution. O worker chama o HESK apenas quando há trabalho. Tickets já criados são procurados pelo tracking ID antes de qualquer retry.

## Verificação de saúde

```sh
cd /opt/hesk-recurring-tickets
php bin/deployment.php check --hesk-path=/var/www/hesk --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite
php bin/deployment.php database-status --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite
php bin/orchestrator.php check --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite
```

Verifique também horário da última execução do Cron, exit code, log privado, lock, espaço em disco, permissões e acesso ao HESK. A página Sistema não prova por si só que o Cron e o gateway HESK funcionam.

## Falhas e recuperação

- HTTP 401: confirme login STAFF no HESK e `HESK_ADMIN_URL`. HTTP 403: sessão/CSRF; recarregue o formulário. HTTP 503: configuração, HESK, SQLite ou migrations; consulte log privado.
- Exit `1`: examine o código operacional e log. Exit `2`: corrija parâmetros/ambiente. Exit `3`: outro ciclo possui o lock; confira se é esperado.
- `failed` e `partial`: inspecione items e tickets HESK pelo tracking ID antes de retry explícito. Itens `succeeded` são preservados.
- Lease expirado: um novo worker pode tomar posse da mesma execution. Lease ativo não deve ser removido manualmente.
- Suspeita de duplicidade: compare tracking IDs, itens persistidos e tickets HESK; não edite tabelas HESK diretamente.

## SQLite, backup e logs

O SQLite usa WAL, foreign keys e migrations versionadas. Execute backup nativo pelo comando de deployment em destino privado, mantendo permissões restritas. Ensaiar migrations e restauração em cópia. Não copie apenas o arquivo principal quando houver WAL pendente. Rotacione logs preservando ownership e permissões; não registre credenciais ou cookies.

## Limitações

Suporte validado para HESK 3.7.12/PHP 8.2. Não há transação distribuída entre SQLite e HESK. `failed`/`partial` requerem retry explícito, executions legadas sem lease precisam de análise manual, o histórico Web é limitado e não há conteúdo individual por patrimônio. O acesso Web aceita qualquer STAFF ativo.

## Estados e comandos de inspeção

| Estado | Significado | Ação normal |
|---|---|---|
| `pending` | execution agendada ainda sem posse | Aguardar ciclo do orquestrador |
| `running` | worker possui lease ativo | Acompanhar heartbeat; não iniciar segundo worker manual |
| `succeeded` | lote completo | Estado terminal; não repetir |
| `failed` | falha antes de concluir | Inspecionar itens e HESK; retry explícito após corrigir causa |
| `partial` | parte do lote criada | Conciliar itens existentes antes do retry |

```sh
php bin/recurrence.php list --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite
php bin/execution.php list --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite --status=failed
php bin/execution.php show --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite --id=1
php bin/execution.php items --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite --id=1
```

O ID `1` acima é ilustrativo. `execution.php retry --id=...` altera estado para permitir nova tentativa; use somente depois de verificar o motivo e o resultado no HESK. O retry reutiliza itens e tracking IDs. A CLI de execution não cria tickets.

## Investigação de uma recorrência atrasada

Confirme `enabled`, `next_run_at`, timezone e intervalo no painel ou `recurrence.php show`. Compare o instante com o relógio UTC e veja se o scheduler atingiu seu limite por ciclo. Confira log e exit code do Cron. Uma competência atrasada é processada por recorrência em cada ciclo; após interrupção longa, o catch-up pode levar vários ciclos. Não avance `next_run_at` manualmente sem entender as executions já materializadas.

## Investigação de ticket ausente ou duplicado

Compare `expected_count`, `created_count`, status e tracking IDs em `execution.php items`. Pesquise cada tracking ID no HESK. Se o ticket existe e o item ainda não concluiu, o retry deve reconciliá-lo. Se não existe, examine validação de catálogo, permissões STAFF, lock HESK e erro operacional. Não insira, atualize ou apague tickets diretamente no banco HESK para “corrigir” a automação.

## Contenção

Para suspender novas criações, desative o Cron e registre o momento. Desabilitar `ADMIN_UI_WRITE_ENABLED` impede POST no painel, mas não desliga o Cron. Pausar uma recorrência evita futuros agendamentos dessa definição; executions já pendentes podem continuar elegíveis. Para retomada, verifique integridade SQLite, leases, logs e tickets HESK, depois faça `orchestrator.php check` antes de religar o Cron.

## Rotina periódica

Diariamente, confira última execução do Cron, exit codes, failures/partials, uso do disco e crescimento dos logs. Semanalmente, execute preflight e verifique o backup mais recente em ambiente isolado. Antes de atualizar código ou HESK, faça snapshot verificável e ensaie migrations e restauração. Mantenha logs e backups fora do webroot com retenção compatível com a política local.
