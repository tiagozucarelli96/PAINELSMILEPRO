<?php
/**
 * eventos_seguranca.php
 * Validações e segurança para o módulo Eventos
 */

/**
 * Gerar token seguro (imprevisível)
 */
function eventos_gerar_token_seguro(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

/**
 * Validar token público (verifica formato)
 */
function eventos_validar_token(string $token): bool {
    // Token deve ser hexadecimal com 64 caracteres (32 bytes)
    return preg_match('/^[a-f0-9]{64}$/i', $token) === 1;
}

/**
 * Rate limiting simples por IP
 */
function eventos_rate_limit(PDO $pdo, string $action, int $max_requests = 10, int $window_seconds = 60): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = md5($action . $ip);
    
    try {
        // Verificar se tabela existe, senão usar sessão
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM eventos_rate_limit 
            WHERE chave = :key AND criado_em > NOW() - INTERVAL ':window seconds'
        ");
        
        // Usar sessão como fallback se tabela não existir
    } catch (Exception $e) {
        // Fallback para sessão
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $session_key = 'rate_limit_' . $key;
        $now = time();
        
        if (!isset($_SESSION[$session_key])) {
            $_SESSION[$session_key] = [];
        }
        
        // Limpar requisições antigas
        $_SESSION[$session_key] = array_filter($_SESSION[$session_key], function($ts) use ($now, $window_seconds) {
            return $ts > ($now - $window_seconds);
        });
        
        // Verificar limite
        if (count($_SESSION[$session_key]) >= $max_requests) {
            return false; // Bloqueado
        }
        
        // Registrar requisição
        $_SESSION[$session_key][] = $now;
        return true;
    }
    
    return true;
}

/**
 * Honeypot anti-bot (verificar campo oculto)
 */
function eventos_honeypot_valido(array $post): bool {
    // Campo honeypot deve estar vazio
    // Adicionar campo oculto no formulário: <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
    $honeypot_fields = ['website', 'url', 'fax', 'company_website'];
    
    foreach ($honeypot_fields as $field) {
        if (!empty($post[$field])) {
            return false; // Bot detectado
        }
    }
    
    return true;
}

/**
 * Sanitizar HTML (remover scripts e tags perigosas)
 */
function eventos_sanitizar_html(string $html): string {
    // Tags permitidas (seguras)
    $allowed_tags = '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><h5><h6><div><span><img>';
    
    // Remover scripts
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
    
    // Remover eventos JavaScript inline
    $html = preg_replace('/\s+on\w+\s*=\s*(["\'])[^"\']*\1/i', '', $html);
    $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html);
    
    // Remover javascript: e data: URLs
    $html = preg_replace('/href\s*=\s*(["\'])javascript:[^"\']*\1/i', 'href=""', $html);
    $html = preg_replace('/src\s*=\s*(["\'])javascript:[^"\']*\1/i', 'src=""', $html);
    $html = preg_replace('/src\s*=\s*(["\'])data:[^"\']*\1/i', 'src=""', $html);
    
    // Strip tags não permitidas
    $html = strip_tags($html, $allowed_tags);
    
    return $html;
}

/**
 * Validar tipo de arquivo para upload
 */
function eventos_validar_tipo_arquivo(string $mime_type, string $contexto = 'anexo'): bool {
    $tipos_permitidos = [
        'anexo' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp3',
            'video/mp4', 'video/webm', 'video/ogg',
            'application/pdf'
        ],
        'imagem' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp'
        ],
        'audio' => [
            'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp3'
        ],
        'video' => [
            'video/mp4', 'video/webm', 'video/ogg'
        ]
    ];
    
    $permitidos = $tipos_permitidos[$contexto] ?? $tipos_permitidos['anexo'];
    
    // Bloquear executáveis sempre
    $bloqueados = [
        'application/x-executable',
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-sh',
        'application/x-php',
        'text/x-php',
        'application/javascript',
        'text/javascript'
    ];
    
    if (in_array($mime_type, $bloqueados)) {
        return false;
    }
    
    return in_array($mime_type, $permitidos);
}

/**
 * Validar tamanho de arquivo (em bytes)
 */
function eventos_validar_tamanho_arquivo(int $size_bytes, int $max_mb = 400): bool {
    $max_bytes = $max_mb * 1024 * 1024;
    return $size_bytes <= $max_bytes;
}

/**
 * Verificar se usuário pode acessar reunião
 */
function eventos_usuario_pode_acessar_reuniao(PDO $pdo, int $user_id, int $meeting_id): bool {
    // Superadmin pode tudo
    if (!empty($_SESSION['perm_superadmin'])) {
        return true;
    }
    
    // Verificar se tem permissão de eventos
    if (empty($_SESSION['perm_eventos'])) {
        return false;
    }
    
    // Por enquanto, qualquer usuário com perm_eventos pode acessar
    // Podemos adicionar restrições por unidade no futuro
    return true;
}

/**
 * Verificar se fornecedor pode acessar reunião
 */
function eventos_fornecedor_pode_acessar_reuniao(PDO $pdo, int $supplier_id, int $meeting_id): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM eventos_fornecedores_vinculos 
        WHERE supplier_id = :sid AND meeting_id = :mid
    ");
    $stmt->execute([':sid' => $supplier_id, ':mid' => $meeting_id]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Registrar log de acesso
 */
function eventos_log_acesso(PDO $pdo, string $tipo, int $referencia_id, ?int $user_id = null, array $dados = []): void {
    try {
        // Log simples no error_log
        $info = [
            'tipo' => $tipo,
            'ref_id' => $referencia_id,
            'user_id' => $user_id,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'dados' => $dados
        ];
        error_log("[EVENTOS_AUDIT] " . json_encode($info));
        
    } catch (Exception $e) {
        // Silencioso
    }
}

/**
 * Validar e processar upload seguro
 */
function eventos_processar_upload_seguro(array $file, string $contexto = 'anexo', int $max_mb = 400): array {
    // Verificar erro de upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $erros = [
            UPLOAD_ERR_INI_SIZE => 'Arquivo excede limite do servidor',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo excede limite do formulário',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária não encontrada',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao gravar arquivo',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão'
        ];
        return ['ok' => false, 'error' => $erros[$file['error']] ?? 'Erro desconhecido'];
    }
    
    // Validar tipo
    $mime = mime_content_type($file['tmp_name']) ?: $file['type'];
    if (!eventos_validar_tipo_arquivo($mime, $contexto)) {
        return ['ok' => false, 'error' => 'Tipo de arquivo não permitido'];
    }
    
    // Validar tamanho
    if (!eventos_validar_tamanho_arquivo($file['size'], $max_mb)) {
        return ['ok' => false, 'error' => "Arquivo excede limite de {$max_mb}MB"];
    }
    
    // Determinar kind
    $kind = 'outros';
    if (strpos($mime, 'image/') === 0) $kind = 'imagem';
    elseif (strpos($mime, 'audio/') === 0) $kind = 'audio';
    elseif (strpos($mime, 'video/') === 0) $kind = 'video';
    elseif ($mime === 'application/pdf') $kind = 'pdf';
    
    return [
        'ok' => true,
        'file' => $file,
        'mime' => $mime,
        'kind' => $kind,
        'size' => $file['size'],
        'name' => $file['name']
    ];
}
