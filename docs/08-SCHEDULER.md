# 08 — Scheduler de recorrências

**Status da SCH-001:** `CONCLUÍDO`

## Objetivo e limite

O scheduler identifica recorrências ativas e vencidas, registra cada ocorrência como `pending` e avança `next_run_at`. Ele não cria tickets, não carrega o HESK, não processa executions pendentes e não instala Cron.

## Scheduler x Cron

O scheduler contém a regra de seleção, cálculo e persistência. O Cron é o relógio externo que pode chamar:

```bash
php bin/scheduler.php run --db-path=/caminho/app.sqlite --limit=100
```

Nenhum Cron é criado automaticamente pela aplicação.

## Fluxo

```text
agora UTC
    ↓
findDue: enabled=1 e next_run_at <= agora
    ↓ ordena por next_run_at, id e aplica limit
para cada recorrência
    ↓
scheduled_for = next_run_at atual
    ↓
calcula próxima data no calendário civil da timezone
    ↓
transação SQLite
    ├─ cria recurrence_execution pending
    └─ atualiza next_run_at se ainda tiver o valor esperado
    ↓
commit ou rollback integral
```


## Clock e seleção

`Scheduler::check()` e `Scheduler::run()` recebem um `DateTimeImmutable`. A CLI injeta o horário UTC corrente; testes usam instantes fixos.

`RecurrenceRepository::findDue()` seleciona somente:

```text
enabled = 1 AND next_run_at <= agora UTC
```

O resultado usa o índice `idx_recurrences_enabled_next_run`, ordena por `next_run_at` e `id` e respeita `--limit`. O padrão é 100 e os valores aceitos são de 1 a 1000 recorrências. `quantity` não altera esse limite.

## Cálculo civil e timezone

O banco continua armazenando instantes em UTC. Para calcular a próxima ocorrência:

1. converte `scheduled_for` para a timezone IANA da recorrência;
2. adiciona o intervalo no calendário civil local;
3. converte o resultado novamente para UTC.

Assim, uma agenda de 09:00 permanece às 09:00 locais quando o offset legal mudar.

Unidades:

- `day`: adiciona dias civis;
- `week`: adiciona blocos de sete dias civis;
- `month`: calcula ano/mês de destino e limita o dia ao último válido;
- `year`: mantém mês e dia quando válidos e limita o dia quando necessário.

Exemplos:

```text
31/jan + 1 mês → 28/fev ou 29/fev
31/mar + 1 mês → 30/abr
29/fev + 1 ano não bissexto → 28/fev
```

Hora, minuto, segundo e timezone civil são preservados. O cálculo seguinte sempre parte do `next_run_at` persistido corrente.

## Política de catch-up

Cada run processa no máximo uma competência por recorrência. Se `next_run_at` estiver vários períodos atrasado, o scheduler registra a competência mais antiga e avança somente uma vez. Um run posterior poderá processar a próxima competência ainda vencida.

Essa política evita loops e rajadas ilimitadas. Catch-up configurável não faz parte da SCH-001.

## Transação e duplicidade

A criação da execution e o avanço de `next_run_at` usam a mesma transação. Se a inserção ou atualização falhar, ambas são revertidas.

O update compara o `next_run_at` esperado e exige que a recorrência continue ativa. Se outro processo ou operador modificar a linha, o scheduler faz rollback e relata erro.

Se já existir `(recurrence_id, scheduled_for)`, a recorrência é reportada em `skipped` com motivo `execution_already_exists`; nenhuma nova linha é criada e `next_run_at` não avança silenciosamente.

Essa proteção não conclui SAFE-001. São tratados pelo worker e pelo processamento de lotes:

- lock e reserva entre múltiplos workers;
- retry explícito;
- retomada depois de falha;
- recuperação de lotes parciais;
- tickets criados antes de um eventual crash.

## CLI

Sem argumentos, a CLI mostra ajuda e não executa o scheduler.

Dry run:

```bash
php bin/scheduler.php check --db-path=/caminho/app.sqlite --limit=100
```

O comando informa o horário usado, as recorrências vencidas, `scheduled_for` e o próximo instante calculado. Nenhuma recorrência ou execution é alterada.

Persistência:

```bash
php bin/scheduler.php run --db-path=/caminho/app.sqlite --limit=100
```

O relatório separa recorrências processadas, ignoradas e com erro. Também é possível configurar o banco por `APP_DB_PATH`.

Inspeção somente leitura das executions:

```bash
php bin/recurrence.php executions --db-path=/caminho/app.sqlite --id=1
```

## Validação

Execute os testes locais correspondentes e valide a integração com uma instalação HESK de teste antes de ativar automações. Use banco SQLite isolado e dados fictícios; não use um banco operacional para ensaio.
