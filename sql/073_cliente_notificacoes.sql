-- Central de notificações para clientes

CREATE TABLE IF NOT EXISTS cliente_notificacao_modelos (
    id BIGSERIAL PRIMARY KEY,
    chave VARCHAR(120) NOT NULL UNIQUE,
    nome VARCHAR(180) NOT NULL,
    descricao TEXT NULL,
    gatilho VARCHAR(180) NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    envio_automatico BOOLEAN NOT NULL DEFAULT FALSE,
    canal_email BOOLEAN NOT NULL DEFAULT TRUE,
    assunto VARCHAR(220) NOT NULL,
    mensagem_texto TEXT NOT NULL,
    botao_texto VARCHAR(80) NOT NULL DEFAULT 'Acessar painel',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS cliente_notificacao_envios (
    id BIGSERIAL PRIMARY KEY,
    modelo_id BIGINT NULL REFERENCES cliente_notificacao_modelos(id) ON DELETE SET NULL,
    chave_modelo VARCHAR(120) NOT NULL,
    pre_contrato_id BIGINT NULL,
    me_event_id BIGINT NULL,
    meeting_id BIGINT NULL,
    portal_id BIGINT NULL,
    cliente_nome VARCHAR(255) NULL,
    cliente_email VARCHAR(255) NULL,
    canal VARCHAR(40) NOT NULL DEFAULT 'email',
    assunto VARCHAR(220) NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pendente',
    erro TEXT NULL,
    enviado_em TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cliente_notificacao_envios_pre
    ON cliente_notificacao_envios(pre_contrato_id, chave_modelo, created_at DESC);

INSERT INTO cliente_notificacao_modelos
    (chave, nome, descricao, gatilho, ativo, envio_automatico, canal_email, assunto, mensagem_texto, botao_texto)
VALUES
    (
        'contrato_aprovado',
        'Contrato aprovado',
        'Envia o link da área do cliente quando o contrato é aprovado e criado na ME.',
        'Quando o pré-contrato muda para Aprovado / Criado na ME',
        TRUE,
        TRUE,
        TRUE,
        'Seu painel do evento está disponível',
        'Olá {{nome_cliente}}, tudo bem?

Estou te enviando o link do seu painel do evento. É por lá que vamos centralizar todas as informações importantes, como organização geral, detalhes de decoração, formulários (DJ, lista de convidados, totém, etc.) e outros pontos essenciais.

Acesse aqui: {{link_painel}}

Peço, por favor, que você acesse com calma e preencha todos os formulários solicitados dentro do prazo de 15 dias antes do evento, até {{prazo_formularios}}. Essas informações são super importantes para que tudo saia exatamente como você imaginou no grande dia.

Qualquer dúvida durante o preenchimento, pode falar comigo, estou por aqui para te ajudar!',
        'Acessar painel do evento'
    )
ON CONFLICT (chave) DO NOTHING;
