-- Migração: Tabelas de Contabilidade
-- Data: 2025-10-25 00:36:35


        CREATE TABLE IF NOT EXISTS contab_documentos (
            id SERIAL PRIMARY KEY,
            numero VARCHAR(50) NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            data_vencimento DATE,
            data_pagamento DATE,
            status VARCHAR(20) DEFAULT 'pendente',
            observacoes TEXT,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS contab_parcelas (
            id SERIAL PRIMARY KEY,
            documento_id INTEGER REFERENCES contab_documentos(id),
            numero_parcela INTEGER NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            data_vencimento DATE,
            data_pagamento DATE,
            status VARCHAR(20) DEFAULT 'pendente',
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS contab_anexos (
            id SERIAL PRIMARY KEY,
            documento_id INTEGER REFERENCES contab_documentos(id),
            nome_arquivo VARCHAR(255) NOT NULL,
            caminho_arquivo VARCHAR(500) NOT NULL,
            tamanho_bytes BIGINT,
            tipo_mime VARCHAR(100),
            autor_id INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS contab_tokens (
            id SERIAL PRIMARY KEY,
            token VARCHAR(100) UNIQUE NOT NULL,
            descricao VARCHAR(200),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

-- Índices
CREATE INDEX IF NOT EXISTS idx_contab_documentos_numero ON contab_documentos(numero);
CREATE INDEX IF NOT EXISTS idx_contab_documentos_status ON contab_documentos(status);
CREATE INDEX IF NOT EXISTS idx_contab_parcelas_documento_id ON contab_parcelas(documento_id);
CREATE INDEX IF NOT EXISTS idx_contab_anexos_documento_id ON contab_anexos(documento_id);
