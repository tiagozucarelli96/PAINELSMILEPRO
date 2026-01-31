<?php
/**
 * cron_logger.php
 * Helper para registrar execuções de cron jobs
 */

/**
 * Garante que a tabela de log existe
 */
function cron_logger_ensure_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    
    try {
        // Verificar se tabela existe
        $stmt = $pdo->query("
            SELECT 1 FROM information_schema.tables 
            WHERE table_name = 'sistema_cron_execucoes'
            LIMIT 1
        ");
        
        if (!$stmt->fetch()) {
            // Criar tabela
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sistema_cron_execucoes (
                    id SERIAL PRIMARY KEY,
                    tipo VARCHAR(100) NOT NULL,
                    iniciado_em TIMESTAMP NOT NULL DEFAULT NOW(),
                    finalizado_em TIMESTAMP,
                    sucesso BOOLEAN,
                    duracao_ms INTEGER,
                    resultado JSONB,
                    ip_origem VARCHAR(45),
                    user_agent TEXT,
                    criado_em TIMESTAMP DEFAULT NOW()
                )
            ");
            
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cron_exec_tipo ON sistema_cron_execucoes(tipo)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cron_exec_iniciado ON sistema_cron_execucoes(iniciado_em DESC)");
        }
        
        $checked = true;
    } catch (PDOException $e) {
        error_log("[CRON_LOGGER] Erro ao garantir schema: " . $e->getMessage());
    }
}

/**
 * Registra o início de uma execução de cron
 * @return int ID da execução (para usar no finalizar)
 */
function cron_logger_start(PDO $pdo, string $tipo): int {
    cron_logger_ensure_schema($pdo);
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sistema_cron_execucoes (tipo, iniciado_em, ip_origem, user_agent)
            VALUES (:tipo, NOW(), :ip, :ua)
            RETURNING id
        ");
        $stmt->execute([
            ':tipo' => $tipo,
            ':ip' => $ip,
            ':ua' => substr($user_agent, 0, 500)
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['id'] ?? 0);
        
    } catch (PDOException $e) {
        error_log("[CRON_LOGGER] Erro ao registrar início: " . $e->getMessage());
        return 0;
    }
}

/**
 * Registra o fim de uma execução de cron
 */
function cron_logger_finish(PDO $pdo, int $execucao_id, bool $sucesso, array $resultado = [], ?int $inicio_ms = null): void {
    if ($execucao_id <= 0) return;
    
    $duracao = null;
    if ($inicio_ms !== null) {
        $duracao = (int)(microtime(true) * 1000 - $inicio_ms);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE sistema_cron_execucoes
            SET finalizado_em = NOW(),
                sucesso = :sucesso,
                duracao_ms = :duracao,
                resultado = :resultado
            WHERE id = :id
        ");
        $stmt->bindValue(':id', $execucao_id, PDO::PARAM_INT);
        $stmt->bindValue(':sucesso', $sucesso, PDO::PARAM_BOOL);
        $stmt->bindValue(':duracao', $duracao, PDO::PARAM_INT);
        $stmt->bindValue(':resultado', json_encode($resultado, JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $stmt->execute();
        
    } catch (PDOException $e) {
        error_log("[CRON_LOGGER] Erro ao registrar fim: " . $e->getMessage());
    }
}

/**
 * Busca última execução de cada tipo de cron
 */
function cron_logger_get_ultimas(PDO $pdo): array {
    cron_logger_ensure_schema($pdo);
    
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT ON (tipo)
                tipo,
                id as execucao_id,
                iniciado_em,
                finalizado_em,
                sucesso,
                duracao_ms,
                resultado,
                ip_origem,
                CASE 
                    WHEN sucesso IS NULL THEN 'executando'
                    WHEN sucesso = TRUE THEN 'sucesso'
                    ELSE 'erro'
                END as status_texto
            FROM sistema_cron_execucoes
            ORDER BY tipo, iniciado_em DESC
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("[CRON_LOGGER] Erro ao buscar últimas: " . $e->getMessage());
        return [];
    }
}

/**
 * Busca histórico de execuções de um tipo específico
 */
function cron_logger_get_historico(PDO $pdo, string $tipo, int $limite = 20): array {
    cron_logger_ensure_schema($pdo);
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id as execucao_id,
                tipo,
                iniciado_em,
                finalizado_em,
                sucesso,
                duracao_ms,
                resultado,
                ip_origem,
                CASE 
                    WHEN sucesso IS NULL THEN 'executando'
                    WHEN sucesso = TRUE THEN 'sucesso'
                    ELSE 'erro'
                END as status_texto
            FROM sistema_cron_execucoes
            WHERE tipo = :tipo
            ORDER BY iniciado_em DESC
            LIMIT :limite
        ");
        $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("[CRON_LOGGER] Erro ao buscar histórico: " . $e->getMessage());
        return [];
    }
}

/**
 * Estatísticas de execuções
 */
function cron_logger_get_stats(PDO $pdo, int $dias = 7): array {
    cron_logger_ensure_schema($pdo);
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                tipo,
                COUNT(*) as total_execucoes,
                COUNT(*) FILTER (WHERE sucesso = TRUE) as sucessos,
                COUNT(*) FILTER (WHERE sucesso = FALSE) as erros,
                AVG(duracao_ms) FILTER (WHERE duracao_ms IS NOT NULL) as duracao_media_ms,
                MAX(iniciado_em) as ultima_execucao
            FROM sistema_cron_execucoes
            WHERE iniciado_em >= NOW() - INTERVAL :dias DAY
            GROUP BY tipo
            ORDER BY tipo
        ");
        $stmt->execute([':dias' => $dias . ' days']);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        // Fallback para sintaxe alternativa
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    tipo,
                    COUNT(*) as total_execucoes,
                    COUNT(CASE WHEN sucesso = TRUE THEN 1 END) as sucessos,
                    COUNT(CASE WHEN sucesso = FALSE THEN 1 END) as erros,
                    AVG(duracao_ms) as duracao_media_ms,
                    MAX(iniciado_em) as ultima_execucao
                FROM sistema_cron_execucoes
                WHERE iniciado_em >= NOW() - INTERVAL '$dias days'
                GROUP BY tipo
                ORDER BY tipo
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            error_log("[CRON_LOGGER] Erro ao buscar stats: " . $e2->getMessage());
            return [];
        }
    }
}
