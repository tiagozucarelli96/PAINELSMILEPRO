-- 009_kardex_movimentos.sql
-- Estrutura completa para o módulo de Kardex e movimentos de estoque

-- 1. Tabela principal de movimentos de estoque
CREATE TABLE IF NOT EXISTS lc_movimentos_estoque (
    id SERIAL PRIMARY KEY,
    insumo_id INT NOT NULL REFERENCES lc_insumos(id) ON DELETE CASCADE,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('entrada', 'consumo_evento', 'ajuste', 'perda', 'devolucao')),
    quantidade_base NUMERIC(14,6) NOT NULL, -- quantidade na unidade base do insumo
    unidade_digitada VARCHAR(20), -- unidade que foi digitada pelo usuário
    quantidade_digitada NUMERIC(14,6), -- quantidade digitada pelo usuário
    fator_aplicado NUMERIC(14,6) DEFAULT 1.0, -- fator de conversão aplicado
    data_movimento TIMESTAMP NOT NULL DEFAULT NOW(),
    referencia VARCHAR(200), -- ex: "lista #45, evento #302"
    observacao TEXT,
    custo_unitario NUMERIC(14,2), -- custo unitário na época do movimento
    fornecedor_id INT REFERENCES fornecedores(id),
    usuario_id INT NOT NULL, -- quem fez o movimento
    usuario_nome VARCHAR(100) NOT NULL,
    lista_id INT REFERENCES lc_listas(id), -- referência à lista se aplicável
    evento_id INT, -- referência ao evento se aplicável
    contagem_id INT REFERENCES estoque_contagens(id), -- referência à contagem se aplicável
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    modificado_em TIMESTAMP,
    modificado_por INT,
    ativo BOOLEAN NOT NULL DEFAULT true
);

-- 2. Índices para performance
CREATE INDEX IF NOT EXISTS idx_movimentos_insumo_id ON lc_movimentos_estoque(insumo_id);
CREATE INDEX IF NOT EXISTS idx_movimentos_data ON lc_movimentos_estoque(data_movimento);
CREATE INDEX IF NOT EXISTS idx_movimentos_tipo ON lc_movimentos_estoque(tipo);
CREATE INDEX IF NOT EXISTS idx_movimentos_usuario_id ON lc_movimentos_estoque(usuario_id);
CREATE INDEX IF NOT EXISTS idx_movimentos_lista_id ON lc_movimentos_estoque(lista_id);
CREATE INDEX IF NOT EXISTS idx_movimentos_evento_id ON lc_movimentos_estoque(evento_id);
CREATE INDEX IF NOT EXISTS idx_movimentos_contagem_id ON lc_movimentos_estoque(contagem_id);
CREATE INDEX IF NOT EXISTS idx_movimentos_ativo ON lc_movimentos_estoque(ativo);

-- 3. Tabela de baixas por evento
CREATE TABLE IF NOT EXISTS lc_eventos_baixados (
    id SERIAL PRIMARY KEY,
    lista_id INT NOT NULL REFERENCES lc_listas(id) ON DELETE CASCADE,
    evento_id INT NOT NULL,
    evento_nome VARCHAR(200) NOT NULL,
    data_evento DATE NOT NULL,
    data_baixa TIMESTAMP NOT NULL DEFAULT NOW(),
    baixado_por INT NOT NULL,
    baixado_por_nome VARCHAR(100) NOT NULL,
    observacao TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'baixado' CHECK (status IN ('baixado', 'revertido')),
    revertido_em TIMESTAMP,
    revertido_por INT,
    revertido_por_nome VARCHAR(100)
);

-- 4. Índices para baixas por evento
CREATE INDEX IF NOT EXISTS idx_eventos_baixados_lista_id ON lc_eventos_baixados(lista_id);
CREATE INDEX IF NOT EXISTS idx_eventos_baixados_evento_id ON lc_eventos_baixados(evento_id);
CREATE INDEX IF NOT EXISTS idx_eventos_baixados_data_baixa ON lc_eventos_baixados(data_baixa);
CREATE INDEX IF NOT EXISTS idx_eventos_baixados_status ON lc_eventos_baixados(status);

-- 5. Tabela de ajustes manuais
CREATE TABLE IF NOT EXISTS lc_ajustes_estoque (
    id SERIAL PRIMARY KEY,
    insumo_id INT NOT NULL REFERENCES lc_insumos(id) ON DELETE CASCADE,
    tipo_ajuste VARCHAR(20) NOT NULL CHECK (tipo_ajuste IN ('entrada', 'saida')),
    quantidade_base NUMERIC(14,6) NOT NULL,
    unidade_digitada VARCHAR(20) NOT NULL,
    quantidade_digitada NUMERIC(14,6) NOT NULL,
    fator_aplicado NUMERIC(14,6) NOT NULL,
    motivo VARCHAR(100) NOT NULL, -- ex: "Ajuste de inventário", "Perda por validade"
    observacao TEXT,
    custo_unitario NUMERIC(14,2),
    data_ajuste TIMESTAMP NOT NULL DEFAULT NOW(),
    usuario_id INT NOT NULL,
    usuario_nome VARCHAR(100) NOT NULL,
    aprovado_por INT,
    aprovado_em TIMESTAMP,
    ativo BOOLEAN NOT NULL DEFAULT true
);

-- 6. Índices para ajustes
CREATE INDEX IF NOT EXISTS idx_ajustes_insumo_id ON lc_ajustes_estoque(insumo_id);
CREATE INDEX IF NOT EXISTS idx_ajustes_data ON lc_ajustes_estoque(data_ajuste);
CREATE INDEX IF NOT EXISTS idx_ajustes_usuario_id ON lc_ajustes_estoque(usuario_id);
CREATE INDEX IF NOT EXISTS idx_ajustes_ativo ON lc_ajustes_estoque(ativo);

-- 7. Tabela de perdas e devoluções
CREATE TABLE IF NOT EXISTS lc_perdas_devolucoes (
    id SERIAL PRIMARY KEY,
    insumo_id INT NOT NULL REFERENCES lc_insumos(id) ON DELETE CASCADE,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('perda', 'devolucao')),
    quantidade_base NUMERIC(14,6) NOT NULL,
    unidade_digitada VARCHAR(20) NOT NULL,
    quantidade_digitada NUMERIC(14,6) NOT NULL,
    fator_aplicado NUMERIC(14,6) NOT NULL,
    motivo VARCHAR(100) NOT NULL, -- ex: "Vencimento", "Devolução fornecedor"
    observacao TEXT,
    custo_unitario NUMERIC(14,2),
    data_ocorrencia TIMESTAMP NOT NULL DEFAULT NOW(),
    usuario_id INT NOT NULL,
    usuario_nome VARCHAR(100) NOT NULL,
    aprovado_por INT,
    aprovado_em TIMESTAMP,
    ativo BOOLEAN NOT NULL DEFAULT true
);

-- 8. Índices para perdas e devoluções
CREATE INDEX IF NOT EXISTS idx_perdas_insumo_id ON lc_perdas_devolucoes(insumo_id);
CREATE INDEX IF NOT EXISTS idx_perdas_tipo ON lc_perdas_devolucoes(tipo);
CREATE INDEX IF NOT EXISTS idx_perdas_data ON lc_perdas_devolucoes(data_ocorrencia);
CREATE INDEX IF NOT EXISTS idx_perdas_ativo ON lc_perdas_devolucoes(ativo);

-- 9. Tabela de configurações do módulo de estoque
CREATE TABLE IF NOT EXISTS lc_config_estoque (
    id SERIAL PRIMARY KEY,
    chave VARCHAR(50) NOT NULL UNIQUE,
    valor TEXT NOT NULL,
    descricao TEXT,
    tipo VARCHAR(20) DEFAULT 'string' CHECK (tipo IN ('string', 'number', 'boolean', 'json')),
    categoria VARCHAR(50) DEFAULT 'geral',
    modificado_por INT,
    modificado_em TIMESTAMP DEFAULT NOW()
);

-- 10. Inserir configurações padrão
INSERT INTO lc_config_estoque (chave, valor, descricao, tipo, categoria) VALUES
('kardex_periodo_padrao', '30', 'Período padrão para exibição do Kardex (dias)', 'number', 'kardex'),
('kardex_paginacao', '50', 'Número de linhas por página no Kardex', 'number', 'kardex'),
('permitir_ajustes_oper', 'true', 'Permitir que OPER faça ajustes de estoque', 'boolean', 'permissoes'),
('permitir_perdas_oper', 'true', 'Permitir que OPER registre perdas', 'boolean', 'permissoes'),
('exibir_custos_oper', 'false', 'Exibir custos para perfil OPER', 'boolean', 'permissoes'),
('kardex_exportar_csv', 'true', 'Permitir exportação CSV do Kardex', 'boolean', 'kardex'),
('baixa_evento_automatica', 'false', 'Baixa automática ao fechar evento', 'boolean', 'eventos'),
('sugestao_compra_horizonte', '7', 'Horizonte padrão para sugestão de compra (dias)', 'number', 'sugestao'),
('sugestao_compra_lead_time', '2', 'Lead time padrão para sugestão de compra (dias)', 'number', 'sugestao'),
('sugestao_compra_seguranca', '10', 'Percentual de segurança padrão para sugestão de compra (%)', 'number', 'sugestao')
ON CONFLICT (chave) DO NOTHING;

-- 11. Função para calcular saldo atual de um insumo
CREATE OR REPLACE FUNCTION lc_calcular_saldo_insumo(
    p_insumo_id INT,
    p_data_limite TIMESTAMP DEFAULT NOW()
) RETURNS NUMERIC AS $$
DECLARE
    saldo NUMERIC(14,6) := 0;
BEGIN
    SELECT COALESCE(SUM(
        CASE 
            WHEN tipo IN ('entrada', 'devolucao') THEN quantidade_base
            WHEN tipo IN ('consumo_evento', 'ajuste', 'perda') THEN -quantidade_base
            ELSE 0
        END
    ), 0)
    INTO saldo
    FROM lc_movimentos_estoque
    WHERE insumo_id = p_insumo_id 
    AND data_movimento <= p_data_limite
    AND ativo = true;
    
    RETURN saldo;
END;
$$ LANGUAGE plpgsql;

-- 12. Função para calcular saldo em uma data específica
CREATE OR REPLACE FUNCTION lc_calcular_saldo_insumo_data(
    p_insumo_id INT,
    p_data_inicio TIMESTAMP,
    p_data_fim TIMESTAMP
) RETURNS TABLE (
    saldo_inicial NUMERIC,
    entradas NUMERIC,
    saidas NUMERIC,
    saldo_final NUMERIC
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        COALESCE((
            SELECT lc_calcular_saldo_insumo(p_insumo_id, p_data_inicio)
        ), 0) as saldo_inicial,
        COALESCE((
            SELECT SUM(quantidade_base)
            FROM lc_movimentos_estoque
            WHERE insumo_id = p_insumo_id 
            AND data_movimento BETWEEN p_data_inicio AND p_data_fim
            AND tipo IN ('entrada', 'devolucao')
            AND ativo = true
        ), 0) as entradas,
        COALESCE((
            SELECT SUM(quantidade_base)
            FROM lc_movimentos_estoque
            WHERE insumo_id = p_insumo_id 
            AND data_movimento BETWEEN p_data_inicio AND p_data_fim
            AND tipo IN ('consumo_evento', 'ajuste', 'perda')
            AND ativo = true
        ), 0) as saidas,
        COALESCE((
            SELECT lc_calcular_saldo_insumo(p_insumo_id, p_data_fim)
        ), 0) as saldo_final;
END;
$$ LANGUAGE plpgsql;

-- 13. View para Kardex completo
CREATE OR REPLACE VIEW v_kardex_completo AS
SELECT 
    m.id,
    m.insumo_id,
    i.nome as insumo_nome,
    i.unidade_padrao as insumo_unidade,
    m.tipo,
    m.quantidade_base,
    m.unidade_digitada,
    m.quantidade_digitada,
    m.fator_aplicado,
    m.data_movimento,
    m.referencia,
    m.observacao,
    m.custo_unitario,
    f.nome as fornecedor_nome,
    m.usuario_id,
    m.usuario_nome,
    m.lista_id,
    m.evento_id,
    m.contagem_id,
    m.criado_em,
    -- Saldo acumulado até este movimento
    (
        SELECT lc_calcular_saldo_insumo(m.insumo_id, m.data_movimento)
    ) as saldo_acumulado,
    -- Valor do movimento (se tiver custo)
    CASE 
        WHEN m.custo_unitario IS NOT NULL THEN m.quantidade_base * m.custo_unitario
        ELSE NULL
    END as valor_movimento
FROM lc_movimentos_estoque m
JOIN lc_insumos i ON i.id = m.insumo_id
LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
WHERE m.ativo = true
ORDER BY m.insumo_id, m.data_movimento ASC;

-- 14. View para resumo de movimentos por insumo
CREATE OR REPLACE VIEW v_resumo_movimentos_insumo AS
SELECT 
    i.id as insumo_id,
    i.nome as insumo_nome,
    i.unidade_padrao,
    i.preco as preco_atual,
    i.fator_correcao,
    (i.preco * i.fator_correcao) as custo_corrigido_atual,
    COALESCE((
        SELECT lc_calcular_saldo_insumo(i.id)
    ), 0) as saldo_atual,
    COALESCE((
        SELECT SUM(quantidade_base)
        FROM lc_movimentos_estoque
        WHERE insumo_id = i.id 
        AND tipo IN ('entrada', 'devolucao')
        AND ativo = true
    ), 0) as total_entradas,
    COALESCE((
        SELECT SUM(quantidade_base)
        FROM lc_movimentos_estoque
        WHERE insumo_id = i.id 
        AND tipo IN ('consumo_evento', 'ajuste', 'perda')
        AND ativo = true
    ), 0) as total_saidas,
    COALESCE((
        SELECT COUNT(*)
        FROM lc_movimentos_estoque
        WHERE insumo_id = i.id 
        AND ativo = true
    ), 0) as total_movimentos,
    COALESCE((
        SELECT MAX(data_movimento)
        FROM lc_movimentos_estoque
        WHERE insumo_id = i.id 
        AND ativo = true
    ), NULL) as ultimo_movimento
FROM lc_insumos i
WHERE i.ativo = true;

-- 15. Triggers para auditoria
CREATE OR REPLACE FUNCTION lc_auditar_movimento() RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        NEW.modificado_em = NOW();
        NEW.modificado_por = COALESCE(NEW.modificado_por, OLD.usuario_id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER tr_auditar_movimento
    BEFORE UPDATE ON lc_movimentos_estoque
    FOR EACH ROW
    EXECUTE FUNCTION lc_auditar_movimento();

-- 16. Comentários para documentação
COMMENT ON TABLE lc_movimentos_estoque IS 'Registro de todos os movimentos de estoque (entradas, saídas, ajustes, perdas, devoluções)';
COMMENT ON TABLE lc_eventos_baixados IS 'Controle de baixas por evento';
COMMENT ON TABLE lc_ajustes_estoque IS 'Ajustes manuais de estoque';
COMMENT ON TABLE lc_perdas_devolucoes IS 'Registro de perdas e devoluções';
COMMENT ON TABLE lc_config_estoque IS 'Configurações do módulo de estoque';

COMMENT ON FUNCTION lc_calcular_saldo_insumo(INT, TIMESTAMP) IS 'Calcula o saldo atual de um insumo até uma data específica';
COMMENT ON FUNCTION lc_calcular_saldo_insumo_data(INT, TIMESTAMP, TIMESTAMP) IS 'Calcula saldo inicial, entradas, saídas e saldo final em um período';

-- 17. Inserir dados de exemplo (opcional - para testes)
-- INSERT INTO lc_movimentos_estoque (insumo_id, tipo, quantidade_base, unidade_digitada, quantidade_digitada, fator_aplicado, referencia, observacao, usuario_id, usuario_nome)
-- VALUES 
-- (1, 'entrada', 10.0, 'kg', 10.0, 1.0, 'Compra inicial', 'Estoque inicial', 1, 'Administrador'),
-- (2, 'entrada', 5.0, 'L', 5.0, 1.0, 'Compra inicial', 'Estoque inicial', 1, 'Administrador');

-- 18. Verificar se todas as tabelas foram criadas
DO $$
DECLARE
    tabela_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO tabela_count 
    FROM information_schema.tables 
    WHERE table_name IN (
        'lc_movimentos_estoque', 'lc_eventos_baixados', 'lc_ajustes_estoque', 
        'lc_perdas_devolucoes', 'lc_config_estoque'
    );
    
    RAISE NOTICE 'Tabelas do módulo Kardex criadas: %', tabela_count;
END $$;
