<?php
// registrar_acesso.php — Sistema de registro de acesso e inteligência de uso
require_once __DIR__ . '/conexao.php';

class RegistroAcesso {
    private $pdo;
    private $usuarioId;
    private $ip;
    private $userAgent;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->usuarioId = $_SESSION['usuario_id'] ?? null;
        $this->ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    public function registrar($modulo, $acao = 'acesso_pagina', $dados = []) {
        if (!$this->usuarioId) {
            return false;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO demandas_logs (
                    usuario_id, 
                    acao, 
                    entidade, 
                    entidade_id, 
                    dados_novos, 
                    ip_origem, 
                    user_agent, 
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->usuarioId,
                $acao,
                $modulo,
                0,
                json_encode($dados),
                $this->ip,
                $this->userAgent
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Erro ao registrar acesso: " . $e->getMessage());
            return false;
        }
    }
    
    public function obterEstatisticas($periodo = 30) {
        if (!$this->usuarioId) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    entidade as modulo,
                    COUNT(*) as acessos,
                    MAX(created_at) as ultimo_acesso
                FROM demandas_logs 
                WHERE usuario_id = ? 
                AND acao = 'acesso_pagina'
                AND created_at >= NOW() - INTERVAL ? DAY
                GROUP BY entidade 
                ORDER BY acessos DESC
            ");
            
            $stmt->execute([$this->usuarioId, $periodo]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao obter estatísticas: " . $e->getMessage());
            return [];
        }
    }
    
    public function obterModulosFrequentes($limite = 5) {
        if (!$this->usuarioId) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    entidade as modulo,
                    COUNT(*) as acessos
                FROM demandas_logs 
                WHERE usuario_id = ? 
                AND acao = 'acesso_pagina'
                AND created_at >= NOW() - INTERVAL '7 days'
                GROUP BY entidade 
                ORDER BY acessos DESC 
                LIMIT ?
            ");
            
            $stmt->execute([$this->usuarioId, $limite]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao obter módulos frequentes: " . $e->getMessage());
            return [];
        }
    }
    
    public function obterSugestoes($limite = 3) {
        if (!$this->usuarioId) {
            return [];
        }
        
        try {
            // Obter módulos mais acessados por outros usuários do mesmo perfil
            $stmt = $this->pdo->prepare("
                SELECT 
                    dl.entidade as modulo,
                    COUNT(*) as acessos
                FROM demandas_logs dl
                JOIN usuarios u ON dl.usuario_id = u.id
                WHERE u.perfil = (
                    SELECT perfil FROM usuarios WHERE id = ?
                )
                AND dl.acao = 'acesso_pagina'
                AND dl.created_at >= NOW() - INTERVAL '30 days'
                AND dl.entidade NOT IN (
                    SELECT entidade 
                    FROM demandas_logs 
                    WHERE usuario_id = ? 
                    AND created_at >= NOW() - INTERVAL '7 days'
                )
                GROUP BY dl.entidade 
                ORDER BY acessos DESC 
                LIMIT ?
            ");
            
            $stmt->execute([$this->usuarioId, $this->usuarioId, $limite]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao obter sugestões: " . $e->getMessage());
            return [];
        }
    }
    
    public function obterProdutividade($periodo = 30) {
        if (!$this->usuarioId) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    DATE(created_at) as data,
                    COUNT(*) as acessos,
                    COUNT(DISTINCT entidade) as modulos_unicos
                FROM demandas_logs 
                WHERE usuario_id = ? 
                AND acao = 'acesso_pagina'
                AND created_at >= NOW() - INTERVAL ? DAY
                GROUP BY DATE(created_at)
                ORDER BY data DESC
            ");
            
            $stmt->execute([$this->usuarioId, $periodo]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao obter produtividade: " . $e->getMessage());
            return [];
        }
    }
}

// Processar requisições AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $registro = new RegistroAcesso();
    
    if (isset($input['modulo']) && isset($input['acao'])) {
        $resultado = $registro->registrar(
            $input['modulo'],
            $input['acao'],
            $input['dados'] ?? []
        );
        
        echo json_encode(['sucesso' => $resultado]);
    } else {
        echo json_encode(['erro' => 'Parâmetros inválidos']);
    }
    
    exit;
}

// Se acessado diretamente, redirecionar
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Location: dashboard.php');
    exit;
}
?>
