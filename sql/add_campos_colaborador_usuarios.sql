-- Adicionar campos de dados pessoais completos na tabela usuarios
-- Para fusão entre cadastro de usuários e colaboradores

-- Campos que já podem existir (serão criados apenas se não existirem)
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS cpf VARCHAR(14),
ADD COLUMN IF NOT EXISTS rg VARCHAR(20),
ADD COLUMN IF NOT EXISTS telefone VARCHAR(20),
ADD COLUMN IF NOT EXISTS celular VARCHAR(20),
ADD COLUMN IF NOT EXISTS endereco_cep VARCHAR(9),
ADD COLUMN IF NOT EXISTS endereco_logradouro VARCHAR(255),
ADD COLUMN IF NOT EXISTS endereco_numero VARCHAR(20),
ADD COLUMN IF NOT EXISTS endereco_complemento VARCHAR(100),
ADD COLUMN IF NOT EXISTS endereco_bairro VARCHAR(100),
ADD COLUMN IF NOT EXISTS endereco_cidade VARCHAR(100),
ADD COLUMN IF NOT EXISTS endereco_estado VARCHAR(2),
ADD COLUMN IF NOT EXISTS nome_completo VARCHAR(255);

-- Comentários para documentação
COMMENT ON COLUMN usuarios.cpf IS 'CPF do colaborador/usuário';
COMMENT ON COLUMN usuarios.rg IS 'RG do colaborador/usuário';
COMMENT ON COLUMN usuarios.telefone IS 'Telefone fixo do colaborador/usuário';
COMMENT ON COLUMN usuarios.celular IS 'Celular do colaborador/usuário';
COMMENT ON COLUMN usuarios.endereco_cep IS 'CEP do endereço';
COMMENT ON COLUMN usuarios.endereco_logradouro IS 'Rua/Avenida do endereço';
COMMENT ON COLUMN usuarios.endereco_numero IS 'Número do endereço';
COMMENT ON COLUMN usuarios.endereco_complemento IS 'Complemento do endereço (apto, bloco, etc)';
COMMENT ON COLUMN usuarios.endereco_bairro IS 'Bairro do endereço';
COMMENT ON COLUMN usuarios.endereco_cidade IS 'Cidade do endereço';
COMMENT ON COLUMN usuarios.endereco_estado IS 'Estado (UF) do endereço';
COMMENT ON COLUMN usuarios.nome_completo IS 'Nome completo do colaborador/usuário (pode ser diferente do nome de login)';

-- Criar índices para melhor performance em buscas
CREATE INDEX IF NOT EXISTS idx_usuarios_cpf ON usuarios(cpf) WHERE cpf IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_usuarios_rg ON usuarios(rg) WHERE rg IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_usuarios_cep ON usuarios(endereco_cep) WHERE endereco_cep IS NOT NULL;

