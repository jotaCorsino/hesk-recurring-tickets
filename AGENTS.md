# AGENTS.md

## Objetivo

Manter uma automação externa de tickets recorrentes para HESK OSS 3.7.12 e PHP 8.2, sem alterar o core do HESK ou inserir tickets diretamente por SQL.

## Antes de alterar

Leia `README.md`, `docs/01-LEVANTAMENTO-HESK.md`, `docs/02-ARQUITETURA.md`, `docs/03-ROADMAP.md`, `docs/04-DECISOES-TECNICAS.md` e a documentação da área afetada. Inspecione o estado Git e os testes relevantes.

## Implementação

- Preserve funções nativas do HESK, configuração externa e compatibilidade com PHP 8.2.
- Valide IDs e opções contra os catálogos locais antes de criar ou ativar recorrências.
- Mantenha lease, idempotência, reconciliação e logs úteis.
- Nunca versionar `hesk_settings.inc.php`, bancos reais, backups, credenciais, cookies, tokens ou dados pessoais.
- Os JSONs em `config/examples/` são exemplos fictícios; não suponha que seus IDs existam em uma instalação.
- Teste antes de concluir e atualize a documentação afetada.
- Em alterações visuais, avalie a interface no navegador e apresente ao usuário para revisão antes de integrar novos fluxos definitivos.

## Git

Use uma branch por mudança, commits pequenos e PR quando houver remoto configurado e autorização para publicação. Uma cópia local sem remoto não deve ser publicada automaticamente.
