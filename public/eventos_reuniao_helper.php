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
    string $author_type = 'interno'
): array {
    try {
        $pdo->beginTransaction();
        
        // Buscar seção atual
        $secao = eventos_reuniao_get_secao($pdo, $meeting_id, $section);
        
        if (!$secao) {
            // Criar seção se não existir
            $stmt = $pdo->prepare("
                INSERT INTO eventos_reunioes_secoes (meeting_id, section, content_html, content_text, created_at, updated_at, updated_by)
                VALUES (:meeting_id, :section, :html, :text, NOW(), NOW(), :user_id)
                RETURNING *
            ");
            $stmt->execute([
                ':meeting_id' => $meeting_id,
                ':section' => $section,
                ':html' => $content_html,
                ':text' => strip_tags($content_html),
                ':user_id' => $user_id
            ]);
            $secao = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Atualizar seção
            $stmt = $pdo->prepare("
                UPDATE eventos_reunioes_secoes 
                SET content_html = :html, content_text = :text, updated_at = NOW(), updated_by = :user_id
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $secao['id'],
                ':html' => $content_html,
                ':text' => strip_tags($content_html),
                ':user_id' => $user_id
            ]);
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
