<?php
// contab_gerar_link.php - Gerar link para portal de contabilidade
session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Verificar permissões
if (!lc_can_access_module('contabilidade')) {
    header('Location: index.php?page=dashboard&erro=permissao_negada');
    exit;
}

$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Processar geração de link
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $descricao = trim($_POST['descricao'] ?? '');
        $limite_diario = intval($_POST['limite_diario'] ?? 10);
        
        if (empty($descricao)) {
            throw new Exception('Descrição é obrigatória');
        }
        
        // Gerar token único
        $token = bin2hex(random_bytes(32));
        
        // Inserir token no banco
        $stmt = $pdo->prepare("INSERT INTO contab_tokens (token, descricao, ativo, limite_diario) VALUES (?, ?, TRUE, ?)");
        $stmt->execute([$token, $descricao, $limite_diario]);
        
        $link = "https://painelsmilepro-production.up.railway.app/contab_link.php?t=" . $token;
        
        $sucesso = "Link gerado com sucesso!";
        
    } catch (Exception $e) {
        $erro = "Erro ao gerar link: " . $e->getMessage();
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerar Link - Portal Contabilidade</title>
    <link rel="stylesheet" href="estilo.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
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
        
        .form-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6b7280 0%, #9ca3af 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(107, 114, 128, 0.3);
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(107, 114, 128, 0.4);
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        
        .tokens-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .tokens-table th,
        .tokens-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .tokens-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #374151;
        }
        
        .token-link {
            font-family: monospace;
            background: #f1f5f9;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            word-break: break-all;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-ativo {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inativo {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">🔗 Gerar Link - Portal Contabilidade</h1>
            <p class="subtitle">Crie links seguros para fornecedores enviarem documentos</p>
        </div>
        
        <?php if ($sucesso): ?>
        <div class="alert alert-success">
            ✅ <?= htmlspecialchars($sucesso) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
        <div class="alert alert-error">
            ❌ <?= htmlspecialchars($erro) ?>
        </div>
        <?php endif; ?>
        
        <div class="form-section">
            <h2>📝 Criar Novo Link</h2>
            <form method="POST">
                <div class="form-group">
                    <label class="form-label" for="descricao">Descrição do Link</label>
                    <input type="text" id="descricao" name="descricao" class="form-input" 
                           placeholder="Ex: Link para fornecedores enviarem documentos de janeiro" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="limite_diario">Limite Diário de Envios</label>
                    <input type="number" id="limite_diario" name="limite_diario" class="form-input" 
                           value="10" min="1" max="100">
                </div>
                
                <button type="submit" class="btn btn-primary">
                    🔗 Gerar Link
                </button>
            </form>
        </div>
        
        <div class="form-section">
            <h2>📋 Links Existentes</h2>
            <?php if (empty($tokens)): ?>
                <p>Nenhum link criado ainda.</p>
            <?php else: ?>
                <table class="tokens-table">
                    <thead>
                        <tr>
                            <th>Descrição</th>
                            <th>Link</th>
                            <th>Status</th>
                            <th>Limite Diário</th>
                            <th>Criado em</th>
                            <th>Último Uso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tokens as $token): ?>
                        <tr>
                            <td><?= htmlspecialchars($token['descricao']) ?></td>
                            <td>
                                <div class="token-link">
                                    <a href="contab_link.php?t=<?= htmlspecialchars($token['token']) ?>" target="_blank">
                                        contab_link.php?t=<?= htmlspecialchars($token['token']) ?>
                                    </a>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?= $token['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                    <?= $token['ativo'] ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td><?= $token['limite_diario'] ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($token['criado_em'])) ?></td>
                            <td>
                                <?= $token['ultimo_uso'] ? date('d/m/Y H:i', strtotime($token['ultimo_uso'])) : 'Nunca' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="index.php?page=dashboard" class="btn btn-secondary">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
</body>
</html>
