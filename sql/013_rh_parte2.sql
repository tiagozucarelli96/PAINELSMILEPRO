-- 013_rh_parte2.sql
-- PARTE 2: Criar tabelas do RH

-- Tabela de Holerites
CREATE TABLE IF NOT EXISTS rh_holerites (
    id SERIAL PRIMARY KEY,
    usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    mes_competencia VARCHAR(7) NOT NULL, -- YYYY-MM
    valor_liquido NUMERIC(10,2),
    observacao TEXT,
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(usuario_id, mes_competencia)
);

-- Tabela de anexos RH
CREATE TABLE IF NOT EXISTS rh_anexos (
    id SERIAL PRIMARY KEY,
    holerite_id INT REFERENCES rh_holerites(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo_anexo VARCHAR(50) NOT NULL,
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tipo_mime VARCHAR(100) NOT NULL,
    tamanho_bytes BIGINT NOT NULL,
    autor_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);
