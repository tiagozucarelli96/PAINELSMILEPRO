-- 008_estoque_contagem.sql
-- Módulo de Contagem de Estoque - Fase A

CREATE TABLE IF NOT EXISTS estoque_contagens (
  id SERIAL PRIMARY KEY,
  data_ref DATE NOT NULL,              -- ex.: segunda-feira da semana
  criada_por INT,
  status VARCHAR(20) NOT NULL DEFAULT 'rascunho', -- rascunho|fechada
  observacao TEXT,
  criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS estoque_contagem_itens (
  id SERIAL PRIMARY KEY,
  contagem_id INT NOT NULL REFERENCES estoque_contagens(id) ON DELETE CASCADE,
  insumo_id INT NOT NULL REFERENCES lc_insumos(id),
  unidade_id_digitada INT NOT NULL REFERENCES lc_unidades(id),
  qtd_digitada NUMERIC(14,6) NOT NULL,     -- o que o usuário digitou
  fator_aplicado NUMERIC(14,6) NOT NULL,   -- fator conversão p/ base
  qtd_contada_base NUMERIC(14,6) NOT NULL, -- convertido p/ unidade base do insumo
  observacao TEXT
);

-- opcional para leitura futura (não usar ainda na Fase A)
ALTER TABLE lc_insumos
  ADD COLUMN IF NOT EXISTS ean_code VARCHAR(32);

-- Campos para sistema de alertas (Fase D)
ALTER TABLE lc_insumos
  ADD COLUMN IF NOT EXISTS estoque_atual NUMERIC(14,6) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS estoque_minimo NUMERIC(14,6) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS embalagem_multiplo NUMERIC(14,6) DEFAULT 1;

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_estoque_contagens_data_ref ON estoque_contagens(data_ref);
CREATE INDEX IF NOT EXISTS idx_estoque_contagens_status ON estoque_contagens(status);
CREATE INDEX IF NOT EXISTS idx_estoque_contagem_itens_contagem_id ON estoque_contagem_itens(contagem_id);
CREATE INDEX IF NOT EXISTS idx_estoque_contagem_itens_insumo_id ON estoque_contagem_itens(insumo_id);
