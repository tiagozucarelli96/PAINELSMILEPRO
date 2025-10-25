<?php
// GERADOR_MIGRACOES_SQL.php
// Gerador de migrações SQL baseado nas inconsistências encontradas

echo "<h1>🔧 GERADOR DE MIGRAÇÕES SQL</h1>";

// Carregar inconsistências
$inconsistencias = json_decode(file_get_contents('/tmp/inconsistencias.json'), true);

// Conectar ao banco
require_once __DIR__ . '/public/conexao.php';

echo "<h2>1. 🔌 Conectando ao banco...</h2>";
try {
    $stmt = $pdo->query("SELECT current_database(), current_schema()");
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>✅ Conectado ao banco: {$info['current_database']}</p>";
    echo "<p>✅ Schema atual: {$info['current_schema']}</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro de conexão: " . $e->getMessage() . "</p>";
    exit;
}

// Criar diretório de migrações
$migrations_dir = __DIR__ . '/sql/migrations';
if (!is_dir($migrations_dir)) {
    mkdir($migrations_dir, 0755, true);
    echo "<p>📁 Diretório de migrações criado: $migrations_dir</p>";
}

// Função para gerar timestamp
function gerarTimestamp() {
    return date('YmdHis');
}

// Função para criar migração
function criarMigracao($nome, $conteudo, $migrations_dir) {
    $timestamp = gerarTimestamp();
    $arquivo = $migrations_dir . '/' . $timestamp . '__' . $nome . '.sql';
    file_put_contents($arquivo, $conteudo);
    return $arquivo;
}

// Função para criar rollback
function criarRollback($nome, $conteudo, $migrations_dir) {
    $timestamp = gerarTimestamp();
    $arquivo = $migrations_dir . '/' . $timestamp . '__' . $nome . '__down.sql';
    file_put_contents($arquivo, $conteudo);
    return $arquivo;
}

echo "<h2>2. 📝 Gerando migrações...</h2>";

$migracoes_criadas = [];

// 1. Migração para tabelas do módulo Compras
$tabelas_compras = [
    'lc_categorias' => "
        CREATE TABLE IF NOT EXISTS lc_categorias (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            ordem INTEGER DEFAULT 0,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_unidades' => "
        CREATE TABLE IF NOT EXISTS lc_unidades (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(50) NOT NULL,
            simbolo VARCHAR(10) NOT NULL,
            tipo VARCHAR(20) DEFAULT 'volume',
            fator_base DECIMAL(10,4) DEFAULT 1.0,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_fichas' => "
        CREATE TABLE IF NOT EXISTS lc_fichas (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            descricao TEXT,
            consumo_pessoa DECIMAL(10,4) DEFAULT 1.0,
            rendimento_base_pessoas INTEGER DEFAULT 1,
            nome_exibicao VARCHAR(200),
            ativo BOOLEAN DEFAULT TRUE,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_itens' => "
        CREATE TABLE IF NOT EXISTS lc_itens (
            id SERIAL PRIMARY KEY,
            ficha_id INTEGER REFERENCES lc_fichas(id),
            insumo_id INTEGER,
            quantidade DECIMAL(10,4) NOT NULL,
            unidade VARCHAR(20),
            tipo VARCHAR(20) DEFAULT 'preparo',
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_ficha_componentes' => "
        CREATE TABLE IF NOT EXISTS lc_ficha_componentes (
            id SERIAL PRIMARY KEY,
            ficha_id INTEGER REFERENCES lc_fichas(id),
            insumo_id INTEGER,
            quantidade DECIMAL(10,4) NOT NULL,
            unidade VARCHAR(20),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_itens_fixos' => "
        CREATE TABLE IF NOT EXISTS lc_itens_fixos (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            categoria VARCHAR(100),
            unidade VARCHAR(20),
            quantidade_padrao DECIMAL(10,4) DEFAULT 1.0,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_arredondamentos' => "
        CREATE TABLE IF NOT EXISTS lc_arredondamentos (
            id SERIAL PRIMARY KEY,
            insumo_id INTEGER,
            regra VARCHAR(50) NOT NULL,
            valor DECIMAL(10,4),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_rascunhos' => "
        CREATE TABLE IF NOT EXISTS lc_rascunhos (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            tipo VARCHAR(20) DEFAULT 'compras',
            payload JSONB,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_encomendas_itens' => "
        CREATE TABLE IF NOT EXISTS lc_encomendas_itens (
            id SERIAL PRIMARY KEY,
            lista_id INTEGER,
            fornecedor_id INTEGER,
            evento_id INTEGER,
            insumo_id INTEGER,
            quantidade DECIMAL(10,4) NOT NULL,
            unidade VARCHAR(20),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_encomendas_overrides' => "
        CREATE TABLE IF NOT EXISTS lc_encomendas_overrides (
            id SERIAL PRIMARY KEY,
            lista_id INTEGER,
            fornecedor_id INTEGER,
            evento_id INTEGER,
            insumo_id INTEGER,
            quantidade_override DECIMAL(10,4),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_geracoes' => "
        CREATE TABLE IF NOT EXISTS lc_geracoes (
            id SERIAL PRIMARY KEY,
            grupo_token VARCHAR(100) UNIQUE NOT NULL,
            criado_por INTEGER,
            criado_por_nome VARCHAR(200),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_lista_eventos' => "
        CREATE TABLE IF NOT EXISTS lc_lista_eventos (
            id SERIAL PRIMARY KEY,
            grupo_id INTEGER,
            espaco VARCHAR(200),
            convidados INTEGER,
            horario TIME,
            evento_texto VARCHAR(500),
            data_evento DATE,
            dia_semana VARCHAR(20),
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_compras_consolidadas' => "
        CREATE TABLE IF NOT EXISTS lc_compras_consolidadas (
            id SERIAL PRIMARY KEY,
            grupo_id INTEGER,
            insumo_id INTEGER,
            nome_insumo VARCHAR(200),
            unidade VARCHAR(20),
            qtd_bruta DECIMAL(10,4),
            qtd_final DECIMAL(10,4),
            foi_arredondado BOOLEAN DEFAULT FALSE,
            origem_json JSONB,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );"
];

$sql_compras = "-- Migração: Tabelas do módulo Compras\n";
$sql_compras .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tabelas_compras as $tabela => $ddl) {
    $sql_compras .= $ddl . "\n";
}

// Adicionar índices
$sql_compras .= "
-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_lc_fichas_nome ON lc_fichas(nome);
CREATE INDEX IF NOT EXISTS idx_lc_itens_ficha_id ON lc_itens(ficha_id);
CREATE INDEX IF NOT EXISTS idx_lc_itens_insumo_id ON lc_itens(insumo_id);
CREATE INDEX IF NOT EXISTS idx_lc_compras_consolidadas_grupo_id ON lc_compras_consolidadas(grupo_id);
CREATE INDEX IF NOT EXISTS idx_lc_lista_eventos_grupo_id ON lc_lista_eventos(grupo_id);
";

$arquivo_compras = criarMigracao('criar_tabelas_compras', $sql_compras, $migrations_dir);
$migracoes_criadas[] = $arquivo_compras;

// 2. Migração para tabelas de Fornecedores e Freelancers
$tabelas_fornecedores = [
    'fornecedores' => "
        CREATE TABLE IF NOT EXISTS fornecedores (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            cnpj VARCHAR(20),
            ie VARCHAR(20),
            telefone VARCHAR(20),
            email VARCHAR(200),
            contato_responsavel VARCHAR(200),
            categoria VARCHAR(100),
            observacao TEXT,
            pix_tipo VARCHAR(20),
            pix_chave VARCHAR(200),
            token_publico VARCHAR(100) UNIQUE,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_freelancers' => "
        CREATE TABLE IF NOT EXISTS lc_freelancers (
            id SERIAL PRIMARY KEY,
            nome_completo VARCHAR(200) NOT NULL,
            cpf VARCHAR(14) UNIQUE NOT NULL,
            pix_tipo VARCHAR(20),
            pix_chave VARCHAR(200),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );"
];

$sql_fornecedores = "-- Migração: Tabelas de Fornecedores e Freelancers\n";
$sql_fornecedores .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tabelas_fornecedores as $tabela => $ddl) {
    $sql_fornecedores .= $ddl . "\n";
}

$sql_fornecedores .= "
-- Índices
CREATE INDEX IF NOT EXISTS idx_fornecedores_nome ON fornecedores(nome);
CREATE INDEX IF NOT EXISTS idx_fornecedores_cnpj ON fornecedores(cnpj);
CREATE INDEX IF NOT EXISTS idx_lc_freelancers_cpf ON lc_freelancers(cpf);
";

$arquivo_fornecedores = criarMigracao('criar_tabelas_fornecedores', $sql_fornecedores, $migrations_dir);
$migracoes_criadas[] = $arquivo_fornecedores;

// 3. Migração para tabelas de Pagamentos
$tabelas_pagamentos = [
    'lc_solicitacoes_pagamento' => "
        CREATE TABLE IF NOT EXISTS lc_solicitacoes_pagamento (
            id SERIAL PRIMARY KEY,
            criador_id INTEGER,
            beneficiario_tipo VARCHAR(20) NOT NULL,
            freelancer_id INTEGER,
            fornecedor_id INTEGER,
            valor DECIMAL(10,2) NOT NULL,
            data_desejada DATE,
            observacoes TEXT,
            status VARCHAR(20) DEFAULT 'aguardando',
            status_atualizado_em TIMESTAMP,
            status_atualizado_por INTEGER,
            origem VARCHAR(50),
            pix_tipo VARCHAR(20),
            pix_chave VARCHAR(200),
            data_pagamento DATE,
            observacao_pagamento TEXT,
            motivo_suspensao TEXT,
            motivo_recusa TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_timeline_pagamentos' => "
        CREATE TABLE IF NOT EXISTS lc_timeline_pagamentos (
            id SERIAL PRIMARY KEY,
            solicitacao_id INTEGER REFERENCES lc_solicitacoes_pagamento(id),
            autor_id INTEGER,
            acao VARCHAR(50) NOT NULL,
            descricao TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'lc_anexos_pagamentos' => "
        CREATE TABLE IF NOT EXISTS lc_anexos_pagamentos (
            id SERIAL PRIMARY KEY,
            solicitacao_id INTEGER REFERENCES lc_solicitacoes_pagamento(id),
            nome_arquivo VARCHAR(255) NOT NULL,
            caminho_arquivo VARCHAR(500) NOT NULL,
            tamanho_bytes BIGINT,
            tipo_mime VARCHAR(100),
            eh_comprovante BOOLEAN DEFAULT FALSE,
            autor_id INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );"
];

$sql_pagamentos = "-- Migração: Tabelas de Pagamentos\n";
$sql_pagamentos .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tabelas_pagamentos as $tabela => $ddl) {
    $sql_pagamentos .= $ddl . "\n";
}

$sql_pagamentos .= "
-- Índices
CREATE INDEX IF NOT EXISTS idx_lc_solicitacoes_criador_id ON lc_solicitacoes_pagamento(criador_id);
CREATE INDEX IF NOT EXISTS idx_lc_solicitacoes_status ON lc_solicitacoes_pagamento(status);
CREATE INDEX IF NOT EXISTS idx_lc_timeline_solicitacao_id ON lc_timeline_pagamentos(solicitacao_id);
CREATE INDEX IF NOT EXISTS idx_lc_anexos_solicitacao_id ON lc_anexos_pagamentos(solicitacao_id);
";

$arquivo_pagamentos = criarMigracao('criar_tabelas_pagamentos', $sql_pagamentos, $migrations_dir);
$migracoes_criadas[] = $arquivo_pagamentos;

// 4. Migração para tabelas de Demandas
$tabelas_demandas = [
    'demandas_quadros' => "
        CREATE TABLE IF NOT EXISTS demandas_quadros (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            descricao TEXT,
            cor VARCHAR(7) DEFAULT '#3B82F6',
            ativo BOOLEAN DEFAULT TRUE,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'demandas_participantes' => "
        CREATE TABLE IF NOT EXISTS demandas_participantes (
            id SERIAL PRIMARY KEY,
            quadro_id INTEGER REFERENCES demandas_quadros(id),
            usuario_id INTEGER,
            permissao VARCHAR(20) DEFAULT 'leitura',
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'demandas_cartoes' => "
        CREATE TABLE IF NOT EXISTS demandas_cartoes (
            id SERIAL PRIMARY KEY,
            quadro_id INTEGER REFERENCES demandas_quadros(id),
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            responsavel_id INTEGER,
            data_vencimento DATE,
            prioridade VARCHAR(20) DEFAULT 'media',
            status VARCHAR(20) DEFAULT 'pendente',
            recorrente BOOLEAN DEFAULT FALSE,
            recorrencia_config JSONB,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'demandas_preferencias_notificacao' => "
        CREATE TABLE IF NOT EXISTS demandas_preferencias_notificacao (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER,
            tipo_notificacao VARCHAR(20) NOT NULL,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );"
];

$sql_demandas = "-- Migração: Tabelas de Demandas\n";
$sql_demandas .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tabelas_demandas as $tabela => $ddl) {
    $sql_demandas .= $ddl . "\n";
}

$sql_demandas .= "
-- Índices
CREATE INDEX IF NOT EXISTS idx_demandas_quadros_criado_por ON demandas_quadros(criado_por);
CREATE INDEX IF NOT EXISTS idx_demandas_participantes_quadro_id ON demandas_participantes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_participantes_usuario_id ON demandas_participantes(usuario_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_quadro_id ON demandas_cartoes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_responsavel_id ON demandas_cartoes(responsavel_id);
";

$arquivo_demandas = criarMigracao('criar_tabelas_demandas', $sql_demandas, $migrations_dir);
$migracoes_criadas[] = $arquivo_demandas;

// 5. Migração para tabelas de Comercial
$tabelas_comercial = [
    'comercial_inscricoes' => "
        CREATE TABLE IF NOT EXISTS comercial_inscricoes (
            id SERIAL PRIMARY KEY,
            degustacao_id INTEGER,
            nome VARCHAR(200) NOT NULL,
            email VARCHAR(200) NOT NULL,
            telefone VARCHAR(20),
            cpf VARCHAR(14),
            status VARCHAR(20) DEFAULT 'pendente',
            pagamento_status VARCHAR(20),
            pagamento_id VARCHAR(100),
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'comercial_campos_padrao' => "
        CREATE TABLE IF NOT EXISTS comercial_campos_padrao (
            id SERIAL PRIMARY KEY,
            campos_json JSONB NOT NULL,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'comercial_email_config' => "
        CREATE TABLE IF NOT EXISTS comercial_email_config (
            id SERIAL PRIMARY KEY,
            host VARCHAR(200) NOT NULL,
            port INTEGER DEFAULT 587,
            username VARCHAR(200),
            password VARCHAR(500),
            encryption VARCHAR(20) DEFAULT 'tls',
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );"
];

$sql_comercial = "-- Migração: Tabelas de Comercial\n";
$sql_comercial .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tabelas_comercial as $tabela => $ddl) {
    $sql_comercial .= $ddl . "\n";
}

$sql_comercial .= "
-- Índices
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_degustacao_id ON comercial_inscricoes(degustacao_id);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_email ON comercial_inscricoes(email);
";

$arquivo_comercial = criarMigracao('criar_tabelas_comercial', $sql_comercial, $migrations_dir);
$migracoes_criadas[] = $arquivo_comercial;

// 6. Migração para tabelas de RH
$tabelas_rh = [
    'rh_holerites' => "
        CREATE TABLE IF NOT EXISTS rh_holerites (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER,
            mes_competencia VARCHAR(7) NOT NULL,
            valor_liquido DECIMAL(10,2),
            observacao TEXT,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'rh_anexos' => "
        CREATE TABLE IF NOT EXISTS rh_anexos (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER,
            holerite_id INTEGER,
            nome_arquivo VARCHAR(255) NOT NULL,
            caminho_arquivo VARCHAR(500) NOT NULL,
            tamanho_bytes BIGINT,
            tipo_mime VARCHAR(100),
            autor_id INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );"
];

$sql_rh = "-- Migração: Tabelas de RH\n";
$sql_rh .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tabelas_rh as $tabela => $ddl) {
    $sql_rh .= $ddl . "\n";
}

$sql_rh .= "
-- Índices
CREATE INDEX IF NOT EXISTS idx_rh_holerites_usuario_id ON rh_holerites(usuario_id);
CREATE INDEX IF NOT EXISTS idx_rh_holerites_mes_competencia ON rh_holerites(mes_competencia);
CREATE INDEX IF NOT EXISTS idx_rh_anexos_usuario_id ON rh_anexos(usuario_id);
CREATE INDEX IF NOT EXISTS idx_rh_anexos_holerite_id ON rh_anexos(holerite_id);
";

$arquivo_rh = criarMigracao('criar_tabelas_rh', $sql_rh, $migrations_dir);
$migracoes_criadas[] = $arquivo_rh;

// 7. Migração para tabelas de Contabilidade
$tabelas_contab = [
    'contab_documentos' => "
        CREATE TABLE IF NOT EXISTS contab_documentos (
            id SERIAL PRIMARY KEY,
            numero VARCHAR(50) NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            data_vencimento DATE,
            data_pagamento DATE,
            status VARCHAR(20) DEFAULT 'pendente',
            observacoes TEXT,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'contab_parcelas' => "
        CREATE TABLE IF NOT EXISTS contab_parcelas (
            id SERIAL PRIMARY KEY,
            documento_id INTEGER REFERENCES contab_documentos(id),
            numero_parcela INTEGER NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            data_vencimento DATE,
            data_pagamento DATE,
            status VARCHAR(20) DEFAULT 'pendente',
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'contab_anexos' => "
        CREATE TABLE IF NOT EXISTS contab_anexos (
            id SERIAL PRIMARY KEY,
            documento_id INTEGER REFERENCES contab_documentos(id),
            nome_arquivo VARCHAR(255) NOT NULL,
            caminho_arquivo VARCHAR(500) NOT NULL,
            tamanho_bytes BIGINT,
            tipo_mime VARCHAR(100),
            autor_id INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'contab_tokens' => "
        CREATE TABLE IF NOT EXISTS contab_tokens (
            id SERIAL PRIMARY KEY,
            token VARCHAR(100) UNIQUE NOT NULL,
            descricao VARCHAR(200),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );"
];

$sql_contab = "-- Migração: Tabelas de Contabilidade\n";
$sql_contab .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tabelas_contab as $tabela => $ddl) {
    $sql_contab .= $ddl . "\n";
}

$sql_contab .= "
-- Índices
CREATE INDEX IF NOT EXISTS idx_contab_documentos_numero ON contab_documentos(numero);
CREATE INDEX IF NOT EXISTS idx_contab_documentos_status ON contab_documentos(status);
CREATE INDEX IF NOT EXISTS idx_contab_parcelas_documento_id ON contab_parcelas(documento_id);
CREATE INDEX IF NOT EXISTS idx_contab_anexos_documento_id ON contab_anexos(documento_id);
";

$arquivo_contab = criarMigracao('criar_tabelas_contab', $sql_contab, $migrations_dir);
$migracoes_criadas[] = $arquivo_contab;

// 8. Migração para tabelas de Estoque
$tabelas_estoque = [
    'lc_movimentos_estoque' => "
        CREATE TABLE IF NOT EXISTS lc_movimentos_estoque (
            id SERIAL PRIMARY KEY,
            insumo_id INTEGER,
            tipo_movimento VARCHAR(20) NOT NULL,
            quantidade DECIMAL(10,4) NOT NULL,
            unidade VARCHAR(20),
            data_movimento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            observacoes TEXT,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'portao_logs' => "
        CREATE TABLE IF NOT EXISTS portao_logs (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER,
            acao VARCHAR(50) NOT NULL,
            ip VARCHAR(45),
            user_agent TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );",
    
    'clickup_tokens' => "
        CREATE TABLE IF NOT EXISTS clickup_tokens (
            id SERIAL PRIMARY KEY,
            token VARCHAR(500) NOT NULL,
            team_id VARCHAR(100),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );"
];

$sql_estoque = "-- Migração: Tabelas de Estoque e Logs\n";
$sql_estoque .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tabelas_estoque as $tabela => $ddl) {
    $sql_estoque .= $ddl . "\n";
}

$sql_estoque .= "
-- Índices
CREATE INDEX IF NOT EXISTS idx_lc_movimentos_insumo_id ON lc_movimentos_estoque(insumo_id);
CREATE INDEX IF NOT EXISTS idx_lc_movimentos_data_movimento ON lc_movimentos_estoque(data_movimento);
CREATE INDEX IF NOT EXISTS idx_portao_logs_usuario_id ON portao_logs(usuario_id);
CREATE INDEX IF NOT EXISTS idx_portao_logs_criado_em ON portao_logs(criado_em);
";

$arquivo_estoque = criarMigracao('criar_tabelas_estoque', $sql_estoque, $migrations_dir);
$migracoes_criadas[] = $arquivo_estoque;

// 9. Migração para funções PostgreSQL
$funcoes_sql = "-- Migração: Funções PostgreSQL\n";
$funcoes_sql .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

$funcoes_sql .= "
-- Função para buscar fornecedores ativos
CREATE OR REPLACE FUNCTION lc_buscar_fornecedores_ativos()
RETURNS TABLE (
    id INTEGER,
    nome VARCHAR(200),
    cnpj VARCHAR(20),
    telefone VARCHAR(20),
    email VARCHAR(200)
) AS \$\$
BEGIN
    RETURN QUERY
    SELECT f.id, f.nome, f.cnpj, f.telefone, f.email
    FROM fornecedores f
    WHERE f.ativo = TRUE
    ORDER BY f.nome;
END;
\$\$ LANGUAGE plpgsql;

-- Função para buscar freelancers ativos
CREATE OR REPLACE FUNCTION lc_buscar_freelancers_ativos()
RETURNS TABLE (
    id INTEGER,
    nome_completo VARCHAR(200),
    cpf VARCHAR(14),
    pix_tipo VARCHAR(20),
    pix_chave VARCHAR(200)
) AS \$\$
BEGIN
    RETURN QUERY
    SELECT fl.id, fl.nome_completo, fl.cpf, fl.pix_tipo, fl.pix_chave
    FROM lc_freelancers fl
    WHERE fl.ativo = TRUE
    ORDER BY fl.nome_completo;
END;
\$\$ LANGUAGE plpgsql;

-- Função para gerar token público
CREATE OR REPLACE FUNCTION lc_gerar_token_publico()
RETURNS VARCHAR(100) AS \$\$
BEGIN
    RETURN 'pub_' || substr(md5(random()::text), 1, 32);
END;
\$\$ LANGUAGE plpgsql;

-- Função para estatísticas do RH
CREATE OR REPLACE FUNCTION rh_estatisticas_dashboard()
RETURNS TABLE (
    total_colaboradores INTEGER,
    total_holerites INTEGER,
    holerites_este_mes INTEGER,
    valor_total_pago DECIMAL(10,2)
) AS \$\$
BEGIN
    RETURN QUERY
    SELECT 
        (SELECT COUNT(*)::INTEGER FROM usuarios WHERE ativo = TRUE) as total_colaboradores,
        (SELECT COUNT(*)::INTEGER FROM rh_holerites) as total_holerites,
        (SELECT COUNT(*)::INTEGER FROM rh_holerites WHERE mes_competencia = to_char(CURRENT_DATE, 'YYYY-MM')) as holerites_este_mes,
        (SELECT COALESCE(SUM(valor_liquido), 0) FROM rh_holerites) as valor_total_pago;
END;
\$\$ LANGUAGE plpgsql;

-- Função para estatísticas da Contabilidade
CREATE OR REPLACE FUNCTION contab_estatisticas_dashboard()
RETURNS TABLE (
    total_documentos INTEGER,
    documentos_pendentes INTEGER,
    valor_total_pendente DECIMAL(10,2),
    valor_total_pago DECIMAL(10,2)
) AS \$\$
BEGIN
    RETURN QUERY
    SELECT 
        (SELECT COUNT(*)::INTEGER FROM contab_documentos) as total_documentos,
        (SELECT COUNT(*)::INTEGER FROM contab_documentos WHERE status = 'pendente') as documentos_pendentes,
        (SELECT COALESCE(SUM(valor), 0) FROM contab_documentos WHERE status = 'pendente') as valor_total_pendente,
        (SELECT COALESCE(SUM(valor), 0) FROM contab_documentos WHERE status = 'pago') as valor_total_pago;
END;
\$\$ LANGUAGE plpgsql;
";

$arquivo_funcoes = criarMigracao('criar_funcoes_postgresql', $funcoes_sql, $migrations_dir);
$migracoes_criadas[] = $arquivo_funcoes;

// 10. Migração para adicionar colunas faltantes
$colunas_faltantes = [
    'usuarios' => [
        'perm_agenda_ver' => 'BOOLEAN DEFAULT FALSE',
        'perm_agenda_meus' => 'BOOLEAN DEFAULT FALSE', 
        'perm_agenda_relatorios' => 'BOOLEAN DEFAULT FALSE',
        'perm_gerir_eventos_outros' => 'BOOLEAN DEFAULT FALSE',
        'perm_forcar_conflito' => 'BOOLEAN DEFAULT FALSE',
        'perm_tarefas' => 'BOOLEAN DEFAULT FALSE',
        'perm_lista' => 'BOOLEAN DEFAULT FALSE',
        'perm_demandas' => 'BOOLEAN DEFAULT FALSE',
        'perm_pagamentos' => 'BOOLEAN DEFAULT FALSE',
        'perm_usuarios' => 'BOOLEAN DEFAULT FALSE',
        'perm_portao' => 'BOOLEAN DEFAULT FALSE',
        'perm_banco_smile' => 'BOOLEAN DEFAULT FALSE',
        'perm_banco_smile_admin' => 'BOOLEAN DEFAULT FALSE',
        'perm_notas_fiscais' => 'BOOLEAN DEFAULT FALSE',
        'perm_estoque_logistico' => 'BOOLEAN DEFAULT FALSE',
        'perm_dados_contrato' => 'BOOLEAN DEFAULT FALSE',
        'perm_uso_fiorino' => 'BOOLEAN DEFAULT FALSE',
        'cargo' => 'VARCHAR(100)',
        'cpf' => 'VARCHAR(14)',
        'admissao_data' => 'DATE',
        'salario_base' => 'DECIMAL(10,2)',
        'pix_tipo' => 'VARCHAR(20)',
        'pix_chave' => 'VARCHAR(200)',
        'status_empregado' => 'VARCHAR(20) DEFAULT "ativo"'
    ],
    'lc_insumos' => [
        'nome' => 'VARCHAR(200)',
        'categoria' => 'VARCHAR(100)',
        'unidade_padrao' => 'VARCHAR(20)',
        'custo_unitario' => 'DECIMAL(10,4)',
        'fornecedor_id' => 'INTEGER',
        'substitutos' => 'JSONB',
        'status' => 'VARCHAR(20) DEFAULT "ativo"'
    ],
    'lc_listas' => [
        'categoria' => 'VARCHAR(100)',
        'tipo' => 'VARCHAR(20)',
        'payload' => 'JSONB',
        'modo' => 'VARCHAR(20)',
        'token' => 'VARCHAR(100)'
    ],
    'eventos' => [
        'titulo' => 'VARCHAR(200)',
        'descricao' => 'TEXT',
        'data_inicio' => 'TIMESTAMP',
        'data_fim' => 'TIMESTAMP',
        'local' => 'VARCHAR(200)',
        'status' => 'VARCHAR(20) DEFAULT "ativo"',
        'observacoes' => 'TEXT'
    ],
    'comercial_degustacoes' => [
        'status' => 'VARCHAR(20) DEFAULT "ativo"'
    ]
];

$sql_colunas = "-- Migração: Adicionar colunas faltantes\n";
$sql_colunas .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($colunas_faltantes as $tabela => $colunas) {
    foreach ($colunas as $coluna => $tipo) {
        $sql_colunas .= "ALTER TABLE $tabela ADD COLUMN IF NOT EXISTS $coluna $tipo;\n";
    }
    $sql_colunas .= "\n";
}

$arquivo_colunas = criarMigracao('adicionar_colunas_faltantes', $sql_colunas, $migrations_dir);
$migracoes_criadas[] = $arquivo_colunas;

// Resumo
echo "<h2>3. 📊 Resumo das Migrações</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Migrações Criadas:</h3>";
echo "<p>• <strong>Total de migrações:</strong> " . count($migracoes_criadas) . "</p>";

foreach ($migracoes_criadas as $arquivo) {
    $nome = basename($arquivo);
    echo "<p>• <strong>$nome</strong></p>";
}

echo "</div>";

// Salvar lista de migrações
file_put_contents('/tmp/migracoes_criadas.json', json_encode($migracoes_criadas, JSON_PRETTY_PRINT));

echo "<h2>💾 Lista de migrações salva em /tmp/migracoes_criadas.json</h2>";

echo "<h2>4. 🚀 Próximos Passos</h2>";
echo "<div style='background: #fef3c7; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📋 Instruções:</h3>";
echo "<p>1. 🗄️ Execute as migrações em ordem no banco de dados</p>";
echo "<p>2. 📝 Verifique se todas as tabelas foram criadas corretamente</p>";
echo "<p>3. 🔧 Teste as funções PostgreSQL criadas</p>";
echo "<p>4. ✅ Execute os testes para verificar se os erros foram corrigidos</p>";
echo "</div>";
?>
