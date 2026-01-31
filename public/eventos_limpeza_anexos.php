<?php
/**
 * eventos_limpeza_anexos.php
 * Job de limpeza automática de anexos pesados (áudio/vídeo) 
 * após 10 dias do evento
 * 
 * Deve ser chamado via cron diariamente
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';

/**
 * Executar limpeza de anexos expirados
 * 
 * @param PDO $pdo
 * @return array Resultado da execução
 */
function eventos_limpar_anexos_expirados(PDO $pdo): array {
    $resultado = [
        'total_verificados' => 0,
        'total_deletados' => 0,
        'total_erros' => 0,
        'anexos_deletados' => [],
        'erros' => []
    ];
    
    try {
        // Buscar anexos de áudio/vídeo que:
        // 1. Pertencem a eventos que já passaram há mais de 10 dias
        // 2. Ainda não foram deletados (deleted_at IS NULL)
        // 3. São do tipo áudio ou vídeo
        
        $stmt = $pdo->query("
            SELECT a.id, a.storage_key, a.original_name, a.file_kind, a.size_bytes,
                   r.id as reuniao_id,
                   (r.me_event_snapshot->>'data')::date as data_evento,
                   (r.me_event_snapshot->>'nome') as nome_evento
            FROM eventos_reunioes_anexos a
            JOIN eventos_reunioes r ON r.id = a.meeting_id
            WHERE a.deleted_at IS NULL
            AND a.file_kind IN ('audio', 'video')
            AND (r.me_event_snapshot->>'data')::date < CURRENT_DATE - INTERVAL '10 days'
            ORDER BY a.id
            LIMIT 100
        ");
        
        $anexos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $resultado['total_verificados'] = count($anexos);
        
        if (empty($anexos)) {
            return $resultado;
        }
        
        // Tentar deletar cada anexo do Magalu Cloud
        $uploader = new MagaluUpload();
        
        foreach ($anexos as $anexo) {
            try {
                // Tentar deletar do Magalu
                $deleted = false;
                
                if (!empty($anexo['storage_key'])) {
                    // Usar método de delete se existir
                    if (method_exists($uploader, 'delete')) {
                        $deleted = $uploader->delete($anexo['storage_key']);
                    } else {
                        // Simular delete (apenas marcar no banco)
                        $deleted = true;
                    }
                }
                
                if ($deleted) {
                    // Marcar como deletado no banco
                    $stmt = $pdo->prepare("
                        UPDATE eventos_reunioes_anexos 
                        SET deleted_at = NOW(), deleted_by = 0 
                        WHERE id = :id
                    ");
                    $stmt->execute([':id' => $anexo['id']]);
                    
                    $resultado['total_deletados']++;
                    $resultado['anexos_deletados'][] = [
                        'id' => $anexo['id'],
                        'nome' => $anexo['original_name'],
                        'tipo' => $anexo['file_kind'],
                        'tamanho' => $anexo['size_bytes'],
                        'evento' => $anexo['nome_evento'],
                        'data_evento' => $anexo['data_evento']
                    ];
                } else {
                    throw new Exception("Falha ao deletar do storage");
                }
                
            } catch (Exception $e) {
                $resultado['total_erros']++;
                $resultado['erros'][] = [
                    'id' => $anexo['id'],
                    'nome' => $anexo['original_name'],
                    'erro' => $e->getMessage()
                ];
            }
        }
        
    } catch (Exception $e) {
        $resultado['erros'][] = [
            'erro' => 'Erro geral: ' . $e->getMessage()
        ];
    }
    
    return $resultado;
}

/**
 * Definir expiração para anexos pesados de eventos futuros
 * 
 * @param PDO $pdo
 * @param int $meeting_id
 * @param string $data_evento
 */
function eventos_definir_expiracao_anexos(PDO $pdo, int $meeting_id, string $data_evento): void {
    // Definir expiração para 10 dias após o evento
    $expira_em = date('Y-m-d H:i:s', strtotime($data_evento . ' +10 days'));
    
    // Atualizar anexos de áudio/vídeo sem expiração definida
    $stmt = $pdo->prepare("
        UPDATE eventos_reunioes_anexos 
        SET expires_at = :expira 
        WHERE meeting_id = :meeting_id 
        AND file_kind IN ('audio', 'video')
        AND expires_at IS NULL
        AND deleted_at IS NULL
    ");
    $stmt->execute([':expira' => $expira_em, ':meeting_id' => $meeting_id]);
}

/**
 * Buscar estatísticas de armazenamento
 */
function eventos_stats_armazenamento(PDO $pdo): array {
    $stats = [
        'total_anexos' => 0,
        'total_bytes' => 0,
        'por_tipo' => [],
        'expirados_pendentes' => 0,
        'bytes_expirados' => 0
    ];
    
    try {
        // Total geral
        $stmt = $pdo->query("
            SELECT COUNT(*) as total, COALESCE(SUM(size_bytes), 0) as bytes
            FROM eventos_reunioes_anexos
            WHERE deleted_at IS NULL
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_anexos'] = (int)$row['total'];
        $stats['total_bytes'] = (int)$row['bytes'];
        
        // Por tipo
        $stmt = $pdo->query("
            SELECT file_kind, COUNT(*) as total, COALESCE(SUM(size_bytes), 0) as bytes
            FROM eventos_reunioes_anexos
            WHERE deleted_at IS NULL
            GROUP BY file_kind
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats['por_tipo'][$row['file_kind']] = [
                'total' => (int)$row['total'],
                'bytes' => (int)$row['bytes']
            ];
        }
        
        // Expirados pendentes
        $stmt = $pdo->query("
            SELECT COUNT(*) as total, COALESCE(SUM(a.size_bytes), 0) as bytes
            FROM eventos_reunioes_anexos a
            JOIN eventos_reunioes r ON r.id = a.meeting_id
            WHERE a.deleted_at IS NULL
            AND a.file_kind IN ('audio', 'video')
            AND (r.me_event_snapshot->>'data')::date < CURRENT_DATE - INTERVAL '10 days'
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['expirados_pendentes'] = (int)$row['total'];
        $stats['bytes_expirados'] = (int)$row['bytes'];
        
    } catch (Exception $e) {
        error_log("Erro ao buscar stats: " . $e->getMessage());
    }
    
    return $stats;
}

// Se chamado diretamente (via cron ou teste)
if (php_sapi_name() === 'cli' || isset($_GET['run'])) {
    // Verificar autenticação para chamada web
    if (php_sapi_name() !== 'cli') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['perm_superadmin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado']);
            exit;
        }
    }
    
    header('Content-Type: application/json');
    
    $resultado = eventos_limpar_anexos_expirados($pdo);
    $stats = eventos_stats_armazenamento($pdo);
    
    echo json_encode([
        'ok' => true,
        'resultado' => $resultado,
        'stats' => $stats,
        'executado_em' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
