# 04 — Decisões técnicas

Estas decisões resumem o contrato atual do código; detalhes estão nos documentos temáticos.

| Área | Decisão | Razão |
|---|---|---|
| Integração | Automação fora do core HESK; criação por `hesk_newTicket()` | Preservar regras e relacionamentos nativos |
| Configuração | IDs, campos e opções são específicos da instalação | Evitar hardcode de cliente, categoria e STAFF |
| Persistência | SQLite separado do banco HESK, migrations com checksum | Isolar agenda e detectar divergência de schema |
| Tempo | UTC persistido, timezone IANA por recorrência, calendário civil | Manter horário local estável inclusive em meses e DST |
| Scheduler | Uma competência por recorrência por ciclo e limite global | Catch-up controlado sem rajadas ilimitadas |
| Segurança | Claim `BEGIN IMMEDIATE`, token e lease com heartbeat | Impedir dois workers de alterar a mesma execution |
| Retry | `failed` e `partial` exigem ação explícita; `succeeded` é terminal | Evitar repetição silenciosa |
| Lote | Item estável por ticket, tracking ID persistido antes da criação | Reconciliação após interrupção |
| HESK | Lookup somente leitura e lock nomeado por tracking ID | Reduzir corrida entre workers participantes |
| Bancos | Sem transação distribuída entre SQLite e HESK | Recuperar por identidade persistente, sem compensação destrutiva |
| Web | Autenticação STAFF, sessão separada, CSRF e gates de escrita | Reusar identidade HESK e limitar mutação |
| Formulário | Catálogos lidos do HESK e validação antes de gravar/ativar | Evitar referências obsoletas |
| Concorrência Web | Edição e estado com comparação otimista | Não sobrescrever mudanças paralelas |
| Histórico | 50 executions, dados técnicos omitidos no HTML | Custo previsível e apresentação segura |
| Deploy | Somente `public/` no webroot, preflight somente leitura | Proteger banco, código, logs e segredos |
| Backup | Snapshot SQLite nativo, destino privado e ensaio em cópia | Evitar backup inconsistente e migração sem recuperação |
| Cron | Um orquestrador com lock local e seleção finita | Ciclos controlados e sem sobreposição |

## Limites

A garantia contra duplicação pressupõe que os workers usem o protocolo de tracking ID, lookup e lock. Integrações externas que escrevam no HESK fora desse protocolo não estão cobertas. A compatibilidade deve ser revista quando a versão do HESK mudar. O painel aceita qualquer STAFF ativo; autorização por papel é trabalho futuro.
