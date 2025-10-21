-- 010_fornecedores_integracao.sql
-- Integração de fornecedores com o sistema

-- 1. Verificar se a tabela fornecedores existe e criar se necessário
CREATE TABLE IF NOT EXISTS fornecedores (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    cnpj VARCHAR(18),
    telefone VARCHAR(20),
    email VARCHAR(100),
    endereco TEXT,
    contato_responsavel VARCHAR(100),
    observacoes TEXT,
    ativo BOOLEAN NOT NULL DEFAULT true,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    modificado_em TIMESTAMP,
    modificado_por INT
);

-- 2. Adicionar coluna fornecedor_id à tabela lc_insumos se não existir
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'lc_insumos' AND column_name = 'fornecedor_id') THEN
        ALTER TABLE lc_insumos ADD COLUMN fornecedor_id INT REFERENCES fornecedores(id);
        RAISE NOTICE 'Coluna fornecedor_id adicionada à tabela lc_insumos.';
    END IF;
END $$;

-- 3. Adicionar coluna fornecedor_id à tabela lc_listas se não existir
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'lc_listas' AND column_name = 'fornecedor_id') THEN
        ALTER TABLE lc_listas ADD COLUMN fornecedor_id INT REFERENCES fornecedores(id);
        RAISE NOTICE 'Coluna fornecedor_id adicionada à tabela lc_listas.';
    END IF;
END $$;

-- 4. Adicionar coluna fornecedor_id à tabela lc_movimentos_estoque se não existir
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'lc_movimentos_estoque' AND column_name = 'fornecedor_id') THEN
        ALTER TABLE lc_movimentos_estoque ADD COLUMN fornecedor_id INT REFERENCES fornecedores(id);
        RAISE NOTICE 'Coluna fornecedor_id adicionada à tabela lc_movimentos_estoque.';
    END IF;
END $$;

-- 5. Criar tabela de encomendas por fornecedor
CREATE TABLE IF NOT EXISTS lc_encomendas_fornecedor (
    id SERIAL PRIMARY KEY,
    lista_id INT NOT NULL REFERENCES lc_listas(id) ON DELETE CASCADE,
    fornecedor_id INT NOT NULL REFERENCES fornecedores(id),
    status VARCHAR(20) NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'enviada', 'confirmada', 'entregue', 'cancelada')),
    data_envio TIMESTAMP,
    data_confirmacao TIMESTAMP,
    data_entrega TIMESTAMP,
    observacoes TEXT,
    valor_total NUMERIC(14,2),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    criado_por INT,
    criado_por_nome VARCHAR(100)
);

-- 6. Índices para performance
CREATE INDEX IF NOT EXISTS idx_fornecedores_ativo ON fornecedores(ativo);
CREATE INDEX IF NOT EXISTS idx_fornecedores_nome ON fornecedores(nome);
CREATE INDEX IF NOT EXISTS idx_insumos_fornecedor ON lc_insumos(fornecedor_id);
CREATE INDEX IF NOT EXISTS idx_listas_fornecedor ON lc_listas(fornecedor_id);
CREATE INDEX IF NOT EXISTS idx_movimentos_fornecedor ON lc_movimentos_estoque(fornecedor_id);
CREATE INDEX IF NOT EXISTS idx_encomendas_fornecedor ON lc_encomendas_fornecedor(fornecedor_id);
CREATE INDEX IF NOT EXISTS idx_encomendas_lista ON lc_encomendas_fornecedor(lista_id);
CREATE INDEX IF NOT EXISTS idx_encomendas_status ON lc_encomendas_fornecedor(status);

-- 7. Inserir fornecedores de exemplo
INSERT INTO fornecedores (nome, cnpj, telefone, email, endereco, contato_responsavel) VALUES
('Fornecedor Padrão', '00.000.000/0001-00', '(11) 99999-9999', 'contato@fornecedor.com', 'Endereço do fornecedor', 'João Silva')
ON CONFLICT (nome) DO NOTHING;

-- 8. Função para buscar fornecedores ativos
CREATE OR REPLACE FUNCTION lc_buscar_fornecedores_ativos() RETURNS TABLE (
    id INT,
    nome VARCHAR(200),
    telefone VARCHAR(20),
    email VARCHAR(100),
    contato_responsavel VARCHAR(100)
) AS $$
BEGIN
    RETURN QUERY
    SELECT f.id, f.nome, f.telefone, f.email, f.contato_responsavel
    FROM fornecedores f
    WHERE f.ativo = true
    ORDER BY f.nome;
END;
$$ LANGUAGE plpgsql;

-- 9. Função para criar encomenda para fornecedor
CREATE OR REPLACE FUNCTION lc_criar_encomenda_fornecedor(
    p_lista_id INT,
    p_fornecedor_id INT,
    p_criado_por INT,
    p_criado_por_nome VARCHAR(100),
    p_observacoes TEXT DEFAULT NULL
) RETURNS INT AS $$
DECLARE
    encomenda_id INT;
BEGIN
    INSERT INTO lc_encomendas_fornecedor 
    (lista_id, fornecedor_id, criado_por, criado_por_nome, observacoes)
    VALUES (p_lista_id, p_fornecedor_id, p_criado_por, p_criado_por_nome, p_observacoes)
    RETURNING id INTO encomenda_id;
    
    RETURN encomenda_id;
END;
$$ LANGUAGE plpgsql;

-- 10. View para fornecedores com estatísticas
CREATE OR REPLACE VIEW v_fornecedores_estatisticas AS
SELECT 
    f.id,
    f.nome,
    f.telefone,
    f.email,
    f.contato_responsavel,
    f.ativo,
    COUNT(DISTINCT i.id) as total_insumos,
    COUNT(DISTINCT l.id) as total_listas,
    COUNT(DISTINCT e.id) as total_encomendas,
    COALESCE(SUM(e.valor_total), 0) as valor_total_encomendas
FROM fornecedores f
LEFT JOIN lc_insumos i ON i.fornecedor_id = f.id AND i.ativo = true
LEFT JOIN lc_listas l ON l.fornecedor_id = f.id
LEFT JOIN lc_encomendas_fornecedor e ON e.fornecedor_id = f.id
GROUP BY f.id, f.nome, f.telefone, f.email, f.contato_responsavel, f.ativo
ORDER BY f.nome;

-- 11. Comentários para documentação
COMMENT ON TABLE fornecedores IS 'Cadastro de fornecedores do sistema';
COMMENT ON TABLE lc_encomendas_fornecedor IS 'Encomendas enviadas para fornecedores';
COMMENT ON FUNCTION lc_buscar_fornecedores_ativos() IS 'Busca fornecedores ativos para uso em formulários';
COMMENT ON FUNCTION lc_criar_encomenda_fornecedor(INT, INT, INT, VARCHAR(100), TEXT) IS 'Cria nova encomenda para fornecedor';

-- 12. Verificar se todas as tabelas foram criadas
DO $$
DECLARE
    tabela_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO tabela_count 
    FROM information_schema.tables 
    WHERE table_name IN ('fornecedores', 'lc_encomendas_fornecedor');
    
    RAISE NOTICE 'Tabelas de fornecedores criadas: %', tabela_count;
END $$;
