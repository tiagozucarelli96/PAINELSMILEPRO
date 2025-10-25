-- Migração: Tabelas de Pagamentos
-- Data: 2025-10-25 00:36:35


        CREATE TABLE IF NOT EXISTS lc_solicitacoes_pagamento (
            id SERIAL PRIMARY KEY,
            criador_id INTEGER,
            beneficiario_tipo VARCHAR(20) NOT NULL,
            freelancer_id INTEGER,
            fornecedor_id INTEGER,
            valor DECIMAL(10,2) NOT NULL,
            data_desejada DATE,
            observacoes TEXT,
            status VARCHAR(20) DEFAULT 'aguardando',
            status_atualizado_em TIMESTAMP,
            status_atualizado_por INTEGER,
            origem VARCHAR(50),
            pix_tipo VARCHAR(20),
            pix_chave VARCHAR(200),
            data_pagamento DATE,
            observacao_pagamento TEXT,
            motivo_suspensao TEXT,
            motivo_recusa TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_timeline_pagamentos (
            id SERIAL PRIMARY KEY,
            solicitacao_id INTEGER REFERENCES lc_solicitacoes_pagamento(id),
            autor_id INTEGER,
            acao VARCHAR(50) NOT NULL,
            descricao TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_anexos_pagamentos (
            id SERIAL PRIMARY KEY,
            solicitacao_id INTEGER REFERENCES lc_solicitacoes_pagamento(id),
            nome_arquivo VARCHAR(255) NOT NULL,
            caminho_arquivo VARCHAR(500) NOT NULL,
            tamanho_bytes BIGINT,
            tipo_mime VARCHAR(100),
            eh_comprovante BOOLEAN DEFAULT FALSE,
            autor_id INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

-- Índices
CREATE INDEX IF NOT EXISTS idx_lc_solicitacoes_criador_id ON lc_solicitacoes_pagamento(criador_id);
CREATE INDEX IF NOT EXISTS idx_lc_solicitacoes_status ON lc_solicitacoes_pagamento(status);
CREATE INDEX IF NOT EXISTS idx_lc_timeline_solicitacao_id ON lc_timeline_pagamentos(solicitacao_id);
CREATE INDEX IF NOT EXISTS idx_lc_anexos_solicitacao_id ON lc_anexos_pagamentos(solicitacao_id);
