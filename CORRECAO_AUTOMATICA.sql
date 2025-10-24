-- SCRIPT DE CORREÇÃO AUTOMÁTICA
-- Gerado em: 2025-10-24 23:47:34

-- Adicionar colunas de permissões faltantes
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_agenda_ver BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_agenda_meus BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_agenda_relatorios BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_agenda_editar BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_agenda_criar BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_agenda_excluir BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_demandas_ver BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_demandas_editar BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_demandas_criar BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_demandas_excluir BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_demandas_ver_produtividade BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_comercial_ver BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_comercial_deg_editar BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_comercial_deg_inscritos BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_comercial_conversao BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_gerir_eventos_outros BOOLEAN DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_forcar_conflito BOOLEAN DEFAULT false;

-- Criar tabelas faltantes
-- TODO: Criar tabela information_schema
-- TODO: Criar tabela smilee12_painel_smile
-- TODO: Criar tabela lc_solicitacoes_pagamento
-- TODO: Criar tabela solicitacoes_pagfor
-- TODO: Criar tabela usuarios
-- TODO: Criar tabela agenda_espacos
-- TODO: Criar tabela agenda_eventos
-- TODO: Criar tabela verificar_conflito_agenda
-- TODO: Criar tabela obter_proximos_eventos
-- TODO: Criar tabela calcular_conversao_visitas
-- TODO: Criar tabela agenda_tokens_ics
-- TODO: Criar tabela comercial_inscricoes
-- TODO: Criar tabela comercial_degustacoes
-- TODO: Criar tabela clickup_tokens
-- TODO: Criar tabela SET
-- TODO: Criar tabela pg_type
-- TODO: Criar tabela pg_enum
-- TODO: Criar tabela pg_namespace
-- TODO: Criar tabela comercial_campos_padrao
-- TODO: Criar tabela comercial_email_config
-- TODO: Criar tabela lc_insumos
-- TODO: Criar tabela lc_arredondamentos
-- TODO: Criar tabela lc_categorias
-- TODO: Criar tabela demandas_preferencias_notificacao
-- TODO: Criar tabela demandas_configuracoes
-- TODO: Criar tabela lc_ficha_componentes
-- TODO: Criar tabela lc_itens
-- TODO: Criar tabela lc_fichas
-- TODO: Criar tabela lc_fornecedores
-- TODO: Criar tabela lc_itens_fixos
-- TODO: Criar tabela if
-- TODO: Criar tabela lc_unidades
-- TODO: Criar tabela fornecedores
-- TODO: Criar tabela lc_freelancers
-- TODO: Criar tabela Name
-- TODO: Criar tabela Email
-- TODO: Criar tabela lc_receitas
-- TODO: Criar tabela lc_receita_componentes
-- TODO: Criar tabela contab_estatisticas_dashboard
-- TODO: Criar tabela contab_documentos
-- TODO: Criar tabela contab_parcelas
-- TODO: Criar tabela contab_anexos
-- TODO: Criar tabela contab_tokens
-- TODO: Criar tabela pg_indexes
-- TODO: Criar tabela pagamentos_solicitacoes
-- TODO: Criar tabela estoque_contagens
-- TODO: Criar tabela rh_holerites
-- TODO: Criar tabela demandas_logs
-- TODO: Criar tabela demandas_cartoes
-- TODO: Criar tabela demandas_participantes
-- TODO: Criar tabela demandas_quadros
-- TODO: Criar tabela demandas_recorrencia
-- TODO: Criar tabela demandas_colunas
-- TODO: Criar tabela demandas_comentarios
-- TODO: Criar tabela demandas_anexos
-- TODO: Criar tabela demandas_notificacoes
-- TODO: Criar tabela lc_anexos_pagamentos
-- TODO: Criar tabela lc_listas
-- TODO: Criar tabela lc_compras_consolidadas
-- TODO: Criar tabela estoque_contagem_itens
-- TODO: Criar tabela lc_listas_eventos
-- TODO: Criar tabela lc_evento_cardapio
-- TODO: Criar tabela lc_calcular_saldo_insumo_data
-- TODO: Criar tabela lc_movimentos_estoque
-- TODO: Criar tabela eventos
-- TODO: Criar tabela obter_eventos_hoje
-- TODO: Criar tabela obter_eventos_semana
-- TODO: Criar tabela OR
-- TODO: Criar tabela lc_timeline_pagamentos
-- TODO: Criar tabela base
-- TODO: Criar tabela lc_estatisticas_anexos_solicitacao
-- TODO: Criar tabela lc_anexos_miniaturas
-- TODO: Criar tabela lc_anexos_logs_download
-- TODO: Criar tabela lc_config
-- TODO: Criar tabela lc_insumos_substitutos
-- TODO: Criar tabela lc_rascunhos
-- TODO: Criar tabela lc_encomendas_itens
-- TODO: Criar tabela lc_encomendas_overrides
-- TODO: Criar tabela lc_geracoes
-- TODO: Criar tabela pagamentos_anexos
-- TODO: Criar tabela rh_anexos
-- TODO: Criar tabela comercial_anexos
-- TODO: Criar tabela estoque_anexos
-- TODO: Criar tabela active
-- TODO: Criar tabela hidden
-- TODO: Criar tabela lc_lista_eventos
-- TODO: Criar tabela INFORMATION_SCHEMA
-- TODO: Criar tabela portao_logs
-- TODO: Criar tabela rh_estatisticas_dashboard
-- TODO: Criar tabela lc_buscar_fornecedores_ativos
-- TODO: Criar tabela lc_buscar_freelancers_ativos
-- TODO: Criar tabela lc_gerar_token_publico
-- TODO: Criar tabela para

-- Criar funções faltantes
-- TODO: Criar função obter_proximos_eventos
-- TODO: Criar função obter_eventos_hoje
-- TODO: Criar função obter_eventos_semana
-- TODO: Criar função verificar_conflito_agenda
-- TODO: Criar função calcular_conversao_visitas
-- TODO: Criar função gerar_token_ics

