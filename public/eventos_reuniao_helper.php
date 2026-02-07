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

    $done = true;
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
function eventos_form_template_salvar(PDO $pdo, string $nome, string $categoria, array $schema, int $user_id): array {
    eventos_reuniao_ensure_schema($pdo);

    $nome = trim($nome);
    $categoria = trim($categoria) !== '' ? trim($categoria) : 'geral';
    if ($nome === '') {
        return ['ok' => false, 'error' => 'Nome do modelo é obrigatório'];
    }
    if (empty($schema)) {
        return ['ok' => false, 'error' => 'Adicione pelo menos um campo no formulário antes de salvar o modelo'];
    }

    $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($schemaJson === false) {
        return ['ok' => false, 'error' => 'Não foi possível serializar o modelo'];
    }

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
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return ['ok' => false, 'error' => 'Falha ao salvar modelo'];
    }

    $decoded = json_decode((string)($row['schema_json'] ?? '[]'), true);
    $row['schema'] = is_array($decoded) ? $decoded : [];
    return ['ok' => true, 'template' => $row];
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
    
    return ['ok' => true];
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
 * Gerar link público para cliente (DJ)
 */
function eventos_reuniao_gerar_link_cliente(PDO $pdo, int $meeting_id, int $user_id): array {
    // Verificar se já existe link ativo
    $stmt = $pdo->prepare("
        SELECT * FROM eventos_links_publicos 
        WHERE meeting_id = :meeting_id AND link_type = 'cliente_dj' AND is_active = TRUE
        LIMIT 1
    ");
    $stmt->execute([':meeting_id' => $meeting_id]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($link) {
        return ['ok' => true, 'link' => $link, 'created' => false];
    }
    
    // Gerar token seguro
    $token = bin2hex(random_bytes(32));
    
    // Criar link
    $stmt = $pdo->prepare("
        INSERT INTO eventos_links_publicos 
        (meeting_id, token, link_type, allowed_sections, is_active, created_by, created_at)
        VALUES (:meeting_id, :token, 'cliente_dj', :sections, TRUE, :user_id, NOW())
        RETURNING *
    ");
    $stmt->execute([
        ':meeting_id' => $meeting_id,
        ':token' => $token,
        ':sections' => json_encode(['dj_protocolo']),
        ':user_id' => $user_id
    ]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return ['ok' => true, 'link' => $link, 'created' => true];
}

/**
 * Buscar link público por token
 */
function eventos_link_publico_get(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("
        SELECT lp.*, r.me_event_snapshot, r.status as reuniao_status
        FROM eventos_links_publicos lp
        JOIN eventos_reunioes r ON r.id = lp.meeting_id
        WHERE lp.token = :token
    ");
    $stmt->execute([':token' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
