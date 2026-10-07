# 05 — Baseline manual da POC

Antes de testar a criação automatizada, crie manualmente um ticket **fictício** em uma instalação de teste do HESK. Registre solicitante, categoria, técnico, prioridade, status, campos personalizados e comportamento de notificação. Use apenas dados de teste e compare o resultado com o ticket criado pela CLI.

O exemplo público usa `Example requester`, categoria `Preventive Workstation Maintenance` e empresa `Example Company`. Os IDs dependem do HESK local. Não copie IDs de outra instalação e não execute a POC contra produção sem revisar a definição JSON.

A validação técnica importante é que a criação passe por `hesk_newTicket()`, atribua um tracking ID e preserve o relacionamento com o solicitante, sem `INSERT` direto em tickets.
