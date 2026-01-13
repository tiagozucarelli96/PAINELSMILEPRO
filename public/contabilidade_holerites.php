<?php
// contabilidade_holerites.php — Tela de Holerites
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

// Processar exclusão de holerite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'excluir_holerite') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        
        $stmt = $pdo->prepare("DELETE FROM contabilidade_holerites WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        $mensagem = 'Holerite excluído com sucesso!';
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Processar cadastro de holerite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'cadastrar_holerite') {
    try {
        $mes_competencia = trim($_POST['mes_competencia'] ?? '');
        $empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
        $e_ajuste = isset($_POST['e_ajuste']) && $_POST['e_ajuste'] === '1';
        $observacao = trim($_POST['observacao'] ?? '');
        
        if (empty($mes_competencia)) {
            throw new Exception('Mês de competência é obrigatório');
        }
        
        // Validar formato MM/AAAA
        if (!preg_match('/^\d{2}\/\d{4}$/', $mes_competencia)) {
            throw new Exception('Formato inválido. Use MM/AAAA (ex: 01/2024)');
        }
        
        if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Arquivo é obrigatório');
        }
        
        // Processar upload
        try {
            $uploader = new MagaluUpload();
            $resultado = $uploader->upload($_FILES['arquivo'], 'contabilidade/holerites');
            
            // Salvar chave_storage (para presigned URLs) e URL (fallback)
            $arquivo_url = $resultado['url'] ?? null;
            $chave_storage = $resultado['chave_storage'] ?? null;
            $arquivo_nome = $resultado['nome_original'] ?? $_FILES['arquivo']['name'];
            
            // Inserir holerite
            // Garantir que e_ajuste seja boolean explícito
            $e_ajuste_bool = (bool)$e_ajuste;
            
            // Verificar se coluna empresa_id existe
            $has_empresa_id = false;
            try {
                $stmt_check = $pdo->prepare("
                    SELECT column_name FROM information_schema.columns 
                    WHERE table_schema = current_schema() 
                    AND table_name = 'contabilidade_holerites' 
                    AND column_name = 'empresa_id'
                ");
                $stmt_check->execute();
                $has_empresa_id = (bool)$stmt_check->fetchColumn();
            } catch (Exception $e) {
                // Ignorar
            }
            
            if ($has_empresa_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO contabilidade_holerites 
                    (arquivo_url, arquivo_nome, chave_storage, mes_competencia, e_ajuste, observacao, empresa_id)
                    VALUES (:arquivo_url, :arquivo_nome, :chave_storage, :competencia, :e_ajuste, :obs, :empresa_id)
                ");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO contabilidade_holerites 
                    (arquivo_url, arquivo_nome, chave_storage, mes_competencia, e_ajuste, observacao)
                    VALUES (:arquivo_url, :arquivo_nome, :chave_storage, :competencia, :e_ajuste, :obs)
                ");
            }
            $stmt->bindValue(':arquivo_url', $arquivo_url, PDO::PARAM_STR);
            $stmt->bindValue(':arquivo_nome', $arquivo_nome, PDO::PARAM_STR);
            $stmt->bindValue(':chave_storage', $chave_storage, PDO::PARAM_STR);
            $stmt->bindValue(':competencia', $mes_competencia, PDO::PARAM_STR);
            $stmt->bindValue(':e_ajuste', $e_ajuste_bool, PDO::PARAM_BOOL);
            $stmt->bindValue(':obs', !empty($observacao) ? $observacao : null, PDO::PARAM_STR);
            if ($has_empresa_id) {
                $stmt->bindValue(':empresa_id', $empresa_id, PDO::PARAM_INT);
            }
            $stmt->execute();
            
            $mensagem = 'Holerite cadastrado com sucesso!';
            
        } catch (Exception $e) {
            throw new Exception('Erro ao fazer upload: ' . $e->getMessage());
        }
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar empresas
$empresas = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM contabilidade_empresas
        WHERE ativo = TRUE
        ORDER BY nome ASC
    ");
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir
    error_log("Erro ao buscar empresas: " . $e->getMessage());
}

// Buscar holerites
$holerites = [];
try {
    $stmt = $pdo->query("
        SELECT h.*, e.nome as empresa_nome, e.cnpj as empresa_cnpj
        FROM contabilidade_holerites h
        LEFT JOIN contabilidade_empresas e ON e.id = h.empresa_id
        ORDER BY h.mes_competencia DESC, h.criado_em DESC
        LIMIT 50
    ");
    $holerites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Holerites - Contabilidade</title>
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
        .form-input, .form-select { padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }
        .checkbox-group { display: flex; align-items: center; gap: 0.5rem; margin: 1rem 0; }
        .btn-primary { background: #1e40af; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; }
        .btn-primary:hover { background: #1e3a8a; }
        .table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; }
        .table th { background: #1e40af; color: white; padding: 1rem; text-align: left; }
        .table td { padding: 1rem; border-bottom: 1px solid #e5e7eb; }
        .badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.875rem; font-weight: 500; }
        .badge-aberto { background: #fef3c7; color: #92400e; }
        .badge-ajuste { background: #dbeafe; color: #1e40af; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📄 Holerites</h1>
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
            <h2 class="form-section-title">➕ Cadastrar Novo Holerite</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="cadastrar_holerite">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Empresa *</label>
                        <select name="empresa_id" class="form-input" required>
                            <option value="">Selecione uma empresa...</option>
                            <?php foreach ($empresas as $emp): ?>
                            <option value="<?= $emp['id'] ?>">
                                <?= htmlspecialchars($emp['nome']) ?> - <?= htmlspecialchars($emp['cnpj']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Mês de Competência (MM/AAAA) *</label>
                        <input type="text" name="mes_competencia" class="form-input" 
                               placeholder="01/2024" pattern="\d{2}/\d{4}" required>
                        <small style="color: #64748b; font-size: 0.875rem; margin-top: 0.25rem;">
                            Formato: MM/AAAA (ex: 01/2024)
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Arquivo *</label>
                        <input type="file" name="arquivo" class="form-input" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="e_ajuste" id="e_ajuste" value="1">
                    <label for="e_ajuste">É ajuste?</label>
                </div>
                
                <div class="form-group" style="margin-top: 1rem;">
                    <label class="form-label">Observação (apenas para admin)</label>
                    <textarea name="observacao" class="form-input" rows="3" 
                              placeholder="Observações internas..."></textarea>
                </div>
                
                <button type="submit" class="btn-primary">💾 Cadastrar Holerite</button>
            </form>
        </div>
        
        <!-- Lista de Holerites -->
        <div class="form-section">
            <h2 class="form-section-title">📋 Holerites Cadastrados</h2>
            <?php if (empty($holerites)): ?>
            <p style="color: #64748b; text-align: center; padding: 2rem;">Nenhum holerite cadastrado ainda.</p>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Competência</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th>Arquivo</th>
                        <th>Data</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holerites as $holerite): ?>
                    <tr>
                        <td><?= htmlspecialchars($holerite['mes_competencia']) ?></td>
                        <td>
                            <?php if ($holerite['e_ajuste']): ?>
                                <span class="badge badge-ajuste">Ajuste</span>
                            <?php else: ?>
                                Normal
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-aberto">
                                <?= ucfirst($holerite['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($holerite['chave_storage']) || !empty($holerite['arquivo_url'])): ?>
                                <a href="contabilidade_download.php?tipo=holerite&id=<?= $holerite['id'] ?>" target="_blank">📎 Ver</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= date('d/m/Y H:i', strtotime($holerite['criado_em'])) ?></td>
                        <td>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja excluir este holerite?');">
                                <input type="hidden" name="acao" value="excluir_holerite">
                                <input type="hidden" name="id" value="<?= $holerite['id'] ?>">
                                <button type="submit" style="background: #ef4444; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">🗑️ Excluir</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
