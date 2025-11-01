-- Adicionar colunas para armazenar adições pendentes de pessoas
-- Os valores só serão aplicados quando o pagamento for confirmado

-- Verificar e adicionar qtd_pessoas_pendente
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'qtd_pessoas_pendente'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN qtd_pessoas_pendente INTEGER DEFAULT NULL;
        COMMENT ON COLUMN comercial_inscricoes.qtd_pessoas_pendente IS 'Quantidade de pessoas pendentes de confirmação de pagamento';
    END IF;
END $$;

-- Verificar e adicionar valor_adicional_pendente
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'valor_adicional_pendente'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN valor_adicional_pendente NUMERIC(10,2) DEFAULT NULL;
        COMMENT ON COLUMN comercial_inscricoes.valor_adicional_pendente IS 'Valor adicional pendente de confirmação de pagamento';
    END IF;
END $$;

-- Verificar e adicionar qr_code_adicional_id
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'qr_code_adicional_id'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN qr_code_adicional_id VARCHAR(255) DEFAULT NULL;
        COMMENT ON COLUMN comercial_inscricoes.qr_code_adicional_id IS 'ID do QR Code PIX gerado para pagamento adicional pendente';
    END IF;
END $$;

-- Verificar e adicionar qr_code_adicional_expira_em
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'qr_code_adicional_expira_em'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN qr_code_adicional_expira_em TIMESTAMP DEFAULT NULL;
        COMMENT ON COLUMN comercial_inscricoes.qr_code_adicional_expira_em IS 'Data/hora de expiração do QR Code adicional. Se expirar sem pagamento, valores pendentes serão cancelados';
    END IF;
END $$;

