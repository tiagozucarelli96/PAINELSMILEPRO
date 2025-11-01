-- Adicionar colunas de pagamento Asaas à tabela comercial_inscricoes se não existirem

-- Verificar e adicionar asaas_payment_id
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'asaas_payment_id'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN asaas_payment_id VARCHAR(255);
    END IF;
END $$;

-- Verificar e adicionar valor_pago se não existir
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'valor_pago'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN valor_pago NUMERIC(10,2);
    END IF;
END $$;

-- Verificar e adicionar compareceu se não existir
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'compareceu'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN compareceu BOOLEAN DEFAULT FALSE;
    END IF;
END $$;

