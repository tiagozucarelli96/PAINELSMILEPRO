BEGIN;
CREATE TABLE IF NOT EXISTS orcamento_unidades (
 id BIGSERIAL PRIMARY KEY, nome VARCHAR(100) UNIQUE NOT NULL, descricao TEXT, capacidade_min INT, capacidade_max INT,
 tipos_evento JSONB NOT NULL DEFAULT '[]', permite_cerimonia BOOLEAN DEFAULT FALSE, permite_renovacao BOOLEAN DEFAULT FALSE,
 imagens JSONB NOT NULL DEFAULT '[]', video_url TEXT, ativo BOOLEAN NOT NULL DEFAULT TRUE, criado_em TIMESTAMPTZ DEFAULT NOW(), atualizado_em TIMESTAMPTZ DEFAULT NOW());
CREATE TABLE IF NOT EXISTS orcamento_pacotes (
 id BIGSERIAL PRIMARY KEY, unidade_id BIGINT REFERENCES orcamento_unidades(id) ON DELETE CASCADE, nome VARCHAR(140) NOT NULL,
 tipo_evento VARCHAR(30) NOT NULL, perfil VARCHAR(30), formato_gastronomico VARCHAR(40), convidados_min INT, convidados_max INT,
 dias_semana JSONB NOT NULL DEFAULT '[0,1,2,3,4,5,6]', pdf_url TEXT, descricao TEXT, diferenciais JSONB NOT NULL DEFAULT '[]',
 video_url TEXT, prioridade INT NOT NULL DEFAULT 100, ativo BOOLEAN NOT NULL DEFAULT TRUE, criado_em TIMESTAMPTZ DEFAULT NOW(), atualizado_em TIMESTAMPTZ DEFAULT NOW());
CREATE TABLE IF NOT EXISTS orcamento_regras (
 id BIGSERIAL PRIMARY KEY, tipo_evento VARCHAR(30) NOT NULL, convidados_min INT, convidados_max INT, cerimonia BOOLEAN,
 perfil VARCHAR(30), alimentacao VARCHAR(40), dia_semana SMALLINT, unidade_id BIGINT REFERENCES orcamento_unidades(id),
 pacote_id BIGINT REFERENCES orcamento_pacotes(id), prioridade INT DEFAULT 100, papel VARCHAR(15) DEFAULT 'principal', ativo BOOLEAN DEFAULT TRUE);
CREATE TABLE IF NOT EXISTS orcamento_leads (
 id BIGSERIAL PRIMARY KEY, nome VARCHAR(140) NOT NULL, whatsapp VARCHAR(20) NOT NULL, email VARCHAR(180), tipo_evento VARCHAR(30) NOT NULL,
 data_evento DATE, convidados INT NOT NULL, respostas JSONB NOT NULL, unidade_recomendada VARCHAR(100), pacote_recomendado VARCHAR(140),
 segunda_opcao VARCHAR(160), consentimento BOOLEAN NOT NULL, origem VARCHAR(80) DEFAULT 'orcamento-guiado', sessao_uuid UUID NOT NULL,
 criado_em TIMESTAMPTZ DEFAULT NOW());
CREATE TABLE IF NOT EXISTS orcamento_metricas (
 id BIGSERIAL PRIMARY KEY, sessao_uuid UUID NOT NULL, lead_id BIGINT REFERENCES orcamento_leads(id) ON DELETE SET NULL,
 evento VARCHAR(60) NOT NULL, dados JSONB NOT NULL DEFAULT '{}', criado_em TIMESTAMPTZ DEFAULT NOW());
CREATE INDEX IF NOT EXISTS idx_orcamento_pacotes_filtro ON orcamento_pacotes(tipo_evento, ativo, convidados_min, convidados_max);
CREATE INDEX IF NOT EXISTS idx_orcamento_leads_criado ON orcamento_leads(criado_em DESC);
CREATE INDEX IF NOT EXISTS idx_orcamento_metricas_sessao ON orcamento_metricas(sessao_uuid, criado_em);
INSERT INTO orcamento_unidades(nome,descricao,capacidade_min,capacidade_max,tipos_evento,permite_cerimonia,permite_renovacao) VALUES
 ('Lisbon','Espaço intimista para comemorações.',50,100,'["casamento","bodas","15anos"]',false,false),
 ('Cristal','Espaço elegante com opção de cerimônia.',70,150,'["casamento","bodas","15anos"]',true,true),
 ('Garden','Estrutura ampla para grandes celebrações.',90,250,'["casamento","bodas","15anos"]',true,true),
 ('DiverKids','Unidade infantil para festas de 30 a 70 convidados.',30,70,'["infantil"]',false,false),
 ('Lisbon Kids','Unidade infantil para festas de 50 a 100 convidados.',50,100,'["infantil"]',false,false)
ON CONFLICT(nome) DO NOTHING;
COMMIT;
