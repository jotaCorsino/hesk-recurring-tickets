# HESK Recurring Tickets

Este projeto resolve um problema comum de operação: o HESK não oferece, por padrão, uma forma flexível de programar chamados recorrentes, como manutenções preventivas mensais, trimestrais ou anuais. Em vez de depender de alguém lembrar de abrir esses chamados manualmente, o sistema permite configurar a recorrência uma única vez e automatiza as próximas execuções.

Na prática, ele funciona como uma camada externa ao HESK. Pelo painel Web, é possível definir o que deve ser criado, quando deve acontecer, para quem o chamado será atribuído e quantos tickets devem ser gerados. Um processo agendado verifica periodicamente as recorrências vencidas, utiliza as próprias funções internas do HESK para criar os chamados e registra cada execução em SQLite para manter histórico, rastreabilidade e proteção contra duplicidades.

O HESK continua sendo o sistema principal de tickets. Este projeto apenas acrescenta a automação de recorrências ao redor dele, sem alterar o core do HESK e sem criar tickets por inserção SQL direta.

## Desenvolvimento assistido por IA

Este projeto foi desenvolvido por meio de **programação assistida por IA**, combinando planejamento, revisão e validação humana com o uso do **ChatGPT** e do **Codex** para apoiar arquitetura, implementação, testes, documentação e refinamento do código.

| Item | Informação |
|---|---|
| Início do desenvolvimento | 02/10/2026 |
| Conclusão da primeira versão | 07/10/2026 |
| Abordagem | Programação assistida por IA |
| Ferramentas principais | ChatGPT + Codex |
| Modelo utilizado na maior parte do desenvolvimento | GPT-5.6 Sol |
| Nível de raciocínio predominante | Alto |

A IA foi utilizada como ferramenta de desenvolvimento assistido; decisões de escopo, homologação, validação funcional e publicação permaneceram sob supervisão humana.

## Recursos

- Painel Web para listar, criar, editar, ativar e pausar recorrências e consultar execuções.
- Autenticação pela sessão STAFF do HESK, com sessão separada para CSRF e mensagens do painel.
- Scheduler com cálculo de calendário na timezone da recorrência e um único Cron.
- Lotes de tickets com item persistente, tracking ID, lease e reconciliação para evitar duplicidade em retries.
- Catálogos HESK lidos a cada requisição e validação referencial antes de gravar ou ativar.
- Preflight de implantação, migrations versionadas, backup SQLite e códigos de erro operacionais.

## Arquitetura

```text
Painel STAFF ──> SQLite (recorrências, execuções e itens)
Cron único ──> orchestrator ──> scheduler ──> lease/worker
                                              └──> catálogo HESK + hesk_newTicket()
                                                        └──> HESK (tickets)
```

O orquestrador limita o trabalho por ciclo. A identidade de cada item é persistida antes de chamar o HESK. Em um retry, o gateway procura o mesmo tracking ID e reconcilia um ticket existente. SQLite e o banco do HESK não compartilham transação; por isso o projeto não promete exactly-once fora do protocolo de lookup e lock descrito em [processamento de lotes](docs/10-BATCH-PROCESSING.md).

## Requisitos

- PHP 8.2, CLI e Web, com `PDO`, `pdo_sqlite` e `sqlite3`.
- HESK OSS 3.7.12 acessível no filesystem e seu banco configurado pelo próprio HESK.
- Um diretório privado gravável para SQLite, lock e logs; somente `public/` deve ficar acessível pela Web.
- Cron capaz de executar PHP CLI. Verifique as funções HESK na versão instalada antes de atualizar o HESK.

## Configuração

Defina as variáveis no ambiente de execução, sem versionar segredos:

| Variável | Finalidade | Exemplo público |
|---|---|---|
| `HESK_PATH` | instalação do HESK | `/var/www/hesk` |
| `HESK_SERVER_NAME` | host usado pelo bootstrap CLI | `helpdesk.example.com` |
| `HESK_ADMIN_URL` | login STAFF exibido pelo painel | `https://helpdesk.example.com/admin/` |
| `APP_DB_PATH` | SQLite privado | `/opt/hesk-recurring-tickets/storage/app.sqlite` |
| `ADMIN_UI_BASE_PATH` | prefixo Web, se houver | `/recurrent/` |
| `ADMIN_UI_ENABLED` | habilita leitura do painel | `1` |
| `ADMIN_UI_WRITE_ENABLED` | habilita POST no painel | `0` inicialmente |
| `ORCHESTRATOR_LOCK_PATH` | lock local, quando necessário | `/opt/hesk-recurring-tickets/storage/orchestrator.lock` |

IDs de solicitante, categoria e STAFF e opções de campos personalizados são próprios de **cada** instalação HESK. Os JSONs em `config/examples/` usam valores fictícios e devem ser adaptados após consulta aos catálogos locais. Eles não são configuração de produção.

## Início local

```sh
php bin/recurrence.php migrate --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite
php bin/deployment.php check --hesk-path=/var/www/hesk --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite
php bin/orchestrator.php check --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite
```

Consulte `--help` de cada CLI para parâmetros atuais. Antes de ativar automações, valide o HESK local, o banco, as permissões, o ponto público e os valores dos exemplos. O fluxo de implantação e operação está em [docs/13-DEPLOYMENT.md](docs/13-DEPLOYMENT.md) e [docs/14-OPERACAO.md](docs/14-OPERACAO.md).

Um Cron genérico, após a validação, pode executar:

```cron
*/5 * * * * cd /opt/hesk-recurring-tickets && /usr/bin/php bin/orchestrator.php run --hesk-path=/var/www/hesk >> /opt/hesk-recurring-tickets/storage/logs/orchestrator.log 2>&1
```

Configure `APP_DB_PATH`, `HESK_SERVER_NAME` e demais variáveis no ambiente do Cron. Não exponha `storage/`, `src/`, `config/`, `database/` ou `bin/` ao servidor Web.

## Testes

Os testes usam fakes e bancos temporários, sem infraestrutura privada:

```sh
for test in run persistence scheduler safety batch web auth errors catalogs deployment orchestrator; do php "tests/$test.php"; done
```

A integração com HESK deve ser validada em uma instalação de teste antes de uso real. A POC CLI aceita uma definição JSON explícita via `POC_DEFINITION_PATH`; o arquivo de exemplo também é fictício.

## Documentação

- [Levantamento genérico HESK](docs/01-LEVANTAMENTO-HESK.md), [arquitetura](docs/02-ARQUITETURA.md), [roadmap](docs/03-ROADMAP.md) e [decisões](docs/04-DECISOES-TECNICAS.md).
- [Persistência](docs/07-PERSISTENCIA.md), [scheduler](docs/08-SCHEDULER.md), [lease](docs/09-EXECUTION-SAFETY.md) e [lotes](docs/10-BATCH-PROCESSING.md).
- [Painel](docs/11-ADMIN-UI.md), [erros](docs/12-OPERATIONAL-ERRORS.md), [implantação](docs/13-DEPLOYMENT.md) e [operação](docs/14-OPERACAO.md).

O manual PDF específico de uma instalação não integra esta distribuição. Um manual público poderá ser gerado a partir desta documentação revisada.

## Limitações

- Suporte testado para HESK 3.7.12 e PHP 8.2; outras versões exigem validação.
- O projeto não cria tickets por SQL direto nem altera o core HESK.
- `failed` e `partial` exigem retry explícito; executions legadas sem lease requerem análise manual.
- Patrimônio e conteúdo individual por item ainda não têm modelo próprio.
- A autenticação aceita qualquer STAFF ativo; controle por papel específico ainda não foi implementado.
