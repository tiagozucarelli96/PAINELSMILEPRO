-- SQL idempotente para sistema de demandas
-- Aplicar no PostgreSQL do Railway

-- Demandas
CREATE TABLE IF NOT EXISTS demandas (
  id SERIAL PRIMARY KEY,
  descricao TEXT NOT NULL,
  prazo DATE NOT NULL,
  responsavel_id INTEGER NOT NULL,
  criador_id INTEGER NOT NULL,
  whatsapp VARCHAR(32),
  status TEXT NOT NULL DEFAULT 'pendente',
  data_criacao TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  data_conclusao TIMESTAMPTZ
);

-- Índices úteis
CREATE INDEX IF NOT EXISTS idx_demandas_responsavel_status ON demandas (responsavel_id, status);
CREATE INDEX IF NOT EXISTS idx_demandas_prazo ON demandas (prazo);

-- Comentários (chat da demanda)
CREATE TABLE IF NOT EXISTS demandas_comentarios (
  id SERIAL PRIMARY KEY,
  demanda_id INTEGER NOT NULL REFERENCES demandas(id) ON DELETE CASCADE,
  autor_id INTEGER NOT NULL,
  mensagem TEXT NOT NULL,
  data_criacao TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_demandas_comentarios_demanda ON demandas_comentarios (demanda_id, data_criacao);

-- Anexos (metadados)
CREATE TABLE IF NOT EXISTS demandas_arquivos (
  id SERIAL PRIMARY KEY,
  demanda_id INTEGER NOT NULL REFERENCES demandas(id) ON DELETE CASCADE,
  nome_original TEXT NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  tamanho_bytes BIGINT NOT NULL,
  chave_storage TEXT NOT NULL,
  criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_demandas_arquivos_demanda ON demandas_arquivos (demanda_id, criado_em);

-- Modelos de demandas fixas (semana)
CREATE TABLE IF NOT EXISTS demandas_modelos (
  id SERIAL PRIMARY KEY,
  titulo VARCHAR(140) NOT NULL,
  descricao_padrao TEXT NOT NULL,
  responsavel_id INTEGER NOT NULL,
  dia_semana INT NOT NULL,
  prazo_offset_dias INT NOT NULL,
  hora_geracao TIME NOT NULL DEFAULT '09:00',
  ativo BOOLEAN NOT NULL DEFAULT TRUE
);

-- Log de geração (evita duplicidade/dia)
CREATE TABLE IF NOT EXISTS demandas_modelos_log (
  id SERIAL PRIMARY KEY,
  modelo_id INTEGER NOT NULL REFERENCES demandas_modelos(id) ON DELETE CASCADE,
  gerado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  demanda_id INTEGER REFERENCES demandas(id)
);
CREATE INDEX IF NOT EXISTS idx_demandas_modelos_log_modelo_data ON demandas_modelos_log (modelo_id, gerado_em);

-- Reforços (caso já exista tabela com diferenças)
ALTER TABLE demandas ADD COLUMN IF NOT EXISTS whatsapp VARCHAR(32);
ALTER TABLE demandas ADD COLUMN IF NOT EXISTS data_conclusao TIMESTAMPTZ;
ALTER TABLE demandas ALTER COLUMN status SET DEFAULT 'pendente';
