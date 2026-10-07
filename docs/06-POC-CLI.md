# 06 — POC CLI

`bin/poc-create-ticket.php` valida a integração e pode criar **um** ticket por execução. Ele requer uma definição JSON explícita via `POC_DEFINITION_PATH`; não há IDs, usuários ou empresa de produção embutidos no runtime.

```sh
export HESK_PATH=/var/www/hesk
export HESK_SERVER_NAME=helpdesk.example.com
export POC_DEFINITION_PATH=/opt/hesk-recurring-tickets/config/examples/poc-ticket.json
php bin/poc-create-ticket.php --check
```

Revise o JSON para corresponder a uma instalação de teste. `--check` apenas valida; `--execute` cria o ticket e deve ser usado somente após conferir solicitante, categoria, STAFF, campos e notificações. O exemplo público contém IDs fictícios e pode falhar corretamente na validação local.

A CLI usa o bootstrap HESK e `HeskTicketCreator`, enquanto os testes `php tests/run.php` exercitam a lógica com fakes.
