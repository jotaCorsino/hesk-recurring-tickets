# 11 — Painel administrativo

O painel em `public/index.php` oferece lista, criação, edição, ativação e pausa de recorrências, histórico de execuções e estado básico do sistema. A identidade visual pública usa um símbolo próprio e neutro, sem assets de empresa terceira. A revisão visual deve ocorrer em uma instalação de teste antes de habilitar escrita.

## Autenticação e segurança

O painel reutiliza a sessão STAFF do HESK 3.7.12 e `hesk_isLoggedIn()`. Depois da validação, fecha a sessão STAFF e usa uma sessão própria para CSRF e mensagens. Clientes HESK não entram. Qualquer STAFF ativo pode acessar; não há filtro por papel nesta versão.

`ADMIN_UI_ENABLED=1` habilita leitura. `ADMIN_UI_WRITE_ENABLED=1` habilita POST, com CSRF, comparação otimista e banco SQLite preexistente. Ambos são gates operacionais e não substituem autenticação. `HESK_ADMIN_URL` define o link de login. O cookie do painel respeita `ADMIN_UI_BASE_PATH`.

## Catálogos e formulário

O provider lê solicitantes, categorias, STAFF, prioridades, status e campos personalizados do HESK a cada requisição. O formulário filtra controles pela categoria com JS progressivo, mas o servidor revalida todas as referências antes de gravar ou ativar. Pausa não depende de consulta ao catálogo.

O histórico limita a consulta às 50 executions recentes, agrupa itens sem N+1 e mostra códigos de erro seguros, sem mensagem técnica bruta, tokens ou campos de lease. A página Sistema mostra runtime, gates, SQLite e migrations na requisição; Cron, logs e HESK exigem verificação operacional separada.

## Revisão visual

Confira tela de acesso, lista, formulário novo e editado, histórico e Sistema em desktop e mobile. Verifique nomes longos, campos dinâmicos, erros 422, referências indisponíveis e links sob um subdiretório como `/recurrent/`. O branding neutro e os assets SVG precisam de avaliação visual do usuário antes de considerar a apresentação aprovada.
