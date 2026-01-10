<?php
// contabilidade_colaboradores.php — Tela de Colaboradores
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';

// Verificar se está logado
$is_admin = !empty($_SESSION['logado']) && !empty($_SESSION['perm_administrativo']);
$is_contabilidade = !empty($_SESSION['contabilidade_logado']) && $_SESSION['contabilidade_logado'] === true;

if (!$is_admin && !$is_contabilidade) {
    if ($is_contabilidade) {
        header('Location: contabilidade_login.php');
    } else {
        header('Location: index.php?page=login');
    }
    exit;
}

$mensagem = '';
$erro = '';

// Processar cadastro de documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'anexar_documento') {
    try {
        $colaborador_id = (int)($_POST['colaborador_id'] ?? 0);
        $tipo_documento = trim($_POST['tipo_documento'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        
        if ($colaborador_id <= 0) {
            throw new Exception('Colaborador inválido');
        }
        
        if (empty($tipo_documento)) {
            throw new Exception('Tipo de documento é obrigatório');
        }
        
        if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Arquivo é obrigatório');
        }
        
        // Processar upload
        try {
            $uploader = new MagaluUpload();
            $resultado = $uploader->upload($_FILES['arquivo'], 'contabilidade/colaboradores/' . $colaborador_id);
            
            $arquivo_url = $resultado['url'] ?? null;
            $arquivo_nome = $resultado['nome_original'] ?? $_FILES['arquivo']['name'];
            
            // Inserir documento
            $stmt = $pdo->prepare("
                INSERT INTO contabilidade_colaboradores_documentos 
                (colaborador_id, tipo_documento, arquivo_url, arquivo_nome, descricao)
                VALUES (:colab_id, :tipo, :arquivo_url, :arquivo_nome, :desc)
            ");
            $stmt->execute([
                ':colab_id' => $colaborador_id,
                ':tipo' => $tipo_documento,
                ':arquivo_url' => $arquivo_url,
                ':arquivo_nome' => $arquivo_nome,
                ':desc' => !empty($descricao) ? $descricao : null
            ]);
            
            $mensagem = 'Documento anexado com sucesso!';
            
        } catch (Exception $e) {
            throw new Exception('Erro ao fazer upload: ' . $e->getMessage());
        }
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar colaboradores
$colaboradores = [];
try {
    $stmt = $pdo->query("
        SELECT id, nome, email, cargo, ativo
        FROM usuarios
        WHERE ativo = TRUE
        ORDER BY nome ASC
    ");
    $colaboradores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erro ao buscar colaboradores: " . $e->getMessage());
}

// Buscar documentos por colaborador
$documentos_por_colaborador = [];
if (!empty($colaboradores)) {
    try {
        $ids = array_column($colaboradores, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT colaborador_id, tipo_documento, arquivo_url, arquivo_nome, descricao, criado_em
            FROM contabilidade_colaboradores_documentos
            WHERE colaborador_id IN ($placeholders)
            ORDER BY criado_em DESC
        ");
        $stmt->execute($ids);
        $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($documentos as $doc) {
            if (!isset($documentos_por_colaborador[$doc['colaborador_id']])) {
                $documentos_por_colaborador[$doc['colaborador_id']] = [];
            }
            $documentos_por_colaborador[$doc['colaborador_id']][] = $doc;
        }
    } catch (Exception $e) {
        // Tabela pode não existir
    }
}

// Colaborador selecionado para anexar documento
$colaborador_selecionado = isset($_GET['anexar']) ? (int)$_GET['anexar'] : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colaboradores - Contabilidade</title>
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
        .colaboradores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        .colaborador-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.2s;
        }
        .colaborador-card:hover {
            border-color: #1e40af;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .colaborador-nome {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 0.5rem;
        }
        .colaborador-info {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 1rem;
        }
        .colaborador-docs {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }
        .doc-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            font-size: 0.875rem;
        }
        .doc-tipo {
            color: #64748b;
        }
        .doc-link {
            color: #1e40af;
            text-decoration: none;
        }
        .btn-anexar {
            background: #1e40af;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-anexar:hover { background: #1e3a8a; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; }
        .form-group { display: flex; flex-direction: column; }
        .form-label { font-weight: 500; color: #374151; margin-bottom: 0.5rem; }
        .form-input, .form-select { padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }
        .btn-primary { background: #1e40af; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; }
        .btn-primary:hover { background: #1e3a8a; }
    </style>
</head>
<body>
    <div class="header">
        <h1>👥 Colaboradores</h1>
        <a href="<?= $is_contabilidade ? 'contabilidade_painel.php' : 'index.php?page=contabilidade' ?>" class="btn-back">← Voltar</a>
    </div>
    
    <div class="container">
        <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        
        <?php if ($colaborador_selecionado > 0): ?>
        <!-- Formulário de Anexar Documento -->
        <?php
        $colab_selecionado = null;
        foreach ($colaboradores as $colab) {
            if ($colab['id'] == $colaborador_selecionado) {
                $colab_selecionado = $colab;
                break;
            }
        }
        ?>
        <?php if ($colab_selecionado): ?>
        <div class="form-section">
            <h2 class="form-section-title">
                📎 Anexar Documento - <?= htmlspecialchars($colab_selecionado['nome']) ?>
            </h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="anexar_documento">
                <input type="hidden" name="colaborador_id" value="<?= $colab_selecionado['id'] ?>">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Tipo de Documento *</label>
                        <select name="tipo_documento" class="form-select" required>
                            <option value="">Selecione...</option>
                            <option value="contrato">Contrato</option>
                            <option value="ajuste">Ajuste</option>
                            <option value="advertencia">Advertência</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Arquivo *</label>
                        <input type="file" name="arquivo" class="form-input" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Descrição (opcional)</label>
                        <textarea name="descricao" class="form-input" rows="3" placeholder="Descrição do documento..."></textarea>
                    </div>
                </div>
                
                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn-primary">💾 Anexar Documento</button>
                    <a href="contabilidade_colaboradores.php" class="btn-anexar" style="background: #6b7280;">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- Lista de Colaboradores -->
        <div class="form-section">
            <h2 class="form-section-title">👥 Colaboradores Cadastrados</h2>
            
            <?php if (empty($colaboradores)): ?>
            <p style="color: #64748b; text-align: center; padding: 2rem;">Nenhum colaborador cadastrado no sistema.</p>
            <?php else: ?>
            <div class="colaboradores-grid">
                <?php foreach ($colaboradores as $colab): ?>
                <div class="colaborador-card">
                    <div class="colaborador-nome"><?= htmlspecialchars($colab['nome']) ?></div>
                    <div class="colaborador-info">
                        <?php if ($colab['email']): ?>
                            📧 <?= htmlspecialchars($colab['email']) ?><br>
                        <?php endif; ?>
                        <?php if ($colab['cargo']): ?>
                            💼 <?= htmlspecialchars($colab['cargo']) ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (isset($documentos_por_colaborador[$colab['id']]) && !empty($documentos_por_colaborador[$colab['id']])): ?>
                    <div class="colaborador-docs">
                        <strong style="font-size: 0.875rem; color: #374151; margin-bottom: 0.5rem; display: block;">
                            Documentos (<?= count($documentos_por_colaborador[$colab['id']]) ?>)
                        </strong>
                        <?php foreach (array_slice($documentos_por_colaborador[$colab['id']], 0, 3) as $doc): ?>
                        <div class="doc-item">
                            <span class="doc-tipo">
                                <?= ucfirst($doc['tipo_documento']) ?>
                                <?php if ($doc['descricao']): ?>
                                    - <?= htmlspecialchars(substr($doc['descricao'], 0, 30)) ?>
                                <?php endif; ?>
                            </span>
                            <?php if ($doc['arquivo_url']): ?>
                            <a href="<?= htmlspecialchars($doc['arquivo_url']) ?>" target="_blank" class="doc-link">📎 Ver</a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($documentos_por_colaborador[$colab['id']]) > 3): ?>
                        <div style="font-size: 0.875rem; color: #64748b; margin-top: 0.5rem;">
                            +<?= count($documentos_por_colaborador[$colab['id']]) - 3 ?> mais...
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 1rem;">
                        <a href="?anexar=<?= $colab['id'] ?>" class="btn-anexar">📎 Anexar Documento</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
