-- Índices básicos de performance (Logística V1)
CREATE INDEX IF NOT EXISTS idx_logistica_eventos_data ON logistica_eventos_espelho (data_evento);
CREATE INDEX IF NOT EXISTS idx_logistica_eventos_unidade ON logistica_eventos_espelho (unidade_interna_id);
CREATE INDEX IF NOT EXISTS idx_logistica_eventos_space ON logistica_eventos_espelho (space_visivel);

CREATE INDEX IF NOT EXISTS idx_logistica_lista_eventos_evento ON logistica_lista_eventos (evento_id);
CREATE INDEX IF NOT EXISTS idx_logistica_lista_eventos_lista ON logistica_lista_eventos (lista_id);

CREATE INDEX IF NOT EXISTS idx_logistica_alertas_unidade_data ON logistica_alertas_log (unidade_id, criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_logistica_alertas_tipo_data ON logistica_alertas_log (tipo, criado_em DESC);

CREATE INDEX IF NOT EXISTS idx_logistica_movimentos_origem_data ON logistica_estoque_movimentos (unidade_id_origem, criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_logistica_movimentos_destino_data ON logistica_estoque_movimentos (unidade_id_destino, criado_em DESC);
