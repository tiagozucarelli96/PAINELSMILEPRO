-- Migração: Tabelas de Fornecedores e Freelancers
-- Data: 2025-10-25 00:36:35


        CREATE TABLE IF NOT EXISTS fornecedores (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            cnpj VARCHAR(20),
            ie VARCHAR(20),
            telefone VARCHAR(20),
            email VARCHAR(200),
            contato_responsavel VARCHAR(200),
            categoria VARCHAR(100),
            observacao TEXT,
            pix_tipo VARCHAR(20),
            pix_chave VARCHAR(200),
            token_publico VARCHAR(100) UNIQUE,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_freelancers (
            id SERIAL PRIMARY KEY,
            nome_completo VARCHAR(200) NOT NULL,
            cpf VARCHAR(14) UNIQUE NOT NULL,
            pix_tipo VARCHAR(20),
            pix_chave VARCHAR(200),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

-- Índices
CREATE INDEX IF NOT EXISTS idx_fornecedores_nome ON fornecedores(nome);
CREATE INDEX IF NOT EXISTS idx_fornecedores_cnpj ON fornecedores(cnpj);
CREATE INDEX IF NOT EXISTS idx_lc_freelancers_cpf ON lc_freelancers(cpf);
