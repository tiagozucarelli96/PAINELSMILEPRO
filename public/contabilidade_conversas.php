<?php
// contabilidade_conversas.php — Tela de Conversas (Chat Contábil)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';

// Verificar se está logado (admin ou contabilidade)
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

$autor = $is_admin ? 'admin' : 'contabilidade';
$mensagem = '';
$erro = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'criar_conversa') {
        try {
            $assunto = trim($_POST['assunto'] ?? '');
            if (empty($assunto)) {
                throw new Exception('Assunto é obrigatório');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO contabilidade_conversas (assunto, criado_por)
                VALUES (:assunto, :autor)
                RETURNING id
            ");
            $stmt->execute([
                ':assunto' => $assunto,
                ':autor' => $autor
            ]);
            $conversa_id = $stmt->fetchColumn();
            
            header('Location: ?id=' . $conversa_id);
            exit;
            
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
    
    if ($acao === 'enviar_mensagem') {
        try {
            $conversa_id = (int)($_POST['conversa_id'] ?? 0);
            $texto = trim($_POST['mensagem'] ?? '');
            
            if ($conversa_id <= 0) {
                throw new Exception('Conversa inválida');
            }
            
            // Verificar se conversa está concluída
            $stmt = $pdo->prepare("SELECT status FROM contabilidade_conversas WHERE id = :id");
            $stmt->execute([':id' => $conversa_id]);
            $conversa = $stmt->fetch();
            
            if (!$conversa) {
                throw new Exception('Conversa não encontrada');
            }
            
            if ($conversa['status'] === 'concluido') {
                throw new Exception('Esta conversa está concluída. Reabra para enviar mensagens.');
            }
            
            $anexo_url = null;
            $anexo_nome = null;
            
            // Processar anexo se houver
            if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                try {
                    $uploader = new MagaluUpload();
                    $resultado = $uploader->upload($_FILES['anexo'], 'contabilidade/conversas/' . $conversa_id);
                    
                    // Salvar chave_storage (para presigned URLs) e URL (fallback)
                    $anexo_url = $resultado['url'] ?? null;
                    $chave_storage = $resultado['chave_storage'] ?? null;
                    $anexo_nome = $resultado['nome_original'] ?? $_FILES['anexo']['name'];
                } catch (Exception $e) {
                    error_log("Erro ao fazer upload de anexo: " . $e->getMessage());
                }
            }
            
            if (empty($texto) && empty($anexo_url)) {
                throw new Exception('Mensagem ou anexo é obrigatório');
            }
            
            // Inserir mensagem
            $stmt = $pdo->prepare("
                INSERT INTO contabilidade_conversas_mensagens 
                (conversa_id, autor, mensagem, anexo_url, anexo_nome, chave_storage)
                VALUES (:conversa_id, :autor, :mensagem, :anexo_url, :anexo_nome, :chave_storage)
            ");
            $stmt->execute([
                ':conversa_id' => $conversa_id,
                ':autor' => $autor,
                ':mensagem' => !empty($texto) ? $texto : null,
                ':anexo_url' => $anexo_url,
                ':anexo_nome' => $anexo_nome,
                ':chave_storage' => $chave_storage
            ]);
            
            // Atualizar data da conversa
            $stmt = $pdo->prepare("
                UPDATE contabilidade_conversas 
                SET atualizado_em = NOW() 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $conversa_id]);
            
            $mensagem = 'Mensagem enviada com sucesso!';
            
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
    
    if ($acao === 'alterar_status') {
        try {
            $conversa_id = (int)($_POST['conversa_id'] ?? 0);
            $novo_status = trim($_POST['status'] ?? '');
            
            if (!in_array($novo_status, ['aberto', 'em_andamento', 'concluido'])) {
                throw new Exception('Status inválido');
            }
            
            $stmt = $pdo->prepare("
                UPDATE contabilidade_conversas 
                SET status = :status, atualizado_em = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $novo_status,
                ':id' => $conversa_id
            ]);
            
            $mensagem = 'Status atualizado com sucesso!';
            
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
}

// Buscar conversas
$conversas = [];
try {
    $stmt = $pdo->query("
        SELECT c.*, 
               COUNT(m.id) as total_mensagens,
               MAX(m.criado_em) as ultima_mensagem
        FROM contabilidade_conversas c
        LEFT JOIN contabilidade_conversas_mensagens m ON m.conversa_id = c.id
        GROUP BY c.id
        ORDER BY c.atualizado_em DESC
        LIMIT 50
    ");
    $conversas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir
}

// Buscar mensagens de uma conversa específica
$conversa_atual = null;
$mensagens_conversa = [];
$conversa_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($conversa_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM contabilidade_conversas WHERE id = :id");
        $stmt->execute([':id' => $conversa_id]);
        $conversa_atual = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($conversa_atual) {
            $stmt = $pdo->prepare("
                SELECT * FROM contabilidade_conversas_mensagens
                WHERE conversa_id = :id
                ORDER BY criado_em ASC
            ");
            $stmt->execute([':id' => $conversa_id]);
            $mensagens_conversa = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Tabela pode não existir
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversas - Contabilidade</title>
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
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .conversas-layout {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 1.5rem;
            height: calc(100vh - 200px);
        }
        .conversas-list {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            overflow-y: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .conversas-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .conversa-item {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .conversa-item:hover {
            background: #f8fafc;
            border-color: #e5e7eb;
        }
        .conversa-item.active {
            background: #eff6ff;
            border-color: #1e40af;
        }
        .conversa-assunto {
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 0.25rem;
        }
        .conversa-meta {
            font-size: 0.875rem;
            color: #64748b;
        }
        .conversa-view {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .conversa-header {
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1rem;
        }
        .conversa-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 0.5rem;
        }
        .conversa-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-aberto { background: #fef3c7; color: #92400e; }
        .status-em_andamento { background: #dbeafe; color: #1e40af; }
        .status-concluido { background: #d1fae5; color: #065f46; }
        .mensagens-container {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
        }
        .mensagem {
            margin-bottom: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            border-left: 3px solid #1e40af;
        }
        .mensagem.admin { border-left-color: #059669; }
        .mensagem-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        .mensagem-autor {
            font-weight: 600;
            color: #1e40af;
        }
        .mensagem.admin .mensagem-autor { color: #059669; }
        .mensagem-data {
            font-size: 0.875rem;
            color: #64748b;
        }
        .mensagem-texto {
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .mensagem-anexo {
            margin-top: 0.5rem;
        }
        .mensagem-anexo a {
            color: #1e40af;
            text-decoration: none;
        }
        .form-enviar {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .form-input, .form-textarea {
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 1rem;
        }
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        .form-actions {
            display: flex;
            gap: 0.5rem;
        }
        .btn-primary {
            background: #1e40af;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
        }
        .btn-primary:hover { background: #1e3a8a; }
        .btn-secondary {
            background: #6b7280;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
        }
        .btn-secondary:hover { background: #4b5563; }
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .form-section-title { font-size: 1.25rem; font-weight: 600; color: #1e40af; margin-bottom: 1.5rem; }
        .form-group { display: flex; flex-direction: column; margin-bottom: 1rem; }
        .form-label { font-weight: 500; color: #374151; margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1>💬 Conversas</h1>
        <a href="<?= $is_contabilidade ? 'contabilidade_painel.php' : 'index.php?page=contabilidade' ?>" class="btn-back">← Voltar</a>
    </div>
    
    <div class="container">
        <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        
        <?php if (!$conversa_atual): ?>
        <!-- Lista de Conversas -->
        <div class="form-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 class="form-section-title">💬 Conversas</h2>
                <button onclick="document.getElementById('modal-nova-conversa').style.display='block'" class="btn-primary">
                    ➕ Nova Conversa
                </button>
            </div>
            
            <?php if (empty($conversas)): ?>
            <p style="color: #64748b; text-align: center; padding: 2rem;">Nenhuma conversa ainda. Crie a primeira!</p>
            <?php else: ?>
            <div style="display: grid; gap: 1rem;">
                <?php foreach ($conversas as $conv): ?>
                <a href="?id=<?= $conv['id'] ?>" style="text-decoration: none; color: inherit;">
                    <div style="background: white; padding: 1.5rem; border-radius: 8px; border: 1px solid #e5e7eb; transition: all 0.2s;" 
                         onmouseover="this.style.borderColor='#1e40af'; this.style.transform='translateY(-2px)'"
                         onmouseout="this.style.borderColor='#e5e7eb'; this.style.transform='translateY(0)'">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                            <div style="font-weight: 600; color: #1e40af; font-size: 1.125rem;">
                                <?= htmlspecialchars($conv['assunto']) ?>
                            </div>
                            <span class="conversa-status status-<?= $conv['status'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $conv['status'])) ?>
                            </span>
                        </div>
                        <div style="font-size: 0.875rem; color: #64748b;">
                            <?= $conv['total_mensagens'] ?> mensagem<?= $conv['total_mensagens'] !== 1 ? 's' : '' ?>
                            <?php if ($conv['ultima_mensagem']): ?>
                                • <?= date('d/m/Y H:i', strtotime($conv['ultima_mensagem'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Modal Nova Conversa -->
        <div id="modal-nova-conversa" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; padding: 2rem; max-width: 500px; width: 90%;">
                <h3 style="margin-bottom: 1.5rem; color: #1e40af;">Nova Conversa</h3>
                <form method="POST">
                    <input type="hidden" name="acao" value="criar_conversa">
                    <div class="form-group">
                        <label class="form-label">Assunto *</label>
                        <input type="text" name="assunto" class="form-input" required autofocus>
                    </div>
                    <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn-primary">Criar</button>
                        <button type="button" class="btn-secondary" onclick="document.getElementById('modal-nova-conversa').style.display='none'">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Visualização da Conversa -->
        <div class="conversas-layout">
            <!-- Lista Lateral -->
            <div class="conversas-list">
                <div class="conversas-list-header">
                    <strong>Conversas</strong>
                    <a href="contabilidade_conversas.php" style="color: #1e40af; text-decoration: none;">➕ Nova</a>
                </div>
                <?php foreach ($conversas as $conv): ?>
                <a href="?id=<?= $conv['id'] ?>" style="text-decoration: none; color: inherit;">
                    <div class="conversa-item <?= $conv['id'] == $conversa_id ? 'active' : '' ?>">
                        <div class="conversa-assunto"><?= htmlspecialchars($conv['assunto']) ?></div>
                        <div class="conversa-meta">
                            <span class="conversa-status status-<?= $conv['status'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $conv['status'])) ?>
                            </span>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <!-- Conversa Atual -->
            <div class="conversa-view">
                <div class="conversa-header">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div class="conversa-title"><?= htmlspecialchars($conversa_atual['assunto']) ?></div>
                            <span class="conversa-status status-<?= $conversa_atual['status'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $conversa_atual['status'])) ?>
                            </span>
                        </div>
                        <?php if ($conversa_atual['status'] !== 'concluido' || $is_admin): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="acao" value="alterar_status">
                            <input type="hidden" name="conversa_id" value="<?= $conversa_atual['id'] ?>">
                            <select name="status" onchange="this.form.submit()" style="padding: 0.5rem; border-radius: 6px; border: 1px solid #d1d5db;">
                                <option value="aberto" <?= $conversa_atual['status'] === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                                <option value="em_andamento" <?= $conversa_atual['status'] === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                                <option value="concluido" <?= $conversa_atual['status'] === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                            </select>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mensagens-container">
                    <?php if (empty($mensagens_conversa)): ?>
                    <p style="text-align: center; color: #64748b; padding: 2rem;">Nenhuma mensagem ainda. Seja o primeiro a enviar!</p>
                    <?php else: ?>
                    <?php foreach ($mensagens_conversa as $msg): ?>
                    <div class="mensagem <?= $msg['autor'] === 'admin' ? 'admin' : '' ?>">
                        <div class="mensagem-header">
                            <span class="mensagem-autor">
                                <?= $msg['autor'] === 'admin' ? '👤 Administrador' : '📑 Contabilidade' ?>
                            </span>
                            <span class="mensagem-data">
                                <?= date('d/m/Y H:i', strtotime($msg['criado_em'])) ?>
                            </span>
                        </div>
                        <?php if ($msg['mensagem']): ?>
                        <div class="mensagem-texto"><?= nl2br(htmlspecialchars($msg['mensagem'])) ?></div>
                        <?php endif; ?>
                        <?php if ($msg['anexo_url']): ?>
                        <div class="mensagem-anexo">
                            <a href="<?= htmlspecialchars($msg['anexo_url']) ?>" target="_blank">
                                📎 <?= htmlspecialchars($msg['anexo_nome']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <?php if ($conversa_atual['status'] !== 'concluido'): ?>
                <form method="POST" enctype="multipart/form-data" class="form-enviar">
                    <input type="hidden" name="acao" value="enviar_mensagem">
                    <input type="hidden" name="conversa_id" value="<?= $conversa_atual['id'] ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Mensagem</label>
                        <textarea name="mensagem" class="form-textarea" placeholder="Digite sua mensagem..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Anexo (opcional)</label>
                        <input type="file" name="anexo" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    
                    <button type="submit" class="btn-primary">📤 Enviar</button>
                </form>
                <?php else: ?>
                <div style="padding: 1rem; background: #fef3c7; border-radius: 8px; color: #92400e; text-align: center;">
                    Esta conversa está concluída. <?php if ($is_admin): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="acao" value="alterar_status">
                        <input type="hidden" name="conversa_id" value="<?= $conversa_atual['id'] ?>">
                        <input type="hidden" name="status" value="aberto">
                        <button type="submit" style="background: none; border: none; color: #1e40af; text-decoration: underline; cursor: pointer;">
                            Reabrir conversa
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
