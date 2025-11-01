-- Adicionar colunas para contrato ME Eventos na tabela comercial_inscricoes

-- Verificar e adicionar nome_titular_contrato
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'nome_titular_contrato'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN nome_titular_contrato VARCHAR(255);
    END IF;
END $$;

-- Verificar e adicionar cpf_3_digitos
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'cpf_3_digitos'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN cpf_3_digitos VARCHAR(3);
    END IF;
END $$;

-- Verificar e adicionar me_event_id (se não existir)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'me_event_id'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN me_event_id INT;
    END IF;
END $$;

-- Verificar e adicionar me_cliente_cpf (para armazenar CPF completo validado, criptografado seria ideal mas por enquanto vamos só adicionar)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'me_cliente_cpf'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN me_cliente_cpf VARCHAR(11);
    END IF;
END $$;

