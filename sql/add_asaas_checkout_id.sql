-- Adicionar coluna asaas_checkout_id à tabela comercial_inscricoes se não existir

DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'asaas_checkout_id'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN asaas_checkout_id VARCHAR(255);
        COMMENT ON COLUMN comercial_inscricoes.asaas_checkout_id IS 'ID do Checkout Asaas criado para esta inscrição';
    END IF;
END $$;

