<?php
// contab_gerar_link.php — Gerar links para portal contábil
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';

// Verificar permissões (apenas ADM)
if (!isset($_SESSION['perfil']) || $_SESSION['perfil'] !== 'ADM') {
    header('Location: index.php?page=dashboard');
    exit;
}

$sucesso = '';
$erro = '';

// Processar criação de novo token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['criar_token'])) {
    try {
        $descricao = trim($_POST['descricao']);
        $limite_diario = (int)($_POST['limite_diario'] ?? 50);
        
        if (empty($descricao)) {
            throw new Exception('Descrição é obrigatória');
        }
        
        // Gerar token único
        $token = bin2hex(random_bytes(32));
        
        $stmt = $pdo->prepare("
            INSERT INTO contab_tokens (token, descricao, ativo, limite_diario) 
            VALUES (?, ?, TRUE, ?)
        ");
        $stmt->execute([$token, $descricao, $limite_diario]);
        
        $sucesso = "Token criado com sucesso!";
        
    } catch (Exception $e) {
        $erro = "Erro ao criar token: " . $e->getMessage();
    }
}

// Processar ativação/desativação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_token'])) {
    try {
        $token_id = (int)$_POST['token_id'];
        $ativo = $_POST['ativo'] === 'true';
        
        $stmt = $pdo->prepare("UPDATE contab_tokens SET ativo = ? WHERE id = ?");
        $stmt->execute([$ativo, $token_id]);
        
        $sucesso = "Token " . ($ativo ? "ativado" : "desativado") . " com sucesso!";
        
    } catch (Exception $e) {
        $erro = "Erro ao alterar status: " . $e->getMessage();
    }
}

// Buscar tokens existentes
$tokens = [];
try {
    $stmt = $pdo->query("SELECT * FROM contab_tokens ORDER BY criado_em DESC");
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar tokens: " . $e->getMessage();
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerar Links - Portal Contábil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .title {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 10px;
        }
        
        .subtitle {
            font-size: 1.2rem;
            color: #6b7280;
        }
        
        .main-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .form-section {
            margin-bottom: 40px;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }
        
        .form-input, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .btn-success {
            background: #059669;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-success:hover {
            background: #047857;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            color: #555;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
            color: #444;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-ativo {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inativo {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .token-link {
            font-family: monospace;
            background: #f1f5f9;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            word-break: break-all;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .actions {
            display: flex;
            gap: 8px;
        }
        
        .copy-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: background-color 0.2s ease;
        }
        
        .copy-btn:hover {
            background: #2563eb;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">🔗 Gerar Links - Portal Contábil</h1>
            <p class="subtitle">Crie e gerencie tokens de acesso para o portal contábil</p>
        </div>
        
        <div class="main-content">
            <?php if ($sucesso): ?>
                <div class="alert alert-success">
                    <strong>✅ Sucesso!</strong> <?= h($sucesso) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($erro): ?>
                <div class="alert alert-error">
                    <strong>❌ Erro!</strong> <?= h($erro) ?>
                </div>
            <?php endif; ?>
            
            <div class="form-section">
                <h2 class="section-title">
                    <span>➕</span>
                    Criar Novo Token
                </h2>
                
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="descricao">Descrição *</label>
                            <input type="text" id="descricao" name="descricao" class="form-input" 
                                   placeholder="Ex: Token para cliente XYZ" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="limite_diario">Limite Diário</label>
                            <input type="number" id="limite_diario" name="limite_diario" class="form-input" 
                                   value="50" min="1" max="1000">
                        </div>
                    </div>
                    
                    <button type="submit" name="criar_token" class="btn-primary">
                        <span>🔑</span>
                        Criar Token
                    </button>
                </form>
            </div>
            
            <div class="form-section">
                <h2 class="section-title">
                    <span>📋</span>
                    Tokens Existentes
                </h2>
                
                <?php if (empty($tokens)): ?>
                    <div style="text-align: center; padding: 40px; color: #6b7280;">
                        <div style="font-size: 48px; margin-bottom: 16px;">🔑</div>
                        <h3>Nenhum token encontrado</h3>
                        <p>Crie seu primeiro token para começar a usar o portal contábil.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Descrição</th>
                                    <th>Token</th>
                                    <th>Status</th>
                                    <th>Limite Diário</th>
                                    <th>Criado em</th>
                                    <th>Último Uso</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tokens as $token): ?>
                                    <tr>
                                        <td><?= h($token['id']) ?></td>
                                        <td><?= h($token['descricao']) ?></td>
                                        <td>
                                            <div class="token-link">
                                                <?= h(substr($token['token'], 0, 20)) ?>...
                                                <button class="copy-btn" onclick="copyToken('<?= h($token['token']) ?>')">
                                                    📋
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= $token['ativo'] ? 'ativo' : 'inativo' ?>">
                                                <?= $token['ativo'] ? 'Ativo' : 'Inativo' ?>
                                            </span>
                                        </td>
                                        <td><?= h($token['limite_diario']) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($token['criado_em'])) ?></td>
                                        <td>
                                            <?= $token['ultimo_uso'] ? date('d/m/Y H:i', strtotime($token['ultimo_uso'])) : 'Nunca' ?>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <a href="contab_link.php?t=<?= h($token['token']) ?>" 
                                                   target="_blank" class="btn-secondary" style="text-decoration: none;">
                                                    🔗 Testar
                                                </a>
                                                
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="token_id" value="<?= $token['id'] ?>">
                                                    <input type="hidden" name="ativo" value="<?= $token['ativo'] ? 'false' : 'true' ?>">
                                                    <button type="submit" name="toggle_token" 
                                                            class="<?= $token['ativo'] ? 'btn-danger' : 'btn-success' ?>">
                                                        <?= $token['ativo'] ? '❌' : '✅' ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function copyToken(token) {
            const fullUrl = window.location.origin + '/contab_link.php?t=' + token;
            navigator.clipboard.writeText(fullUrl).then(() => {
                alert('Link copiado para a área de transferência!');
            }).catch(() => {
                // Fallback para navegadores mais antigos
                const textArea = document.createElement('textarea');
                textArea.value = fullUrl;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('Link copiado para a área de transferência!');
            });
        }
    </script>
</body>
</html>