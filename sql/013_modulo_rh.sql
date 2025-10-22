-- 013_modulo_rh.sql
-- Módulo RH - Holerites e Dossiê do Colaborador

-- 1. Adicionar campos extras na tabela usuarios (sem quebrar login)
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS cpf VARCHAR(14),
ADD COLUMN IF NOT EXISTS cargo VARCHAR(100),
ADD COLUMN IF NOT EXISTS admissao_data DATE,
ADD COLUMN IF NOT EXISTS salario_base NUMERIC(10,2),
ADD COLUMN IF NOT EXISTS pix_tipo VARCHAR(20),
ADD COLUMN IF NOT EXISTS pix_chave VARCHAR(255),
ADD COLUMN IF NOT EXISTS status_empregado VARCHAR(20) DEFAULT 'ativo' CHECK (status_empregado IN ('ativo', 'inativo'));

-- 2. Tabela de Holerites
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

-- 3. Tabela de anexos RH (reutilizando engine existente)
CREATE TABLE IF NOT EXISTS rh_anexos (
    id SERIAL PRIMARY KEY,
    holerite_id INT REFERENCES rh_holerites(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE, -- Para anexos gerais do colaborador
    tipo_anexo VARCHAR(50) NOT NULL, -- 'holerite', 'rg', 'cpf', 'aso', 'contrato'
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tipo_mime VARCHAR(100) NOT NULL,
    tamanho_bytes BIGINT NOT NULL,
    autor_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 4. Índices para performance
CREATE INDEX IF NOT EXISTS idx_rh_holerites_usuario ON rh_holerites(usuario_id);
CREATE INDEX IF NOT EXISTS idx_rh_holerites_competencia ON rh_holerites(mes_competencia);
CREATE INDEX IF NOT EXISTS idx_rh_anexos_holerite ON rh_anexos(holerite_id);
CREATE INDEX IF NOT EXISTS idx_rh_anexos_usuario ON rh_anexos(usuario_id);
CREATE INDEX IF NOT EXISTS idx_rh_anexos_tipo ON rh_anexos(tipo_anexo);

-- 5. Função para obter estatísticas do RH
CREATE OR REPLACE FUNCTION rh_estatisticas_dashboard()
RETURNS TABLE (
    total_colaboradores BIGINT,
    colaboradores_ativos BIGINT,
    holerites_mes_atual BIGINT,
    documentos_vencendo BIGINT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        (SELECT COUNT(*) FROM usuarios WHERE ativo = true) as total_colaboradores,
        (SELECT COUNT(*) FROM usuarios WHERE ativo = true AND status_empregado = 'ativo') as colaboradores_ativos,
        (SELECT COUNT(*) FROM rh_holerites WHERE mes_competencia = TO_CHAR(NOW(), 'YYYY-MM')) as holerites_mes_atual,
        (SELECT COUNT(*) FROM rh_anexos WHERE criado_em >= NOW() - INTERVAL '30 days') as documentos_vencendo;
END;
$$ LANGUAGE plpgsql;

-- 6. Função para buscar holerites do usuário
CREATE OR REPLACE FUNCTION rh_buscar_holerites_usuario(p_usuario_id INT)
RETURNS TABLE (
    id INT,
    mes_competencia VARCHAR(7),
    valor_liquido NUMERIC(10,2),
    observacao TEXT,
    criado_em TIMESTAMP,
    total_anexos BIGINT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        h.id,
        h.mes_competencia,
        h.valor_liquido,
        h.observacao,
        h.criado_em,
        COUNT(a.id) as total_anexos
    FROM rh_holerites h
    LEFT JOIN rh_anexos a ON a.holerite_id = h.id
    WHERE h.usuario_id = p_usuario_id
    GROUP BY h.id, h.mes_competencia, h.valor_liquido, h.observacao, h.criado_em
    ORDER BY h.mes_competencia DESC;
END;
$$ LANGUAGE plpgsql;

-- 7. Trigger para atualizar updated_at
CREATE OR REPLACE FUNCTION rh_update_holerite_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.atualizado_em = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_rh_update_holerite_updated_at ON rh_holerites;
CREATE TRIGGER trg_rh_update_holerite_updated_at
BEFORE UPDATE ON rh_holerites
FOR EACH ROW
EXECUTE FUNCTION rh_update_holerite_updated_at();

-- 8. Comentários para documentação
COMMENT ON TABLE rh_holerites IS 'Holerites dos colaboradores';
COMMENT ON TABLE rh_anexos IS 'Anexos dos holerites e documentos RH';
COMMENT ON FUNCTION rh_estatisticas_dashboard() IS 'Estatísticas do dashboard RH';
COMMENT ON FUNCTION rh_buscar_holerites_usuario(INT) IS 'Buscar holerites de um usuário específico';

-- 9. Verificar se as tabelas foram criadas
DO $$
DECLARE
    tabela_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO tabela_count 
    FROM information_schema.tables 
    WHERE table_name IN ('rh_holerites', 'rh_anexos');
    
    RAISE NOTICE 'Tabelas do módulo RH criadas: %', tabela_count;
END $$;
