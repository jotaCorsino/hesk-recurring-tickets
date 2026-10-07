# 12 — Erros operacionais

A migration 004 adiciona códigos de erro estáveis a executions e itens. O erro técnico permanece para diagnóstico em logs e no SQLite privado; o painel mostra somente o texto seguro produzido por `OperationalErrorCatalog`. Códigos ausentes ou desconhecidos recebem uma mensagem genérica.

O código é produzido no ponto que conhece a falha, como configuração inválida, catálogo HESK indisponível, perda de lease, lookup ou criação. O histórico Web não projeta `error_message` técnico nem tokens. `php tests/errors.php` valida classificação e fallback sem acessar uma instalação real.
