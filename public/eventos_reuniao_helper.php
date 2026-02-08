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
 * Garante estrutura necessária para formulário dinâmico da Reunião Final.
 */
function eventos_reuniao_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE eventos_reunioes_secoes ADD COLUMN IF NOT EXISTS form_schema_json JSONB");
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
        $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS slot_index INTEGER");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS form_schema_json JSONB");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS content_html_snapshot TEXT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS form_title VARCHAR(160)");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_links_publicos ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_links_slot_ativo ON eventos_links_publicos(meeting_id, link_type, slot_index, is_active)");
    } catch (Throwable $e) {
        error_log('eventos_reuniao_ensure_schema: falha ao ajustar tabela eventos_links_publicos: ' . $e->getMessage());
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
 * Normaliza schema de formulário para persistência.
 */
function eventos_form_template_normalizar_schema(array $schema): array {
    $allowed_types = ['text', 'textarea', 'yesno', 'select', 'file', 'section', 'divider'];
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
        $required = !empty($item['required']) && !in_array($type, ['section', 'divider'], true);
        $options = [];
        if ($type === 'select' && !empty($item['options']) && is_array($item['options'])) {
            foreach ($item['options'] as $opt) {
                $text = trim((string)$opt);
                if ($text !== '') {
                    $options[] = $text;
                }
            }
        }

        if ($type !== 'divider' && $label === '') {
            continue;
        }

        $id = trim((string)($item['id'] ?? ''));
        if ($id === '') {
            $id = 'f_' . bin2hex(random_bytes(4));
        }

        $normalized[] = [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'required' => $required,
            'options' => $options,
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
function eventos_reuniao_get_or_create(PDO $pdo, int $me_event_id, int $user_id): array {
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

        return ['ok' => true, 'reuniao' => $reuniao, 'created' => false];
    }
    
    // Buscar dados do evento na ME para snapshot
    $event_result = eventos_me_buscar_por_id($pdo, $me_event_id);
    if (!$event_result['ok']) {
        return ['ok' => false, 'error' => 'Evento não encontrado na ME: ' . ($event_result['error'] ?? '')];
    }
    
    $snapshot = eventos_me_criar_snapshot($event_result['event']);
    
    // Criar reunião
    $stmt = $pdo->prepare("
        INSERT INTO eventos_reunioes (me_event_id, me_event_snapshot, status, created_by, created_at, updated_at)
        VALUES (:me_event_id, :snapshot, 'rascunho', :user_id, NOW(), NOW())
        RETURNING *
    ");
    $stmt->execute([
        ':me_event_id' => $me_event_id,
        ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ':user_id' => $user_id
    ]);
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
        $pdo->beginTransaction();
        
        // Buscar seção atual
        $secao = eventos_reuniao_get_secao($pdo, $meeting_id, $section);
        
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
 * Destrava um quadro (slot) do formulário DJ para permitir nova edição do cliente.
 * Mantém o mesmo token; apenas limpa submitted_at.
 */
function eventos_reuniao_destravar_dj_slot(PDO $pdo, int $meeting_id, int $slot_index, int $user_id): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    $slot_index = max(1, min(50, (int)$slot_index));
    $has_slot_index_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index');
    $has_submitted_at_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at');

    if ($has_slot_index_col) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = 'cliente_dj'
              AND COALESCE(slot_index, 1) = :slot_index
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':slot_index' => $slot_index
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id
            FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = 'cliente_dj'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
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

    // Não usamos mais trava global da seção DJ, mas removemos caso exista (compatibilidade).
    $stmt = $pdo->prepare("
        UPDATE eventos_reunioes_secoes
        SET is_locked = FALSE, locked_at = NULL, locked_by = NULL, updated_at = NOW(), updated_by = :user_id
        WHERE meeting_id = :meeting_id AND section = 'dj_protocolo'
    ");
    $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':user_id' => $user_id
    ]);

    return ['ok' => true, 'link_id' => $link_id, 'slot_index' => $slot_index];
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
    $stmt = $pdo->prepare("
        UPDATE eventos_reunioes 
        SET status = :status, updated_at = NOW(), updated_by = :user_id
        WHERE id = :id
    ");
    return $stmt->execute([
        ':id' => $meeting_id,
        ':status' => $status,
        ':user_id' => $user_id
    ]);
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
    ?int $uploaded_by_user_id = null
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

    $stmt = $pdo->prepare("
        INSERT INTO eventos_reunioes_anexos
        (meeting_id, section, file_kind, original_name, mime_type, size_bytes, storage_key, public_url, uploaded_by_user_id, uploaded_by_type, uploaded_at)
        VALUES
        (:meeting_id, :section, :file_kind, :original_name, :mime_type, :size_bytes, :storage_key, :public_url, :uploaded_by_user_id, :uploaded_by_type, NOW())
        RETURNING *
    ");
    $stmt->execute([
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
    ]);

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
    int $slot_index = 1
): array {
    eventos_reuniao_ensure_schema($pdo);

    $slot_index = max(1, min(50, (int)$slot_index));
    $has_slot_index_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index');
    $has_schema_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'form_schema_json');
    $has_content_snapshot_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'content_html_snapshot');
    $has_form_title_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'form_title');
    $has_submitted_at_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at');

    // Verificar se já existe link ativo para esse slot.
    if ($has_slot_index_col) {
        $stmt = $pdo->prepare("
            SELECT * FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = 'cliente_dj'
              AND is_active = TRUE
              AND COALESCE(slot_index, 1) = :slot_index
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':slot_index' => $slot_index
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id AND link_type = 'cliente_dj' AND is_active = TRUE
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
    }
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($link) {
        return ['ok' => true, 'link' => $link, 'created' => false];
    }

    $secao = eventos_reuniao_get_secao($pdo, $meeting_id, 'dj_protocolo');
    if (!$secao) {
        return ['ok' => false, 'error' => 'Seção DJ/Protocolos não encontrada'];
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
        return ['ok' => false, 'error' => 'Salve o formulário da seção DJ antes de gerar o link para o cliente'];
    }

    // Se já existe token para o slot, reativa o mesmo token em vez de criar novo.
    if ($has_slot_index_col) {
        $stmt = $pdo->prepare("
            SELECT * FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = 'cliente_dj'
              AND COALESCE(slot_index, 1) = :slot_index
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':slot_index' => $slot_index
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM eventos_links_publicos
            WHERE meeting_id = :meeting_id
              AND link_type = 'cliente_dj'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
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
    $values = [':meeting_id', ':token', "'cliente_dj'", ':sections', 'TRUE', ':user_id', 'NOW()'];
    $params = [
        ':meeting_id' => $meeting_id,
        ':token' => $token,
        ':sections' => json_encode(['dj_protocolo']),
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

    $sql = "INSERT INTO eventos_links_publicos (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $values) . ")
            RETURNING *";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    return ['ok' => true, 'link' => $link, 'created' => true];
}

/**
 * Lista links públicos ativos do cliente DJ por reunião.
 */
function eventos_reuniao_listar_links_cliente(PDO $pdo, int $meeting_id): array {
    eventos_reuniao_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return [];
    }

    $has_slot_index_col = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index');
    $sql = "
        SELECT * FROM eventos_links_publicos
        WHERE meeting_id = :meeting_id
          AND link_type = 'cliente_dj'
          AND is_active = TRUE
        ORDER BY " . ($has_slot_index_col ? "COALESCE(slot_index, 1) ASC, " : "") . "id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':meeting_id' => $meeting_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $decoded = json_decode((string)($row['form_schema_json'] ?? '[]'), true);
        $row['form_schema'] = is_array($decoded) ? $decoded : [];
    }
    unset($row);

    return $rows;
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
