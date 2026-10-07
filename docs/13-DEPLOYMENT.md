# 13 — Implantação genérica

## Topologia

Mantenha o projeto e o SQLite fora do webroot. Exponha somente `public/`, por symlink controlado ou configuração equivalente do servidor Web. Nunca exponha `storage/`, backups, `src/`, `bin/`, `database/`, `config/` ou documentação administrativa. Um exemplo de caminhos é projeto em `/opt/hesk-recurring-tickets`, HESK em `/var/www/hesk` e painel em `https://helpdesk.example.com/recurrent/`.

## Preparação

1. Instale PHP 8.2 com PDO SQLite e SQLite3 para CLI e Web.
2. Configure `HESK_PATH`, `HESK_SERVER_NAME`, `HESK_ADMIN_URL`, `APP_DB_PATH` e `ADMIN_UI_BASE_PATH` conforme a instalação. Proteja os valores no ambiente do servidor, fora do Git.
3. Prepare diretórios privados para SQLite, logs e lock; o usuário do Cron precisa de escrita e o Web deve ter somente o acesso necessário.
4. Execute `php bin/deployment.php check --hesk-path=/var/www/hesk --db-path=/opt/hesk-recurring-tickets/storage/app.sqlite` e resolva bloqueios.
5. Faça backup por `php bin/deployment.php backup --db-path=... --backup-path=...` em destino privado fora do checkout. O comando recusa WAL não vazio e sobrescrita.
6. Ensaiar migrations em uma **cópia** do backup antes de migrar o SQLite de uso. `database-status` verifica schema, checksums, integridade e foreign keys.
7. Publique somente `public/`; teste login STAFF, cookie, CSRF, leitura e escrita com gates controlados.
8. Valide um ciclo em ambiente de teste e configure um único Cron para `bin/orchestrator.php run`.

## Cron e limites

O orquestrador obtém lock local não bloqueante, agenda no máximo 10 recorrências e processa até 10 executions por padrão. `check` é somente leitura. `run` requer banco existente e não aplica migrations automaticamente. Saídas: `0` sucesso, `1` falha operacional, `2` configuração/uso, `3` lock ocupado. Consulte `--help` para opções de limite.

```cron
*/5 * * * * cd /opt/hesk-recurring-tickets && /usr/bin/php bin/orchestrator.php run --hesk-path=/var/www/hesk >> /opt/hesk-recurring-tickets/storage/logs/orchestrator.log 2>&1
```

Configure rotação e retenção de logs fora da aplicação. Não rode workers separados e o orquestrador sobre a mesma execution sem entender o protocolo de lease. Teste restauração do SQLite em ambiente isolado; não substitua o banco ativo às cegas.

## Preflight e migrations em detalhe

`check` classifica verificações como `OK`, `AVISO` ou `BLOQUEIO` sem carregar o bootstrap HESK. Ele confere PHP/extensões, estrutura privada e pública, HESK, permissões, SQLite, WAL, integridade e migrations. Um `AVISO` pode representar uma etapa ainda não concluída; um `BLOQUEIO` exige correção antes de publicar ou iniciar o Cron. Parâmetros adicionais: `--public-path`, `--base-path` e `--log-path`.

`database-status` faz leitura imutável do banco. O backup usa `SQLite3::backup()`, exige destino absoluto inexistente, recusa WAL não vazio, valida integridade e foreign keys, e deixa o arquivo com modo `0600`. Não use cópia simples de `app.sqlite` com WAL ativo. Guarde o snapshot original; aplique migrations primeiro a uma cópia restaurável e confira `database-status` novamente. Em uma falha parcial, suspenda escrita Web e Cron, preserve logs e estado, e decida restauração a partir do snapshot verificado.

## Publicação Web

O `DocumentRoot` ou link público deve apontar somente a `public/`. Se o painel ocupar `/recurrent/`, ajuste `ADMIN_UI_BASE_PATH=/recurrent/` para links, assets, redirects e cookie. Defina `ADMIN_UI_ENABLED=1` após confirmar a sessão STAFF. Comece com `ADMIN_UI_WRITE_ENABLED=0`; valide GET e depois habilite POST em uma janela controlada. O painel não cria o SQLite nem aplica migrations. HESK e projeto devem compartilhar o contexto necessário de sessão/cookie no mesmo host conforme a implantação local.

## Revisão antes de ligar o Cron

- Execute os 11 testes PHP e lint; confirme as extensões no PHP CLI e Web.
- Confirme que `HESK_SERVER_NAME` corresponde ao host do HESK e que `HESK_ADMIN_URL` leva ao login STAFF esperado.
- Importe uma recorrência fictícia adaptada e mantenha `enabled=false` até validar seus IDs e campos pelo painel.
- Confirme que `orchestrator.php check` não modifica o SQLite e que os limites por ciclo são adequados.
- Faça um teste de criação isolado no HESK de teste. Verifique tracking ID, solicitante, categoria, responsável, campos e ausência de notificação ao cliente quando configurada assim.
- Garanta que a conta do Cron tenha acesso ao projeto, HESK, SQLite, lock e log, sem conceder escrita pública aos diretórios privados.

## Atualização

Pare o Cron e feche temporariamente o gate Web de escrita. Salve backup verificado, implante arquivos sem expor diretórios privados, ensaie novas migrations em cópia e aplique-as explicitamente. Rode testes/preflight e um ciclo controlado. Reabra escrita Web e Cron somente após conferir estado e logs. Uma mudança de versão HESK exige nova verificação de bootstrap, funções e schema.
