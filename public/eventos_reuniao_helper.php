<?php
/**
 * eventos_reuniao_helper.php
 * Helper para gerenciar reuniões de eventos
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_me_helper.php';

// Carregar notificações (se existir)
if (file_exists(__DIR__ . '/eventos_notificacoes.php')) {
    require_once __DIR__ . '/eventos_notificacoes.php';
}

/**
 * Cache simples de existência de colunas.
 */
function eventos_reuniao_has_column(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = :table
          AND column_name = :column
          AND table_schema = ANY (current_schemas(FALSE))
        LIMIT 1
    ");
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

/**
 * Cache simples de existencia de tabelas.
 */
function eventos_reuniao_has_table(PDO $pdo, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_name = :table
          AND table_schema = ANY (current_schemas(FALSE))
        LIMIT 1
    ");
    $stmt->execute([':table' => $table]);
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}

/**
 * Garante estrutura necessária para formulário dinâmico da Reunião Final.
 */
function eventos_reuniao_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }

    try {
        if (eventos_reuniao_has_table($pdo, 'eventos_reunioes_secoes')) {
            $pdo->exec("ALTER TABLE eventos_reunioes_secoes ADD COLUMN IF NOT EXISTS form_schema_json JSONB");
        }
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao criar coluna form_schema_json: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_form_templates (
                id BIGSERIAL PRIMARY KEY,
                nome VARCHAR(120) NOT NULL,
                categoria VARCHAR(40) NOT NULL DEFAULT 'geral',
                schema_json JSONB NOT NULL,
                ativo BOOLEAN NOT NULL DEFAULT TRUE,
                created_by_user_id INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_form_templates_ativo ON eventos_form_templates (ativo, updated_at DESC)");
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao criar tabela eventos_form_templates: ' . $e->getMessage());
    }

    // Campos adicionais para múltiplos links/formulários DJ por reunião.
    try {
        if (eventos_reuniao_has_table($pdo, 'eventos_links_publicos')) {
            $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS slot_index INTEGER");
            $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS form_schema_json JSONB");
            $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS content_html_snapshot TEXT");
            $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS form_title VARCHAR(160)");
            $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NULL");
            $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS portal_visible BOOLEAN NOT NULL DEFAULT FALSE");
            $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS portal_editable BOOLEAN NOT NULL DEFAULT FALSE");
            $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS portal_configured BOOLEAN NOT NULL DEFAULT FALSE");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_links_slot_ativo ON eventos_links_publicos(meeting_id, link_type, slot_index, is_active)");
        }
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tabela eventos_links_publicos: ' . $e->getMessage());
    }

    // Tipo real do evento (manual, definido na organização).
    try {
        if (eventos_reuniao_has_table($pdo, 'eventos_reunioes')) {
            $pdo->exec("ALTER TABLE IF EXISTS eventos_reunioes ADD COLUMN IF NOT EXISTS tipo_evento_real VARCHAR(24)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_reunioes_tipo_evento_real ON eventos_reunioes(tipo_evento_real)");
        }
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tipo_evento_real em eventos_reunioes: ' . $e->getMessage());
    }

    // Observação opcional por arquivo enviado pelo cliente/equipe.
    try {
        if (eventos_reuniao_has_table($pdo, 'eventos_reunioes_anexos')) {
            $pdo->exec("ALTER TABLE IF EXISTS eventos_reunioes_anexos ADD COLUMN IF NOT EXISTS note TEXT");
        }
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tabela eventos_reunioes_anexos: ' . $e->getMessage());
    }

    // Portal do cliente por reunião (visibilidade e edição por módulo).
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_cliente_portais (
                id BIGSERIAL PRIMARY KEY,
                meeting_id BIGINT NOT NULL UNIQUE,
                token VARCHAR(96) NOT NULL UNIQUE,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                visivel_reuniao BOOLEAN NOT NULL DEFAULT FALSE,
                editavel_reuniao BOOLEAN NOT NULL DEFAULT FALSE,
                visivel_dj BOOLEAN NOT NULL DEFAULT FALSE,
                editavel_dj BOOLEAN NOT NULL DEFAULT FALSE,
                visivel_convidados BOOLEAN NOT NULL DEFAULT FALSE,
                editavel_convidados BOOLEAN NOT NULL DEFAULT FALSE,
                visivel_arquivos BOOLEAN NOT NULL DEFAULT FALSE,
                editavel_arquivos BOOLEAN NOT NULL DEFAULT FALSE,
                created_by_user_id INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS visivel_reuniao BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS editavel_reuniao BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS visivel_dj BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS editavel_dj BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS visivel_convidados BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS editavel_convidados BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS visivel_arquivos BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS editavel_arquivos BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS created_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_cliente_portais_meeting ON eventos_cliente_portais(meeting_id)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_cliente_portais_token ON eventos_cliente_portais(token)");
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tabela eventos_cliente_portais: ' . $e->getMessage());
    }

    // Módulo de arquivos do evento (campos solicitados + arquivos enviados).
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_arquivos_campos (
                id BIGSERIAL PRIMARY KEY,
                meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
                titulo VARCHAR(180) NOT NULL,
                descricao TEXT NULL,
                chave_sistema VARCHAR(80) NULL,
                interno_only BOOLEAN NOT NULL DEFAULT FALSE,
                obrigatorio_cliente BOOLEAN NOT NULL DEFAULT FALSE,
                ativo BOOLEAN NOT NULL DEFAULT TRUE,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_by_user_id INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMP NULL
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS meeting_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS titulo VARCHAR(180)");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS descricao TEXT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS chave_sistema VARCHAR(80) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS interno_only BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS obrigatorio_cliente BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS ativo BOOLEAN NOT NULL DEFAULT TRUE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS sort_order INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS created_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_campos ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_campos_meeting ON eventos_arquivos_campos(meeting_id, ativo)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_campos_sort ON eventos_arquivos_campos(meeting_id, sort_order, id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_campos_chave ON eventos_arquivos_campos(meeting_id, chave_sistema)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_campos_interno ON eventos_arquivos_campos(meeting_id, interno_only)");
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tabela eventos_arquivos_campos: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_arquivos_itens (
                id BIGSERIAL PRIMARY KEY,
                meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
                campo_id BIGINT NULL REFERENCES eventos_arquivos_campos(id) ON DELETE SET NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                size_bytes BIGINT NOT NULL DEFAULT 0,
                storage_key VARCHAR(500) NOT NULL,
                public_url TEXT NULL,
                descricao TEXT NULL,
                visivel_cliente BOOLEAN NOT NULL DEFAULT FALSE,
                uploaded_by_type VARCHAR(20) NOT NULL DEFAULT 'interno',
                uploaded_by_user_id INTEGER NULL,
                uploaded_at TIMESTAMP NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMP NULL,
                deleted_by_user_id INTEGER NULL
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS meeting_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS campo_id BIGINT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS original_name VARCHAR(255)");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS mime_type VARCHAR(120)");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS size_bytes BIGINT NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS storage_key VARCHAR(500)");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS public_url TEXT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS descricao TEXT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS visivel_cliente BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS uploaded_by_type VARCHAR(20) NOT NULL DEFAULT 'interno'");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS uploaded_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS uploaded_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_itens ADD COLUMN IF NOT EXISTS deleted_by_user_id INTEGER NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_itens_meeting ON eventos_arquivos_itens(meeting_id, deleted_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_itens_campo ON eventos_arquivos_itens(campo_id, deleted_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_itens_visivel_cliente ON eventos_arquivos_itens(meeting_id, visivel_cliente, deleted_at)");
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tabela eventos_arquivos_itens: ' . $e->getMessage());
    }

    // Log interno de anexos do módulo de arquivos.
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_arquivos_logs (
                id BIGSERIAL PRIMARY KEY,
                meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
                campo_id BIGINT NULL REFERENCES eventos_arquivos_campos(id) ON DELETE SET NULL,
                arquivo_id BIGINT NULL REFERENCES eventos_arquivos_itens(id) ON DELETE SET NULL,
                action_type VARCHAR(40) NOT NULL,
                action_note TEXT NULL,
                actor_type VARCHAR(20) NOT NULL DEFAULT 'interno',
                actor_user_id INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_logs ADD COLUMN IF NOT EXISTS meeting_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_logs ADD COLUMN IF NOT EXISTS campo_id BIGINT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_logs ADD COLUMN IF NOT EXISTS arquivo_id BIGINT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_logs ADD COLUMN IF NOT EXISTS action_type VARCHAR(40)");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_logs ADD COLUMN IF NOT EXISTS action_note TEXT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_logs ADD COLUMN IF NOT EXISTS actor_type VARCHAR(20) NOT NULL DEFAULT 'interno'");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_logs ADD COLUMN IF NOT EXISTS actor_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_arquivos_logs ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_logs_meeting ON eventos_arquivos_logs(meeting_id, created_at DESC, id DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_logs_campo ON eventos_arquivos_logs(campo_id, created_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_arquivos_logs_arquivo ON eventos_arquivos_logs(arquivo_id, created_at DESC)");
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tabela eventos_arquivos_logs: ' . $e->getMessage());
    }

    // Lista de convidados (configuração por evento + convidados).
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_convidados_config (
                id BIGSERIAL PRIMARY KEY,
                meeting_id BIGINT NOT NULL UNIQUE,
                tipo_evento VARCHAR(24) NOT NULL DEFAULT 'infantil',
                updated_by_type VARCHAR(20) NOT NULL DEFAULT 'interno',
                updated_by_user_id INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados_config ADD COLUMN IF NOT EXISTS meeting_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados_config ADD COLUMN IF NOT EXISTS tipo_evento VARCHAR(24) NOT NULL DEFAULT 'infantil'");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados_config ADD COLUMN IF NOT EXISTS updated_by_type VARCHAR(20) NOT NULL DEFAULT 'interno'");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados_config ADD COLUMN IF NOT EXISTS updated_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados_config ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados_config ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_convidados_config_meeting ON eventos_convidados_config(meeting_id)");
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tabela eventos_convidados_config: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_convidados (
                id BIGSERIAL PRIMARY KEY,
                meeting_id BIGINT NOT NULL,
                nome VARCHAR(180) NOT NULL,
                faixa_etaria VARCHAR(40) NULL,
                numero_mesa VARCHAR(20) NULL,
                checkin_at TIMESTAMP NULL,
                checkin_by_user_id INTEGER NULL,
                created_by_type VARCHAR(20) NOT NULL DEFAULT 'cliente',
                created_by_user_id INTEGER NULL,
                updated_by_user_id INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMP NULL
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS meeting_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS nome VARCHAR(180)");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS faixa_etaria VARCHAR(40) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS numero_mesa VARCHAR(20) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS checkin_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS checkin_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS created_by_type VARCHAR(20) NOT NULL DEFAULT 'cliente'");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS created_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS updated_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_convidados ADD COLUMN IF NOT EXISTS is_draft BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_convidados_meeting ON eventos_convidados(meeting_id, deleted_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_convidados_draft ON eventos_convidados(meeting_id, is_draft)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_convidados_mesa ON eventos_convidados(meeting_id, numero_mesa)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_convidados_nome ON eventos_convidados(meeting_id, lower(nome))");
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tabela eventos_convidados: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Categorias válidas para modelos de formulário.
 */
function eventos_form_template_allowed_categories(): array {
    return ['15anos', 'casamento', 'infantil', 'geral'];
}

/**
 * Tipos reais de evento suportados na organização.
 */
function eventos_reuniao_tipos_evento_real_allowed(): array {
    return ['casamento', '15anos', 'infantil'];
}

/**
 * Normaliza tipo real do evento.
 */
function eventos_reuniao_normalizar_tipo_evento_real(?string $tipo_evento_real): string {
    $tipo = strtolower(trim((string)$tipo_evento_real));
    if (in_array($tipo, eventos_reuniao_tipos_evento_real_allowed(), true)) {
        return $tipo;
    }
    return '';
}

/**
 * Rótulo amigável do tipo real do evento.
 */
function eventos_reuniao_tipo_evento_real_label(string $tipo_evento_real): string {
    $tipo = eventos_reuniao_normalizar_tipo_evento_real($tipo_evento_real);
    if ($tipo === 'casamento') {
        return 'Casamento';
    }
    if ($tipo === '15anos') {
        return '15 anos';
    }
    if ($tipo === 'infantil') {
        return 'Infantil';
    }
    return 'Não definido';
}

/**
 * Mapeia tipo real para o tipo usado na lista de convidados.
 */
function eventos_reuniao_tipo_real_para_convidados_tipo(string $tipo_evento_real): string {
    $tipo = eventos_reuniao_normalizar_tipo_evento_real($tipo_evento_real);
    return $tipo === 'infantil' ? 'infantil' : 'mesa';
}

/**
 * Schema padrão do formulário "protocolo 15 anos".
 */
function eventos_form_template_schema_protocolo_15anos(): array {
    return [
        ['type' => 'section', 'label' => 'ORGANIZAÇÃO 15 ANOS'],
        ['type' => 'note', 'label' => 'A seguir iremos fazer algumas perguntas importantes para realização do seu evento. Após o envio do formulário, sinalize nossa equipe.'],
        ['type' => 'note', 'label' => 'Evite usar abreviações e gírias. Quanto mais transparente, melhor.'],
        ['type' => 'note', 'label' => 'Importante: este formulário não deve ser alterado 15 dias antes do evento ou enviado posterior a isso.'],
        ['type' => 'divider', 'label' => '---'],

        ['type' => 'section', 'label' => 'Músicas'],
        ['type' => 'note', 'label' => 'Envie o link da música (YouTube) e o tempo de início. Exemplo: Valsa 0:20.'],
        ['type' => 'textarea', 'label' => 'Música da entrada da debutante para o cerimonial'],
        ['type' => 'textarea', 'label' => 'Vai ter sapato, anel e etc? Se sim, quais são as músicas desses momentos?'],
        ['type' => 'textarea', 'label' => 'Valsa. Se for ter mais de uma, descreva quem irá dançar e qual música.'],
        ['type' => 'textarea', 'label' => 'Irá ter mais algum momento especial no cerimonial? Cite o momento e a música.'],

        ['type' => 'section', 'label' => 'GOSTO MUSICAL / REPERTÓRIO'],
        ['type' => 'textarea', 'label' => 'Qual ritmo(s) tocar?'],
        ['type' => 'textarea', 'label' => 'Qual ritmo(s) não tocar?'],
        ['type' => 'textarea', 'label' => 'Alguma música em especial que não pode faltar?'],
        ['type' => 'textarea', 'label' => 'Cite 5 artistas/banda que mais gosta.'],
        ['type' => 'text', 'label' => 'Link da playlist (opcional).'],

        ['type' => 'section', 'label' => 'CRONOGRAMA'],
        ['type' => 'yesno', 'label' => 'Irá cantar o parabéns após o cerimonial?'],
        ['type' => 'note', 'label' => 'Recomendação: cantar após o cerimonial para aproveitar melhor a festa e manter maquiagem/cabelo em bom estado.'],
        ['type' => 'textarea', 'label' => 'Irá ter algum fornecedor externo? Se sim, cite NOME e FUNÇÃO.'],
        ['type' => 'textarea', 'label' => 'Vai levar algum item para o salão? (caderno, lembrancinha etc). Se sim, cite aqui.'],
        ['type' => 'textarea', 'label' => 'Se algum item tiver especificação de entrega aos convidados, descreva.'],
        ['type' => 'text', 'label' => 'Vai se arrumar no buffet? Se sim, qual horário vai chegar?'],

        ['type' => 'section', 'label' => 'INFORMAÇÕES IMPORTANTES (EVENTOS LISBON 1 - PARQUE DOS SINOS)'],
        ['type' => 'note', 'label' => 'Sobre organização das mesas: para eventos com mais de 60 pessoas, montamos mesas com 10 lugares cada.'],
        ['type' => 'yesno', 'label' => 'Vai abrir os brinquedos?'],
        ['type' => 'note', 'label' => 'Valores dos brinquedos: até 6 crianças R$ 100,00; a partir de 6 crianças R$ 200,00; acima de 14 crianças, a cada 10 acrescenta-se R$ 100,00 por monitor adicional.'],
    ];
}

/**
 * Garante que o template padrão "protocolo 15 anos" exista no banco atual.
 */
function eventos_form_template_seed_protocolo_15anos(PDO $pdo, int $user_id = 0, bool $force_update = false): array {
    eventos_reuniao_ensure_schema($pdo);

    $template_name = 'protocolo 15 anos';
    $template_category = '15anos';

    $stmt = $pdo->prepare("
        SELECT id
        FROM eventos_form_templates
        WHERE lower(nome) = lower(:nome)
          AND categoria = :categoria
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':nome' => $template_name,
        ':categoria' => $template_category,
    ]);
    $existing_id = (int)($stmt->fetchColumn() ?: 0);

    if ($existing_id > 0 && !$force_update) {
        return ['ok' => true, 'mode' => 'exists', 'template_id' => $existing_id];
    }

    $schema = eventos_form_template_schema_protocolo_15anos();
    return eventos_form_template_salvar(
        $pdo,
        $template_name,
        $template_category,
        $schema,
        $user_id,
        $existing_id > 0 ? $existing_id : null
    );
}

/**
 * Gera ID para campo de formulario.
 */
function eventos_form_template_field_id(string $prefix = 'f'): string {
    try {
        return $prefix . '_' . bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        return $prefix . '_' . str_replace('.', '', uniqid('', true));
    }
}

/**
 * Normaliza texto livre para parser de importacao.
 */
function eventos_form_template_normalizar_texto(string $text): string {
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $decoded);
    $decoded = preg_replace('/\s+/u', ' ', $decoded);
    return trim((string)$decoded);
}

/**
 * Lowercase com fallback quando mbstring nao estiver ativo.
 */
function eventos_form_template_lower(string $text): string {
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }
    return strtolower($text);
}

/**
 * Procura substring com fallback para ambientes sem mbstring.
 */
function eventos_form_template_contains(string $text, string $needle): bool {
    if (function_exists('mb_strpos')) {
        return mb_strpos($text, $needle) !== false;
    }
    return strpos($text, $needle) !== false;
}

/**
 * Detecta se uma linha parece pergunta de preenchimento.
 */
function eventos_form_template_is_question_like(string $text): bool {
    $text = eventos_form_template_normalizar_texto($text);
    if ($text === '') {
        return false;
    }
    $lower = eventos_form_template_lower($text);
    $non_question_prefixes = [
        'exemplo',
        'devendo ',
        'nossa recomend',
        'importante:',
        'lembrando',
        'valores:',
    ];
    foreach ($non_question_prefixes as $prefix) {
        if (str_starts_with($lower, $prefix)) {
            return false;
        }
    }

    $text_without_urls = preg_replace('/https?:\/\/\S+/i', '', $text);
    if (eventos_form_template_contains((string)$text_without_urls, '?')) {
        return true;
    }

    $starts = [
        'qual ',
        'quais ',
        'cite ',
        'descreva ',
        'nos envie ',
        'envie ',
        'vai ',
        'ira ',
        'irá ',
        'link da ',
        'link do ',
        'se ',
    ];
    foreach ($starts as $prefix) {
        if (str_starts_with($lower, $prefix)) {
            return true;
        }
    }
    return false;
}

/**
 * Detecta se o texto deve ser exibido como titulo de secao.
 */
function eventos_form_template_is_section_title(string $text, string $tag = 'p', bool $only_strong = false): bool {
    $text = eventos_form_template_normalizar_texto($text);
    if ($text === '' || eventos_form_template_contains($text, '?')) {
        return false;
    }

    $tag = strtolower(trim($tag));
    if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
        return true;
    }

    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    if ($only_strong && $len <= 140 && !eventos_form_template_is_question_like($text)) {
        return true;
    }

    $letters = preg_replace('/[^\pL]/u', '', $text);
    if ($letters === '') {
        return false;
    }
    $upper = preg_replace('/[^\p{Lu}]/u', '', $letters);
    $letters_len = function_exists('mb_strlen') ? mb_strlen($letters) : strlen($letters);
    if ($letters_len <= 0) {
        return false;
    }
    $upper_len = function_exists('mb_strlen') ? mb_strlen($upper) : strlen($upper);
    $ratio = $upper_len / $letters_len;
    return $ratio >= 0.62 && $len <= 140;
}

/**
 * Detecta texto de orientacao (nao preenchivel) para virar nota.
 */
function eventos_form_template_is_instruction_text(string $text): bool {
    $text = eventos_form_template_normalizar_texto($text);
    if ($text === '' || eventos_form_template_is_question_like($text)) {
        return false;
    }
    $lower = eventos_form_template_lower($text);
    if (str_starts_with($lower, 'exemplo')
        || str_starts_with($lower, 'importante')
        || str_starts_with($lower, 'lembrando')
        || str_starts_with($lower, 'nossa recomend')
        || str_starts_with($lower, 'a seguir iremos')
        || str_starts_with($lower, 'devendo ')
        || str_starts_with($lower, 'valores:')
    ) {
        return true;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    return $len >= 80;
}

/**
 * Define tipo de campo por heuristica.
 */
function eventos_form_template_guess_field_type(string $text, bool $yesno_hint = false): string {
    $lower = eventos_form_template_lower(eventos_form_template_normalizar_texto($text));
    if ($yesno_hint
        || str_contains($lower, 'marque com x')
        || str_contains($lower, '( ) sim')
        || str_contains($lower, '( ) nao')
        || str_contains($lower, 'irá cantar o parabéns')
        || str_contains($lower, 'ira cantar o parabens')
        || str_contains($lower, 'vai abrir os brinquedos')
    ) {
        return 'yesno';
    }

    if (str_contains($lower, 'link da playlist')
        || str_contains($lower, 'qual horário')
        || str_contains($lower, 'qual horario')
        || str_contains($lower, 'vai chegar')
    ) {
        return 'text';
    }

    return 'textarea';
}

/**
 * Parseia texto puro em blocos simples.
 */
function eventos_form_template_parse_text_blocks(string $source): array {
    $lines = preg_split('/\R/u', $source) ?: [];
    $blocks = [];
    foreach ($lines as $line) {
        $text = eventos_form_template_normalizar_texto((string)$line);
        if ($text === '') {
            continue;
        }
        if ($text === '---' || $text === '___') {
            $blocks[] = ['kind' => 'divider'];
            continue;
        }
        $yesno = (bool)(
            preg_match('/\bsim\b/iu', $text)
            && preg_match('/\bn[aã]o\b/iu', $text)
        );
        if ($yesno) {
            $blocks[] = [
                'kind' => 'table',
                'yesno' => true,
                'blank' => false,
                'text' => $text,
            ];
            continue;
        }
        $blocks[] = [
            'kind' => 'text',
            'tag' => 'p',
            'text' => $text,
            'only_strong' => false,
        ];
    }
    return $blocks;
}

/**
 * Parseia HTML em blocos sequenciais.
 */
function eventos_form_template_parse_html_blocks(string $source): array {
    if (!class_exists('DOMDocument')) {
        return [];
    }

    $dom = new DOMDocument();
    $prev_state = libxml_use_internal_errors(true);

    $flags = 0;
    if (defined('LIBXML_HTML_NODEFDTD')) {
        $flags |= LIBXML_HTML_NODEFDTD;
    }
    if (defined('LIBXML_HTML_NOIMPLIED')) {
        $flags |= LIBXML_HTML_NOIMPLIED;
    }

    $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $source . '</body></html>';
    $loaded = $dom->loadHTML($wrapped, $flags);
    libxml_clear_errors();
    libxml_use_internal_errors($prev_state);

    if (!$loaded) {
        return [];
    }

    $body_nodes = $dom->getElementsByTagName('body');
    $body = $body_nodes->item(0);
    if (!$body instanceof DOMElement) {
        return [];
    }

    $container = $body;
    $children = [];
    foreach ($body->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $children[] = $child;
        }
    }
    if (count($children) === 1 && strtolower($children[0]->tagName) === 'div') {
        $container = $children[0];
    }

    $blocks = [];
    foreach ($container->childNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($node->tagName);

        if ($tag === 'hr') {
            $blocks[] = ['kind' => 'divider'];
            continue;
        }

        if ($tag === 'table') {
            $table_text = eventos_form_template_normalizar_texto((string)$node->textContent);
            $has_yesno = (bool)(
                preg_match('/\bsim\b/iu', $table_text)
                && preg_match('/\bn[aã]o\b/iu', $table_text)
            );
            $is_blank = trim($table_text) === '';
            $blocks[] = [
                'kind' => 'table',
                'yesno' => $has_yesno,
                'blank' => $is_blank,
                'text' => $table_text,
            ];
            continue;
        }

        if (in_array($tag, ['ul', 'ol'], true)) {
            foreach ($node->getElementsByTagName('li') as $li) {
                $txt = eventos_form_template_normalizar_texto((string)$li->textContent);
                if ($txt === '') {
                    continue;
                }
                $blocks[] = [
                    'kind' => 'text',
                    'tag' => 'li',
                    'text' => $txt,
                    'only_strong' => false,
                ];
            }
            continue;
        }

        if (!in_array($tag, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'div'], true)) {
            continue;
        }

        $text = eventos_form_template_normalizar_texto((string)$node->textContent);
        if ($text === '') {
            continue;
        }

        $strong_parts = [];
        foreach (['strong', 'b'] as $st_tag) {
            foreach ($node->getElementsByTagName($st_tag) as $st_node) {
                $part = eventos_form_template_normalizar_texto((string)$st_node->textContent);
                if ($part !== '') {
                    $strong_parts[] = $part;
                }
            }
        }
        $strong_text = eventos_form_template_normalizar_texto(implode(' ', $strong_parts));
        $only_strong = ($strong_text !== '' && $strong_text === $text);

        $blocks[] = [
            'kind' => 'text',
            'tag' => $tag,
            'text' => $text,
            'only_strong' => $only_strong,
        ];
    }

    return $blocks;
}

/**
 * Converte blocos para schema dinamico.
 */
function eventos_form_template_blocks_to_schema(array $blocks, bool $incluir_notas = true): array {
    $schema = [];
    $last_question_index = null;

    $push = static function (
        array &$items,
        string $type,
        string $label,
        bool $required = false,
        array $options = []
    ): int {
        $normalized_label = eventos_form_template_normalizar_texto($label);
        if ($type !== 'divider' && $normalized_label === '') {
            return -1;
        }
        $items[] = [
            'id' => eventos_form_template_field_id($type === 'section' ? 's' : 'f'),
            'type' => $type,
            'label' => $type === 'divider' ? '---' : $normalized_label,
            'required' => $required && !in_array($type, ['section', 'divider', 'note'], true),
            'options' => $type === 'select' ? array_values(array_filter(array_map('trim', $options), static fn($v) => $v !== '')) : [],
        ];
        return count($items) - 1;
    };

    foreach ($blocks as $block) {
        $kind = strtolower(trim((string)($block['kind'] ?? 'text')));

        if ($kind === 'divider') {
            $push($schema, 'divider', '---');
            $last_question_index = null;
            continue;
        }

        if ($kind === 'table') {
            $table_text = eventos_form_template_normalizar_texto((string)($block['text'] ?? ''));
            $yesno = !empty($block['yesno']);
            $blank = !empty($block['blank']);

            if ($yesno) {
                $target_index = $last_question_index;
                if ($target_index === null) {
                    for ($i = count($schema) - 1; $i >= 0; $i--) {
                        $type = strtolower(trim((string)($schema[$i]['type'] ?? '')));
                        if (in_array($type, ['divider', 'section', 'note'], true)) {
                            continue;
                        }
                        $target_index = $i;
                        break;
                    }
                }

                if ($target_index !== null && isset($schema[$target_index])) {
                    $schema[$target_index]['type'] = 'yesno';
                    $schema[$target_index]['required'] = false;
                    $schema[$target_index]['options'] = [];
                } elseif ($incluir_notas && $table_text !== '') {
                    $push($schema, 'note', $table_text);
                }
            } elseif (!$blank && $incluir_notas && $table_text !== '') {
                $push($schema, 'note', $table_text);
            }
            $last_question_index = null;
            continue;
        }

        $text = eventos_form_template_normalizar_texto((string)($block['text'] ?? ''));
        if ($text === '') {
            continue;
        }

        $tag = strtolower(trim((string)($block['tag'] ?? 'p')));
        $only_strong = !empty($block['only_strong']);

        if (eventos_form_template_is_section_title($text, $tag, $only_strong)) {
            $push($schema, 'section', $text);
            $last_question_index = null;
            continue;
        }

        if (eventos_form_template_is_question_like($text)) {
            $field_type = eventos_form_template_guess_field_type($text, false);
            $last_question_index = $push($schema, $field_type, $text);
            continue;
        }

        if ($incluir_notas && eventos_form_template_is_instruction_text($text)) {
            $push($schema, 'note', $text);
        }
        $last_question_index = null;
    }

    return $schema;
}

/**
 * Gera schema automatico a partir de texto ou HTML.
 */
function eventos_form_template_gerar_schema_por_fonte(string $source, bool $incluir_notas = true): array {
    $source = trim($source);
    if ($source === '') {
        return [];
    }

    $blocks = [];
    if (preg_match('/<\s*[a-zA-Z][^>]*>/', $source)) {
        $blocks = eventos_form_template_parse_html_blocks($source);
    }
    if (empty($blocks)) {
        $blocks = eventos_form_template_parse_text_blocks($source);
    }

    $schema = eventos_form_template_blocks_to_schema($blocks, $incluir_notas);
    return eventos_form_template_normalizar_schema($schema);
}

/**
 * Normaliza schema de formulário para persistência.
 */
function eventos_form_template_normalizar_schema(array $schema): array {
    $allowed_types = ['text', 'textarea', 'yesno', 'select', 'file', 'section', 'divider', 'note'];
    $normalized = [];
    foreach ($schema as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = strtolower(trim((string)($item['type'] ?? 'text')));
        if (!in_array($type, $allowed_types, true)) {
            $type = 'text';
        }
        $label = trim((string)($item['label'] ?? ''));
        $required = !empty($item['required']) && !in_array($type, ['section', 'divider', 'note'], true);
        $options = [];
        if ($type === 'select' && !empty($item['options']) && is_array($item['options'])) {
            foreach ($item['options'] as $opt) {
                $text = trim((string)$opt);
                if ($text !== '') {
                    $options[] = $text;
                }
            }
        }

        $content_html = '';
        if ($type === 'note') {
            $raw_content_html = trim((string)($item['content_html'] ?? ''));
            if ($raw_content_html !== '') {
                // Remove apenas scripts diretos; demais tags ficam para formatação rica.
                $content_html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $raw_content_html) ?? '';
                $content_html = trim($content_html);
            }
        }

        if ($type !== 'divider' && $label === '' && !($type === 'note' && $content_html !== '')) {
            continue;
        }

        $id = trim((string)($item['id'] ?? ''));
        if ($id === '') {
            $id = eventos_form_template_field_id($type === 'section' ? 's' : 'f');
        }

        $normalized[] = [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'required' => $required,
            'options' => $options,
            'content_html' => $type === 'note' ? $content_html : '',
        ];
    }
    return $normalized;
}

/**
 * Verifica se schema possui ao menos um campo útil para preenchimento.
 */
function eventos_form_template_tem_campo_util(array $schema): bool {
    $fillable = ['text', 'textarea', 'yesno', 'select', 'file'];
    foreach ($schema as $field) {
        if (!is_array($field)) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? '')));
        $label = trim((string)($field['label'] ?? ''));
        if (in_array($type, $fillable, true) && $label !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Lista modelos salvos de formulário.
 */
function eventos_form_templates_listar(PDO $pdo): array {
    eventos_reuniao_ensure_schema($pdo);
    $stmt = $pdo->query("
        SELECT id, nome, categoria, schema_json, created_by_user_id, created_at, updated_at
        FROM eventos_form_templates
        WHERE ativo = TRUE
        ORDER BY updated_at DESC, id DESC
        LIMIT 200
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $decoded = json_decode((string)($row['schema_json'] ?? '[]'), true);
        $row['schema'] = is_array($decoded) ? $decoded : [];
    }
    unset($row);
    return $rows;
}

/**
 * Salva um modelo de formulário reutilizável.
 */
function eventos_form_template_salvar(
    PDO $pdo,
    string $nome,
    string $categoria,
    array $schema,
    int $user_id,
    ?int $template_id = null
): array {
    eventos_reuniao_ensure_schema($pdo);

    $nome = trim($nome);
    $categoria = trim($categoria) !== '' ? trim($categoria) : 'geral';
    $nome_length = function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome);
    if ($nome_length < 3) {
        return ['ok' => false, 'error' => 'Nome do modelo deve ter ao menos 3 caracteres'];
    }
    if (!in_array($categoria, eventos_form_template_allowed_categories(), true)) {
        return ['ok' => false, 'error' => 'Categoria do modelo inválida'];
    }

    $schema_normalized = eventos_form_template_normalizar_schema($schema);
    if (empty($schema_normalized) || !eventos_form_template_tem_campo_util($schema_normalized)) {
        return ['ok' => false, 'error' => 'Adicione ao menos um campo preenchível antes de salvar o modelo'];
    }

    $schemaJson = json_encode($schema_normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($schemaJson === false) {
        return ['ok' => false, 'error' => 'Não foi possível serializar o modelo'];
    }

    if ($template_id !== null && $template_id > 0) {
        $stmt = $pdo->prepare("
            UPDATE eventos_form_templates
            SET nome = :nome,
                categoria = :categoria,
                schema_json = CAST(:schema_json AS jsonb),
                ativo = TRUE,
                updated_at = NOW()
            WHERE id = :id
            RETURNING id, nome, categoria, schema_json, created_by_user_id, created_at, updated_at
        ");
        $stmt->execute([
            ':id' => $template_id,
            ':nome' => $nome,
            ':categoria' => $categoria,
            ':schema_json' => $schemaJson,
        ]);
        $mode = 'updated';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO eventos_form_templates
            (nome, categoria, schema_json, ativo, created_by_user_id, created_at, updated_at)
            VALUES
            (:nome, :categoria, CAST(:schema_json AS jsonb), TRUE, :user_id, NOW(), NOW())
            RETURNING id, nome, categoria, schema_json, created_by_user_id, created_at, updated_at
        ");
        $stmt->execute([
            ':nome' => $nome,
            ':categoria' => $categoria,
            ':schema_json' => $schemaJson,
            ':user_id' => $user_id > 0 ? $user_id : null,
        ]);
        $mode = 'created';
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return ['ok' => false, 'error' => 'Falha ao salvar modelo'];
    }

    $decoded = json_decode((string)($row['schema_json'] ?? '[]'), true);
    $row['schema'] = is_array($decoded) ? $decoded : [];
    return ['ok' => true, 'mode' => $mode, 'template' => $row];
}

/**
 * Arquiva modelo de formulário (soft delete).
 */
function eventos_form_template_arquivar(PDO $pdo, int $template_id): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($template_id <= 0) {
        return ['ok' => false, 'error' => 'Modelo inválido'];
    }

    $stmt = $pdo->prepare("
        UPDATE eventos_form_templates
        SET ativo = FALSE, updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $template_id]);
    if ($stmt->rowCount() <= 0) {
        return ['ok' => false, 'error' => 'Modelo não encontrado'];
    }
    return ['ok' => true];
}

eventos_reuniao_ensure_schema($pdo);

/**
 * Buscar ou criar reunião para um evento ME
 */
function eventos_reuniao_get_or_create(PDO $pdo, int $me_event_id, int $user_id, ?string $tipo_evento_real = null): array {
    $tipo_evento_real_norm = eventos_reuniao_normalizar_tipo_evento_real($tipo_evento_real);
    $has_tipo_evento_real_col = eventos_reuniao_has_column($pdo, 'eventos_reunioes', 'tipo_evento_real');

    // Verificar se já existe
    $stmt = $pdo->prepare("
        SELECT * FROM eventos_reunioes WHERE me_event_id = :me_event_id
    ");
    $stmt->execute([':me_event_id' => $me_event_id]);
    $reuniao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reuniao) {
        $snapshot_raw = (string)($reuniao['me_event_snapshot'] ?? '');
        $snapshot = json_decode($snapshot_raw, true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $faltando_local = trim((string)($snapshot['local'] ?? '')) === '';
        $faltando_cliente = trim((string)($snapshot['cliente']['nome'] ?? '')) === '';
        $faltando_hora = trim((string)($snapshot['hora_inicio'] ?? '')) === '';

        if ($faltando_local || $faltando_cliente || $faltando_hora) {
            $event_result = eventos_me_buscar_por_id($pdo, $me_event_id);
            if (!empty($event_result['ok']) && !empty($event_result['event']) && is_array($event_result['event'])) {
                $snapshot_novo = eventos_me_criar_snapshot($event_result['event']);
                $snapshot_ajustado = $snapshot;
                $atualizado = false;

                foreach (['id', 'nome', 'data', 'hora_inicio', 'hora_fim', 'local', 'unidade', 'tipo_evento'] as $campo) {
                    $atual = trim((string)($snapshot_ajustado[$campo] ?? ''));
                    $novo = trim((string)($snapshot_novo[$campo] ?? ''));
                    if ($atual === '' && $novo !== '') {
                        $snapshot_ajustado[$campo] = $snapshot_novo[$campo];
                        $atualizado = true;
                    }
                }

                $convidados_atual = (int)($snapshot_ajustado['convidados'] ?? 0);
                $convidados_novo = (int)($snapshot_novo['convidados'] ?? 0);
                if ($convidados_atual <= 0 && $convidados_novo > 0) {
                    $snapshot_ajustado['convidados'] = $convidados_novo;
                    $atualizado = true;
                }

                if (!isset($snapshot_ajustado['cliente']) || !is_array($snapshot_ajustado['cliente'])) {
                    $snapshot_ajustado['cliente'] = [];
                    $atualizado = true;
                }

                foreach (['id', 'nome', 'email', 'telefone'] as $campo_cliente) {
                    if ($campo_cliente === 'id') {
                        $id_atual = (int)($snapshot_ajustado['cliente']['id'] ?? 0);
                        $id_novo = (int)($snapshot_novo['cliente']['id'] ?? 0);
                        if ($id_atual <= 0 && $id_novo > 0) {
                            $snapshot_ajustado['cliente']['id'] = $id_novo;
                            $atualizado = true;
                        }
                        continue;
                    }

                    $atual = trim((string)($snapshot_ajustado['cliente'][$campo_cliente] ?? ''));
                    $novo = trim((string)($snapshot_novo['cliente'][$campo_cliente] ?? ''));
                    if ($atual === '' && $novo !== '') {
                        $snapshot_ajustado['cliente'][$campo_cliente] = $snapshot_novo['cliente'][$campo_cliente];
                        $atualizado = true;
                    }
                }

                if ($atualizado) {
                    $snapshot_ajustado['snapshot_at'] = date('Y-m-d H:i:s');

                    $stmt_update = $pdo->prepare("
                        UPDATE eventos_reunioes
                        SET me_event_snapshot = :snapshot, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt_update->execute([
                        ':snapshot' => json_encode($snapshot_ajustado, JSON_UNESCAPED_UNICODE),
                        ':id' => (int)$reuniao['id'],
                    ]);

                    $stmt_refresh = $pdo->prepare("SELECT * FROM eventos_reunioes WHERE id = :id");
                    $stmt_refresh->execute([':id' => (int)$reuniao['id']]);
                    $reuniao = $stmt_refresh->fetch(PDO::FETCH_ASSOC) ?: $reuniao;
                }
            }
        }

        if ($tipo_evento_real_norm !== '' && $has_tipo_evento_real_col) {
            $tipo_atual = eventos_reuniao_normalizar_tipo_evento_real((string)($reuniao['tipo_evento_real'] ?? ''));
            if ($tipo_atual !== $tipo_evento_real_norm) {
                $snapshot_update = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
                if (!is_array($snapshot_update)) {
                    $snapshot_update = [];
                }
                $snapshot_update['tipo_evento_real'] = $tipo_evento_real_norm;
                $snapshot_update['snapshot_at'] = date('Y-m-d H:i:s');

                $stmt_tipo = $pdo->prepare("
                    UPDATE eventos_reunioes
                    SET tipo_evento_real = :tipo_evento_real,
                        me_event_snapshot = :snapshot,
                        updated_at = NOW()
                    WHERE id = :id
                    RETURNING *
                ");
                $stmt_tipo->execute([
                    ':tipo_evento_real' => $tipo_evento_real_norm,
                    ':snapshot' => json_encode($snapshot_update, JSON_UNESCAPED_UNICODE),
                    ':id' => (int)$reuniao['id'],
                ]);
                $reuniao = $stmt_tipo->fetch(PDO::FETCH_ASSOC) ?: $reuniao;
            }

            eventos_convidados_salvar_config(
                $pdo,
                (int)$reuniao['id'],
                eventos_reuniao_tipo_real_para_convidados_tipo($tipo_evento_real_norm),
                'interno',
                $user_id
            );
        }

        return ['ok' => true, 'reuniao' => $reuniao, 'created' => false];
    }
    
    // Buscar dados do evento na ME para snapshot
    $event_result = eventos_me_buscar_por_id($pdo, $me_event_id);
    if (!$event_result['ok']) {
        return ['ok' => false, 'error' => 'Evento não encontrado na ME: ' . ($event_result['error'] ?? '')];
    }
    
    $snapshot = eventos_me_criar_snapshot($event_result['event']);
    if ($tipo_evento_real_norm !== '') {
        $snapshot['tipo_evento_real'] = $tipo_evento_real_norm;
    }
    
    // Criar reunião
    $insert_cols = ['me_event_id', 'me_event_snapshot', 'status', 'created_by', 'created_at', 'updated_at'];
    $insert_vals = [':me_event_id', ':snapshot', "'rascunho'", ':user_id', 'NOW()', 'NOW()'];
    $insert_params = [
        ':me_event_id' => $me_event_id,
        ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ':user_id' => $user_id
    ];
    if ($has_tipo_evento_real_col) {
        $insert_cols[] = 'tipo_evento_real';
        $insert_vals[] = ':tipo_evento_real';
        $insert_params[':tipo_evento_real'] = $tipo_evento_real_norm !== '' ? $tipo_evento_real_norm : null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO eventos_reunioes (" . implode(', ', $insert_cols) . ")
        VALUES (" . implode(', ', $insert_vals) . ")
        RETURNING *
    ");
    $stmt->execute($insert_params);
    $reuniao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Criar seções vazias
    $sections = ['decoracao', 'observacoes_gerais', 'dj_protocolo'];
    foreach ($sections as $section) {
        $stmt = $pdo->prepare("
            INSERT INTO eventos_reunioes_secoes (meeting_id, section, content_html, content_text, created_at, updated_at)
            VALUES (:meeting_id, :section, '', '', NOW(), NOW())
            ON CONFLICT (meeting_id, section) DO NOTHING
        ");
        $stmt->execute([':meeting_id' => $reuniao['id'], ':section' => $section]);
    }

    if ($tipo_evento_real_norm !== '') {
        eventos_convidados_salvar_config(
            $pdo,
            (int)$reuniao['id'],
            eventos_reuniao_tipo_real_para_convidados_tipo($tipo_evento_real_norm),
            'interno',
            $user_id
        );
    }
    
    return ['ok' => true, 'reuniao' => $reuniao, 'created' => true];
}

/**
 * Buscar reunião por ID
 */
function eventos_reuniao_get(PDO $pdo, int $meeting_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM eventos_reunioes WHERE id = :id");
    $stmt->execute([':id' => $meeting_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Atualiza o tipo real do evento definido manualmente na organização.
 */
function eventos_reuniao_atualizar_tipo_evento_real(PDO $pdo, int $meeting_id, string $tipo_evento_real, int $user_id = 0): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $tipo = eventos_reuniao_normalizar_tipo_evento_real($tipo_evento_real);
    if ($tipo === '') {
        return ['ok' => false, 'error' => 'Tipo de evento inválido'];
    }

    $has_tipo_evento_real_col = eventos_reuniao_has_column($pdo, 'eventos_reunioes', 'tipo_evento_real');
    if (!$has_tipo_evento_real_col) {
        return ['ok' => false, 'error' => 'Campo tipo_evento_real indisponível neste ambiente'];
    }

    $reuniao = eventos_reuniao_get($pdo, $meeting_id);
    if (!$reuniao) {
        return ['ok' => false, 'error' => 'Reunião não encontrada'];
    }

    $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    $snapshot['tipo_evento_real'] = $tipo;
    $snapshot['snapshot_at'] = date('Y-m-d H:i:s');

    try {
        $stmt = $pdo->prepare("
            UPDATE eventos_reunioes
            SET tipo_evento_real = :tipo_evento_real,
                me_event_snapshot = :snapshot,
                updated_at = NOW()
            WHERE id = :id
            RETURNING *
        ");
        $stmt->execute([
            ':tipo_evento_real' => $tipo,
            ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ':id' => $meeting_id,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Não foi possível atualizar o tipo do evento'];
        }

        // Sincroniza tipo da lista de convidados para evitar seleção manual pelo cliente.
        $cfg = eventos_convidados_salvar_config(
            $pdo,
            $meeting_id,
            eventos_reuniao_tipo_real_para_convidados_tipo($tipo),
            'interno',
            $user_id
        );
        if (empty($cfg['ok'])) {
            error_log('eventos_reuniao_atualizar_tipo_evento_real: falha ao sincronizar convidados: ' . ($cfg['error'] ?? 'erro desconhecido'));
        }

        return ['ok' => true, 'reuniao' => $row];
    } catch (Throwable $e) {
        error_log('eventos_reuniao_atualizar_tipo_evento_real: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao atualizar tipo do evento'];
    }
}

/**
 * Listar reuniões com filtros
 */
function eventos_reuniao_listar(PDO $pdo, array $filters = []): array {
    $where = [];
    $params = [];
    
    if (!empty($filters['status'])) {
        $where[] = "status = :status";
        $params[':status'] = $filters['status'];
    }
    
    if (!empty($filters['search'])) {
        $where[] = "(me_event_snapshot->>'nome' ILIKE :search OR me_event_snapshot->'cliente'->>'nome' ILIKE :search)";
        $params[':search'] = '%' . $filters['search'] . '%';
    }
    
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $stmt = $pdo->prepare("
        SELECT * FROM eventos_reunioes 
        {$where_sql}
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Buscar seção de uma reunião
 */
function eventos_reuniao_get_secao(PDO $pdo, int $meeting_id, string $section): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM eventos_reunioes_secoes 
        WHERE meeting_id = :meeting_id AND section = :section
    ");
    $stmt->execute([':meeting_id' => $meeting_id, ':section' => $section]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Salvar conteúdo de uma seção (com versionamento)
 */
function eventos_reuniao_salvar_secao(
    PDO $pdo, 
    int $meeting_id, 
    string $section, 
    string $content_html, 
    int $user_id,
    string $note = '',
    string $author_type = 'interno',
    ?string $form_schema_json = null
): array {
    try {
        $prev_html = '';
        $prev_schema = null;

        $pdo->beginTransaction();
        
        // Buscar seção atual
        $secao = eventos_reuniao_get_secao($pdo, $meeting_id, $section);
        if ($secao) {
            $prev_html = (string)($secao['content_html'] ?? '');
            if (array_key_exists('form_schema_json', $secao)) {
                $prev_schema = (string)($secao['form_schema_json'] ?? '');
            }
        }
        
        if (!$secao) {
            // Criar seção se não existir
            $params = [
                ':meeting_id' => $meeting_id,
                ':section' => $section,
                ':html' => $content_html,
                ':text' => strip_tags($content_html),
                ':user_id' => $user_id,
            ];
            if ($form_schema_json !== null && eventos_reuniao_has_column($pdo, 'eventos_reunioes_secoes', 'form_schema_json')) {
                $stmt = $pdo->prepare("
                    INSERT INTO eventos_reunioes_secoes
                    (meeting_id, section, content_html, content_text, form_schema_json, created_at, updated_at, updated_by)
                    VALUES (:meeting_id, :section, :html, :text, CAST(:form_schema_json AS jsonb), NOW(), NOW(), :user_id)
                    RETURNING *
                ");
                $params[':form_schema_json'] = $form_schema_json;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO eventos_reunioes_secoes
                    (meeting_id, section, content_html, content_text, created_at, updated_at, updated_by)
                    VALUES (:meeting_id, :section, :html, :text, NOW(), NOW(), :user_id)
                    RETURNING *
                ");
            }
            $stmt->execute($params);
            $secao = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Atualizar seção
            $sql = "
                UPDATE eventos_reunioes_secoes
                SET content_html = :html, content_text = :text, updated_at = NOW(), updated_by = :user_id
            ";
            $params = [
                ':id' => $secao['id'],
                ':html' => $content_html,
                ':text' => strip_tags($content_html),
                ':user_id' => $user_id,
            ];
            if ($form_schema_json !== null && eventos_reuniao_has_column($pdo, 'eventos_reunioes_secoes', 'form_schema_json')) {
                $sql .= ", form_schema_json = CAST(:form_schema_json AS jsonb)";
                $params[':form_schema_json'] = $form_schema_json;
            }
            $sql .= " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        
        // Buscar próximo número de versão
        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(version_number), 0) + 1 as next_version
            FROM eventos_reunioes_versoes 
            WHERE meeting_id = :meeting_id AND section = :section
        ");
        $stmt->execute([':meeting_id' => $meeting_id, ':section' => $section]);
        $next = (int)$stmt->fetchColumn();
        
        // Desmarcar versões anteriores como não ativas
        $stmt = $pdo->prepare("
            UPDATE eventos_reunioes_versoes 
            SET is_active = FALSE 
            WHERE meeting_id = :meeting_id AND section = :section
        ");
        $stmt->execute([':meeting_id' => $meeting_id, ':section' => $section]);
        
        // Criar nova versão
        $stmt = $pdo->prepare("
            INSERT INTO eventos_reunioes_versoes 
            (meeting_id, section, version_number, content_html, created_by_user_id, created_by_type, created_at, note, is_active)
            VALUES (:meeting_id, :section, :version, :html, :user_id, :author_type, NOW(), :note, TRUE)
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':section' => $section,
            ':version' => $next,
            ':html' => $content_html,
            ':user_id' => $author_type === 'interno' ? $user_id : null,
            ':author_type' => $author_type,
            ':note' => $note ?: 'Edição manual'
        ]);
        
        // Atualizar timestamp da reunião
        $stmt = $pdo->prepare("
            UPDATE eventos_reunioes SET updated_at = NOW(), updated_by = :user_id WHERE id = :id
        ");
        $stmt->execute([':id' => $meeting_id, ':user_id' => $user_id]);
        
        $pdo->commit();

        // Notificações (fora da transação)
        try {
            $changed_html = trim($prev_html) !== trim($content_html);
            $changed_schema = false;
            if ($form_schema_json !== null && $prev_schema !== null) {
                $changed_schema = trim($prev_schema) !== trim($form_schema_json);
            } elseif ($form_schema_json !== null && $prev_schema === null) {
                // Se não tínhamos schema antes, qualquer schema enviado é uma mudança.
                $changed_schema = trim($form_schema_json) !== '';
            }

            $should_notify = ($changed_html || $changed_schema)
                && $section === 'decoracao'
                && in_array($author_type, ['interno', 'fornecedor'], true);

            if ($should_notify && function_exists('eventos_notificar_decoracao_atualizada')) {
                eventos_notificar_decoracao_atualizada($pdo, $meeting_id);
            }
        } catch (Throwable $e) {
            error_log("eventos_reuniao_salvar_secao: falha ao notificar decoração: " . $e->getMessage());
        }
        
        return ['ok' => true, 'version' => $next];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Buscar histórico de versões de uma seção
 */
function eventos_reuniao_get_versoes(PDO $pdo, int $meeting_id, string $section, int $limit = 20): array {
    $stmt = $pdo->prepare("
        SELECT v.*, u.nome as autor_nome
        FROM eventos_reunioes_versoes v
        LEFT JOIN usuarios u ON u.id = v.created_by_user_id
        WHERE v.meeting_id = :meeting_id AND v.section = :section
        ORDER BY v.version_number DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':meeting_id', $meeting_id, PDO::PARAM_INT);
    $stmt->bindValue(':section', $section, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Restaurar uma versão
 */
function eventos_reuniao_restaurar_versao(PDO $pdo, int $version_id, int $user_id): array {
    // Buscar versão
    $stmt = $pdo->prepare("SELECT * FROM eventos_reunioes_versoes WHERE id = :id");
    $stmt->execute([':id' => $version_id]);
    $versao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$versao) {
        return ['ok' => false, 'error' => 'Versão não encontrada'];
    }
    
    // Salvar como nova versão com nota
    return eventos_reuniao_salvar_secao(
        $pdo,
        $versao['meeting_id'],
        $versao['section'],
        $versao['content_html'],
        $user_id,
        "Restaurada da versão #{$versao['version_number']}"
    );
}

/**
 * Travar seção (DJ após envio do cliente)
 */
function eventos_reuniao_travar_secao(PDO $pdo, int $meeting_id, string $section, int $user_id): bool {
    $stmt = $pdo->prepare("
        UPDATE eventos_reunioes_secoes 
        SET is_locked = TRUE, locked_at = NOW(), locked_by = :user_id
        WHERE meeting_id = :meeting_id AND section = :section
    ");
    return $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':section' => $section,
        ':user_id' => $user_id
    ]);
}

/**
 * Destravar seção
 */
function eventos_reuniao_destravar_secao(PDO $pdo, int $meeting_id, string $section, int $user_id): array {
    $stmt = $pdo->prepare("
        UPDATE eventos_reunioes_secoes 
        SET is_locked = FALSE, locked_at = NULL, locked_by = NULL, updated_at = NOW(), updated_by = :user_id
        WHERE meeting_id = :meeting_id AND section = :section
    ");
    $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':section' => $section,
        ':user_id' => $user_id
    ]);
    
    // Criar versão com nota
    $secao = eventos_reuniao_get_secao($pdo, $meeting_id, $section);
    if ($secao) {
        eventos_reuniao_salvar_secao(
            $pdo,
            $meeting_id,
            $section,
            $secao['content_html'],
            $user_id,
            'Seção destravada por funcionário'
        );
    }

    if ($section === 'dj_protocolo') {
        eventos_reuniao_reativar_links_cliente_dj($pdo, $meeting_id);
    }
    
    return ['ok' => true];
}

/**
 * Reativa links de cliente DJ ao destravar seção.
 * Mantém apenas o link mais recente ativo por slot.
 */
function eventos_reuniao_reativar_links_cliente_dj(PDO $pdo, int $meeting_id): bool {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return false;
    }

    $has_slot_index_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index');
    $has_submitted_at_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at');

    if ($has_slot_index_col) {
        $sql = "
            WITH latest AS (
                SELECT DISTINCT ON (COALESCE(slot_index, 1)) id
                FROM eventos_links_publicos
                WHERE meeting_id = :meeting_id AND link_type = 'cliente_dj'
                ORDER BY COALESCE(slot_index, 1), id DESC
            )
            UPDATE eventos_links_publicos lp
            SET is_active = CASE WHEN lp.id IN (SELECT id FROM latest) THEN TRUE ELSE FALSE END
        ";
        if ($has_submitted_at_col) {
            $sql .= ",
                submitted_at = CASE
                    WHEN lp.id IN (SELECT id FROM latest) THEN NULL
                    ELSE lp.submitted_at
                END
            ";
        }
        $sql .= "
            WHERE lp.meeting_id = :meeting_id
              AND lp.link_type = 'cliente_dj'
        ";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([':meeting_id' => $meeting_id]);
    }

    $stmtLatest = $pdo->prepare("
        SELECT id
        FROM eventos_links_publicos
        WHERE meeting_id = :meeting_id AND link_type = 'cliente_dj'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtLatest->execute([':meeting_id' => $meeting_id]);
    $latest_id = (int)$stmtLatest->fetchColumn();
    if ($latest_id <= 0) {
        return true;
    }

    $sql = "
        UPDATE eventos_links_publicos
        SET is_active = CASE WHEN id = :latest_id THEN TRUE ELSE FALSE END
    ";
    if ($has_submitted_at_col) {
        $sql .= ",
            submitted_at = CASE
                WHEN id = :latest_id THEN NULL
                ELSE submitted_at
            END
        ";
    }
    $sql .= "
        WHERE meeting_id = :meeting_id
          AND link_type = 'cliente_dj'
    ";

    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':latest_id' => $latest_id
    ]);
}

/**
 * Destrava um quadro (slot) de link público do cliente.
 * Mantém o mesmo token; apenas limpa submitted_at.
 */
function eventos_reuniao_destravar_slot_cliente(
    PDO $pdo,
    int $meeting_id,
    int $slot_index,
    int $user_id,
    string $link_type = 'cliente_dj',
    ?string $unlock_section = null
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $link_type = trim((string)$link_type);
    if ($link_type === '') {
        $link_type = 'cliente_dj';
    }
    $slot_index = max(1, min(50, (int)$slot_index));
    $has_slot_index_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index');
    $has_submitted_at_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at');

    if ($has_slot_index_col) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = :link_type
              AND COALESCE(slot_index, 1) = :slot_index
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':link_type' => $link_type,
            ':slot_index' => $slot_index
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id
            FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = :link_type
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':link_type' => $link_type
        ]);
    }

    $link_id = (int)$stmt->fetchColumn();
    if ($link_id <= 0) {
        return ['ok' => false, 'error' => 'Link do cliente não encontrado para este quadro'];
    }

    $set = "is_active = TRUE";
    if ($has_submitted_at_col) {
        $set .= ", submitted_at = NULL";
    }
    $stmt = $pdo->prepare("UPDATE eventos_links_publicos SET {$set} WHERE id = :id");
    $stmt->execute([':id' => $link_id]);

    if ($unlock_section !== null && trim($unlock_section) !== '') {
        $stmt = $pdo->prepare("
            UPDATE eventos_reunioes_secoes
            SET is_locked = FALSE, locked_at = NULL, locked_by = NULL, updated_at = NOW(), updated_by = :user_id
            WHERE meeting_id = :meeting_id AND section = :section
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':user_id' => $user_id,
            ':section' => $unlock_section
        ]);
    }

    return ['ok' => true, 'link_id' => $link_id, 'slot_index' => $slot_index];
}

/**
 * Destrava um quadro (slot) do formulário DJ para permitir nova edição do cliente.
 * Mantém o mesmo token; apenas limpa submitted_at.
 */
function eventos_reuniao_destravar_dj_slot(PDO $pdo, int $meeting_id, int $slot_index, int $user_id): array {
    return eventos_reuniao_destravar_slot_cliente(
        $pdo,
        $meeting_id,
        $slot_index,
        $user_id,
        'cliente_dj',
        'dj_protocolo'
    );
}

/**
 * Exclui um quadro (slot) de link público do cliente.
 * Regra de segurança: não exclui quadro que já recebeu envio do cliente.
 */
function eventos_reuniao_excluir_slot_cliente(
    PDO $pdo,
    int $meeting_id,
    int $slot_index,
    int $user_id,
    string $link_type = 'cliente_dj'
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $link_type = trim((string)$link_type);
    if ($link_type === '') {
        $link_type = 'cliente_dj';
    }
    $slot_index = max(1, min(50, (int)$slot_index));
    $has_slot_index_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index');
    $has_submitted_at_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at');

    $where_sql = "meeting_id = :meeting_id AND link_type = :link_type";
    $params = [
        ':meeting_id' => $meeting_id,
        ':link_type' => $link_type
    ];

    if ($has_slot_index_col) {
        $where_sql .= " AND COALESCE(slot_index, 1) = :slot_index";
        $params[':slot_index'] = $slot_index;
    } elseif ($slot_index !== 1) {
        return ['ok' => false, 'error' => 'Este ambiente suporta apenas quadro 1'];
    }

    if ($has_submitted_at_col) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM eventos_links_publicos
            WHERE {$where_sql}
              AND submitted_at IS NOT NULL
        ");
        $stmt->execute($params);
        $submitted_count = (int)$stmt->fetchColumn();
        if ($submitted_count > 0) {
            return ['ok' => false, 'error' => 'Este quadro já foi enviado pelo cliente e não pode ser excluído.'];
        }
    }

    $stmt = $pdo->prepare("
        UPDATE eventos_links_publicos
        SET is_active = FALSE
        WHERE {$where_sql}
          AND is_active = TRUE
    ");
    $stmt->execute($params);

    return [
        'ok' => true,
        'slot_index' => $slot_index,
        'removed_links' => (int)$stmt->rowCount(),
        'updated_by' => $user_id,
    ];
}

/**
 * Exclui um quadro (slot) DJ da reunião.
 * Regra de segurança: não exclui quadro que já recebeu envio do cliente.
 */
function eventos_reuniao_excluir_dj_slot(PDO $pdo, int $meeting_id, int $slot_index, int $user_id): array {
    return eventos_reuniao_excluir_slot_cliente($pdo, $meeting_id, $slot_index, $user_id, 'cliente_dj');
}

/**
 * Excluir reunião (e dados relacionados: seções, versões, anexos, links)
 */
function eventos_reuniao_excluir(PDO $pdo, int $meeting_id): bool {
    try {
        $pdo->beginTransaction();
        // Ordem: links, anexos, versões, seções, reunião
        foreach (['eventos_links_publicos', 'eventos_reunioes_anexos', 'eventos_reunioes_versoes', 'eventos_reunioes_secoes'] as $tabela) {
            $stmt = $pdo->prepare("DELETE FROM {$tabela} WHERE meeting_id = :id");
            $stmt->execute([':id' => $meeting_id]);
        }
        $stmt = $pdo->prepare("DELETE FROM eventos_reunioes WHERE id = :id");
        $stmt->execute([':id' => $meeting_id]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("eventos_reuniao_excluir: " . $e->getMessage());
        return false;
    }
}

/**
 * Atualizar status da reunião
 */
function eventos_reuniao_atualizar_status(PDO $pdo, int $meeting_id, string $status, int $user_id): bool {
    $prev = null;
    try {
        $stmt_prev = $pdo->prepare("SELECT status FROM eventos_reunioes WHERE id = :id");
        $stmt_prev->execute([':id' => $meeting_id]);
        $prev = (string)($stmt_prev->fetchColumn() ?: '');
    } catch (Throwable $e) {
        // Se falhar, ainda tenta atualizar.
        $prev = null;
    }

    $stmt = $pdo->prepare("
        UPDATE eventos_reunioes
        SET status = :status, updated_at = NOW(), updated_by = :user_id
        WHERE id = :id
    ");
    $ok = $stmt->execute([
        ':id' => $meeting_id,
        ':status' => $status,
        ':user_id' => $user_id
    ]);

    // Notifica decoração quando marcada como concluída (transição).
    try {
        if ($ok && $prev !== null && $prev !== $status && $status === 'concluida' && function_exists('eventos_notificar_decoracao_reuniao_concluida')) {
            eventos_notificar_decoracao_reuniao_concluida($pdo, $meeting_id);
        }
    } catch (Throwable $e) {
        error_log("eventos_reuniao_atualizar_status: falha ao notificar decoração concluída: " . $e->getMessage());
    }

    return $ok;
}

/**
 * Buscar anexos de uma seção
 */
function eventos_reuniao_get_anexos(PDO $pdo, int $meeting_id, string $section): array {
    $stmt = $pdo->prepare("
        SELECT * FROM eventos_reunioes_anexos 
        WHERE meeting_id = :meeting_id AND section = :section AND deleted_at IS NULL
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute([':meeting_id' => $meeting_id, ':section' => $section]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Classificar tipo do arquivo a partir do MIME.
 */
function eventos_reuniao_file_kind_from_mime(string $mime_type): string {
    $mime = strtolower(trim($mime_type));
    if (strpos($mime, 'image/') === 0) return 'imagem';
    if (strpos($mime, 'audio/') === 0) return 'audio';
    if (strpos($mime, 'video/') === 0) return 'video';
    if ($mime === 'application/pdf') return 'pdf';
    return 'outros';
}

/**
 * Salvar metadados de anexo no banco.
 */
function eventos_reuniao_salvar_anexo(
    PDO $pdo,
    int $meeting_id,
    string $section,
    array $upload_result,
    string $uploaded_by_type = 'interno',
    ?int $uploaded_by_user_id = null,
    ?string $note = null
): array {
    $original_name = trim((string)($upload_result['nome_original'] ?? 'arquivo'));
    $mime_type = trim((string)($upload_result['mime_type'] ?? 'application/octet-stream'));
    $size_bytes = (int)($upload_result['tamanho_bytes'] ?? 0);
    $storage_key = trim((string)($upload_result['chave_storage'] ?? ''));
    $public_url = trim((string)($upload_result['url'] ?? ''));

    if ($storage_key === '') {
        return ['ok' => false, 'error' => 'storage_key inválido'];
    }

    $file_kind = eventos_reuniao_file_kind_from_mime($mime_type);

    $has_note_col = eventos_reuniao_has_column($pdo, 'eventos_reunioes_anexos', 'note');
    $sql = "
        INSERT INTO eventos_reunioes_anexos
        (meeting_id, section, file_kind, original_name, mime_type, size_bytes, storage_key, public_url, uploaded_by_user_id, uploaded_by_type, uploaded_at";
    if ($has_note_col) {
        $sql .= ", note";
    }
    $sql .= ")
        VALUES
        (:meeting_id, :section, :file_kind, :original_name, :mime_type, :size_bytes, :storage_key, :public_url, :uploaded_by_user_id, :uploaded_by_type, NOW()";
    if ($has_note_col) {
        $sql .= ", :note";
    }
    $sql .= ")
        RETURNING *
    ";

    $params = [
        ':meeting_id' => $meeting_id,
        ':section' => $section,
        ':file_kind' => $file_kind,
        ':original_name' => $original_name !== '' ? $original_name : 'arquivo',
        ':mime_type' => $mime_type !== '' ? $mime_type : 'application/octet-stream',
        ':size_bytes' => max(0, $size_bytes),
        ':storage_key' => $storage_key,
        ':public_url' => $public_url !== '' ? $public_url : null,
        ':uploaded_by_user_id' => $uploaded_by_user_id,
        ':uploaded_by_type' => in_array($uploaded_by_type, ['interno', 'cliente', 'fornecedor'], true) ? $uploaded_by_type : 'interno'
    ];
    if ($has_note_col) {
        $clean_note = trim((string)($note ?? ''));
        $params[':note'] = $clean_note !== '' ? $clean_note : null;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return ['ok' => true, 'anexo' => $stmt->fetch(PDO::FETCH_ASSOC)];
}

/**
 * Gerar link público para cliente (DJ).
 * Permite múltiplos links por reunião via slot_index.
 */
function eventos_reuniao_gerar_link_cliente(
    PDO $pdo,
    int $meeting_id,
    int $user_id,
    ?array $schema_snapshot = null,
    ?string $content_html_snapshot = null,
    ?string $form_title = null,
    int $slot_index = 1,
    string $section = 'dj_protocolo',
    string $link_type = 'cliente_dj'
): array {
    eventos_reuniao_ensure_schema($pdo);

    $section = trim(strtolower($section));
    $section_map = [
        'dj_protocolo' => ['label' => 'DJ/Protocolos', 'default_link_type' => 'cliente_dj'],
        'observacoes_gerais' => ['label' => 'Observações Gerais', 'default_link_type' => 'cliente_observacoes'],
    ];
    if (!isset($section_map[$section])) {
        return ['ok' => false, 'error' => 'Seção inválida para geração de link'];
    }
    $section_label = (string)$section_map[$section]['label'];
    $link_type = trim((string)$link_type);
    if ($link_type === '') {
        $link_type = (string)$section_map[$section]['default_link_type'];
    }

    $slot_index = max(1, min(50, (int)$slot_index));
    $has_slot_index_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index');
    $has_schema_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'form_schema_json');
    $has_content_snapshot_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'content_html_snapshot');
    $has_form_title_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'form_title');
    $has_submitted_at_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at');
    $has_portal_visible_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'portal_visible');
    $has_portal_editable_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'portal_editable');
    $has_portal_configured_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'portal_configured');

    // Verificar se já existe link ativo para esse slot.
    if ($has_slot_index_col) {
        $stmt = $pdo->prepare("
            SELECT * FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = :link_type
              AND is_active = TRUE
              AND COALESCE(slot_index, 1) = :slot_index
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':link_type' => $link_type,
            ':slot_index' => $slot_index
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id AND link_type = :link_type AND is_active = TRUE
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':link_type' => $link_type
        ]);
    }
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($link) {
        return ['ok' => true, 'link' => $link, 'created' => false];
    }

    $secao = eventos_reuniao_get_secao($pdo, $meeting_id, $section);
    if (!$secao) {
        return ['ok' => false, 'error' => 'Seção ' . $section_label . ' não encontrada'];
    }

    $schema_normalized = [];
    if (is_array($schema_snapshot)) {
        $schema_normalized = eventos_form_template_normalizar_schema($schema_snapshot);
    } elseif (!empty($secao['form_schema_json'])) {
        $decoded = json_decode((string)$secao['form_schema_json'], true);
        if (is_array($decoded)) {
            $schema_normalized = eventos_form_template_normalizar_schema($decoded);
        }
    }
    $has_schema = !empty($schema_normalized) && eventos_form_template_tem_campo_util($schema_normalized);

    $content_html = trim((string)($content_html_snapshot ?? ($secao['content_html'] ?? '')));
    $has_content = trim(strip_tags($content_html)) !== '';
    if (!$has_schema && !$has_content) {
        return ['ok' => false, 'error' => 'Salve o formulário da seção ' . $section_label . ' antes de gerar o link para o cliente'];
    }

    // Se já existe token para o slot, reativa o mesmo token em vez de criar novo.
    if ($has_slot_index_col) {
        $stmt = $pdo->prepare("
            SELECT * FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = :link_type
              AND COALESCE(slot_index, 1) = :slot_index
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':link_type' => $link_type,
            ':slot_index' => $slot_index
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = :link_type
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':link_type' => $link_type
        ]);
    }
    $existing_link = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing_link && empty($existing_link['is_active'])) {
        $set_parts = ["is_active = TRUE"];
        $params = [':id' => (int)$existing_link['id']];

        if ($has_submitted_at_col) {
            $set_parts[] = "submitted_at = NULL";
        }
        if ($has_schema_col) {
            $set_parts[] = "form_schema_json = CAST(:form_schema_json AS jsonb)";
            $params[':form_schema_json'] = $has_schema
                ? json_encode($schema_normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
        }
        if ($has_content_snapshot_col) {
            $set_parts[] = "content_html_snapshot = :content_html_snapshot";
            $params[':content_html_snapshot'] = $has_content ? $content_html : null;
        }
        if ($has_form_title_col) {
            $set_parts[] = "form_title = :form_title";
            $title = trim((string)$form_title);
            if ($title !== '') {
                $params[':form_title'] = function_exists('mb_substr') ? mb_substr($title, 0, 160) : substr($title, 0, 160);
            } else {
                $params[':form_title'] = null;
            }
        }
        if ($has_portal_visible_col && !array_key_exists(':portal_visible', $params)) {
            $set_parts[] = "portal_visible = COALESCE(portal_visible, FALSE)";
        }
        if ($has_portal_editable_col && !array_key_exists(':portal_editable', $params)) {
            $set_parts[] = "portal_editable = COALESCE(portal_editable, FALSE)";
        }
        if ($has_portal_configured_col && !array_key_exists(':portal_configured', $params)) {
            $set_parts[] = "portal_configured = COALESCE(portal_configured, FALSE)";
        }

        $sql = "UPDATE eventos_links_publicos
                SET " . implode(', ', $set_parts) . "
                WHERE id = :id
                RETURNING *";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reactivated = $stmt->fetch(PDO::FETCH_ASSOC) ?: $existing_link;
        return ['ok' => true, 'link' => $reactivated, 'created' => false, 'reactivated' => true];
    }

    $token = bin2hex(random_bytes(32));
    $columns = ['meeting_id', 'token', 'link_type', 'allowed_sections', 'is_active', 'created_by', 'created_at'];
    $values = [':meeting_id', ':token', ':link_type', ':sections', 'TRUE', ':user_id', 'NOW()'];
    $params = [
        ':meeting_id' => $meeting_id,
        ':token' => $token,
        ':link_type' => $link_type,
        ':sections' => json_encode([$section]),
        ':user_id' => $user_id
    ];

    if ($has_slot_index_col) {
        $columns[] = 'slot_index';
        $values[] = ':slot_index';
        $params[':slot_index'] = $slot_index;
    }
    if ($has_schema_col) {
        $columns[] = 'form_schema_json';
        $values[] = 'CAST(:form_schema_json AS jsonb)';
        $params[':form_schema_json'] = $has_schema
            ? json_encode($schema_normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
    }
    if ($has_content_snapshot_col) {
        $columns[] = 'content_html_snapshot';
        $values[] = ':content_html_snapshot';
        $params[':content_html_snapshot'] = $has_content ? $content_html : null;
    }
    if ($has_form_title_col) {
        $columns[] = 'form_title';
        $values[] = ':form_title';
        $title = trim((string)$form_title);
        if ($title !== '') {
            $params[':form_title'] = function_exists('mb_substr') ? mb_substr($title, 0, 160) : substr($title, 0, 160);
        } else {
            $params[':form_title'] = null;
        }
    }
    if ($has_portal_visible_col) {
        $columns[] = 'portal_visible';
        $values[] = 'FALSE';
    }
    if ($has_portal_editable_col) {
        $columns[] = 'portal_editable';
        $values[] = 'FALSE';
    }
    if ($has_portal_configured_col) {
        $columns[] = 'portal_configured';
        $values[] = 'FALSE';
    }

    $sql = "INSERT INTO eventos_links_publicos (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $values) . ")
            RETURNING *";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    return ['ok' => true, 'link' => $link, 'created' => true];
}

/**
 * Lista links públicos ativos de cliente por reunião e tipo.
 */
function eventos_reuniao_listar_links_cliente(PDO $pdo, int $meeting_id, string $link_type = 'cliente_dj'): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return [];
    }

    $link_type = trim((string)$link_type);
    if ($link_type === '') {
        $link_type = 'cliente_dj';
    }

    $has_slot_index_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index');
    $sql = "
        SELECT * FROM eventos_links_publicos
        WHERE meeting_id = :meeting_id
          AND link_type = :link_type
          AND is_active = TRUE
        ORDER BY " . ($has_slot_index_col ? "COALESCE(slot_index, 1) ASC, " : "") . "id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':link_type' => $link_type
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $decoded = json_decode((string)($row['form_schema_json'] ?? '[]'), true);
        $row['form_schema'] = is_array($decoded) ? $decoded : [];
        $row['portal_visible'] = !empty($row['portal_visible']);
        $row['portal_editable'] = !empty($row['portal_editable']);
        $row['portal_configured'] = !empty($row['portal_configured']);
    }
    unset($row);

    return $rows;
}

/**
 * Atualiza regras de visibilidade/edição do portal para um slot de link público.
 * Se necessário, cria/reativa o link do slot automaticamente.
 */
function eventos_reuniao_atualizar_slot_portal_config(
    PDO $pdo,
    int $meeting_id,
    int $slot_index,
    string $link_type,
    bool $portal_visible,
    bool $portal_editable,
    int $user_id = 0,
    ?array $schema_snapshot = null,
    ?string $content_html_snapshot = null,
    ?string $form_title = null,
    string $section = 'dj_protocolo'
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $slot_index = max(1, min(50, (int)$slot_index));
    $link_type = trim((string)$link_type);
    if ($link_type === '') {
        $link_type = 'cliente_dj';
    }
    $section = trim((string)$section) !== '' ? trim((string)$section) : 'dj_protocolo';

    $has_slot_index_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index');
    $has_submitted_at_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at');
    $has_portal_visible_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'portal_visible');
    $has_portal_editable_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'portal_editable');
    $has_portal_configured_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'portal_configured');

    if (!$has_portal_visible_col && !$has_portal_editable_col) {
        return ['ok' => false, 'error' => 'Configuração de portal indisponível neste ambiente'];
    }

    if ($has_slot_index_col) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = :link_type
              AND COALESCE(slot_index, 1) = :slot_index
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':link_type' => $link_type,
            ':slot_index' => $slot_index
        ]);
    } else {
        if ($slot_index !== 1) {
            return ['ok' => false, 'error' => 'Este ambiente suporta apenas quadro 1'];
        }
        $stmt = $pdo->prepare("
            SELECT *
            FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = :link_type
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':link_type' => $link_type
        ]);
    }
    $link = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $needs_link = $portal_visible || $portal_editable || (is_array($schema_snapshot) && !empty($schema_snapshot));
    if (!$link && $needs_link) {
        $created = eventos_reuniao_gerar_link_cliente(
            $pdo,
            $meeting_id,
            $user_id,
            $schema_snapshot,
            $content_html_snapshot,
            $form_title,
            $slot_index,
            $section,
            $link_type
        );
        if (empty($created['ok']) || empty($created['link']['id'])) {
            return ['ok' => false, 'error' => $created['error'] ?? 'Não foi possível preparar o formulário do quadro'];
        }
        $link = $created['link'];
    }

    if (!$link) {
        return ['ok' => true, 'slot_index' => $slot_index, 'link' => null];
    }

    $set_parts = [];
    $params = [':id' => (int)$link['id']];

    if ($has_portal_visible_col) {
        $set_parts[] = "portal_visible = " . ($portal_visible ? 'TRUE' : 'FALSE');
    }
    if ($has_portal_editable_col) {
        $set_parts[] = "portal_editable = " . ($portal_editable ? 'TRUE' : 'FALSE');
    }
    if ($has_portal_configured_col) {
        $set_parts[] = "portal_configured = TRUE";
    }
    if ($portal_visible || $portal_editable) {
        $set_parts[] = "is_active = TRUE";
    }
    if ($portal_editable && $has_submitted_at_col) {
        $set_parts[] = "submitted_at = NULL";
    }

    if (!empty($set_parts)) {
        $sql = "UPDATE eventos_links_publicos SET " . implode(', ', $set_parts) . " WHERE id = :id RETURNING *";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC) ?: $link;
        $decoded = json_decode((string)($updated['form_schema_json'] ?? '[]'), true);
        $updated['form_schema'] = is_array($decoded) ? $decoded : [];
        $updated['portal_visible'] = !empty($updated['portal_visible']);
        $updated['portal_editable'] = !empty($updated['portal_editable']);
        $updated['portal_configured'] = !empty($updated['portal_configured']);
        return ['ok' => true, 'slot_index' => $slot_index, 'link' => $updated];
    }

    $decoded = json_decode((string)($link['form_schema_json'] ?? '[]'), true);
    $link['form_schema'] = is_array($decoded) ? $decoded : [];
    $link['portal_visible'] = !empty($link['portal_visible']);
    $link['portal_editable'] = !empty($link['portal_editable']);
    $link['portal_configured'] = !empty($link['portal_configured']);
    return ['ok' => true, 'slot_index' => $slot_index, 'link' => $link];
}

/**
 * Buscar link público por token
 */
function eventos_link_publico_get(PDO $pdo, string $token): ?array {
    eventos_reuniao_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT lp.*, r.me_event_snapshot, r.status as reuniao_status
        FROM eventos_links_publicos lp
        JOIN eventos_reunioes r ON r.id = lp.meeting_id
        WHERE lp.token = :token
    ");
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return null;
    }

    $decoded = json_decode((string)($row['form_schema_json'] ?? '[]'), true);
    $row['form_schema'] = is_array($decoded) ? $decoded : [];
    $row['portal_visible'] = !empty($row['portal_visible']);
    $row['portal_editable'] = !empty($row['portal_editable']);
    $row['portal_configured'] = !empty($row['portal_configured']);
    return $row;
}

/**
 * Registrar acesso a link público
 */
function eventos_link_publico_registrar_acesso(PDO $pdo, int $link_id): void {
    $stmt = $pdo->prepare("
        UPDATE eventos_links_publicos 
        SET last_access_at = NOW(), access_count = access_count + 1
        WHERE id = :id
    ");
    $stmt->execute([':id' => $link_id]);
}

/**
 * Atualiza snapshot de conteúdo do link público sem marcar como enviado (não trava).
 */
function eventos_link_publico_salvar_snapshot(PDO $pdo, int $link_id, string $content_html): bool {
    eventos_reuniao_ensure_schema($pdo);
    if ($link_id <= 0) {
        return false;
    }

    $has_snapshot_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'content_html_snapshot');
    if (!$has_snapshot_col) {
        return true;
    }

    $stmt = $pdo->prepare("
        UPDATE eventos_links_publicos
        SET content_html_snapshot = :html
        WHERE id = :id
    ");
    return $stmt->execute([
        ':id' => $link_id,
        ':html' => $content_html
    ]);
}

/**
 * Registra envio do cliente no próprio link sem desativar o token.
 */
function eventos_link_publico_registrar_envio(PDO $pdo, int $link_id, string $content_html): bool {
    eventos_reuniao_ensure_schema($pdo);
    if ($link_id <= 0) {
        return false;
    }

    $has_snapshot_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'content_html_snapshot');
    $has_submitted_at_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at');

    $sql = "UPDATE eventos_links_publicos SET is_active = TRUE";
    $params = [':id' => $link_id];

    if ($has_snapshot_col) {
        $sql .= ", content_html_snapshot = :content_html_snapshot";
        $params[':content_html_snapshot'] = $content_html;
    }
    if ($has_submitted_at_col) {
        $sql .= ", submitted_at = NOW()";
    }
    $sql .= " WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

/**
 * Desativa link público (ex.: após envio do cliente).
 */
function eventos_link_publico_desativar(PDO $pdo, int $link_id): bool {
    eventos_reuniao_ensure_schema($pdo);
    if ($link_id <= 0) {
        return false;
    }

    $has_submitted_at_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at');
    $sql = "UPDATE eventos_links_publicos SET is_active = FALSE";
    if ($has_submitted_at_col) {
        $sql .= ", submitted_at = NOW()";
    }
    $sql .= " WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    return $stmt->execute([':id' => $link_id]);
}

/**
 * URL base do portal do cliente.
 */
function eventos_cliente_portal_base_url(): string {
    $candidates = [
        (string)(getenv('EVENTOS_CLIENTE_PORTAL_BASE_URL') ?: ''),
        (string)(getenv('APP_CLIENT_PORTAL_URL') ?: ''),
        (string)(getenv('APP_URL') ?: ''),
        (string)(getenv('BASE_URL') ?: ''),
    ];
    foreach ($candidates as $candidate) {
        $value = trim($candidate);
        if ($value !== '') {
            return rtrim($value, '/');
        }
    }
    return 'https://painelpro.smileeventos.com.br';
}

/**
 * Monta URL pública do portal do cliente por token.
 */
function eventos_cliente_portal_build_url(string $token): string {
    $base = eventos_cliente_portal_base_url();
    return $base . '/index.php?page=eventos_cliente_portal&token=' . urlencode($token);
}

/**
 * Converte linha de portal em estrutura consistente.
 */
function eventos_cliente_portal_normalizar_row(?array $row): ?array {
    if (!$row) {
        return null;
    }
    $token = trim((string)($row['token'] ?? ''));
    $row['id'] = (int)($row['id'] ?? 0);
    $row['meeting_id'] = (int)($row['meeting_id'] ?? 0);
    $row['token'] = $token;
    $row['is_active'] = !empty($row['is_active']);
    $row['visivel_reuniao'] = !empty($row['visivel_reuniao']);
    $row['editavel_reuniao'] = !empty($row['editavel_reuniao']);
    $row['visivel_dj'] = !empty($row['visivel_dj']);
    $row['editavel_dj'] = !empty($row['editavel_dj']);
    $row['visivel_convidados'] = !empty($row['visivel_convidados']);
    $row['editavel_convidados'] = !empty($row['editavel_convidados']);
    $row['visivel_arquivos'] = !empty($row['visivel_arquivos']);
    $row['editavel_arquivos'] = !empty($row['editavel_arquivos']);
    $row['url'] = $token !== '' ? eventos_cliente_portal_build_url($token) : '';
    return $row;
}

/**
 * Busca portal do cliente por reunião.
 */
function eventos_cliente_portal_get(PDO $pdo, int $meeting_id): ?array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM eventos_cliente_portais
            WHERE meeting_id = :meeting_id
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        return eventos_cliente_portal_normalizar_row($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    } catch (Throwable $e) {
        error_log('eventos_cliente_portal_get: ' . $e->getMessage());
        return null;
    }
}

/**
 * Busca portal do cliente por token.
 */
function eventos_cliente_portal_get_by_token(PDO $pdo, string $token): ?array {
    eventos_reuniao_ensure_schema($pdo);
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM eventos_cliente_portais
            WHERE token = :token
            LIMIT 1
        ");
        $stmt->execute([':token' => $token]);
        return eventos_cliente_portal_normalizar_row($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    } catch (Throwable $e) {
        error_log('eventos_cliente_portal_get_by_token: ' . $e->getMessage());
        return null;
    }
}

/**
 * Cria (ou retorna) portal do cliente para reunião.
 */
function eventos_cliente_portal_get_or_create(PDO $pdo, int $meeting_id, int $user_id = 0): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $has_is_active_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'is_active');
    $has_visivel_reuniao_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'visivel_reuniao');
    $has_editavel_reuniao_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'editavel_reuniao');
    $has_visivel_dj_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'visivel_dj');
    $has_editavel_dj_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'editavel_dj');
    $has_visivel_convidados_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'visivel_convidados');
    $has_editavel_convidados_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'editavel_convidados');
    $has_visivel_arquivos_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'visivel_arquivos');
    $has_editavel_arquivos_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'editavel_arquivos');
    $has_created_by_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'created_by_user_id');
    $has_created_at_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'created_at');
    $has_updated_at_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'updated_at');

    try {
        $existing = eventos_cliente_portal_get($pdo, $meeting_id);
        if ($existing) {
            if ($has_is_active_col && empty($existing['is_active'])) {
                $set_parts = ["is_active = TRUE"];
                if ($has_updated_at_col) {
                    $set_parts[] = "updated_at = NOW()";
                }
                if (!empty($set_parts)) {
                    $stmt = $pdo->prepare("
                        UPDATE eventos_cliente_portais
                        SET " . implode(', ', $set_parts) . "
                        WHERE id = :id
                        RETURNING *
                    ");
                    $stmt->execute([':id' => (int)$existing['id']]);
                    $existing = eventos_cliente_portal_normalizar_row($stmt->fetch(PDO::FETCH_ASSOC) ?: $existing);
                }
            }
            return ['ok' => true, 'portal' => $existing, 'created' => false];
        }

        $token = bin2hex(random_bytes(24));
    } catch (Throwable $e) {
        try {
            $token = bin2hex(random_bytes(16)) . substr(sha1(uniqid('portal_', true)), 0, 16);
        } catch (Throwable $e2) {
            $token = sha1(uniqid('portal_', true) . microtime(true));
        }
    }

    try {
        $columns = ['meeting_id', 'token'];
        $values = [':meeting_id', ':token'];
        $params = [
            ':meeting_id' => $meeting_id,
            ':token' => $token,
        ];

        if ($has_is_active_col) {
            $columns[] = 'is_active';
            $values[] = 'TRUE';
        }
        if ($has_visivel_reuniao_col) {
            $columns[] = 'visivel_reuniao';
            $values[] = 'FALSE';
        }
        if ($has_editavel_reuniao_col) {
            $columns[] = 'editavel_reuniao';
            $values[] = 'FALSE';
        }
        if ($has_visivel_dj_col) {
            $columns[] = 'visivel_dj';
            $values[] = 'FALSE';
        }
        if ($has_editavel_dj_col) {
            $columns[] = 'editavel_dj';
            $values[] = 'FALSE';
        }
        if ($has_visivel_convidados_col) {
            $columns[] = 'visivel_convidados';
            $values[] = 'FALSE';
        }
        if ($has_editavel_convidados_col) {
            $columns[] = 'editavel_convidados';
            $values[] = 'FALSE';
        }
        if ($has_visivel_arquivos_col) {
            $columns[] = 'visivel_arquivos';
            $values[] = 'FALSE';
        }
        if ($has_editavel_arquivos_col) {
            $columns[] = 'editavel_arquivos';
            $values[] = 'FALSE';
        }
        if ($has_created_by_col) {
            $columns[] = 'created_by_user_id';
            $values[] = ':user_id';
            $params[':user_id'] = $user_id > 0 ? $user_id : null;
        }
        if ($has_created_at_col) {
            $columns[] = 'created_at';
            $values[] = 'NOW()';
        }
        if ($has_updated_at_col) {
            $columns[] = 'updated_at';
            $values[] = 'NOW()';
        }

        $stmt = $pdo->prepare("
            INSERT INTO eventos_cliente_portais (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $values) . ")
            RETURNING *
        ");
        $stmt->execute($params);
        $portal = eventos_cliente_portal_normalizar_row($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
        if (!$portal) {
            return ['ok' => false, 'error' => 'Não foi possível criar o portal do cliente'];
        }

        return ['ok' => true, 'portal' => $portal, 'created' => true];
    } catch (Throwable $e) {
        error_log('eventos_cliente_portal_get_or_create: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao preparar portal do cliente.'];
    }
}

/**
 * Sincroniza o link público de Reunião Final (Observações Gerais) com a configuração do portal.
 * Garante que, ao habilitar visibilidade/edição da reunião, exista ao menos um link disponível.
 */
function eventos_cliente_portal_sincronizar_link_reuniao(
    PDO $pdo,
    int $meeting_id,
    bool $visivel_reuniao,
    bool $editavel_reuniao,
    int $user_id = 0
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $schema_snapshot = null;
    $content_snapshot = null;
    $form_title = 'Reunião Final';

    $secao_observacoes = eventos_reuniao_get_secao($pdo, $meeting_id, 'observacoes_gerais');
    if (is_array($secao_observacoes) && !empty($secao_observacoes)) {
        $schema_raw = json_decode((string)($secao_observacoes['form_schema_json'] ?? '[]'), true);
        if (is_array($schema_raw) && !empty($schema_raw)) {
            $schema_snapshot = $schema_raw;
        }
        $content_raw = trim((string)($secao_observacoes['content_html'] ?? ''));
        if ($content_raw !== '') {
            $content_snapshot = $content_raw;
        }
        if ($schema_snapshot !== null || $content_snapshot !== null) {
            $form_title = 'Reunião Final - Observações Gerais';
        }
    }

    // Fallback: se Observações Gerais estiver vazio, reaproveita o conteúdo de Decoração.
    if ($schema_snapshot === null && $content_snapshot === null) {
        $secao_decoracao = eventos_reuniao_get_secao($pdo, $meeting_id, 'decoracao');
        if (is_array($secao_decoracao) && !empty($secao_decoracao)) {
            $schema_raw = json_decode((string)($secao_decoracao['form_schema_json'] ?? '[]'), true);
            if (is_array($schema_raw) && !empty($schema_raw)) {
                $schema_snapshot = $schema_raw;
            }
            $content_raw = trim((string)($secao_decoracao['content_html'] ?? ''));
            if ($content_raw !== '') {
                $content_snapshot = $content_raw;
            }
            if ($schema_snapshot !== null || $content_snapshot !== null) {
                $form_title = 'Reunião Final - Decoração';
            }
        }
    }

    $result = eventos_reuniao_atualizar_slot_portal_config(
        $pdo,
        $meeting_id,
        1,
        'cliente_observacoes',
        $visivel_reuniao,
        $editavel_reuniao,
        $user_id,
        $schema_snapshot,
        $content_snapshot,
        $form_title,
        'observacoes_gerais'
    );

    if (empty($result['ok'])) {
        // Não interromper o fluxo de configuração do portal em caso de seção ainda vazia.
        $error_text = (string)($result['error'] ?? '');
        $is_empty_section = stripos($error_text, 'Salve o formulário da seção') !== false;
        if ($is_empty_section) {
            return ['ok' => true, 'link' => null, 'warning' => $error_text];
        }
        return ['ok' => false, 'error' => $error_text !== '' ? $error_text : 'Falha ao sincronizar link da reunião'];
    }

    return ['ok' => true, 'link' => $result['link'] ?? null];
}

/**
 * Atualiza configurações do portal do cliente por reunião.
 */
function eventos_cliente_portal_atualizar_config(PDO $pdo, int $meeting_id, array $config, int $user_id = 0): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $created = eventos_cliente_portal_get_or_create($pdo, $meeting_id, $user_id);
    if (empty($created['ok']) || empty($created['portal']['id'])) {
        return ['ok' => false, 'error' => $created['error'] ?? 'Portal não encontrado'];
    }

    $visivel_reuniao = !empty($config['visivel_reuniao']);
    $editavel_reuniao = !empty($config['editavel_reuniao']);
    $visivel_dj = !empty($config['visivel_dj']);
    $editavel_dj = !empty($config['editavel_dj']);
    $visivel_convidados = !empty($config['visivel_convidados']);
    $editavel_convidados = !empty($config['editavel_convidados']);
    $visivel_arquivos = !empty($config['visivel_arquivos']);
    $editavel_arquivos = !empty($config['editavel_arquivos']);

    if ($editavel_reuniao) {
        $visivel_reuniao = true;
    }
    if ($editavel_dj) {
        $visivel_dj = true;
    }
    if ($editavel_convidados) {
        $visivel_convidados = true;
    }
    if ($editavel_arquivos) {
        $visivel_arquivos = true;
    }

    $has_visivel_reuniao_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'visivel_reuniao');
    $has_editavel_reuniao_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'editavel_reuniao');
    $has_visivel_dj_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'visivel_dj');
    $has_editavel_dj_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'editavel_dj');
    $has_visivel_convidados_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'visivel_convidados');
    $has_editavel_convidados_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'editavel_convidados');
    $has_visivel_arquivos_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'visivel_arquivos');
    $has_editavel_arquivos_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'editavel_arquivos');
    $has_updated_at_col = eventos_reuniao_has_column($pdo, 'eventos_cliente_portais', 'updated_at');

    try {
        $set_parts = [];
        $params = [':id' => (int)$created['portal']['id']];

        if ($has_visivel_reuniao_col) {
            $set_parts[] = 'visivel_reuniao = :visivel_reuniao';
            $params[':visivel_reuniao'] = $visivel_reuniao ? 1 : 0;
        }
        if ($has_editavel_reuniao_col) {
            $set_parts[] = 'editavel_reuniao = :editavel_reuniao';
            $params[':editavel_reuniao'] = $editavel_reuniao ? 1 : 0;
        }
        if ($has_visivel_dj_col) {
            $set_parts[] = 'visivel_dj = :visivel_dj';
            $params[':visivel_dj'] = $visivel_dj ? 1 : 0;
        }
        if ($has_editavel_dj_col) {
            $set_parts[] = 'editavel_dj = :editavel_dj';
            $params[':editavel_dj'] = $editavel_dj ? 1 : 0;
        }
        if ($has_visivel_convidados_col) {
            $set_parts[] = 'visivel_convidados = :visivel_convidados';
            $params[':visivel_convidados'] = $visivel_convidados ? 1 : 0;
        }
        if ($has_editavel_convidados_col) {
            $set_parts[] = 'editavel_convidados = :editavel_convidados';
            $params[':editavel_convidados'] = $editavel_convidados ? 1 : 0;
        }
        if ($has_visivel_arquivos_col) {
            $set_parts[] = 'visivel_arquivos = :visivel_arquivos';
            $params[':visivel_arquivos'] = $visivel_arquivos ? 1 : 0;
        }
        if ($has_editavel_arquivos_col) {
            $set_parts[] = 'editavel_arquivos = :editavel_arquivos';
            $params[':editavel_arquivos'] = $editavel_arquivos ? 1 : 0;
        }
        if ($has_updated_at_col) {
            $set_parts[] = 'updated_at = NOW()';
        }

        if (!empty($set_parts)) {
            $stmt = $pdo->prepare("
                UPDATE eventos_cliente_portais
                SET " . implode(', ', $set_parts) . "
                WHERE id = :id
                RETURNING *
            ");
            $stmt->execute($params);
            $portal = eventos_cliente_portal_normalizar_row($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
        } else {
            $portal = eventos_cliente_portal_get($pdo, (int)$meeting_id);
        }

        if (!$portal) {
            return ['ok' => false, 'error' => 'Não foi possível salvar as configurações do portal'];
        }

        $sync_reuniao = eventos_cliente_portal_sincronizar_link_reuniao(
            $pdo,
            $meeting_id,
            $visivel_reuniao,
            $editavel_reuniao,
            $user_id
        );
        if (empty($sync_reuniao['ok'])) {
            error_log('eventos_cliente_portal_atualizar_config sync reuniao: ' . (string)($sync_reuniao['error'] ?? 'erro desconhecido'));
        }

        return ['ok' => true, 'portal' => $portal];
    } catch (Throwable $e) {
        error_log('eventos_cliente_portal_atualizar_config: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar configurações do portal.'];
    }
}

/**
 * Normaliza chave textual para comparação de campos de arquivo.
 */
function eventos_arquivos_campo_chave(string $titulo): string {
    $text = trim($titulo);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text) ?? $text;
    $text = trim((string)$text);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim((string)$text);
}

/**
 * Normaliza chave de campo do sistema.
 */
function eventos_arquivos_chave_sistema_normalizar(?string $chave): string {
    $key = strtolower(trim((string)$chave));
    if ($key === '') {
        return '';
    }
    $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? $key;
    $key = trim((string)$key, '_');
    return trim((string)$key);
}

/**
 * Definições fixas de campos internos do módulo de arquivos.
 */
function eventos_arquivos_campos_sistema_definicoes(): array {
    return [
        'resumo_evento' => [
            'chave_sistema' => 'resumo_evento',
            'titulo' => 'Resumo do evento (Contrato cliente)',
            'descricao' => 'Anexe o PDF do contrato com tudo que foi contratado. Uso interno (não aparece no portal).',
            'obrigatorio_cliente' => false,
            'interno_only' => true,
            'sort_order' => 5,
            'pdf_only' => true,
            'single_active_file' => true,
        ],
        'cardapio' => [
            'chave_sistema' => 'cardapio',
            'titulo' => 'Cardápio',
            'descricao' => 'Anexe a versão atual do cardápio do evento.',
            'obrigatorio_cliente' => false,
            'interno_only' => false,
            'sort_order' => 15,
            'pdf_only' => false,
            'single_active_file' => false,
        ],
    ];
}

/**
 * Regras de comportamento de campo com base na chave de sistema.
 */
function eventos_arquivos_regra_por_chave_sistema(?string $chave_sistema): array {
    $key = eventos_arquivos_chave_sistema_normalizar($chave_sistema);
    $defs = eventos_arquivos_campos_sistema_definicoes();
    $defaults = [
        'chave_sistema' => $key,
        'interno_only' => false,
        'pdf_only' => false,
        'single_active_file' => false,
    ];
    if ($key !== '' && isset($defs[$key])) {
        return array_merge($defaults, $defs[$key]);
    }
    return $defaults;
}

/**
 * Retorna se o campo é interno (não deve aparecer no portal do cliente).
 */
function eventos_arquivos_campo_e_interno(array $campo): bool {
    if (!empty($campo['interno_only'])) {
        return true;
    }
    $rule = eventos_arquivos_regra_por_chave_sistema((string)($campo['chave_sistema'] ?? ''));
    return !empty($rule['interno_only']);
}

/**
 * Validação simples para PDFs.
 */
function eventos_arquivos_arquivo_e_pdf(string $original_name, string $mime_type): bool {
    $name = strtolower(trim($original_name));
    $mime = strtolower(trim($mime_type));
    if ($name !== '' && strlen($name) >= 4 && substr($name, -4) === '.pdf') {
        return true;
    }
    if ($mime === 'application/pdf' || str_contains($mime, '/pdf')) {
        return true;
    }
    return false;
}

/**
 * Busca um campo por chave de sistema na reunião.
 */
function eventos_arquivos_buscar_campo_por_chave(PDO $pdo, int $meeting_id, string $chave_sistema, bool $somente_ativo = true): ?array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return null;
    }
    $key = eventos_arquivos_chave_sistema_normalizar($chave_sistema);
    if ($key === '') {
        return null;
    }

    $where_ativo = $somente_ativo ? "AND ativo = TRUE" : '';
    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_arquivos_campos
        WHERE meeting_id = :meeting_id
          AND deleted_at IS NULL
          AND chave_sistema = :chave_sistema
          {$where_ativo}
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':chave_sistema' => $key,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return null;
    }
    $row['id'] = (int)($row['id'] ?? 0);
    $row['meeting_id'] = (int)($row['meeting_id'] ?? 0);
    $row['obrigatorio_cliente'] = !empty($row['obrigatorio_cliente']);
    $row['ativo'] = !empty($row['ativo']);
    $row['interno_only'] = !empty($row['interno_only']);
    $row['sort_order'] = (int)($row['sort_order'] ?? 0);
    $row['titulo'] = trim((string)($row['titulo'] ?? ''));
    $row['descricao'] = trim((string)($row['descricao'] ?? ''));
    $row['chave_sistema'] = eventos_arquivos_chave_sistema_normalizar((string)($row['chave_sistema'] ?? ''));
    return $row;
}

/**
 * Garante campos fixos do módulo de arquivos (Resumo do evento e Cardápio).
 */
function eventos_arquivos_seed_campos_sistema(PDO $pdo, int $meeting_id, int $user_id = 0): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $defs = eventos_arquivos_campos_sistema_definicoes();
    if (empty($defs)) {
        return ['ok' => true, 'inserted' => 0, 'updated' => 0];
    }

    try {
        $stmt_existing = $pdo->prepare("
            SELECT id, titulo, descricao, chave_sistema, interno_only, obrigatorio_cliente, ativo, sort_order
            FROM eventos_arquivos_campos
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
            ORDER BY id DESC
        ");
        $stmt_existing->execute([':meeting_id' => $meeting_id]);
        $existing_rows = $stmt_existing->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $existing_by_key = [];
        $existing_by_title = [];
        $existing_by_id = [];
        foreach ($existing_rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $existing_by_id[$id] = $row;
            $key = eventos_arquivos_chave_sistema_normalizar((string)($row['chave_sistema'] ?? ''));
            if ($key !== '' && !isset($existing_by_key[$key])) {
                $existing_by_key[$key] = $id;
            }
            $title_key = eventos_arquivos_campo_chave((string)($row['titulo'] ?? ''));
            if ($title_key !== '' && !isset($existing_by_title[$title_key])) {
                $existing_by_title[$title_key] = $id;
            }
        }

        $stmt_sort = $pdo->prepare("
            SELECT COALESCE(MAX(sort_order), 0)
            FROM eventos_arquivos_campos
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
        ");
        $stmt_sort->execute([':meeting_id' => $meeting_id]);
        $next_sort = (int)($stmt_sort->fetchColumn() ?: 0);

        $inserted = 0;
        $updated = 0;
        foreach ($defs as $def) {
            $key = eventos_arquivos_chave_sistema_normalizar((string)($def['chave_sistema'] ?? ''));
            $title = trim((string)($def['titulo'] ?? ''));
            if ($key === '' || $title === '') {
                continue;
            }

            $target_id = 0;
            if (isset($existing_by_key[$key])) {
                $target_id = (int)$existing_by_key[$key];
            } else {
                $title_key = eventos_arquivos_campo_chave($title);
                if ($title_key !== '' && isset($existing_by_title[$title_key])) {
                    $target_id = (int)$existing_by_title[$title_key];
                }
            }

            $sort_order = (int)($def['sort_order'] ?? 0);
            if ($sort_order <= 0) {
                $next_sort += 10;
                $sort_order = $next_sort;
            } elseif ($sort_order > $next_sort) {
                $next_sort = $sort_order;
            }

            $next_descricao = trim((string)($def['descricao'] ?? ''));
            if ($target_id > 0) {
                $current = $existing_by_id[$target_id] ?? null;
                $needs_update = true;
                if (is_array($current)) {
                    $current_title = trim((string)($current['titulo'] ?? ''));
                    $current_desc = trim((string)($current['descricao'] ?? ''));
                    $current_key = eventos_arquivos_chave_sistema_normalizar((string)($current['chave_sistema'] ?? ''));
                    $current_interno = !empty($current['interno_only']);
                    $current_obrigatorio = !empty($current['obrigatorio_cliente']);
                    $current_ativo = !empty($current['ativo']);
                    $current_sort = (int)($current['sort_order'] ?? 0);

                    $needs_update =
                        $current_title !== $title
                        || $current_desc !== $next_descricao
                        || $current_key !== $key
                        || $current_interno !== !empty($def['interno_only'])
                        || $current_obrigatorio !== !empty($def['obrigatorio_cliente'])
                        || !$current_ativo
                        || $current_sort !== $sort_order;
                }

                if (!$needs_update) {
                    continue;
                }

                $stmt_update = $pdo->prepare("
                    UPDATE eventos_arquivos_campos
                    SET titulo = :titulo,
                        descricao = :descricao,
                        chave_sistema = :chave_sistema,
                        interno_only = :interno_only,
                        obrigatorio_cliente = :obrigatorio_cliente,
                        ativo = TRUE,
                        sort_order = :sort_order,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt_update->execute([
                    ':titulo' => $title,
                    ':descricao' => $next_descricao !== '' ? $next_descricao : null,
                    ':chave_sistema' => $key,
                    ':interno_only' => !empty($def['interno_only']) ? 1 : 0,
                    ':obrigatorio_cliente' => !empty($def['obrigatorio_cliente']) ? 1 : 0,
                    ':sort_order' => $sort_order,
                    ':id' => $target_id,
                ]);
                $updated += (int)$stmt_update->rowCount() > 0 ? 1 : 0;
            } else {
                $stmt_insert = $pdo->prepare("
                    INSERT INTO eventos_arquivos_campos
                        (meeting_id, titulo, descricao, chave_sistema, interno_only, obrigatorio_cliente, ativo, sort_order, created_by_user_id, created_at, updated_at)
                    VALUES
                        (:meeting_id, :titulo, :descricao, :chave_sistema, :interno_only, :obrigatorio_cliente, TRUE, :sort_order, :user_id, NOW(), NOW())
                ");
                $stmt_insert->execute([
                    ':meeting_id' => $meeting_id,
                    ':titulo' => $title,
                    ':descricao' => $next_descricao !== '' ? $next_descricao : null,
                    ':chave_sistema' => $key,
                    ':interno_only' => !empty($def['interno_only']) ? 1 : 0,
                    ':obrigatorio_cliente' => !empty($def['obrigatorio_cliente']) ? 1 : 0,
                    ':sort_order' => $sort_order,
                    ':user_id' => $user_id > 0 ? $user_id : null,
                ]);
                $inserted++;
            }
        }

        return ['ok' => true, 'inserted' => $inserted, 'updated' => $updated];
    } catch (Throwable $e) {
        error_log('eventos_arquivos_seed_campos_sistema: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao preparar campos fixos de arquivos.'];
    }
}

/**
 * Campos sugeridos de arquivos por tipo real do evento.
 */
function eventos_arquivos_template_campos_por_tipo_evento(string $tipo_evento_real): array {
    $tipo = eventos_reuniao_normalizar_tipo_evento_real($tipo_evento_real);
    if (!in_array($tipo, ['casamento', '15anos'], true)) {
        return [];
    }

    return [
        [
            'titulo' => 'Imagem do convite',
            'descricao' => 'Envie a arte final do convite (imagem ou PDF).',
            'obrigatorio_cliente' => true,
            'sort_order' => 10,
        ],
        [
            'titulo' => 'Foto para arte do totem de fotos',
            'descricao' => 'Foto em boa resolução para produção da arte do totem.',
            'obrigatorio_cliente' => true,
            'sort_order' => 20,
        ],
    ];
}

/**
 * Garante os campos padrão de arquivos para o tipo do evento.
 */
function eventos_arquivos_seed_campos_por_tipo(PDO $pdo, int $meeting_id, string $tipo_evento_real, int $user_id = 0): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $templates = eventos_arquivos_template_campos_por_tipo_evento($tipo_evento_real);
    if (empty($templates)) {
        return ['ok' => true, 'inserted' => 0];
    }

    try {
        $stmt_existing = $pdo->prepare("
            SELECT id, titulo
            FROM eventos_arquivos_campos
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
        ");
        $stmt_existing->execute([':meeting_id' => $meeting_id]);
        $existing_rows = $stmt_existing->fetchAll(PDO::FETCH_ASSOC);
        $existing_map = [];
        foreach ($existing_rows as $row) {
            $key = eventos_arquivos_campo_chave((string)($row['titulo'] ?? ''));
            if ($key !== '') {
                $existing_map[$key] = (int)($row['id'] ?? 0);
            }
        }

        $stmt_sort = $pdo->prepare("
            SELECT COALESCE(MAX(sort_order), 0)
            FROM eventos_arquivos_campos
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
        ");
        $stmt_sort->execute([':meeting_id' => $meeting_id]);
        $next_sort = (int)($stmt_sort->fetchColumn() ?: 0);

        $inserted = 0;
        foreach ($templates as $template) {
            $titulo = trim((string)($template['titulo'] ?? ''));
            if ($titulo === '') {
                continue;
            }
            $key = eventos_arquivos_campo_chave($titulo);
            if ($key !== '' && isset($existing_map[$key])) {
                continue;
            }

            $sort_order = (int)($template['sort_order'] ?? 0);
            if ($sort_order <= 0) {
                $next_sort += 10;
                $sort_order = $next_sort;
            } elseif ($sort_order > $next_sort) {
                $next_sort = $sort_order;
            }

            $stmt_insert = $pdo->prepare("
                INSERT INTO eventos_arquivos_campos
                    (meeting_id, titulo, descricao, obrigatorio_cliente, ativo, sort_order, created_by_user_id, created_at, updated_at)
                VALUES
                    (:meeting_id, :titulo, :descricao, :obrigatorio_cliente, TRUE, :sort_order, :user_id, NOW(), NOW())
            ");
            $stmt_insert->execute([
                ':meeting_id' => $meeting_id,
                ':titulo' => $titulo,
                ':descricao' => trim((string)($template['descricao'] ?? '')) ?: null,
                ':obrigatorio_cliente' => !empty($template['obrigatorio_cliente']) ? 1 : 0,
                ':sort_order' => $sort_order,
                ':user_id' => $user_id > 0 ? $user_id : null,
            ]);
            $inserted++;
            if ($key !== '') {
                $existing_map[$key] = 1;
            }
        }

        return ['ok' => true, 'inserted' => $inserted];
    } catch (Throwable $e) {
        error_log('eventos_arquivos_seed_campos_por_tipo: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao preparar campos padrão de arquivos.'];
    }
}

/**
 * Lista campos solicitados de arquivos por reunião.
 */
function eventos_arquivos_listar_campos(PDO $pdo, int $meeting_id, bool $somente_ativos = false, bool $incluir_internos = true): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return [];
    }

    $where_ativo = $somente_ativos ? "AND c.ativo = TRUE" : '';
    $where_interno = $incluir_internos ? '' : "AND COALESCE(c.interno_only, FALSE) = FALSE";

    $stmt = $pdo->prepare("
        SELECT
            c.*,
            COALESCE(s.total_arquivos, 0) AS total_arquivos,
            COALESCE(s.total_visiveis_cliente, 0) AS total_visiveis_cliente,
            COALESCE(s.total_upload_cliente, 0) AS total_upload_cliente
        FROM eventos_arquivos_campos c
        LEFT JOIN (
            SELECT
                campo_id,
                COUNT(*) AS total_arquivos,
                SUM(CASE WHEN visivel_cliente THEN 1 ELSE 0 END) AS total_visiveis_cliente,
                SUM(CASE WHEN uploaded_by_type = 'cliente' THEN 1 ELSE 0 END) AS total_upload_cliente
            FROM eventos_arquivos_itens
            WHERE meeting_id = :meeting_id_stats
              AND deleted_at IS NULL
            GROUP BY campo_id
        ) s ON s.campo_id = c.id
        WHERE c.meeting_id = :meeting_id
          AND c.deleted_at IS NULL
          {$where_ativo}
          {$where_interno}
        ORDER BY c.sort_order ASC, c.id ASC
    ");
    $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':meeting_id_stats' => $meeting_id,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['meeting_id'] = (int)($row['meeting_id'] ?? 0);
        $row['obrigatorio_cliente'] = !empty($row['obrigatorio_cliente']);
        $row['ativo'] = !empty($row['ativo']);
        $row['interno_only'] = !empty($row['interno_only']);
        $row['sort_order'] = (int)($row['sort_order'] ?? 0);
        $row['total_arquivos'] = (int)($row['total_arquivos'] ?? 0);
        $row['total_visiveis_cliente'] = (int)($row['total_visiveis_cliente'] ?? 0);
        $row['total_upload_cliente'] = (int)($row['total_upload_cliente'] ?? 0);
        $row['titulo'] = trim((string)($row['titulo'] ?? ''));
        $row['descricao'] = trim((string)($row['descricao'] ?? ''));
        $row['chave_sistema'] = eventos_arquivos_chave_sistema_normalizar((string)($row['chave_sistema'] ?? ''));
    }
    unset($row);

    return $rows;
}

/**
 * Cria ou atualiza um campo solicitado de arquivo.
 */
function eventos_arquivos_salvar_campo(
    PDO $pdo,
    int $meeting_id,
    string $titulo,
    ?string $descricao = null,
    bool $obrigatorio_cliente = false,
    int $user_id = 0
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $titulo_limpo = trim($titulo);
    if ($titulo_limpo === '') {
        return ['ok' => false, 'error' => 'Informe o nome do arquivo solicitado.'];
    }
    if (function_exists('mb_strlen') && mb_strlen($titulo_limpo, 'UTF-8') > 180) {
        return ['ok' => false, 'error' => 'Título do campo muito longo (máximo 180 caracteres).'];
    }
    $descricao_limpa = trim((string)($descricao ?? ''));
    if (function_exists('mb_strlen') && mb_strlen($descricao_limpa, 'UTF-8') > 1500) {
        return ['ok' => false, 'error' => 'Descrição muito longa (máximo 1500 caracteres).'];
    }

    try {
        $stmt_existing = $pdo->prepare("
            SELECT *
            FROM eventos_arquivos_campos
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
              AND LOWER(TRIM(titulo)) = LOWER(TRIM(:titulo))
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt_existing->execute([
            ':meeting_id' => $meeting_id,
            ':titulo' => $titulo_limpo,
        ]);
        $existing = $stmt_existing->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($existing) {
            $stmt_update = $pdo->prepare("
                UPDATE eventos_arquivos_campos
                SET descricao = :descricao,
                    obrigatorio_cliente = :obrigatorio_cliente,
                    ativo = TRUE,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");
            $stmt_update->execute([
                ':descricao' => $descricao_limpa !== '' ? $descricao_limpa : null,
                ':obrigatorio_cliente' => $obrigatorio_cliente ? 1 : 0,
                ':id' => (int)$existing['id'],
            ]);
            $campo = $stmt_update->fetch(PDO::FETCH_ASSOC) ?: $existing;
            return ['ok' => true, 'mode' => 'updated', 'campo' => $campo];
        }

        $stmt_sort = $pdo->prepare("
            SELECT COALESCE(MAX(sort_order), 0)
            FROM eventos_arquivos_campos
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
        ");
        $stmt_sort->execute([':meeting_id' => $meeting_id]);
        $next_sort = (int)($stmt_sort->fetchColumn() ?: 0) + 10;

        $stmt_insert = $pdo->prepare("
            INSERT INTO eventos_arquivos_campos
                (meeting_id, titulo, descricao, chave_sistema, interno_only, obrigatorio_cliente, ativo, sort_order, created_by_user_id, created_at, updated_at)
            VALUES
                (:meeting_id, :titulo, :descricao, NULL, FALSE, :obrigatorio_cliente, TRUE, :sort_order, :user_id, NOW(), NOW())
            RETURNING *
        ");
        $stmt_insert->execute([
            ':meeting_id' => $meeting_id,
            ':titulo' => $titulo_limpo,
            ':descricao' => $descricao_limpa !== '' ? $descricao_limpa : null,
            ':obrigatorio_cliente' => $obrigatorio_cliente ? 1 : 0,
            ':sort_order' => $next_sort,
            ':user_id' => $user_id > 0 ? $user_id : null,
        ]);

        return ['ok' => true, 'mode' => 'created', 'campo' => $stmt_insert->fetch(PDO::FETCH_ASSOC)];
    } catch (Throwable $e) {
        error_log('eventos_arquivos_salvar_campo: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar campo solicitado.'];
    }
}

/**
 * Ativa/desativa um campo solicitado de arquivo.
 */
function eventos_arquivos_atualizar_campo_ativo(PDO $pdo, int $meeting_id, int $campo_id, bool $ativo): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0 || $campo_id <= 0) {
        return ['ok' => false, 'error' => 'Dados inválidos.'];
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE eventos_arquivos_campos
            SET ativo = :ativo,
                updated_at = NOW()
            WHERE id = :id
              AND meeting_id = :meeting_id
              AND deleted_at IS NULL
            RETURNING *
        ");
        $stmt->execute([
            ':ativo' => $ativo ? 1 : 0,
            ':id' => $campo_id,
            ':meeting_id' => $meeting_id,
        ]);
        $campo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$campo) {
            return ['ok' => false, 'error' => 'Campo não encontrado.'];
        }
        return ['ok' => true, 'campo' => $campo];
    } catch (Throwable $e) {
        error_log('eventos_arquivos_atualizar_campo_ativo: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao atualizar campo solicitado.'];
    }
}

/**
 * Lista arquivos do módulo por reunião.
 */
function eventos_arquivos_listar(
    PDO $pdo,
    int $meeting_id,
    bool $somente_visiveis_cliente = false,
    ?int $campo_id = null,
    bool $incluir_campos_internos = true
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return [];
    }

    $where_visivel = $somente_visiveis_cliente ? "AND i.visivel_cliente = TRUE" : '';
    $where_campo = ($campo_id !== null && $campo_id > 0) ? "AND i.campo_id = :campo_id" : '';
    $where_interno = $incluir_campos_internos ? '' : "AND COALESCE(c.interno_only, FALSE) = FALSE";

    $sql = "
        SELECT
            i.*,
            c.titulo AS campo_titulo,
            c.obrigatorio_cliente AS campo_obrigatorio_cliente,
            c.chave_sistema AS campo_chave_sistema,
            COALESCE(c.interno_only, FALSE) AS campo_interno_only
        FROM eventos_arquivos_itens i
        LEFT JOIN eventos_arquivos_campos c
          ON c.id = i.campo_id
         AND c.deleted_at IS NULL
        WHERE i.meeting_id = :meeting_id
          AND i.deleted_at IS NULL
          {$where_visivel}
          {$where_campo}
          {$where_interno}
        ORDER BY i.uploaded_at DESC, i.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $params = [':meeting_id' => $meeting_id];
    if ($campo_id !== null && $campo_id > 0) {
        $params[':campo_id'] = $campo_id;
    }
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['meeting_id'] = (int)($row['meeting_id'] ?? 0);
        $row['campo_id'] = isset($row['campo_id']) ? (int)$row['campo_id'] : null;
        $row['size_bytes'] = (int)($row['size_bytes'] ?? 0);
        $row['visivel_cliente'] = !empty($row['visivel_cliente']);
        $row['uploaded_by_user_id'] = isset($row['uploaded_by_user_id']) ? (int)$row['uploaded_by_user_id'] : null;
        $row['uploaded_by_type'] = trim((string)($row['uploaded_by_type'] ?? 'interno'));
        $row['descricao'] = trim((string)($row['descricao'] ?? ''));
        $row['campo_titulo'] = trim((string)($row['campo_titulo'] ?? ''));
        $row['campo_obrigatorio_cliente'] = !empty($row['campo_obrigatorio_cliente']);
        $row['campo_chave_sistema'] = eventos_arquivos_chave_sistema_normalizar((string)($row['campo_chave_sistema'] ?? ''));
        $row['campo_interno_only'] = !empty($row['campo_interno_only']);
    }
    unset($row);

    return $rows;
}

/**
 * Salva metadados de um arquivo do módulo.
 */
function eventos_arquivos_salvar_item(
    PDO $pdo,
    int $meeting_id,
    array $upload_result,
    ?int $campo_id = null,
    ?string $descricao = null,
    bool $visivel_cliente = false,
    string $uploaded_by_type = 'interno',
    ?int $uploaded_by_user_id = null
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $original_name = trim((string)($upload_result['nome_original'] ?? 'arquivo'));
    $mime_type = trim((string)($upload_result['mime_type'] ?? 'application/octet-stream'));
    $size_bytes = max(0, (int)($upload_result['tamanho_bytes'] ?? 0));
    $storage_key = trim((string)($upload_result['chave_storage'] ?? ''));
    $public_url = trim((string)($upload_result['url'] ?? ''));
    if ($storage_key === '') {
        return ['ok' => false, 'error' => 'Arquivo inválido para salvar metadados.'];
    }

    $campo_id_val = ($campo_id !== null && $campo_id > 0) ? (int)$campo_id : null;
    $campo_row = null;
    if ($campo_id_val !== null) {
        $stmt_campo = $pdo->prepare("
            SELECT id, titulo, chave_sistema, COALESCE(interno_only, FALSE) AS interno_only
            FROM eventos_arquivos_campos
            WHERE id = :id
              AND meeting_id = :meeting_id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt_campo->execute([
            ':id' => $campo_id_val,
            ':meeting_id' => $meeting_id,
        ]);
        $campo_row = $stmt_campo->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$campo_row) {
            $campo_id_val = null;
        } else {
            $campo_row['id'] = (int)($campo_row['id'] ?? 0);
            $campo_row['titulo'] = trim((string)($campo_row['titulo'] ?? ''));
            $campo_row['chave_sistema'] = eventos_arquivos_chave_sistema_normalizar((string)($campo_row['chave_sistema'] ?? ''));
            $campo_row['interno_only'] = !empty($campo_row['interno_only']);
        }
    }

    $uploaded_by_type = strtolower(trim($uploaded_by_type));
    if (!in_array($uploaded_by_type, ['interno', 'cliente', 'fornecedor'], true)) {
        $uploaded_by_type = 'interno';
    }

    $campo_rule = eventos_arquivos_regra_por_chave_sistema((string)($campo_row['chave_sistema'] ?? ''));
    if ($uploaded_by_type === 'cliente' && $campo_row && trim((string)($campo_row['chave_sistema'] ?? '')) !== '') {
        return ['ok' => false, 'error' => 'Este campo não está disponível para envio pelo portal do cliente.'];
    }
    if ($campo_row && eventos_arquivos_campo_e_interno($campo_row) && $uploaded_by_type === 'cliente') {
        return ['ok' => false, 'error' => 'Este tipo de arquivo é interno e não pode ser enviado pelo portal do cliente.'];
    }
    if (!empty($campo_rule['pdf_only']) && !eventos_arquivos_arquivo_e_pdf($original_name, $mime_type)) {
        return ['ok' => false, 'error' => 'Este campo aceita apenas arquivo PDF.'];
    }
    if (!empty($campo_rule['interno_only']) || ($campo_row && eventos_arquivos_campo_e_interno($campo_row))) {
        $visivel_cliente = false;
    } elseif ($uploaded_by_type === 'cliente') {
        $visivel_cliente = true;
    }

    $descricao_limpa = trim((string)($descricao ?? ''));
    if (function_exists('mb_strlen') && mb_strlen($descricao_limpa, 'UTF-8') > 2000) {
        $descricao_limpa = mb_substr($descricao_limpa, 0, 2000, 'UTF-8');
    }

    $replaced_count = 0;
    $started_transaction = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started_transaction = true;
        }

        $stmt = $pdo->prepare("
            INSERT INTO eventos_arquivos_itens
                (meeting_id, campo_id, original_name, mime_type, size_bytes, storage_key, public_url, descricao, visivel_cliente, uploaded_by_type, uploaded_by_user_id, uploaded_at)
            VALUES
                (:meeting_id, :campo_id, :original_name, :mime_type, :size_bytes, :storage_key, :public_url, :descricao, :visivel_cliente, :uploaded_by_type, :uploaded_by_user_id, NOW())
            RETURNING *
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':campo_id' => $campo_id_val,
            ':original_name' => $original_name !== '' ? $original_name : 'arquivo',
            ':mime_type' => $mime_type !== '' ? $mime_type : 'application/octet-stream',
            ':size_bytes' => $size_bytes,
            ':storage_key' => $storage_key,
            ':public_url' => $public_url !== '' ? $public_url : null,
            ':descricao' => $descricao_limpa !== '' ? $descricao_limpa : null,
            ':visivel_cliente' => $visivel_cliente ? 1 : 0,
            ':uploaded_by_type' => $uploaded_by_type,
            ':uploaded_by_user_id' => $uploaded_by_user_id,
        ]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$item) {
            throw new RuntimeException('Falha ao inserir metadados do arquivo.');
        }

        $item_id = (int)($item['id'] ?? 0);
        $single_active_file = !empty($campo_rule['single_active_file']);
        if ($single_active_file && $campo_id_val !== null && $item_id > 0) {
            $stmt_prev = $pdo->prepare("
                SELECT id, original_name
                FROM eventos_arquivos_itens
                WHERE meeting_id = :meeting_id
                  AND campo_id = :campo_id
                  AND id <> :arquivo_id
                  AND deleted_at IS NULL
                ORDER BY uploaded_at DESC, id DESC
            ");
            $stmt_prev->execute([
                ':meeting_id' => $meeting_id,
                ':campo_id' => $campo_id_val,
                ':arquivo_id' => $item_id,
            ]);
            $prev_rows = $stmt_prev->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($prev_rows)) {
                $stmt_hide = $pdo->prepare("
                    UPDATE eventos_arquivos_itens
                    SET deleted_at = NOW(),
                        deleted_by_user_id = :user_id
                    WHERE id = :id
                      AND meeting_id = :meeting_id
                      AND deleted_at IS NULL
                ");
                foreach ($prev_rows as $prev) {
                    $prev_id = (int)($prev['id'] ?? 0);
                    if ($prev_id <= 0) {
                        continue;
                    }
                    $stmt_hide->execute([
                        ':user_id' => $uploaded_by_user_id,
                        ':id' => $prev_id,
                        ':meeting_id' => $meeting_id,
                    ]);
                    if ($stmt_hide->rowCount() > 0) {
                        $replaced_count++;
                        $prev_name = trim((string)($prev['original_name'] ?? 'arquivo'));
                        eventos_arquivos_log_registrar(
                            $pdo,
                            $meeting_id,
                            $campo_id_val,
                            $prev_id,
                            'arquivo_substituido',
                            'Arquivo "' . $prev_name . '" substituído por "' . ($item['original_name'] ?? 'arquivo') . '".',
                            $uploaded_by_type,
                            $uploaded_by_user_id
                        );
                    }
                }
            }
        }

        eventos_arquivos_log_registrar(
            $pdo,
            $meeting_id,
            $campo_id_val,
            $item_id > 0 ? $item_id : null,
            'arquivo_enviado',
            'Arquivo "' . ($item['original_name'] ?? 'arquivo') . '" anexado.',
            $uploaded_by_type,
            $uploaded_by_user_id
        );

        if ($started_transaction) {
            $pdo->commit();
        }

        return [
            'ok' => true,
            'arquivo' => $item,
            'replaced_count' => $replaced_count,
            'field_rule' => $campo_rule,
        ];
    } catch (Throwable $e) {
        if ($started_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('eventos_arquivos_salvar_item: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar arquivo.'];
    }
}

/**
 * Atualiza visibilidade de um arquivo para o cliente.
 */
function eventos_arquivos_atualizar_visibilidade(PDO $pdo, int $meeting_id, int $arquivo_id, bool $visivel_cliente, int $user_id = 0): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0 || $arquivo_id <= 0) {
        return ['ok' => false, 'error' => 'Dados inválidos.'];
    }

    try {
        $stmt_file = $pdo->prepare("
            SELECT
                i.id,
                i.campo_id,
                i.original_name,
                c.chave_sistema,
                COALESCE(c.interno_only, FALSE) AS interno_only
            FROM eventos_arquivos_itens i
            LEFT JOIN eventos_arquivos_campos c
              ON c.id = i.campo_id
             AND c.deleted_at IS NULL
            WHERE i.id = :id
              AND i.meeting_id = :meeting_id
              AND i.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt_file->execute([
            ':id' => $arquivo_id,
            ':meeting_id' => $meeting_id,
        ]);
        $file_row = $stmt_file->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$file_row) {
            return ['ok' => false, 'error' => 'Arquivo não encontrado.'];
        }

        $campo_ref = [
            'chave_sistema' => eventos_arquivos_chave_sistema_normalizar((string)($file_row['chave_sistema'] ?? '')),
            'interno_only' => !empty($file_row['interno_only']),
        ];
        if (eventos_arquivos_campo_e_interno($campo_ref) && $visivel_cliente) {
            return ['ok' => false, 'error' => 'Este arquivo é interno e não pode ficar visível no portal do cliente.'];
        }

        $stmt = $pdo->prepare("
            UPDATE eventos_arquivos_itens
            SET visivel_cliente = :visivel_cliente
            WHERE id = :id
              AND meeting_id = :meeting_id
              AND deleted_at IS NULL
            RETURNING *
        ");
        $stmt->execute([
            ':visivel_cliente' => $visivel_cliente ? 1 : 0,
            ':id' => $arquivo_id,
            ':meeting_id' => $meeting_id,
        ]);
        $arquivo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$arquivo) {
            return ['ok' => false, 'error' => 'Arquivo não encontrado.'];
        }

        $nome = trim((string)($file_row['original_name'] ?? 'arquivo'));
        eventos_arquivos_log_registrar(
            $pdo,
            $meeting_id,
            isset($file_row['campo_id']) ? (int)$file_row['campo_id'] : null,
            $arquivo_id,
            'visibilidade_alterada',
            $visivel_cliente
                ? 'Arquivo "' . $nome . '" marcado como visível no portal do cliente.'
                : 'Arquivo "' . $nome . '" ocultado do portal do cliente.',
            'interno',
            $user_id > 0 ? $user_id : null
        );

        return ['ok' => true, 'arquivo' => $arquivo];
    } catch (Throwable $e) {
        error_log('eventos_arquivos_atualizar_visibilidade: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao atualizar visibilidade do arquivo.'];
    }
}

/**
 * Remove (soft delete) um arquivo do módulo.
 */
function eventos_arquivos_excluir_item(PDO $pdo, int $meeting_id, int $arquivo_id, int $user_id = 0): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0 || $arquivo_id <= 0) {
        return ['ok' => false, 'error' => 'Dados inválidos.'];
    }

    try {
        $stmt_file = $pdo->prepare("
            SELECT id, campo_id, original_name
            FROM eventos_arquivos_itens
            WHERE id = :id
              AND meeting_id = :meeting_id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt_file->execute([
            ':id' => $arquivo_id,
            ':meeting_id' => $meeting_id,
        ]);
        $file_row = $stmt_file->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$file_row) {
            return ['ok' => false, 'error' => 'Arquivo não encontrado.'];
        }

        $stmt = $pdo->prepare("
            UPDATE eventos_arquivos_itens
            SET deleted_at = NOW(),
                deleted_by_user_id = :user_id
            WHERE id = :id
              AND meeting_id = :meeting_id
              AND deleted_at IS NULL
            RETURNING *
        ");
        $stmt->execute([
            ':user_id' => $user_id > 0 ? $user_id : null,
            ':id' => $arquivo_id,
            ':meeting_id' => $meeting_id,
        ]);
        $arquivo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$arquivo) {
            return ['ok' => false, 'error' => 'Arquivo não encontrado.'];
        }

        $nome = trim((string)($file_row['original_name'] ?? 'arquivo'));
        eventos_arquivos_log_registrar(
            $pdo,
            $meeting_id,
            isset($file_row['campo_id']) ? (int)$file_row['campo_id'] : null,
            $arquivo_id,
            'arquivo_excluido',
            'Arquivo "' . $nome . '" removido.',
            'interno',
            $user_id > 0 ? $user_id : null
        );

        return ['ok' => true, 'arquivo' => $arquivo];
    } catch (Throwable $e) {
        error_log('eventos_arquivos_excluir_item: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao excluir arquivo.'];
    }
}

/**
 * Registra log do módulo de arquivos (best effort).
 */
function eventos_arquivos_log_registrar(
    PDO $pdo,
    int $meeting_id,
    ?int $campo_id,
    ?int $arquivo_id,
    string $action_type,
    ?string $action_note = null,
    string $actor_type = 'interno',
    ?int $actor_user_id = null
): void {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return;
    }
    $action = trim($action_type);
    if ($action === '') {
        return;
    }
    $actor = strtolower(trim($actor_type));
    if (!in_array($actor, ['interno', 'cliente', 'fornecedor', 'sistema'], true)) {
        $actor = 'interno';
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO eventos_arquivos_logs
                (meeting_id, campo_id, arquivo_id, action_type, action_note, actor_type, actor_user_id, created_at)
            VALUES
                (:meeting_id, :campo_id, :arquivo_id, :action_type, :action_note, :actor_type, :actor_user_id, NOW())
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':campo_id' => ($campo_id !== null && $campo_id > 0) ? $campo_id : null,
            ':arquivo_id' => ($arquivo_id !== null && $arquivo_id > 0) ? $arquivo_id : null,
            ':action_type' => $action,
            ':action_note' => trim((string)($action_note ?? '')) ?: null,
            ':actor_type' => $actor,
            ':actor_user_id' => ($actor_user_id !== null && $actor_user_id > 0) ? $actor_user_id : null,
        ]);
    } catch (Throwable $e) {
        error_log('eventos_arquivos_log_registrar: ' . $e->getMessage());
    }
}

/**
 * Rótulo amigável para ação de log de anexos.
 */
function eventos_arquivos_log_label(string $action_type): string {
    $type = strtolower(trim($action_type));
    switch ($type) {
        case 'arquivo_enviado':
            return 'Arquivo anexado';
        case 'arquivo_substituido':
            return 'Arquivo substituído';
        case 'arquivo_excluido':
            return 'Arquivo removido';
        case 'visibilidade_alterada':
            return 'Visibilidade alterada';
        default:
            return ucfirst(str_replace('_', ' ', $type));
    }
}

/**
 * Lista logs do módulo de arquivos por reunião.
 */
function eventos_arquivos_logs_listar(PDO $pdo, int $meeting_id, ?int $campo_id = null, int $limit = 150): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return [];
    }

    $limit = max(1, min(500, (int)$limit));
    $where_campo = ($campo_id !== null && $campo_id > 0) ? "AND l.campo_id = :campo_id" : '';

    $sql = "
        SELECT
            l.*,
            c.titulo AS campo_titulo,
            c.chave_sistema AS campo_chave_sistema,
            a.original_name AS arquivo_nome,
            u.nome AS actor_user_nome
        FROM eventos_arquivos_logs l
        LEFT JOIN eventos_arquivos_campos c
          ON c.id = l.campo_id
        LEFT JOIN eventos_arquivos_itens a
          ON a.id = l.arquivo_id
        LEFT JOIN usuarios u
          ON u.id = l.actor_user_id
        WHERE l.meeting_id = :meeting_id
          {$where_campo}
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT :limit_rows
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':meeting_id', $meeting_id, PDO::PARAM_INT);
        if ($campo_id !== null && $campo_id > 0) {
            $stmt->bindValue(':campo_id', (int)$campo_id, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['id'] = (int)($row['id'] ?? 0);
            $row['meeting_id'] = (int)($row['meeting_id'] ?? 0);
            $row['campo_id'] = isset($row['campo_id']) ? (int)$row['campo_id'] : null;
            $row['arquivo_id'] = isset($row['arquivo_id']) ? (int)$row['arquivo_id'] : null;
            $row['actor_user_id'] = isset($row['actor_user_id']) ? (int)$row['actor_user_id'] : null;
            $row['action_type'] = trim((string)($row['action_type'] ?? ''));
            $row['action_label'] = eventos_arquivos_log_label((string)($row['action_type'] ?? ''));
            $row['action_note'] = trim((string)($row['action_note'] ?? ''));
            $row['campo_titulo'] = trim((string)($row['campo_titulo'] ?? ''));
            $row['campo_chave_sistema'] = eventos_arquivos_chave_sistema_normalizar((string)($row['campo_chave_sistema'] ?? ''));
            $row['arquivo_nome'] = trim((string)($row['arquivo_nome'] ?? ''));
            $row['actor_type'] = trim((string)($row['actor_type'] ?? 'interno'));
            $row['actor_user_nome'] = trim((string)($row['actor_user_nome'] ?? ''));
        }
        unset($row);

        return $rows;
    } catch (Throwable $e) {
        error_log('eventos_arquivos_logs_listar: ' . $e->getMessage());
        return [];
    }
}

/**
 * Resumo geral do módulo de arquivos por reunião.
 */
function eventos_arquivos_resumo(PDO $pdo, int $meeting_id): array {
    eventos_reuniao_ensure_schema($pdo);
    $empty = [
        'campos_total' => 0,
        'campos_obrigatorios' => 0,
        'campos_pendentes' => 0,
        'arquivos_total' => 0,
        'arquivos_visiveis_cliente' => 0,
        'arquivos_cliente' => 0,
    ];
    if ($meeting_id <= 0) {
        return $empty;
    }

    try {
        $stmt_campos = $pdo->prepare("
            SELECT
                COUNT(*) AS campos_total,
                SUM(CASE WHEN obrigatorio_cliente THEN 1 ELSE 0 END) AS campos_obrigatorios
            FROM eventos_arquivos_campos
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
              AND COALESCE(interno_only, FALSE) = FALSE
              AND COALESCE(chave_sistema, '') = ''
              AND ativo = TRUE
        ");
        $stmt_campos->execute([':meeting_id' => $meeting_id]);
        $campos = $stmt_campos->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt_pendentes = $pdo->prepare("
            SELECT COUNT(*)
            FROM eventos_arquivos_campos c
            WHERE c.meeting_id = :meeting_id
              AND c.deleted_at IS NULL
              AND COALESCE(c.interno_only, FALSE) = FALSE
              AND COALESCE(c.chave_sistema, '') = ''
              AND c.ativo = TRUE
              AND c.obrigatorio_cliente = TRUE
              AND NOT EXISTS (
                  SELECT 1
                  FROM eventos_arquivos_itens i
                  WHERE i.campo_id = c.id
                    AND i.deleted_at IS NULL
              )
        ");
        $stmt_pendentes->execute([':meeting_id' => $meeting_id]);
        $campos_pendentes = (int)($stmt_pendentes->fetchColumn() ?: 0);

        $stmt_arquivos = $pdo->prepare("
            SELECT
                COUNT(*) AS arquivos_total,
                SUM(CASE WHEN visivel_cliente THEN 1 ELSE 0 END) AS arquivos_visiveis_cliente,
                SUM(CASE WHEN uploaded_by_type = 'cliente' THEN 1 ELSE 0 END) AS arquivos_cliente
            FROM eventos_arquivos_itens
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
        ");
        $stmt_arquivos->execute([':meeting_id' => $meeting_id]);
        $arquivos = $stmt_arquivos->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'campos_total' => (int)($campos['campos_total'] ?? 0),
            'campos_obrigatorios' => (int)($campos['campos_obrigatorios'] ?? 0),
            'campos_pendentes' => $campos_pendentes,
            'arquivos_total' => (int)($arquivos['arquivos_total'] ?? 0),
            'arquivos_visiveis_cliente' => (int)($arquivos['arquivos_visiveis_cliente'] ?? 0),
            'arquivos_cliente' => (int)($arquivos['arquivos_cliente'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('eventos_arquivos_resumo: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * Tipo de evento para lista de convidados.
 */
function eventos_convidados_normalizar_tipo_evento(string $tipo_evento): string {
    $tipo = strtolower(trim($tipo_evento));
    if (in_array($tipo, ['mesa', '15anos_casamento', '15anos', 'casamento'], true)) {
        return 'mesa';
    }
    return 'infantil';
}

/**
 * Opções de faixa etária por tipo de evento.
 */
function eventos_convidados_opcoes_faixa_etaria(string $tipo_evento): array {
    $tipo = eventos_convidados_normalizar_tipo_evento($tipo_evento);
    if ($tipo === 'mesa') {
        return ['0 a 8 anos', '9 anos em diante'];
    }
    return ['0 a 4 anos', '5 a 8 anos', '9 anos em diante'];
}

/**
 * Retorna se o tipo atual usa número de mesa.
 */
function eventos_convidados_tipo_usa_mesa(string $tipo_evento): bool {
    return eventos_convidados_normalizar_tipo_evento($tipo_evento) === 'mesa';
}

/**
 * Sugere tipo de evento com base no snapshot da reunião.
 */
function eventos_convidados_sugerir_tipo_por_reuniao(PDO $pdo, int $meeting_id): string {
    if ($meeting_id <= 0) {
        return 'infantil';
    }
    $reuniao = eventos_reuniao_get($pdo, $meeting_id);
    if (!$reuniao) {
        return 'infantil';
    }

    $tipo_real = eventos_reuniao_normalizar_tipo_evento_real((string)($reuniao['tipo_evento_real'] ?? ''));
    if ($tipo_real !== '') {
        return eventos_reuniao_tipo_real_para_convidados_tipo($tipo_real);
    }

    $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    $tipo_real_snapshot = eventos_reuniao_normalizar_tipo_evento_real((string)($snapshot['tipo_evento_real'] ?? ''));
    if ($tipo_real_snapshot !== '') {
        return eventos_reuniao_tipo_real_para_convidados_tipo($tipo_real_snapshot);
    }

    $tipo_raw = strtolower(trim((string)($snapshot['tipo_evento'] ?? $snapshot['tipoevento'] ?? '')));
    if ($tipo_raw !== '') {
        if (str_contains($tipo_raw, '15') || str_contains($tipo_raw, 'casamento')) {
            return 'mesa';
        }
    }
    return 'infantil';
}

/**
 * Normaliza payload de convidado.
 */
function eventos_convidados_normalizar_linha(array $row): array {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['meeting_id'] = (int)($row['meeting_id'] ?? 0);
    $row['nome'] = trim((string)($row['nome'] ?? ''));
    $row['faixa_etaria'] = trim((string)($row['faixa_etaria'] ?? ''));
    $row['numero_mesa'] = trim((string)($row['numero_mesa'] ?? ''));
    $row['checkin_at'] = isset($row['checkin_at']) && $row['checkin_at'] !== null ? (string)$row['checkin_at'] : null;
    $row['checkin_by_user_id'] = isset($row['checkin_by_user_id']) && $row['checkin_by_user_id'] !== null ? (int)$row['checkin_by_user_id'] : null;
    $row['created_by_type'] = trim((string)($row['created_by_type'] ?? 'cliente'));
    $row['is_draft'] = !empty($row['is_draft']);
    $row['is_checked_in'] = !empty($row['checkin_at']);
    return $row;
}

/**
 * Busca (ou cria) configuração da lista de convidados.
 */
function eventos_convidados_get_config(PDO $pdo, int $meeting_id): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return [
            'meeting_id' => 0,
            'tipo_evento' => 'infantil',
            'usa_mesa' => false,
            'opcoes_faixa' => eventos_convidados_opcoes_faixa_etaria('infantil'),
        ];
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_convidados_config
        WHERE meeting_id = :meeting_id
        LIMIT 1
    ");
    $stmt->execute([':meeting_id' => $meeting_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$row) {
        $tipo_default = eventos_convidados_sugerir_tipo_por_reuniao($pdo, $meeting_id);
        $ins = $pdo->prepare("
            INSERT INTO eventos_convidados_config
            (meeting_id, tipo_evento, updated_by_type, updated_by_user_id, created_at, updated_at)
            VALUES
            (:meeting_id, :tipo_evento, 'interno', NULL, NOW(), NOW())
            ON CONFLICT (meeting_id) DO UPDATE
            SET updated_at = eventos_convidados_config.updated_at
            RETURNING *
        ");
        $ins->execute([
            ':meeting_id' => $meeting_id,
            ':tipo_evento' => $tipo_default,
        ]);
        $row = $ins->fetch(PDO::FETCH_ASSOC) ?: [
            'meeting_id' => $meeting_id,
            'tipo_evento' => $tipo_default,
        ];
    }

    $tipo = eventos_convidados_normalizar_tipo_evento((string)($row['tipo_evento'] ?? 'infantil'));
    return [
        'meeting_id' => (int)($row['meeting_id'] ?? $meeting_id),
        'tipo_evento' => $tipo,
        'usa_mesa' => eventos_convidados_tipo_usa_mesa($tipo),
        'opcoes_faixa' => eventos_convidados_opcoes_faixa_etaria($tipo),
        'updated_at' => isset($row['updated_at']) && $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
    ];
}

/**
 * Atualiza o tipo de evento da lista de convidados.
 */
function eventos_convidados_salvar_config(
    PDO $pdo,
    int $meeting_id,
    string $tipo_evento,
    string $updated_by_type = 'interno',
    int $updated_by_user_id = 0
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $tipo = eventos_convidados_normalizar_tipo_evento($tipo_evento);
    $author = trim($updated_by_type) !== '' ? trim($updated_by_type) : 'interno';

    $stmt = $pdo->prepare("
        INSERT INTO eventos_convidados_config
        (meeting_id, tipo_evento, updated_by_type, updated_by_user_id, created_at, updated_at)
        VALUES
        (:meeting_id, :tipo_evento, :updated_by_type, :updated_by_user_id, NOW(), NOW())
        ON CONFLICT (meeting_id) DO UPDATE
        SET tipo_evento = EXCLUDED.tipo_evento,
            updated_by_type = EXCLUDED.updated_by_type,
            updated_by_user_id = EXCLUDED.updated_by_user_id,
            updated_at = NOW()
        RETURNING *
    ");
    $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':tipo_evento' => $tipo,
        ':updated_by_type' => $author,
        ':updated_by_user_id' => $updated_by_user_id > 0 ? $updated_by_user_id : null,
    ]);

    $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$saved) {
        return ['ok' => false, 'error' => 'Não foi possível salvar o tipo do evento'];
    }

    return ['ok' => true, 'config' => eventos_convidados_get_config($pdo, $meeting_id)];
}

/**
 * Ordenação padrão da lista de convidados (mesa + alfabética).
 */
function eventos_convidados_order_sql(): string {
    return "
        CASE WHEN COALESCE(numero_mesa, '') = '' THEN 1 ELSE 0 END ASC,
        CASE
            WHEN COALESCE(numero_mesa, '') ~ '^[0-9]+$' THEN LPAD(numero_mesa, 10, '0')
            ELSE LOWER(COALESCE(numero_mesa, ''))
        END ASC,
        LOWER(nome) ASC,
        id ASC
    ";
}

/**
 * Lista convidados por reunião.
 */
function eventos_convidados_listar(PDO $pdo, int $meeting_id, string $search = ''): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return [];
    }

    $search = trim($search);
    $params = [':meeting_id' => $meeting_id];
    $where_search = '';
    if ($search !== '') {
        $where_search = " AND nome ILIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_convidados
        WHERE meeting_id = :meeting_id
          AND deleted_at IS NULL
          {$where_search}
        ORDER BY " . eventos_convidados_order_sql()
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map('eventos_convidados_normalizar_linha', $rows);
}

/**
 * Resumo de convidados.
 */
function eventos_convidados_resumo(PDO $pdo, int $meeting_id): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['total' => 0, 'checkin' => 0, 'pendentes' => 0, 'rascunho' => 0, 'publicados' => 0];
    }

    $has_is_draft_col = eventos_reuniao_has_column($pdo, 'eventos_convidados', 'is_draft');
    if ($has_is_draft_col) {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)::int AS total,
                COALESCE(SUM(CASE WHEN checkin_at IS NOT NULL THEN 1 ELSE 0 END), 0)::int AS checkin,
                COALESCE(SUM(CASE WHEN COALESCE(is_draft, FALSE) THEN 1 ELSE 0 END), 0)::int AS rascunho
            FROM eventos_convidados
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)::int AS total,
                COALESCE(SUM(CASE WHEN checkin_at IS NOT NULL THEN 1 ELSE 0 END), 0)::int AS checkin
            FROM eventos_convidados
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
        ");
    }
    $stmt->execute([':meeting_id' => $meeting_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'checkin' => 0, 'rascunho' => 0];
    $total = (int)($row['total'] ?? 0);
    $checkin = (int)($row['checkin'] ?? 0);
    $rascunho = (int)($row['rascunho'] ?? 0);
    return [
        'total' => $total,
        'checkin' => $checkin,
        'pendentes' => max(0, $total - $checkin),
        'rascunho' => $rascunho,
        'publicados' => max(0, $total - $rascunho),
    ];
}

/**
 * Sanitiza nome de convidado.
 */
function eventos_convidados_normalizar_nome(string $nome): string {
    $nome = trim($nome);
    $nome = preg_replace('/\s+/u', ' ', $nome);
    $nome = trim((string)$nome);
    if ($nome === '') {
        return '';
    }
    $max = function_exists('mb_substr') ? mb_substr($nome, 0, 180) : substr($nome, 0, 180);
    return trim((string)$max);
}

/**
 * Sanitiza número da mesa.
 */
function eventos_convidados_normalizar_mesa(?string $numero_mesa): string {
    $mesa = trim((string)$numero_mesa);
    if ($mesa === '') {
        return '';
    }
    $mesa = preg_replace('/\s+/u', ' ', $mesa);
    $mesa = trim((string)$mesa);
    $mesa = function_exists('mb_substr') ? mb_substr($mesa, 0, 20) : substr($mesa, 0, 20);
    return trim((string)$mesa);
}

/**
 * Valida faixa etária conforme tipo.
 */
function eventos_convidados_validar_faixa(?string $faixa_etaria, string $tipo_evento): bool {
    $faixa = trim((string)$faixa_etaria);
    if ($faixa === '') {
        return true;
    }
    return in_array($faixa, eventos_convidados_opcoes_faixa_etaria($tipo_evento), true);
}

/**
 * Cadastra convidado.
 */
function eventos_convidados_adicionar(
    PDO $pdo,
    int $meeting_id,
    string $nome,
    ?string $faixa_etaria = null,
    ?string $numero_mesa = null,
    string $created_by_type = 'cliente',
    int $created_by_user_id = 0,
    bool $is_draft = false
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $nome_norm = eventos_convidados_normalizar_nome($nome);
    if ($nome_norm === '') {
        return ['ok' => false, 'error' => 'Informe o nome do convidado'];
    }

    $config = eventos_convidados_get_config($pdo, $meeting_id);
    $tipo = (string)($config['tipo_evento'] ?? 'infantil');
    $faixa = trim((string)$faixa_etaria);
    if (!eventos_convidados_validar_faixa($faixa, $tipo)) {
        return ['ok' => false, 'error' => 'Faixa etária inválida para o tipo de evento selecionado'];
    }
    $mesa = eventos_convidados_tipo_usa_mesa($tipo) ? eventos_convidados_normalizar_mesa($numero_mesa) : '';

    $has_is_draft_col = eventos_reuniao_has_column($pdo, 'eventos_convidados', 'is_draft');
    $columns = ['meeting_id', 'nome', 'faixa_etaria', 'numero_mesa', 'created_by_type', 'created_by_user_id', 'created_at', 'updated_at'];
    $values = [':meeting_id', ':nome', ':faixa_etaria', ':numero_mesa', ':created_by_type', ':created_by_user_id', 'NOW()', 'NOW()'];
    $params = [
        ':meeting_id' => $meeting_id,
        ':nome' => $nome_norm,
        ':faixa_etaria' => $faixa !== '' ? $faixa : null,
        ':numero_mesa' => $mesa !== '' ? $mesa : null,
        ':created_by_type' => trim($created_by_type) !== '' ? trim($created_by_type) : 'cliente',
        ':created_by_user_id' => $created_by_user_id > 0 ? $created_by_user_id : null,
    ];
    if ($has_is_draft_col) {
        $columns[] = 'is_draft';
        $values[] = ':is_draft';
        $params[':is_draft'] = $is_draft ? 1 : 0;
    }

    $stmt = $pdo->prepare("
        INSERT INTO eventos_convidados
        (" . implode(', ', $columns) . ")
        VALUES
        (" . implode(', ', $values) . ")
        RETURNING *
    ");
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return ['ok' => false, 'error' => 'Não foi possível adicionar convidado'];
    }

    return ['ok' => true, 'convidado' => eventos_convidados_normalizar_linha($row)];
}

/**
 * Atualiza convidado existente.
 */
function eventos_convidados_atualizar(
    PDO $pdo,
    int $meeting_id,
    int $guest_id,
    string $nome,
    ?string $faixa_etaria = null,
    ?string $numero_mesa = null,
    int $updated_by_user_id = 0,
    ?bool $set_draft = null
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0 || $guest_id <= 0) {
        return ['ok' => false, 'error' => 'Convidado inválido'];
    }

    $nome_norm = eventos_convidados_normalizar_nome($nome);
    if ($nome_norm === '') {
        return ['ok' => false, 'error' => 'Informe o nome do convidado'];
    }

    $config = eventos_convidados_get_config($pdo, $meeting_id);
    $tipo = (string)($config['tipo_evento'] ?? 'infantil');
    $faixa = trim((string)$faixa_etaria);
    if (!eventos_convidados_validar_faixa($faixa, $tipo)) {
        return ['ok' => false, 'error' => 'Faixa etária inválida para o tipo de evento selecionado'];
    }
    $mesa = eventos_convidados_tipo_usa_mesa($tipo) ? eventos_convidados_normalizar_mesa($numero_mesa) : '';

    $set_parts = [
        'nome = :nome',
        'faixa_etaria = :faixa_etaria',
        'numero_mesa = :numero_mesa',
        'updated_by_user_id = :updated_by_user_id',
        'updated_at = NOW()',
    ];
    $params = [
        ':id' => $guest_id,
        ':meeting_id' => $meeting_id,
        ':nome' => $nome_norm,
        ':faixa_etaria' => $faixa !== '' ? $faixa : null,
        ':numero_mesa' => $mesa !== '' ? $mesa : null,
        ':updated_by_user_id' => $updated_by_user_id > 0 ? $updated_by_user_id : null,
    ];

    $has_is_draft_col = eventos_reuniao_has_column($pdo, 'eventos_convidados', 'is_draft');
    if ($has_is_draft_col && $set_draft !== null) {
        $set_parts[] = 'is_draft = :is_draft';
        $params[':is_draft'] = $set_draft ? 1 : 0;
    }

    $stmt = $pdo->prepare("
        UPDATE eventos_convidados
        SET " . implode(', ', $set_parts) . "
        WHERE id = :id
          AND meeting_id = :meeting_id
          AND deleted_at IS NULL
        RETURNING *
    ");
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return ['ok' => false, 'error' => 'Convidado não encontrado'];
    }

    return ['ok' => true, 'convidado' => eventos_convidados_normalizar_linha($row)];
}

/**
 * Remove convidado (soft delete).
 */
function eventos_convidados_excluir(PDO $pdo, int $meeting_id, int $guest_id, int $updated_by_user_id = 0): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0 || $guest_id <= 0) {
        return ['ok' => false, 'error' => 'Convidado inválido'];
    }

    $stmt = $pdo->prepare("
        UPDATE eventos_convidados
        SET deleted_at = NOW(),
            updated_by_user_id = :updated_by_user_id,
            updated_at = NOW()
        WHERE id = :id
          AND meeting_id = :meeting_id
          AND deleted_at IS NULL
    ");
    $stmt->execute([
        ':id' => $guest_id,
        ':meeting_id' => $meeting_id,
        ':updated_by_user_id' => $updated_by_user_id > 0 ? $updated_by_user_id : null,
    ]);

    if ($stmt->rowCount() <= 0) {
        return ['ok' => false, 'error' => 'Convidado não encontrado'];
    }

    return ['ok' => true];
}

/**
 * Atualiza status de check-in.
 */
function eventos_convidados_toggle_checkin(
    PDO $pdo,
    int $meeting_id,
    int $guest_id,
    bool $checked_in,
    int $user_id = 0
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0 || $guest_id <= 0) {
        return ['ok' => false, 'error' => 'Convidado inválido'];
    }

    $has_is_draft_col = eventos_reuniao_has_column($pdo, 'eventos_convidados', 'is_draft');
    if ($has_is_draft_col) {
        $stmt_draft = $pdo->prepare("
            SELECT COALESCE(is_draft, FALSE) AS is_draft
            FROM eventos_convidados
            WHERE id = :id
              AND meeting_id = :meeting_id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt_draft->execute([
            ':id' => $guest_id,
            ':meeting_id' => $meeting_id,
        ]);
        $draft_row = $stmt_draft->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($draft_row && !empty($draft_row['is_draft'])) {
            return ['ok' => false, 'error' => 'Convidado ainda em rascunho. Clique em "Salvar geral" no portal do cliente.'];
        }
    }

    if ($checked_in) {
        $stmt = $pdo->prepare("
            UPDATE eventos_convidados
            SET checkin_at = NOW(),
                checkin_by_user_id = :user_id,
                updated_by_user_id = :user_id,
                updated_at = NOW()
            WHERE id = :id
              AND meeting_id = :meeting_id
              AND deleted_at IS NULL
            RETURNING *
        ");
        $stmt->execute([
            ':id' => $guest_id,
            ':meeting_id' => $meeting_id,
            ':user_id' => $user_id > 0 ? $user_id : null,
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE eventos_convidados
            SET checkin_at = NULL,
                checkin_by_user_id = NULL,
                updated_by_user_id = :user_id,
                updated_at = NOW()
            WHERE id = :id
              AND meeting_id = :meeting_id
              AND deleted_at IS NULL
            RETURNING *
        ");
        $stmt->execute([
            ':id' => $guest_id,
            ':meeting_id' => $meeting_id,
            ':user_id' => $user_id > 0 ? $user_id : null,
        ]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return ['ok' => false, 'error' => 'Convidado não encontrado'];
    }

    return ['ok' => true, 'convidado' => eventos_convidados_normalizar_linha($row)];
}

/**
 * Publica todos os convidados em rascunho do cliente para um evento.
 */
function eventos_convidados_publicar_rascunhos_cliente(PDO $pdo, int $meeting_id, int $updated_by_user_id = 0): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $has_is_draft_col = eventos_reuniao_has_column($pdo, 'eventos_convidados', 'is_draft');
    if (!$has_is_draft_col) {
        return ['ok' => true, 'updated' => 0, 'resumo' => eventos_convidados_resumo($pdo, $meeting_id)];
    }

    $stmt = $pdo->prepare("
        UPDATE eventos_convidados
        SET is_draft = FALSE,
            updated_by_user_id = :updated_by_user_id,
            updated_at = NOW()
        WHERE meeting_id = :meeting_id
          AND deleted_at IS NULL
          AND created_by_type = 'cliente'
          AND COALESCE(is_draft, FALSE) = TRUE
    ");
    $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':updated_by_user_id' => $updated_by_user_id > 0 ? $updated_by_user_id : null,
    ]);

    return [
        'ok' => true,
        'updated' => (int)$stmt->rowCount(),
        'resumo' => eventos_convidados_resumo($pdo, $meeting_id),
    ];
}

/**
 * Parseia texto cru em nomes de convidados.
 */
function eventos_convidados_parse_texto_cru(string $texto): array {
    $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = str_replace("\r", "\n", $texto);

    $linhas = preg_split('/\R+/u', $texto) ?: [];
    if (count($linhas) === 1 && preg_match('/[,;]+/u', (string)$linhas[0])) {
        $linhas = preg_split('/[,;]+/u', (string)$linhas[0]) ?: [];
    }

    $nomes = [];
    $seen = [];

    foreach ($linhas as $linha_raw) {
        $linha = trim((string)$linha_raw);
        if ($linha === '') {
            continue;
        }

        $linha = preg_replace('/^[\-\*\+\•\·\–\—\s]+/u', '', $linha) ?? $linha;
        $linha = preg_replace('/^\d{1,4}\s*[\.\)\-\:]\s*/u', '', $linha) ?? $linha;
        $linha = trim($linha, " \t\n\r\0\x0B,;");
        $linha = preg_replace('/\s+/u', ' ', $linha) ?? $linha;
        $linha = trim((string)$linha);

        if ($linha === '') {
            continue;
        }

        $len = function_exists('mb_strlen') ? mb_strlen($linha, 'UTF-8') : strlen($linha);
        if ($len < 2) {
            continue;
        }

        if ($len > 180) {
            $linha = function_exists('mb_substr') ? mb_substr($linha, 0, 180, 'UTF-8') : substr($linha, 0, 180);
        }

        $key = function_exists('mb_strtolower') ? mb_strtolower($linha, 'UTF-8') : strtolower($linha);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $nomes[] = $linha;
    }

    return $nomes;
}

/**
 * Importa lista de convidados via texto cru.
 */
function eventos_convidados_importar_texto_cru(
    PDO $pdo,
    int $meeting_id,
    string $texto,
    string $created_by_type = 'cliente',
    int $created_by_user_id = 0
): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $nomes = eventos_convidados_parse_texto_cru($texto);
    if (empty($nomes)) {
        return ['ok' => false, 'error' => 'Nenhum nome válido encontrado para importar'];
    }

    $existing_stmt = $pdo->prepare("
        SELECT lower(nome) AS nome_key
        FROM eventos_convidados
        WHERE meeting_id = :meeting_id
          AND deleted_at IS NULL
    ");
    $existing_stmt->execute([':meeting_id' => $meeting_id]);
    $existing_rows = $existing_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $existing_map = [];
    foreach ($existing_rows as $row) {
        $key = trim((string)($row['nome_key'] ?? ''));
        if ($key !== '') {
            $existing_map[$key] = true;
        }
    }

    $inserted = 0;
    $skipped = 0;

    $ins = $pdo->prepare("
        INSERT INTO eventos_convidados
        (meeting_id, nome, faixa_etaria, numero_mesa, created_by_type, created_by_user_id, created_at, updated_at)
        VALUES
        (:meeting_id, :nome, NULL, NULL, :created_by_type, :created_by_user_id, NOW(), NOW())
    ");

    foreach ($nomes as $nome) {
        $key = function_exists('mb_strtolower') ? mb_strtolower($nome, 'UTF-8') : strtolower($nome);
        if (isset($existing_map[$key])) {
            $skipped++;
            continue;
        }

        $ins->execute([
            ':meeting_id' => $meeting_id,
            ':nome' => $nome,
            ':created_by_type' => trim($created_by_type) !== '' ? trim($created_by_type) : 'cliente',
            ':created_by_user_id' => $created_by_user_id > 0 ? $created_by_user_id : null,
        ]);
        $existing_map[$key] = true;
        $inserted++;
    }

    return [
        'ok' => true,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'total_detected' => count($nomes),
    ];
}
