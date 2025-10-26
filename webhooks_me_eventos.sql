-- Criar tabelas para webhooks ME Eventos
-- Arquivo: webhooks_me_eventos.sql

-- Tabela para armazenar eventos recebidos via webhook
CREATE TABLE IF NOT EXISTS me_eventos_webhook (
    id SERIAL PRIMARY KEY,
    evento_id VARCHAR(100) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    data_evento DATE,
    status VARCHAR(50) DEFAULT 'ativo',
    tipo_evento VARCHAR(100),
    cliente_nome VARCHAR(255),
    cliente_email VARCHAR(255),
    valor DECIMAL(10,2),
    webhook_tipo VARCHAR(50) NOT NULL, -- 'evento_criado', 'evento_atualizado', 'evento_excluido'
    webhook_data JSONB,
    recebido_em TIMESTAMP DEFAULT NOW(),
    processado BOOLEAN DEFAULT FALSE,
    UNIQUE(evento_id, webhook_tipo)
);

-- Tabela para estatísticas mensais de eventos
CREATE TABLE IF NOT EXISTS me_eventos_stats (
    id SERIAL PRIMARY KEY,
    mes_ano VARCHAR(7) NOT NULL, -- formato: 2025-01
    eventos_criados INTEGER DEFAULT 0,
    eventos_excluidos INTEGER DEFAULT 0,
    eventos_ativos INTEGER DEFAULT 0,
    valor_total DECIMAL(12,2) DEFAULT 0.00,
    contratos_fechados INTEGER DEFAULT 0,
    leads_total INTEGER DEFAULT 0,
    leads_negociacao INTEGER DEFAULT 0,
    vendas_realizadas INTEGER DEFAULT 0,
    atualizado_em TIMESTAMP DEFAULT NOW(),
    UNIQUE(mes_ano)
);

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_me_eventos_webhook_evento_id ON me_eventos_webhook(evento_id);
CREATE INDEX IF NOT EXISTS idx_me_eventos_webhook_recebido_em ON me_eventos_webhook(recebido_em);
CREATE INDEX IF NOT EXISTS idx_me_eventos_webhook_tipo ON me_eventos_webhook(webhook_tipo);
CREATE INDEX IF NOT EXISTS idx_me_eventos_stats_mes_ano ON me_eventos_stats(mes_ano);

-- Função para atualizar estatísticas mensais
CREATE OR REPLACE FUNCTION atualizar_stats_eventos()
RETURNS TRIGGER AS $$
DECLARE
    mes_atual VARCHAR(7);
BEGIN
    mes_atual := TO_CHAR(NOW(), 'YYYY-MM');
    
    -- Inserir ou atualizar estatísticas do mês atual
    INSERT INTO me_eventos_stats (mes_ano, eventos_criados, eventos_excluidos, eventos_ativos)
    VALUES (mes_atual, 0, 0, 0)
    ON CONFLICT (mes_ano) DO NOTHING;
    
    -- Atualizar contadores baseado no tipo de webhook
    IF NEW.webhook_tipo = 'evento_criado' THEN
        UPDATE me_eventos_stats 
        SET eventos_criados = eventos_criados + 1,
            eventos_ativos = eventos_ativos + 1,
            atualizado_em = NOW()
        WHERE mes_ano = mes_atual;
    ELSIF NEW.webhook_tipo = 'evento_excluido' THEN
        UPDATE me_eventos_stats 
        SET eventos_excluidos = eventos_excluidos + 1,
            eventos_ativos = eventos_ativos - 1,
            atualizado_em = NOW()
        WHERE mes_ano = mes_atual;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger para atualizar estatísticas automaticamente
DROP TRIGGER IF EXISTS trigger_atualizar_stats_eventos ON me_eventos_webhook;
CREATE TRIGGER trigger_atualizar_stats_eventos
    AFTER INSERT ON me_eventos_webhook
    FOR EACH ROW
    EXECUTE FUNCTION atualizar_stats_eventos();

-- Inserir dados iniciais para teste
INSERT INTO me_eventos_stats (mes_ano, eventos_criados, eventos_excluidos, eventos_ativos, contratos_fechados, leads_total, leads_negociacao, vendas_realizadas)
VALUES (TO_CHAR(NOW(), 'YYYY-MM'), 0, 0, 0, 0, 0, 0, 0)
ON CONFLICT (mes_ano) DO NOTHING;
