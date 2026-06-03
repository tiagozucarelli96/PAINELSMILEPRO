-- Modelos de contratos e tags automáticas.

CREATE TABLE IF NOT EXISTS contrato_tags (
    id BIGSERIAL PRIMARY KEY,
    tag_codigo VARCHAR(80) NOT NULL UNIQUE,
    nome VARCHAR(160) NOT NULL,
    origem_tipo VARCHAR(40) NOT NULL,
    origem_campo VARCHAR(120) NOT NULL,
    descricao TEXT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS contrato_modelos (
    id BIGSERIAL PRIMARY KEY,
    nome VARCHAR(180) NOT NULL,
    conteudo_html TEXT NOT NULL DEFAULT '',
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_contrato_modelos_nome
    ON contrato_modelos(LOWER(nome));

CREATE INDEX IF NOT EXISTS idx_contrato_tags_origem
    ON contrato_tags(origem_tipo, origem_campo);

INSERT INTO contrato_tags (tag_codigo, nome, origem_tipo, origem_campo, descricao, ativo, created_at, updated_at)
VALUES
    ('#NOME#', 'Nome do cliente', 'cliente', 'nome_completo', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#CPF#', 'CPF do cliente', 'cliente', 'documento_numero', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#RG#', 'RG do cliente', 'cliente', 'rg', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#EMAIL#', 'E-mail do cliente', 'cliente', 'email', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#TELEFONE#', 'Telefone/WhatsApp do cliente', 'cliente', 'telefone_whatsapp', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#CEP#', 'CEP do cliente', 'cliente', 'cep', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#ENDERECO#', 'Endereço do cliente', 'cliente', 'endereco_logradouro', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#NUMERO#', 'Número do endereço', 'cliente', 'endereco_numero', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#COMPLEMENTO#', 'Complemento do endereço', 'cliente', 'endereco_complemento', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#BAIRRO#', 'Bairro do cliente', 'cliente', 'endereco_bairro', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#CIDADE#', 'Cidade do cliente', 'cliente', 'endereco_cidade', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#ESTADO#', 'Estado do cliente', 'cliente', 'endereco_estado', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#NOME_EVENTO#', 'Nome do evento', 'evento', 'nome_evento', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#DATA_EVENTO#', 'Data do evento', 'evento', 'data_evento', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#HORARIO_EVENTO#', 'Horário do evento', 'evento', 'hora_inicio', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#LOCAL_EVENTO#', 'Local do evento', 'evento', 'local_evento', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#UNIDADE#', 'Unidade do evento', 'evento', 'space_visivel', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#CONVIDADOS#', 'Quantidade de convidados', 'evento', 'convidados', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#PACOTE#', 'Pacote contratado', 'evento', 'pacote', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#ITENS_CONTRATADOS#', 'Itens contratados', 'evento', 'itens_contratados', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#VALOR_TOTAL#', 'Valor total contratado', 'financeiro', 'valor_total', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#VALOR_RECEBIDO#', 'Valor recebido', 'financeiro', 'valor_recebido', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#VALOR_A_RECEBER#', 'Valor a receber', 'financeiro', 'valor_a_receber', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW()),
    ('#DATA_HOJE#', 'Data de hoje', 'sistema', 'data_hoje', 'Tag padrão para modelos de contrato.', TRUE, NOW(), NOW())
ON CONFLICT (tag_codigo) DO NOTHING;
