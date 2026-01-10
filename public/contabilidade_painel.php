<?php
// contabilidade_painel.php — Painel principal da contabilidade (após login)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar se está logado
if (empty($_SESSION['contabilidade_logado']) || $_SESSION['contabilidade_logado'] !== true) {
    header('Location: contabilidade_login.php');
    exit;
}

// Verificar token no banco
$sessao_valida = false;
if (!empty($_SESSION['contabilidade_token'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, a.status as acesso_status
            FROM contabilidade_sessoes s
            JOIN contabilidade_acesso a ON a.id = s.acesso_id
            WHERE s.token = :token 
            AND s.ativo = TRUE 
            AND s.expira_em > NOW()
            AND a.status = 'ativo'
        ");
        $stmt->execute([':token' => $_SESSION['contabilidade_token']]);
        $sessao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sessao) {
            $sessao_valida = true;
        } else {
            // Sessão inválida, fazer logout
            unset($_SESSION['contabilidade_logado']);
            unset($_SESSION['contabilidade_token']);
            header('Location: contabilidade_login.php');
            exit;
        }
    } catch (Exception $e) {
        error_log("Erro ao verificar sessão: " . $e->getMessage());
        header('Location: contabilidade_login.php');
        exit;
    }
} else {
    header('Location: contabilidade_login.php');
    exit;
}

// Buscar contadores de itens abertos
$contadores = [
    'guias' => 0,
    'holerites' => 0,
    'honorarios' => 0,
    'conversas' => 0,
    'colaboradores' => 0
];

try {
    // Guias abertas
    $stmt = $pdo->query("SELECT COUNT(*) FROM contabilidade_guias WHERE status = 'aberto'");
    $contadores['guias'] = (int)$stmt->fetchColumn();
    
    // Holerites abertos
    $stmt = $pdo->query("SELECT COUNT(*) FROM contabilidade_holerites WHERE status = 'aberto'");
    $contadores['holerites'] = (int)$stmt->fetchColumn();
    
    // Honorários abertos
    $stmt = $pdo->query("SELECT COUNT(*) FROM contabilidade_honorarios WHERE status = 'aberto'");
    $contadores['honorarios'] = (int)$stmt->fetchColumn();
    
    // Conversas abertas
    $stmt = $pdo->query("SELECT COUNT(*) FROM contabilidade_conversas WHERE status = 'aberto'");
    $contadores['conversas'] = (int)$stmt->fetchColumn();
    
    // Colaboradores (total)
    $stmt = $pdo->query("SELECT COUNT(DISTINCT colaborador_id) FROM contabilidade_colaboradores_documentos");
    $contadores['colaboradores'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // Tabelas podem não existir ainda
    error_log("Erro ao buscar contadores: " . $e->getMessage());
}

// Logout
if (isset($_GET['logout'])) {
    if (!empty($_SESSION['contabilidade_token'])) {
        try {
            $stmt = $pdo->prepare("UPDATE contabilidade_sessoes SET ativo = FALSE WHERE token = :token");
            $stmt->execute([':token' => $_SESSION['contabilidade_token']]);
        } catch (Exception $e) {
            error_log("Erro ao invalidar sessão: " . $e->getMessage());
        }
    }
    unset($_SESSION['contabilidade_logado']);
    unset($_SESSION['contabilidade_token']);
    unset($_SESSION['contabilidade_acesso_id']);
    header('Location: contabilidade_login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel - Contabilidade</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
        }
        
        .btn-logout {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-logout:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            border-color: #3b82f6;
        }
        
        .card-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 0.5rem;
        }
        
        .card-badge {
            display: inline-block;
            background: #fee2e2;
            color: #991b1b;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        .card-badge.zero {
            background: #d1fae5;
            color: #065f46;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📑 Painel Contabilidade</h1>
        <div class="header-actions">
            <a href="?logout=1" class="btn-logout">🚪 Sair</a>
        </div>
    </div>
    
    <div class="container">
        <div class="cards-grid">
            <!-- Guias para Pagamento -->
            <a href="contabilidade_guias.php" class="card">
                <div class="card-icon">💰</div>
                <div class="card-title">Guias para Pagamento</div>
                <div class="card-badge <?= $contadores['guias'] === 0 ? 'zero' : '' ?>">
                    <?= $contadores['guias'] ?> aberto<?= $contadores['guias'] !== 1 ? 's' : '' ?>
                </div>
            </a>
            
            <!-- Holerites -->
            <a href="contabilidade_holerites.php" class="card">
                <div class="card-icon">📄</div>
                <div class="card-title">Holerites</div>
                <div class="card-badge <?= $contadores['holerites'] === 0 ? 'zero' : '' ?>">
                    <?= $contadores['holerites'] ?> aberto<?= $contadores['holerites'] !== 1 ? 's' : '' ?>
                </div>
            </a>
            
            <!-- Honorários -->
            <a href="contabilidade_honorarios.php" class="card">
                <div class="card-icon">💼</div>
                <div class="card-title">Honorários</div>
                <div class="card-badge <?= $contadores['honorarios'] === 0 ? 'zero' : '' ?>">
                    <?= $contadores['honorarios'] ?> aberto<?= $contadores['honorarios'] !== 1 ? 's' : '' ?>
                </div>
            </a>
            
            <!-- Conversas -->
            <a href="contabilidade_conversas.php" class="card">
                <div class="card-icon">💬</div>
                <div class="card-title">Conversas</div>
                <div class="card-badge <?= $contadores['conversas'] === 0 ? 'zero' : '' ?>">
                    <?= $contadores['conversas'] ?> aberto<?= $contadores['conversas'] !== 1 ? 's' : '' ?>
                </div>
            </a>
            
            <!-- Colaboradores -->
            <a href="contabilidade_colaboradores.php" class="card">
                <div class="card-icon">👥</div>
                <div class="card-title">Colaboradores</div>
                <div class="card-badge zero">
                    <?= $contadores['colaboradores'] ?> cadastrado<?= $contadores['colaboradores'] !== 1 ? 's' : '' ?>
                </div>
            </a>
        </div>
    </div>
</body>
</html>
