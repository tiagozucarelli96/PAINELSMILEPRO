-- Script para criar tabelas de receitas e ficha técnica
-- Execute este script no seu banco PostgreSQL

-- 1. Tabela de Receitas
CREATE TABLE IF NOT EXISTS smilee12_painel_smile.lc_receitas (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    descricao TEXT,
    rendimento INTEGER NOT NULL DEFAULT 1, -- Quantas porções rende
    quantia_por_pessoa DECIMAL(8,3) NOT NULL DEFAULT 1.000, -- Quantidade usada por pessoa
    categoria_id INTEGER REFERENCES smilee12_painel_smile.lc_categorias(id) ON DELETE SET NULL,
    custo_total DECIMAL(12,4) DEFAULT 0.0000, -- Custo total calculado
    ativo BOOLEAN DEFAULT true,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tabela de Componentes da Receita (Ficha Técnica)
CREATE TABLE IF NOT EXISTS smilee12_painel_smile.lc_receita_componentes (
    id SERIAL PRIMARY KEY,
    receita_id INTEGER NOT NULL REFERENCES smilee12_painel_smile.lc_receitas(id) ON DELETE CASCADE,
    insumo_id INTEGER NOT NULL REFERENCES smilee12_painel_smile.lc_insumos(id) ON DELETE CASCADE,
    quantidade DECIMAL(10,4) NOT NULL, -- Quantidade do insumo na receita
    unidade_id INTEGER REFERENCES smilee12_painel_smile.lc_unidades(id) ON DELETE SET NULL,
    custo_unitario DECIMAL(12,4) DEFAULT 0.0000, -- Custo unitário do insumo
    custo_total DECIMAL(12,4) DEFAULT 0.0000, -- Custo total deste componente
    observacoes TEXT,
    ordem INTEGER DEFAULT 0, -- Ordem de exibição
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Índices para performance
CREATE INDEX IF NOT EXISTS idx_lc_receitas_categoria ON smilee12_painel_smile.lc_receitas(categoria_id);
CREATE INDEX IF NOT EXISTS idx_lc_receitas_ativo ON smilee12_painel_smile.lc_receitas(ativo);
CREATE INDEX IF NOT EXISTS idx_lc_receita_componentes_receita ON smilee12_painel_smile.lc_receita_componentes(receita_id);
CREATE INDEX IF NOT EXISTS idx_lc_receita_componentes_insumo ON smilee12_painel_smile.lc_receita_componentes(insumo_id);

-- 4. Função para atualizar custo total da receita
CREATE OR REPLACE FUNCTION smilee12_painel_smile.atualizar_custo_receita(p_receita_id INTEGER)
RETURNS DECIMAL(12,4) AS $$
DECLARE
    custo_total DECIMAL(12,4) := 0;
BEGIN
    -- Calcular custo total dos componentes
    SELECT COALESCE(SUM(custo_total), 0)
    INTO custo_total
    FROM smilee12_painel_smile.lc_receita_componentes
    WHERE receita_id = p_receita_id;
    
    -- Atualizar custo total na receita
    UPDATE smilee12_painel_smile.lc_receitas
    SET custo_total = custo_total,
        atualizado_em = CURRENT_TIMESTAMP
    WHERE id = p_receita_id;
    
    RETURN custo_total;
END;
$$ LANGUAGE plpgsql;

-- 5. Trigger para atualizar custo total automaticamente
CREATE OR REPLACE FUNCTION smilee12_painel_smile.trigger_atualizar_custo_receita()
RETURNS TRIGGER AS $$
BEGIN
    PERFORM smilee12_painel_smile.atualizar_custo_receita(
        COALESCE(NEW.receita_id, OLD.receita_id)
    );
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

-- Criar triggers
DROP TRIGGER IF EXISTS trg_atualizar_custo_receita_insert ON smilee12_painel_smile.lc_receita_componentes;
CREATE TRIGGER trg_atualizar_custo_receita_insert
    AFTER INSERT ON smilee12_painel_smile.lc_receita_componentes
    FOR EACH ROW EXECUTE FUNCTION smilee12_painel_smile.trigger_atualizar_custo_receita();

DROP TRIGGER IF EXISTS trg_atualizar_custo_receita_update ON smilee12_painel_smile.lc_receita_componentes;
CREATE TRIGGER trg_atualizar_custo_receita_update
    AFTER UPDATE ON smilee12_painel_smile.lc_receita_componentes
    FOR EACH ROW EXECUTE FUNCTION smilee12_painel_smile.trigger_atualizar_custo_receita();

DROP TRIGGER IF EXISTS trg_atualizar_custo_receita_delete ON smilee12_painel_smile.lc_receita_componentes;
CREATE TRIGGER trg_atualizar_custo_receita_delete
    AFTER DELETE ON smilee12_painel_smile.lc_receita_componentes
    FOR EACH ROW EXECUTE FUNCTION smilee12_painel_smile.trigger_atualizar_custo_receita();

-- 6. Dados de exemplo (opcional)
INSERT INTO smilee12_painel_smile.lc_receitas (nome, descricao, rendimento, quantia_por_pessoa, categoria_id) VALUES
('Pão de Açúcar', 'Pão tradicional doce', 20, 0.5, 1),
('Brigadeiro', 'Doce de chocolate', 50, 0.1, 1),
('Café Expresso', 'Café forte e concentrado', 1, 1.0, 2)
ON CONFLICT DO NOTHING;

-- 7. Verificação final
SELECT 
    'Tabelas criadas com sucesso!' as status,
    COUNT(*) as total_tabelas
FROM information_schema.tables 
WHERE table_schema = 'smilee12_painel_smile' 
AND table_name IN ('lc_receitas', 'lc_receita_componentes');
