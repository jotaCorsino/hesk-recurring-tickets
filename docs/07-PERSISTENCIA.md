# 07 — Persistência e modelo de recorrência

**Status da CFG-001:** `CONCLUÍDO`

## Escopo da CFG-001

A CFG-001 cria a base persistente da aplicação, para a agenda, execuções e itens. A CLI desta etapa não carrega o bootstrap do HESK.

## Por que SQLite


A camada de acesso está encapsulada em repositórios. Se concorrência ou escala futuras exigirem outro banco, a troca pode ser feita sem levar SQL para a CLI ou para regras de negócio.

## Caminho do banco

Precedência de configuração:

1. opção CLI `--db-path`;
2. variável de ambiente `APP_DB_PATH`;
3. padrão `storage/app.sqlite` relativo à raiz do projeto.

Exemplo genérico:

```text
/opt/hesk-recurring-tickets/storage/app.sqlite
```

Esse diretório fica fora do document root público da instalação do HESK. O banco não deve ser movido para uma pasta servida pela web.

Os padrões de `.gitignore` excluem `*.sqlite`, `*.sqlite3`, `*.db` e arquivos auxiliares WAL/SHM de `storage/`. Apenas `storage/.gitkeep` é versionado.

## Schema

### `recurrences`

Contém a definição editável da recorrência:

- identidade, nome e estado `enabled`;
- `timezone`, intervalo e próxima execução;
- quantidade esperada por ocorrência;
- IDs de solicitante, categoria, status, responsável e autor interno;
- prioridade pelo nome, assunto e mensagem;
- política `notify_customer`;
- mapa de custom fields serializado em `custom_fields_json`;
- timestamps de criação e atualização.

Não existe operação de exclusão no repositório. A desativação usa `enabled=false`, preservando histórico e referências.

### `recurrence_executions`

Registra uma ocorrência programada e sua rastreabilidade:

- vínculo obrigatório com `recurrences`;
- instante `scheduled_for`;
- status `pending`, `running`, `succeeded`, `failed` ou `partial`;
- quantidades esperada e criada;
- início, término, erro e timestamps de auditoria.

A FK usa `ON DELETE RESTRICT` e `ON UPDATE RESTRICT`. A chave única `(recurrence_id, scheduled_for)` impede duas linhas para a mesma ocorrência.

Essa chave é uma garantia estrutural, não a implementação completa da SAFE-001. Reserva atômica do trabalho, retry e retomada de lotes parciais são descritos nos documentos de lease e lote.

## Datas e timezone

Entradas de instante devem usar ISO-8601 com `Z` ou deslocamento explícito, por exemplo:

```text
2027-01-15T09:00:00-03:00
```

O valor é normalizado antes da persistência:

```text
2027-01-15T12:00:00Z
```

O campo `timezone`, como `America/Sao_Paulo`, também é validado e preservado. Ele será a referência para cálculos civis nas etapas futuras; esta tarefa não calcula recorrências.

## Custom fields

`custom_fields` deve ser um objeto JSON. As chaves aceitas são `custom1` a `custom100`, e os valores devem ser textos. O repositório ordena as chaves e serializa o mapa em `custom_fields_json`.

Exemplo versionado:

```text
config/examples/monthly-maintenance.json
```

Esse exemplo contém data futura, não usa o marcador `[POC]` e não contém credenciais.

## Migrations

O runner lê `database/migrations/*.sql` em ordem lexical. Cada arquivo deve seguir o padrão `NNN_nome.sql`.

Ao executar `migrate`, ele:

1. cria `schema_migrations` quando necessário;
2. calcula SHA-256 de cada arquivo;
3. aplica migrations pendentes dentro de transação;
4. registra versão, checksum e timestamp;
5. ignora com segurança migrations já aplicadas e inalteradas;
6. bloqueia a execução se o conteúdo de uma migration aplicada tiver sido modificado.

## WAL, integridade e backup

Toda conexão habilita:

```text
PRAGMA foreign_keys=ON
PRAGMA busy_timeout=5000
PRAGMA journal_mode=WAL
```

WAL melhora a convivência entre leituras e uma escrita eventual. O arquivo principal, `-wal` e `-shm` formam estado operacional enquanto há conexão ativa; copiar apenas o arquivo principal durante uma escrita não é um backup seguro.

Na implantação, use exclusivamente o comando controlado `bin/deployment.php backup`, que chama a API nativa `SQLite3::backup()`, exige um destino privado explícito fora do checkout e recusa WAL não vazio sem executar checkpoint automático. O backup de recuperação permanece intocado; qualquer ensaio ou restauração usa uma cópia separada e decisão registrada. O procedimento completo está em `docs/13-DEPLOYMENT.md`.

## Permissões

O usuário do PHP CLI precisa criar e alterar o arquivo e seus auxiliares no diretório `storage`. Referência conservadora para o mesmo usuário/grupo do processo:

```bash
chmod 750 /opt/hesk-recurring-tickets/storage
chmod 660 /opt/hesk-recurring-tickets/storage/app.sqlite
```

Não use permissão global de escrita. O construtor tenta criar o diretório com `0770` e o arquivo com `0660`, mas o `umask` e as regras do provedor continuam prevalecendo.

## CLI administrativa

```bash
php bin/recurrence.php migrate [--db-path=/caminho/app.sqlite]
php bin/recurrence.php create --file=/caminho/recurrence.json [--db-path=/caminho/app.sqlite]
php bin/recurrence.php list [--db-path=/caminho/app.sqlite]
php bin/recurrence.php show --id=1 [--db-path=/caminho/app.sqlite]
php bin/recurrence.php update --id=1 --file=/caminho/alteracoes.json [--db-path=/caminho/app.sqlite]
php bin/recurrence.php enable --id=1 [--db-path=/caminho/app.sqlite]
php bin/recurrence.php disable --id=1 [--db-path=/caminho/app.sqlite]
```

Comandos não destrutivos: não existe `delete`.

## Validação

Execute os testes locais correspondentes e valide a integração com uma instalação HESK de teste antes de ativar automações. Use banco SQLite isolado e dados fictícios; não use um banco operacional para ensaio.
