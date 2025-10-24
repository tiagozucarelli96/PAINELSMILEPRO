-- Schema Completo do Painel Smile PRO
-- Gerado automaticamente em 2025-01-27
-- Este arquivo contém todas as tabelas necessárias para o funcionamento completo do sistema

-- =====================================================
-- TABELAS PRINCIPAIS DO SISTEMA
-- =====================================================

-- Tabela de usuários
CREATE TABLE IF NOT EXISTS usuarios (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    perfil VARCHAR(20) DEFAULT 'OPER' CHECK (perfil IN ('ADM', 'GERENTE', 'OPER', 'CONSULTA')),
    ativo BOOLEAN DEFAULT TRUE,
    telefone VARCHAR(20),
    departamento VARCHAR(100),
    cargo VARCHAR(100),
    avatar VARCHAR(255),
    ultimo_login TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de eventos
CREATE TABLE IF NOT EXISTS eventos (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    data_inicio TIMESTAMP NOT NULL,
    data_fim TIMESTAMP NOT NULL,
    local VARCHAR(255),
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'ativo' CHECK (status IN ('ativo', 'cancelado', 'concluido')),
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de fornecedores
CREATE TABLE IF NOT EXISTS fornecedores (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    razao_social VARCHAR(255),
    cnpj VARCHAR(18) UNIQUE,
    email VARCHAR(255),
    telefone VARCHAR(20),
    endereco TEXT,
    cidade VARCHAR(100),
    estado VARCHAR(2),
    cep VARCHAR(9),
    contato VARCHAR(255),
    observacoes TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de pendências
CREATE TABLE IF NOT EXISTS pendencias (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    prioridade VARCHAR(20) DEFAULT 'media' CHECK (prioridade IN ('baixa', 'media', 'alta', 'urgente')),
    status VARCHAR(20) DEFAULT 'pendente' CHECK (status IN ('pendente', 'em_andamento', 'concluida', 'cancelada')),
    responsavel_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    data_vencimento DATE,
    data_conclusao TIMESTAMP,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de pagamentos
CREATE TABLE IF NOT EXISTS pagamentos (
    id SERIAL PRIMARY KEY,
    fornecedor_id INT REFERENCES fornecedores(id) ON DELETE SET NULL,
    valor DECIMAL(10,2) NOT NULL,
    data_vencimento DATE NOT NULL,
    data_pagamento DATE,
    status VARCHAR(20) DEFAULT 'pendente' CHECK (status IN ('pendente', 'pago', 'vencido', 'cancelado')),
    forma_pagamento VARCHAR(50),
    observacoes TEXT,
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- =====================================================
-- SISTEMA DE ESTOQUE
-- =====================================================

-- Tabela de categorias de insumos
CREATE TABLE IF NOT EXISTS lc_categorias (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de unidades
CREATE TABLE IF NOT EXISTS lc_unidades (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    sigla VARCHAR(10) NOT NULL,
    tipo VARCHAR(20) DEFAULT 'unidade' CHECK (tipo IN ('unidade', 'peso', 'volume', 'comprimento')),
    fator_conversao DECIMAL(10,6) DEFAULT 1,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de insumos
CREATE TABLE IF NOT EXISTS lc_insumos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    categoria_id INT REFERENCES lc_categorias(id) ON DELETE SET NULL,
    unidade_base_id INT REFERENCES lc_unidades(id) ON DELETE SET NULL,
    preco DECIMAL(10,2) DEFAULT 0,
    fator_correcao DECIMAL(10,6) DEFAULT 1,
    estoque_minimo DECIMAL(10,3) DEFAULT 0,
    estoque_atual DECIMAL(10,3) DEFAULT 0,
    fornecedor_id INT REFERENCES fornecedores(id) ON DELETE SET NULL,
    ativo BOOLEAN DEFAULT TRUE,
    ean_code VARCHAR(50),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de fichas técnicas
CREATE TABLE IF NOT EXISTS lc_fichas (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    rendimento DECIMAL(10,3) DEFAULT 1,
    unidade_rendimento VARCHAR(50),
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de componentes das fichas
CREATE TABLE IF NOT EXISTS lc_ficha_componentes (
    id SERIAL PRIMARY KEY,
    ficha_id INT REFERENCES lc_fichas(id) ON DELETE CASCADE,
    insumo_id INT REFERENCES lc_insumos(id) ON DELETE CASCADE,
    quantidade DECIMAL(10,6) NOT NULL,
    unidade_id INT REFERENCES lc_unidades(id) ON DELETE SET NULL,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de listas de compras
CREATE TABLE IF NOT EXISTS lc_listas (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    status VARCHAR(20) DEFAULT 'rascunho' CHECK (status IN ('rascunho', 'ativa', 'finalizada', 'cancelada')),
    data_prevista DATE,
    observacoes TEXT,
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de compras
CREATE TABLE IF NOT EXISTS lc_compras (
    id SERIAL PRIMARY KEY,
    lista_id INT REFERENCES lc_listas(id) ON DELETE CASCADE,
    insumo_id INT REFERENCES lc_insumos(id) ON DELETE CASCADE,
    quantidade DECIMAL(10,3) NOT NULL,
    unidade_id INT REFERENCES lc_unidades(id) ON DELETE SET NULL,
    preco_unitario DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de encomendas
CREATE TABLE IF NOT EXISTS lc_encomendas (
    id SERIAL PRIMARY KEY,
    lista_id INT REFERENCES lc_listas(id) ON DELETE CASCADE,
    fornecedor_id INT REFERENCES fornecedores(id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'pendente' CHECK (status IN ('pendente', 'enviada', 'recebida', 'cancelada')),
    data_prevista DATE,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de itens das encomendas
CREATE TABLE IF NOT EXISTS lc_encomendas_itens (
    id SERIAL PRIMARY KEY,
    encomenda_id INT REFERENCES lc_encomendas(id) ON DELETE CASCADE,
    insumo_id INT REFERENCES lc_insumos(id) ON DELETE CASCADE,
    quantidade DECIMAL(10,3) NOT NULL,
    unidade_id INT REFERENCES lc_unidades(id) ON DELETE SET NULL,
    preco_unitario DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- =====================================================
-- SISTEMA DE DEMANDAS
-- =====================================================

-- Tabela de quadros
CREATE TABLE IF NOT EXISTS demandas_quadros (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    cor VARCHAR(7) DEFAULT '#3b82f6',
    ativo BOOLEAN DEFAULT TRUE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de colunas
CREATE TABLE IF NOT EXISTS demandas_colunas (
    id SERIAL PRIMARY KEY,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    nome VARCHAR(255) NOT NULL,
    posicao INTEGER NOT NULL,
    cor VARCHAR(7) DEFAULT '#6b7280',
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de cartões
CREATE TABLE IF NOT EXISTS demandas_cartoes (
    id SERIAL PRIMARY KEY,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    coluna_id INT REFERENCES demandas_colunas(id) ON DELETE CASCADE,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    posicao INTEGER NOT NULL,
    cor VARCHAR(7) DEFAULT '#ffffff',
    data_vencimento TIMESTAMP,
    responsavel_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criador_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'ativo' CHECK (status IN ('ativo', 'concluido', 'arquivado')),
    prioridade VARCHAR(20) DEFAULT 'media' CHECK (prioridade IN ('baixa', 'media', 'alta', 'urgente')),
    recorrencia VARCHAR(50),
    proxima_data TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de participantes
CREATE TABLE IF NOT EXISTS demandas_participantes (
    id SERIAL PRIMARY KEY,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    permissao VARCHAR(20) DEFAULT 'leitura' CHECK (permissao IN ('leitura', 'escrita', 'admin')),
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(quadro_id, usuario_id)
);

-- Tabela de logs
CREATE TABLE IF NOT EXISTS demandas_logs (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    acao VARCHAR(100) NOT NULL,
    entidade VARCHAR(50) NOT NULL,
    entidade_id INT NOT NULL,
    dados_anteriores JSONB,
    dados_novos JSONB,
    ip_origem INET,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de configurações
CREATE TABLE IF NOT EXISTS demandas_configuracoes (
    id SERIAL PRIMARY KEY,
    chave VARCHAR(100) UNIQUE NOT NULL,
    valor TEXT,
    descricao TEXT,
    tipo VARCHAR(50) DEFAULT 'string' CHECK (tipo IN ('string', 'number', 'boolean', 'json')),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de preferências de notificação
CREATE TABLE IF NOT EXISTS demandas_preferencias_notificacao (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE UNIQUE,
    notificacao_painel BOOLEAN DEFAULT TRUE,
    notificacao_email BOOLEAN DEFAULT TRUE,
    notificacao_whatsapp BOOLEAN DEFAULT FALSE,
    alerta_vencimento INT DEFAULT 24,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- =====================================================
-- SISTEMA DE AGENDA
-- =====================================================

-- Tabela de espaços
CREATE TABLE IF NOT EXISTS agenda_espacos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    descricao TEXT,
    cor VARCHAR(7) DEFAULT '#3b82f6',
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de eventos da agenda
CREATE TABLE IF NOT EXISTS agenda_eventos (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    data_inicio TIMESTAMP NOT NULL,
    data_fim TIMESTAMP NOT NULL,
    espaco_id INT REFERENCES agenda_espacos(id) ON DELETE SET NULL,
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    tipo VARCHAR(50) DEFAULT 'evento' CHECK (tipo IN ('evento', 'visita', 'bloqueio')),
    cor VARCHAR(7),
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de lembretes
CREATE TABLE IF NOT EXISTS agenda_lembretes (
    id SERIAL PRIMARY KEY,
    evento_id INT REFERENCES agenda_eventos(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo VARCHAR(20) DEFAULT 'email' CHECK (tipo IN ('email', 'sms', 'whatsapp')),
    tempo_antecedencia INTEGER DEFAULT 60,
    enviado BOOLEAN DEFAULT FALSE,
    data_envio TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de tokens ICS
CREATE TABLE IF NOT EXISTS agenda_tokens_ics (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    token VARCHAR(255) UNIQUE NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    expira_em TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- =====================================================
-- SISTEMA DE PAGAMENTOS
-- =====================================================

-- Tabela de solicitações de pagamento
CREATE TABLE IF NOT EXISTS pagamentos_solicitacoes (
    id SERIAL PRIMARY KEY,
    criador_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    beneficiario_tipo VARCHAR(20) NOT NULL CHECK (beneficiario_tipo IN ('freelancer', 'fornecedor')),
    freelancer_id INT REFERENCES pagamentos_freelancers(id) ON DELETE SET NULL,
    fornecedor_id INT REFERENCES fornecedores(id) ON DELETE SET NULL,
    valor DECIMAL(10,2) NOT NULL,
    data_desejada DATE,
    observacoes TEXT,
    status VARCHAR(20) DEFAULT 'aguardando' CHECK (status IN ('aguardando', 'aprovado', 'suspenso', 'recusado', 'pago')),
    status_atualizado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    status_atualizado_em TIMESTAMP,
    motivo_suspensao TEXT,
    motivo_recusa TEXT,
    pix_tipo VARCHAR(20),
    pix_chave VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de freelancers
CREATE TABLE IF NOT EXISTS pagamentos_freelancers (
    id SERIAL PRIMARY KEY,
    nome_completo VARCHAR(255) NOT NULL,
    cpf VARCHAR(14) UNIQUE NOT NULL,
    pix_tipo VARCHAR(20) NOT NULL,
    pix_chave VARCHAR(255) NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    observacao TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de timeline de pagamentos
CREATE TABLE IF NOT EXISTS pagamentos_timeline (
    id SERIAL PRIMARY KEY,
    solicitacao_id INT REFERENCES pagamentos_solicitacoes(id) ON DELETE CASCADE,
    autor_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    tipo_evento VARCHAR(50) NOT NULL,
    mensagem TEXT,
    status_de VARCHAR(20),
    status_para VARCHAR(20),
    created_at TIMESTAMP DEFAULT NOW()
);

-- =====================================================
-- SISTEMA COMERCIAL
-- =====================================================

-- Tabela de degustações
CREATE TABLE IF NOT EXISTS comercial_degustacoes (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    data DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fim TIME NOT NULL,
    local VARCHAR(255) NOT NULL,
    capacidade INTEGER NOT NULL,
    data_limite DATE,
    lista_espera BOOLEAN DEFAULT FALSE,
    preco_casamento DECIMAL(10,2) DEFAULT 0,
    incluidos_casamento INTEGER DEFAULT 2,
    preco_15anos DECIMAL(10,2) DEFAULT 0,
    incluidos_15anos INTEGER DEFAULT 3,
    preco_extra DECIMAL(10,2) DEFAULT 0,
    instrutivo_html TEXT,
    email_confirmacao_html TEXT,
    msg_sucesso_html TEXT,
    campos_json JSONB,
    usar_como_padrao BOOLEAN DEFAULT FALSE,
    status VARCHAR(20) DEFAULT 'rascunho' CHECK (status IN ('rascunho', 'publicado', 'encerrado')),
    token_publico VARCHAR(255) UNIQUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de inscrições
CREATE TABLE IF NOT EXISTS comercial_degust_inscricoes (
    id SERIAL PRIMARY KEY,
    event_id INT REFERENCES comercial_degustacoes(id) ON DELETE CASCADE,
    status VARCHAR(20) DEFAULT 'confirmado' CHECK (status IN ('confirmado', 'lista_espera', 'cancelado', 'compareceu')),
    fechou_contrato VARCHAR(10) DEFAULT 'indefinido' CHECK (fechou_contrato IN ('sim', 'nao', 'indefinido')),
    me_event_id INT,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    celular VARCHAR(20),
    dados_json JSONB,
    qtd_pessoas INTEGER DEFAULT 1,
    tipo_festa VARCHAR(20) CHECK (tipo_festa IN ('casamento', '15anos')),
    extras INTEGER DEFAULT 0,
    pagamento_status VARCHAR(20) DEFAULT 'nao_aplicavel' CHECK (pagamento_status IN ('nao_aplicavel', 'aguardando', 'pago', 'expirado', 'cancelado')),
    asaas_payment_id VARCHAR(255),
    ip_origem INET,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de clientes
CREATE TABLE IF NOT EXISTS comercial_clientes (
    id SERIAL PRIMARY KEY,
    inscricao_id INT REFERENCES comercial_degust_inscricoes(id) ON DELETE CASCADE,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telefone VARCHAR(20),
    fechou_contrato BOOLEAN DEFAULT FALSE,
    data_contrato DATE,
    valor_contrato DECIMAL(10,2),
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- =====================================================
-- SISTEMA RH
-- =====================================================

-- Tabela de departamentos
CREATE TABLE IF NOT EXISTS rh_departamentos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de cargos
CREATE TABLE IF NOT EXISTS rh_cargos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    salario_base DECIMAL(10,2) DEFAULT 0,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de funcionários
CREATE TABLE IF NOT EXISTS rh_funcionarios (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    departamento_id INT REFERENCES rh_departamentos(id) ON DELETE SET NULL,
    cargo_id INT REFERENCES rh_cargos(id) ON DELETE SET NULL,
    data_admissao DATE NOT NULL,
    data_demissao DATE,
    salario DECIMAL(10,2) DEFAULT 0,
    ativo BOOLEAN DEFAULT TRUE,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de férias
CREATE TABLE IF NOT EXISTS rh_ferias (
    id SERIAL PRIMARY KEY,
    funcionario_id INT REFERENCES rh_funcionarios(id) ON DELETE CASCADE,
    data_inicio DATE NOT NULL,
    data_fim DATE NOT NULL,
    dias INTEGER NOT NULL,
    status VARCHAR(20) DEFAULT 'solicitado' CHECK (status IN ('solicitado', 'aprovado', 'rejeitado', 'gozado')),
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de benefícios
CREATE TABLE IF NOT EXISTS rh_beneficios (
    id SERIAL PRIMARY KEY,
    funcionario_id INT REFERENCES rh_funcionarios(id) ON DELETE CASCADE,
    tipo VARCHAR(50) NOT NULL,
    valor DECIMAL(10,2) DEFAULT 0,
    data_inicio DATE NOT NULL,
    data_fim DATE,
    ativo BOOLEAN DEFAULT TRUE,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- =====================================================
-- SISTEMA CONTÁBIL
-- =====================================================

-- Tabela de contas
CREATE TABLE IF NOT EXISTS contab_contas (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    nome VARCHAR(255) NOT NULL,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('ativo', 'passivo', 'patrimonio', 'receita', 'despesa')),
    nivel INTEGER DEFAULT 1,
    conta_pai_id INT REFERENCES contab_contas(id) ON DELETE SET NULL,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de categorias
CREATE TABLE IF NOT EXISTS contab_categorias (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('receita', 'despesa')),
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de transações
CREATE TABLE IF NOT EXISTS contab_transacoes (
    id SERIAL PRIMARY KEY,
    conta_id INT REFERENCES contab_contas(id) ON DELETE SET NULL,
    categoria_id INT REFERENCES contab_categorias(id) ON DELETE SET NULL,
    descricao VARCHAR(255) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    data_transacao DATE NOT NULL,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('debito', 'credito')),
    observacoes TEXT,
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- =====================================================
-- SISTEMA DE ESTOQUE AVANÇADO
-- =====================================================

-- Tabela de contagens
CREATE TABLE IF NOT EXISTS estoque_contagens (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    data_ref DATE NOT NULL,
    observacao TEXT,
    status VARCHAR(20) DEFAULT 'aberta' CHECK (status IN ('aberta', 'fechada')),
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de itens das contagens
CREATE TABLE IF NOT EXISTS estoque_contagem_itens (
    id SERIAL PRIMARY KEY,
    contagem_id INT REFERENCES estoque_contagens(id) ON DELETE CASCADE,
    insumo_id INT REFERENCES lc_insumos(id) ON DELETE CASCADE,
    qtd_contada DECIMAL(10,6) NOT NULL,
    unidade_id INT REFERENCES lc_unidades(id) ON DELETE SET NULL,
    qtd_contada_base DECIMAL(10,6) NOT NULL,
    observacao TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de movimentos
CREATE TABLE IF NOT EXISTS estoque_movimentos (
    id SERIAL PRIMARY KEY,
    insumo_id INT REFERENCES lc_insumos(id) ON DELETE CASCADE,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('entrada', 'consumo_evento', 'ajuste', 'perda', 'devolucao')),
    quantidade DECIMAL(10,6) NOT NULL,
    unidade_id INT REFERENCES lc_unidades(id) ON DELETE SET NULL,
    quantidade_base DECIMAL(10,6) NOT NULL,
    referencia VARCHAR(255),
    observacao TEXT,
    custo_unitario DECIMAL(10,2) DEFAULT 0,
    fornecedor_id INT REFERENCES fornecedores(id) ON DELETE SET NULL,
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de alertas
CREATE TABLE IF NOT EXISTS estoque_alertas (
    id SERIAL PRIMARY KEY,
    insumo_id INT REFERENCES lc_insumos(id) ON DELETE CASCADE,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('estoque_baixo', 'vencimento', 'excesso')),
    nivel VARCHAR(20) NOT NULL CHECK (nivel IN ('info', 'warning', 'danger')),
    mensagem TEXT NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela de kardex
CREATE TABLE IF NOT EXISTS estoque_kardex (
    id SERIAL PRIMARY KEY,
    insumo_id INT REFERENCES lc_insumos(id) ON DELETE CASCADE,
    movimento_id INT REFERENCES estoque_movimentos(id) ON DELETE SET NULL,
    saldo_anterior DECIMAL(10,6) DEFAULT 0,
    entrada DECIMAL(10,6) DEFAULT 0,
    saida DECIMAL(10,6) DEFAULT 0,
    saldo_atual DECIMAL(10,6) DEFAULT 0,
    custo_unitario DECIMAL(10,2) DEFAULT 0,
    valor_total DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);

-- =====================================================
-- ÍNDICES PARA PERFORMANCE
-- =====================================================

-- Índices para usuários
CREATE INDEX IF NOT EXISTS idx_usuarios_perfil ON usuarios(perfil);
CREATE INDEX IF NOT EXISTS idx_usuarios_ativo ON usuarios(ativo);
CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email);

-- Índices para eventos
CREATE INDEX IF NOT EXISTS idx_eventos_data ON eventos(data_inicio);
CREATE INDEX IF NOT EXISTS idx_eventos_usuario ON eventos(usuario_id);
CREATE INDEX IF NOT EXISTS idx_eventos_status ON eventos(status);

-- Índices para fornecedores
CREATE INDEX IF NOT EXISTS idx_fornecedores_ativo ON fornecedores(ativo);
CREATE INDEX IF NOT EXISTS idx_fornecedores_cnpj ON fornecedores(cnpj);

-- Índices para estoque
CREATE INDEX IF NOT EXISTS idx_insumos_ativo ON lc_insumos(ativo);
CREATE INDEX IF NOT EXISTS idx_insumos_categoria ON lc_insumos(categoria_id);
CREATE INDEX IF NOT EXISTS idx_insumos_fornecedor ON lc_insumos(fornecedor_id);

-- Índices para demandas
CREATE INDEX IF NOT EXISTS idx_demandas_logs_entidade ON demandas_logs(entidade, entidade_id);
CREATE INDEX IF NOT EXISTS idx_demandas_logs_usuario ON demandas_logs(usuario_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_quadro ON demandas_cartoes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_responsavel ON demandas_cartoes(responsavel_id);

-- Índices para agenda
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio);
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_espaco ON agenda_eventos(espaco_id);
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_usuario ON agenda_eventos(usuario_id);

-- Índices para pagamentos
CREATE INDEX IF NOT EXISTS idx_pagamentos_status ON pagamentos_solicitacoes(status);
CREATE INDEX IF NOT EXISTS idx_pagamentos_criador ON pagamentos_solicitacoes(criador_id);

-- Índices para comercial
CREATE INDEX IF NOT EXISTS idx_comercial_degustacoes_data ON comercial_degustacoes(data);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_evento ON comercial_degust_inscricoes(event_id);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_status ON comercial_degust_inscricoes(status);

-- Índices para RH
CREATE INDEX IF NOT EXISTS idx_rh_funcionarios_ativo ON rh_funcionarios(ativo);
CREATE INDEX IF NOT EXISTS idx_rh_funcionarios_departamento ON rh_funcionarios(departamento_id);

-- Índices para contabilidade
CREATE INDEX IF NOT EXISTS idx_contab_transacoes_data ON contab_transacoes(data_transacao);
CREATE INDEX IF NOT EXISTS idx_contab_transacoes_conta ON contab_transacoes(conta_id);

-- Índices para estoque avançado
CREATE INDEX IF NOT EXISTS idx_estoque_movimentos_insumo ON estoque_movimentos(insumo_id);
CREATE INDEX IF NOT EXISTS idx_estoque_movimentos_tipo ON estoque_movimentos(tipo);
CREATE INDEX IF NOT EXISTS idx_estoque_kardex_insumo ON estoque_kardex(insumo_id);

-- =====================================================
-- DADOS INICIAIS
-- =====================================================

-- Inserir usuário administrador padrão
INSERT INTO usuarios (nome, email, senha, perfil, ativo) 
VALUES ('Administrador', 'admin@smileeventos.com.br', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ADM', TRUE)
ON CONFLICT (email) DO NOTHING;

-- Inserir unidades padrão
INSERT INTO lc_unidades (nome, sigla, tipo, fator_conversao) VALUES
('Unidade', 'un', 'unidade', 1),
('Quilograma', 'kg', 'peso', 1),
('Grama', 'g', 'peso', 0.001),
('Litro', 'L', 'volume', 1),
('Mililitro', 'ml', 'volume', 0.001),
('Metro', 'm', 'comprimento', 1),
('Centímetro', 'cm', 'comprimento', 0.01)
ON CONFLICT DO NOTHING;

-- Inserir categorias padrão
INSERT INTO lc_categorias (nome, descricao) VALUES
('Alimentos', 'Insumos alimentícios'),
('Bebidas', 'Bebidas e líquidos'),
('Decoração', 'Itens de decoração'),
('Equipamentos', 'Equipamentos e utensílios'),
('Limpeza', 'Produtos de limpeza'),
('Outros', 'Outros insumos')
ON CONFLICT DO NOTHING;

-- Inserir espaços da agenda
INSERT INTO agenda_espacos (nome, slug, descricao, cor) VALUES
('Garden', 'garden', 'Espaço Garden para eventos', '#10b981'),
('Diverkids', 'diverkids', 'Espaço Diverkids para eventos', '#3b82f6'),
('Cristal', 'cristal', 'Espaço Cristal para eventos', '#8b5cf6'),
('Lisbon', 'lisbon', 'Espaço Lisbon para eventos', '#f59e0b')
ON CONFLICT (slug) DO NOTHING;

-- Inserir configurações padrão
INSERT INTO demandas_configuracoes (chave, valor, descricao, tipo) VALUES
('smtp_host', 'mail.smileeventos.com.br', 'Servidor SMTP', 'string'),
('smtp_port', '465', 'Porta SMTP', 'string'),
('smtp_username', 'contato@smileeventos.com.br', 'Usuário SMTP', 'string'),
('smtp_password', 'ti1996august', 'Senha SMTP', 'string'),
('smtp_from_name', 'GRUPO Smile EVENTOS', 'Nome do remetente', 'string'),
('smtp_from_email', 'contato@smileeventos.com.br', 'E-mail do remetente', 'string'),
('email_ativado', 'true', 'E-mail ativado', 'boolean'),
('arquivamento_dias', '10', 'Dias para arquivamento', 'number'),
('notificacao_vencimento_horas', '24', 'Horas para notificação de vencimento', 'number')
ON CONFLICT (chave) DO NOTHING;

-- =====================================================
-- FUNÇÕES AUXILIARES
-- =====================================================

-- Função para atualizar updated_at
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Triggers para updated_at
CREATE TRIGGER update_usuarios_updated_at BEFORE UPDATE ON usuarios FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_eventos_updated_at BEFORE UPDATE ON eventos FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_fornecedores_updated_at BEFORE UPDATE ON fornecedores FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_pendencias_updated_at BEFORE UPDATE ON pendencias FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_pagamentos_updated_at BEFORE UPDATE ON pagamentos FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- =====================================================
-- COMENTÁRIOS FINAIS
-- =====================================================

COMMENT ON DATABASE painel_smile_pro IS 'Banco de dados do Painel Smile PRO - Sistema completo de gestão empresarial';
COMMENT ON TABLE usuarios IS 'Usuários do sistema com diferentes perfis de acesso';
COMMENT ON TABLE eventos IS 'Eventos e agendamentos do sistema';
COMMENT ON TABLE fornecedores IS 'Fornecedores e parceiros da empresa';
COMMENT ON TABLE lc_insumos IS 'Insumos e produtos utilizados nos eventos';
COMMENT ON TABLE demandas_quadros IS 'Quadros do sistema de demandas/tarefas';
COMMENT ON TABLE agenda_eventos IS 'Eventos da agenda interna';
COMMENT ON TABLE pagamentos_solicitacoes IS 'Solicitações de pagamento';
COMMENT ON TABLE comercial_degustacoes IS 'Degustações comerciais';
COMMENT ON TABLE rh_funcionarios IS 'Funcionários do RH';
COMMENT ON TABLE contab_transacoes IS 'Transações contábeis';
COMMENT ON TABLE estoque_contagens IS 'Contagens de estoque';
COMMENT ON TABLE estoque_movimentos IS 'Movimentações de estoque';
COMMENT ON TABLE estoque_kardex IS 'Kardex de movimentações';

-- =====================================================
-- FIM DO SCHEMA
-- =====================================================
