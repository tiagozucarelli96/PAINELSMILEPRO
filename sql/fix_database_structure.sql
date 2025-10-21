-- fix_database_structure.sql
-- Correções para a estrutura do banco de dados

-- 1. Adicionar coluna 'status' à tabela lc_listas se não existir
ALTER TABLE lc_listas 
ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'rascunho';

-- 2. Adicionar coluna 'preco' à tabela lc_insumos se não existir
ALTER TABLE lc_insumos 
ADD COLUMN IF NOT EXISTS preco NUMERIC(14,2) DEFAULT 0.00;

-- 3. Adicionar coluna 'criado_por' à tabela lc_insumos_substitutos se não existir
ALTER TABLE lc_insumos_substitutos 
ADD COLUMN IF NOT EXISTS criado_por INT;

-- 4. Criar tabela lc_evento_cardapio se não existir
CREATE TABLE IF NOT EXISTS lc_evento_cardapio (
    id SERIAL PRIMARY KEY,
    evento_id INT NOT NULL,
    ficha_id INT NOT NULL,
    consumo_pessoa_override NUMERIC(14,6),
    ativo BOOLEAN NOT NULL DEFAULT true,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    FOREIGN KEY (evento_id) REFERENCES lc_listas_eventos(id) ON DELETE CASCADE,
    FOREIGN KEY (ficha_id) REFERENCES lc_fichas(id) ON DELETE CASCADE
);

-- 5. Adicionar índices para performance
CREATE INDEX IF NOT EXISTS idx_lc_evento_cardapio_evento_id ON lc_evento_cardapio(evento_id);
CREATE INDEX IF NOT EXISTS idx_lc_evento_cardapio_ficha_id ON lc_evento_cardapio(ficha_id);
CREATE INDEX IF NOT EXISTS idx_lc_evento_cardapio_ativo ON lc_evento_cardapio(ativo);

-- 6. Adicionar coluna 'criado_em' à tabela lc_insumos_substitutos se não existir
ALTER TABLE lc_insumos_substitutos 
ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP DEFAULT NOW();

-- 7. Verificar e corrigir estrutura da tabela lc_insumos
-- Adicionar colunas que podem estar faltando
ALTER TABLE lc_insumos 
ADD COLUMN IF NOT EXISTS ativo BOOLEAN DEFAULT true,
ADD COLUMN IF NOT EXISTS fator_correcao NUMERIC(14,6) DEFAULT 1.0,
ADD COLUMN IF NOT EXISTS unidade_padrao VARCHAR(20) DEFAULT 'un',
ADD COLUMN IF NOT EXISTS fornecedor_id INT,
ADD COLUMN IF NOT EXISTS categoria_id INT;

-- 8. Adicionar foreign keys se não existirem
DO $$
BEGIN
    -- Verificar se a foreign key para fornecedor_id existe
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints 
        WHERE constraint_name = 'fk_lc_insumos_fornecedor_id'
    ) THEN
        ALTER TABLE lc_insumos 
        ADD CONSTRAINT fk_lc_insumos_fornecedor_id 
        FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id);
    END IF;
    
    -- Verificar se a foreign key para categoria_id existe
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints 
        WHERE constraint_name = 'fk_lc_insumos_categoria_id'
    ) THEN
        ALTER TABLE lc_insumos 
        ADD CONSTRAINT fk_lc_insumos_categoria_id 
        FOREIGN KEY (categoria_id) REFERENCES lc_categorias(id);
    END IF;
END $$;

-- 9. Adicionar índices para performance
CREATE INDEX IF NOT EXISTS idx_lc_insumos_ativo ON lc_insumos(ativo);
CREATE INDEX IF NOT EXISTS idx_lc_insumos_fornecedor_id ON lc_insumos(fornecedor_id);
CREATE INDEX IF NOT EXISTS idx_lc_insumos_categoria_id ON lc_insumos(categoria_id);
CREATE INDEX IF NOT EXISTS idx_lc_insumos_preco ON lc_insumos(preco);

-- 10. Verificar se a tabela lc_listas tem todas as colunas necessárias
ALTER TABLE lc_listas 
ADD COLUMN IF NOT EXISTS tipo_lista VARCHAR(20) DEFAULT 'compras',
ADD COLUMN IF NOT EXISTS data_gerada TIMESTAMP DEFAULT NOW(),
ADD COLUMN IF NOT EXISTS espaco_consolidado VARCHAR(100),
ADD COLUMN IF NOT EXISTS eventos_resumo TEXT,
ADD COLUMN IF NOT EXISTS criado_por INT,
ADD COLUMN IF NOT EXISTS criado_por_nome VARCHAR(100),
ADD COLUMN IF NOT EXISTS resumo_eventos TEXT;

-- 11. Adicionar índices para lc_listas
CREATE INDEX IF NOT EXISTS idx_lc_listas_status ON lc_listas(status);
CREATE INDEX IF NOT EXISTS idx_lc_listas_tipo_lista ON lc_listas(tipo_lista);
CREATE INDEX IF NOT EXISTS idx_lc_listas_criado_por ON lc_listas(criado_por);

-- 12. Verificar se a tabela lc_compras_consolidadas existe e tem a estrutura correta
CREATE TABLE IF NOT EXISTS lc_compras_consolidadas (
    id SERIAL PRIMARY KEY,
    lista_id INT NOT NULL,
    insumo_id INT NOT NULL,
    quantidade NUMERIC(14,6) NOT NULL,
    unidade_id INT NOT NULL,
    preco_unitario NUMERIC(14,2) DEFAULT 0.00,
    preco_total NUMERIC(14,2) DEFAULT 0.00,
    observacao TEXT,
    criado_em TIMESTAMP DEFAULT NOW(),
    FOREIGN KEY (lista_id) REFERENCES lc_listas(id) ON DELETE CASCADE,
    FOREIGN KEY (insumo_id) REFERENCES lc_insumos(id) ON DELETE CASCADE,
    FOREIGN KEY (unidade_id) REFERENCES lc_unidades(id)
);

-- 13. Adicionar índices para lc_compras_consolidadas
CREATE INDEX IF NOT EXISTS idx_lc_compras_consolidadas_lista_id ON lc_compras_consolidadas(lista_id);
CREATE INDEX IF NOT EXISTS idx_lc_compras_consolidadas_insumo_id ON lc_compras_consolidadas(insumo_id);

-- 14. Verificar se a tabela lc_listas_eventos existe
CREATE TABLE IF NOT EXISTS lc_listas_eventos (
    id SERIAL PRIMARY KEY,
    lista_id INT NOT NULL,
    evento_id INT NOT NULL,
    evento_nome VARCHAR(200) NOT NULL,
    evento_data DATE NOT NULL,
    evento_convidados INT NOT NULL,
    criado_em TIMESTAMP DEFAULT NOW(),
    FOREIGN KEY (lista_id) REFERENCES lc_listas(id) ON DELETE CASCADE
);

-- 15. Adicionar índices para lc_listas_eventos
CREATE INDEX IF NOT EXISTS idx_lc_listas_eventos_lista_id ON lc_listas_eventos(lista_id);
CREATE INDEX IF NOT EXISTS idx_lc_listas_eventos_evento_id ON lc_listas_eventos(evento_id);

-- 16. Comentários para documentação
COMMENT ON TABLE lc_evento_cardapio IS 'Cardápio dos eventos - relaciona eventos com fichas técnicas';
COMMENT ON TABLE lc_compras_consolidadas IS 'Itens de compra consolidados por lista';
COMMENT ON TABLE lc_listas_eventos IS 'Eventos associados a cada lista de compras';

-- 17. Verificar se todas as tabelas principais existem
DO $$
DECLARE
    tabela_count INTEGER;
BEGIN
    -- Verificar tabelas essenciais
    SELECT COUNT(*) INTO tabela_count 
    FROM information_schema.tables 
    WHERE table_name IN (
        'lc_insumos', 'lc_unidades', 'lc_categorias', 'fornecedores',
        'lc_fichas', 'lc_ficha_componentes', 'lc_listas', 
        'lc_compras_consolidadas', 'lc_listas_eventos', 'lc_evento_cardapio',
        'estoque_contagens', 'estoque_contagem_itens', 'lc_insumos_substitutos'
    );
    
    RAISE NOTICE 'Tabelas principais encontradas: %', tabela_count;
END $$;
