-- 045_vendas_me_tipo_evento_map.sql
-- Mapeamento: tipo de evento do Painel -> ID do tipo na ME Eventos (busca na API e configura aqui)

CREATE TABLE IF NOT EXISTS vendas_me_tipo_evento_map (
    tipo_painel VARCHAR(32) PRIMARY KEY,
    me_tipo_evento_id INT NOT NULL,
    me_tipo_nome VARCHAR(120),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE vendas_me_tipo_evento_map IS 'Mapeamento tipo interno (casamento, 15anos, infantil, pj) para ID do tipo na API ME Eventos';
