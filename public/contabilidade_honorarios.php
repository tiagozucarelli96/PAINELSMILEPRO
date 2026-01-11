<?php
// contabilidade_honorarios.php — Tela de Honorários
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';

// Verificar se está logado
if (empty($_SESSION['contabilidade_logado']) || $_SESSION['contabilidade_logado'] !== true) {
    header('Location: contabilidade_login.php');
    exit;
}

$mensagem = '';
$erro = '';

// Processar cadastro de honorário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'cadastrar_honorario') {
    try {
        $descricao = trim($_POST['descricao'] ?? '');
        $data_vencimento = trim($_POST['data_vencimento'] ?? '');
        
        if (empty($descricao)) {
            throw new Exception('Descrição é obrigatória');
        }
        
        if (empty($data_vencimento)) {
            throw new Exception('Data de vencimento é obrigatória');
        }
        
        if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Arquivo é obrigatório');
        }
        
        // Processar upload
        try {
            $uploader = new MagaluUpload();
            $resultado = $uploader->upload($_FILES['arquivo'], 'contabilidade/honorarios');
            
            // Salvar chave_storage (para presigned URLs) e URL (fallback)
            $arquivo_url = $resultado['url'] ?? null;
            $chave_storage = $resultado['chave_storage'] ?? null;
            $arquivo_nome = $resultado['nome_original'] ?? $_FILES['arquivo']['name'];
            
            // Inserir honorário
            $stmt = $pdo->prepare("
                INSERT INTO contabilidade_honorarios 
                (arquivo_url, arquivo_nome, chave_storage, data_vencimento, descricao)
                VALUES (:arquivo_url, :arquivo_nome, :chave_storage, :vencimento, :desc)
            ");
            $stmt->execute([
                ':arquivo_url' => $arquivo_url,
                ':arquivo_nome' => $arquivo_nome,
                ':chave_storage' => $chave_storage,
                ':vencimento' => $data_vencimento,
                ':desc' => $descricao
            ]);
            
            $mensagem = 'Honorário cadastrado com sucesso!';
            
        } catch (Exception $e) {
            throw new Exception('Erro ao fazer upload: ' . $e->getMessage());
        }
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar honorários
$honorarios = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM contabilidade_honorarios
        ORDER BY data_vencimento DESC, criado_em DESC
        LIMIT 50
    ");
    $honorarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Honorários - Contabilidade</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
        }
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 1.5rem; font-weight: 700; }
        .btn-back { background: rgba(255,255,255,0.2); color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .form-section-title { font-size: 1.25rem; font-weight: 600; color: #1e40af; margin-bottom: 1.5rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; }
        .form-group { display: flex; flex-direction: column; }
        .form-label { font-weight: 500; color: #374151; margin-bottom: 0.5rem; }
        .form-input { padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem; }
        .form-input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }
        .btn-primary { background: #1e40af; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; }
        .btn-primary:hover { background: #1e3a8a; }
        .table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; }
        .table th { background: #1e40af; color: white; padding: 1rem; text-align: left; }
        .table td { padding: 1rem; border-bottom: 1px solid #e5e7eb; }
        .badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.875rem; font-weight: 500; }
        .badge-aberto { background: #fef3c7; color: #92400e; }
        .badge-pago { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
    <div class="header">
        <h1>💼 Honorários</h1>
        <a href="contabilidade_painel.php" class="btn-back">← Voltar</a>
    </div>
    
    <div class="container">
        <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        
        <!-- Formulário de Cadastro -->
        <div class="form-section">
            <h2 class="form-section-title">➕ Cadastrar Novo Honorário</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="cadastrar_honorario">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Descrição *</label>
                        <input type="text" name="descricao" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Data de Vencimento *</label>
                        <input type="date" name="data_vencimento" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Documento/Boleto *</label>
                        <input type="file" name="arquivo" class="form-input" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-primary">💾 Cadastrar Honorário</button>
            </form>
        </div>
        
        <!-- Lista de Honorários -->
        <div class="form-section">
            <h2 class="form-section-title">📋 Honorários Cadastrados</h2>
            <?php if (empty($honorarios)): ?>
            <p style="color: #64748b; text-align: center; padding: 2rem;">Nenhum honorário cadastrado ainda.</p>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Descrição</th>
                        <th>Vencimento</th>
                        <th>Status</th>
                        <th>Arquivo</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($honorarios as $honorario): ?>
                    <tr>
                        <td><?= htmlspecialchars($honorario['descricao']) ?></td>
                        <td><?= date('d/m/Y', strtotime($honorario['data_vencimento'])) ?></td>
                        <td>
                            <span class="badge badge-<?= $honorario['status'] === 'aberto' ? 'aberto' : 'pago' ?>">
                                <?= ucfirst($honorario['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($honorario['chave_storage']) || !empty($honorario['arquivo_url'])): ?>
                                <a href="contabilidade_download.php?tipo=honorario&id=<?= $honorario['id'] ?>" target="_blank">📎 Ver</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= date('d/m/Y H:i', strtotime($honorario['criado_em'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
