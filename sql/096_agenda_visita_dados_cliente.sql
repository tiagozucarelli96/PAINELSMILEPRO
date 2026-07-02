-- 096_agenda_visita_dados_cliente.sql
-- Dados estruturados das novas visitas para uso operacional futuro.

ALTER TABLE agenda_eventos
    ADD COLUMN IF NOT EXISTS visita_tipo VARCHAR(50),
    ADD COLUMN IF NOT EXISTS cliente_nome VARCHAR(255),
    ADD COLUMN IF NOT EXISTS cliente_telefone VARCHAR(50),
    ADD COLUMN IF NOT EXISTS visita_duracao_minutos INT;
