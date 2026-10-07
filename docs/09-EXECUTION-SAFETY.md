# 09 — Segurança e idempotência de execution

**Status da SAFE-001:** `CONCLUÍDO`

## Objetivo e limite

SAFE-001 protege a posse e as transições de uma `recurrence_execution` contra workers concorrentes, Cron sobreposto, interrupção de processo, lease abandonado, retry indevido e uso de token antigo.

Isoladamente, esta etapa não carrega o HESK, não cria tickets, não implementa lote e não altera `recurrence.next_run_at`. Ela opera somente sobre executions já criadas pelo scheduler. A BATCH-001 reutiliza este contrato sem criar um mecanismo paralelo de posse.

## Fluxo e máquina de estados

```text
Scheduler
    ↓
pending
    ↓ claim
running
    ├─ finish succeeded → succeeded (terminal)
    ├─ finish failed    → failed  ── retry explícito ──→ pending
    └─ finish partial   → partial ── retry explícito ──→ pending
```

Regras:

- `pending` pode ser claimed;
- `running` com lease válido pertence exclusivamente ao token atual;
- `running` com lease expirado e metadados completos pode sofrer stale takeover;
- `running` sem token, owner ou expiração é estado legado inconsistente e não é roubado automaticamente;
- `failed` e `partial` não entram no claim automático;
- `succeeded` não aceita claim nem retry.

## Migration 002

`database/migrations/002_execution_leases.sql` preserva a migration 001 e a restrição `UNIQUE (recurrence_id, scheduled_for)`. Ela acrescenta em `recurrence_executions`:

- `attempt_count INTEGER NOT NULL DEFAULT 0`;
- `lease_token TEXT NULL`;
- `lease_owner TEXT NULL`;
- `lease_expires_at TEXT NULL`;
- `last_attempt_at TEXT NULL`.

O índice `idx_recurrence_executions_claimable` usa `status`, `lease_expires_at`, `scheduled_for` e `id` para apoiar a busca ordenada de trabalho elegível. Registros anteriores recebem `attempt_count=0` e permanecem sem lease.

## Claim e concorrência SQLite

O serviço abre `BEGIN IMMEDIATE` antes de procurar o candidato. Enquanto mantém o lock:

1. localiza a primeira execution elegível, ou exclusivamente o ID solicitado;
2. confirma o status e a expiração;
3. gera `bin2hex(random_bytes(32))`;
4. move a linha para `running`;
5. registra owner, token, expiração, `last_attempt_at` e `started_at`;
6. incrementa `attempt_count`;
7. executa `COMMIT`, ou `ROLLBACK` em erro.

Assim, duas conexões SQLite não selecionam e assumem a mesma execution simultaneamente. O scheduler mantém sua transação atual e não realiza claim.

## Lease, owner e token

O lease padrão dura 300 segundos. A API e a CLI aceitam valores entre 30 e 3600 segundos. Todos os instantes são ISO-8601 UTC.

`lease_owner` é um texto legível de 1 a 255 caracteres. Pode seguir um formato operacional como `hostname:pid`, mas a biblioteca não depende dessa convenção.

O token aleatório é a prova de posse. Somente o token correspondente a um lease `running` ainda não expirado pode renovar ou finalizar. `list` e `show` nunca exibem `lease_token`; ele aparece somente no resultado do claim concedido e não deve ser salvo em logs, documentação ou Git.

## Heartbeat e stale takeover

Heartbeat estende `lease_expires_at` apenas quando status, token e validade ainda conferem. No instante exato da expiração, o lease já é considerado expirado e o worker antigo deve parar.

Uma execution `running` com `lease_expires_at <= agora` pode ser assumida por outro worker. O takeover troca owner e token, atualiza as datas e incrementa `attempt_count`. O token anterior perde imediatamente a capacidade de renovar, concluir, falhar ou modificar o estado.

## Finalização e retry

Finish aceita `succeeded`, `failed` ou `partial` somente com token ativo. Em todos os casos registra `finished_at` e limpa token, owner e expiração.

- `succeeded` sempre limpa `error_message` e é terminal;
- `failed` exige mensagem de erro;
- `partial` aceita mensagem explicativa opcional.

Retry é administrativo e explícito, somente para `failed` ou `partial`. Ele mantém ID, `recurrence_id`, `scheduled_for`, contagens e `attempt_count`; limpa lease, `error_code`, `error_message`, `started_at` e `finished_at`; e devolve a mesma linha a `pending`. Uma nova linha nunca é criada para retry. ERR-001 acrescentou `error_code` pela migration 004; finish manual sem classificação explícita usa `UNCLASSIFIED_ERROR`, e succeeded limpa código e mensagem.


## Integração com a BATCH-001

SAFE-001 impede que dois workers processem conscientemente a mesma execution ao mesmo tempo e protege suas transições. A BATCH-001 acrescenta a identidade de ticket que não pertencia a esta etapa:

- um item SQLite por ticket do lote;
- tracking ID persistido antes da criação;
- reconciliação pelo tracking ID após interrupção;
- preservação de itens `succeeded` no retry;
- `created_count` derivado dos itens concluídos.

O `BatchProcessor` recebe uma execution já claimed. Antes de cada item, renova o lease; todas as mutações de item e de `created_count` exigem status `running`, token correspondente e `lease_expires_at` futuro. Depois de um lookup ou create externo, o worker renova novamente antes de persistir o resultado. Se perder a posse, para sem finalizar nem processar o item seguinte. Um novo worker assume pelo stale takeover e reconcilia qualquer ticket que tenha sido criado no intervalo.

Isso não transforma SQLite e MariaDB em uma transação única. A garantia contra a corrida conhecida usa tracking ID estável, lookup e named lock MariaDB no gateway BATCH; os detalhes e os limites estão em `docs/10-BATCH-PROCESSING.md`.

## CLI administrativa

Ajuda:

```bash
php bin/execution.php
```

Listar e inspecionar sem expor token:

```bash
php bin/execution.php list --db-path=/caminho/app.sqlite
php bin/execution.php list --db-path=/caminho/app.sqlite --status=pending
php bin/execution.php show --db-path=/caminho/app.sqlite --id=1
php bin/execution.php items --db-path=/caminho/app.sqlite --id=1
```

Claim da próxima execution ou de um ID específico:

```bash
php bin/execution.php claim --db-path=/caminho/app.sqlite --worker=worker-a --lease-seconds=300
php bin/execution.php claim --db-path=/caminho/app.sqlite --worker=worker-a --id=1 --lease-seconds=300
```

Heartbeat, finish e retry:

```bash
php bin/execution.php heartbeat --db-path=/caminho/app.sqlite --id=1 --token=<TOKEN> --lease-seconds=300
php bin/execution.php finish --db-path=/caminho/app.sqlite --id=1 --token=<TOKEN> --result=succeeded
php bin/execution.php finish --db-path=/caminho/app.sqlite --id=1 --token=<TOKEN> --result=failed --error="erro controlado"
php bin/execution.php finish --db-path=/caminho/app.sqlite --id=1 --token=<TOKEN> --result=partial --error="processamento parcial"
php bin/execution.php retry --db-path=/caminho/app.sqlite --id=1
```

Recusas e validações retornam exit code diferente de zero. A CLI exige migrations atualizadas, não contém SQL, não carrega o HESK e não cria recurrence ou execution.

## Validação

Execute os testes locais correspondentes e valide a integração com uma instalação HESK de teste antes de ativar automações. Use banco SQLite isolado e dados fictícios; não use um banco operacional para ensaio.
