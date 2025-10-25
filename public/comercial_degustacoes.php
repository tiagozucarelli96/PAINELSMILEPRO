<?php
// comercial_degustacoes.php — Lista e gestão de degustações
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Verificar permissões
if (!lc_can_access_comercial()) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

// Processar ações
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$event_id = (int)($_POST['event_id'] ?? $_GET['id'] ?? 0);

if ($action === 'publicar' && $event_id > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE comercial_degustacoes SET status = 'publicado' WHERE id = :id");
        $stmt->execute([':id' => $event_id]);
        $success_message = "Degustação publicada com sucesso!";
    } catch (Exception $e) {
        $error_message = "Erro ao publicar: " . $e->getMessage();
    }
}

if ($action === 'encerrar' && $event_id > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE comercial_degustacoes SET status = 'encerrado' WHERE id = :id");
        $stmt->execute([':id' => $event_id]);
        $success_message = "Degustação encerrada com sucesso!";
    } catch (Exception $e) {
        $error_message = "Erro ao encerrar: " . $e->getMessage();
    }
}

if ($action === 'duplicar' && $event_id > 0) {
    try {
        // Buscar dados da degustação original
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $event_id]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($original) {
            // Criar nova degustação baseada na original
            $sql = "INSERT INTO comercial_degustacoes (nome, data, hora_inicio, hora_fim, local, capacidade, data_limite, lista_espera, preco_casamento, incluidos_casamento, preco_15anos, incluidos_15anos, preco_extra, instrutivo_html, email_confirmacao_html, msg_sucesso_html, campos_json, status, criado_por) 
                    VALUES (:nome, :data, :hora_inicio, :hora_fim, :local, :capacidade, :data_limite, :lista_espera, :preco_casamento, :incluidos_casamento, :preco_15anos, :incluidos_15anos, :preco_extra, :instrutivo_html, :email_confirmacao_html, :msg_sucesso_html, :campos_json, 'rascunho', :criado_por)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nome' => $original['nome'] . ' (Cópia)',
                ':data' => $original['data'],
                ':hora_inicio' => $original['hora_inicio'],
                ':hora_fim' => $original['hora_fim'],
                ':local' => $original['local'],
                ':capacidade' => $original['capacidade'],
                ':data_limite' => $original['data_limite'],
                ':lista_espera' => $original['lista_espera'],
                ':preco_casamento' => $original['preco_casamento'],
                ':incluidos_casamento' => $original['incluidos_casamento'],
                ':preco_15anos' => $original['preco_15anos'],
                ':incluidos_15anos' => $original['incluidos_15anos'],
                ':preco_extra' => $original['preco_extra'],
                ':instrutivo_html' => $original['instrutivo_html'],
                ':email_confirmacao_html' => $original['email_confirmacao_html'],
                ':msg_sucesso_html' => $original['msg_sucesso_html'],
                ':campos_json' => $original['campos_json'],
                ':criado_por' => $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 1
            ]);
            
            $success_message = "Degustação duplicada com sucesso!";
        }
    } catch (Exception $e) {
        $error_message = "Erro ao duplicar: " . $e->getMessage();
    }
}

if ($action === 'apagar' && $event_id > 0) {
    try {
        // Verificar se tem inscrições
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = :id");
        $stmt->execute([':id' => $event_id]);
        $inscricoes_count = $stmt->fetchColumn();
        
        if ($inscricoes_count > 0) {
            $error_message = "Não é possível apagar degustação com inscrições. As inscrições serão preservadas.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM comercial_degustacoes WHERE id = :id");
            $stmt->execute([':id' => $event_id]);
            $success_message = "Degustação apagada com sucesso!";
        }
    } catch (Exception $e) {
        $error_message = "Erro ao apagar: " . $e->getMessage();
    }
}

// Buscar degustações
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search) {
    $where[] = "(nome ILIKE :search OR local ILIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where[] = "status = :status";
    $params[':status'] = $status_filter;
}

$sql = "SELECT d.*, 
               u.nome as criado_por_nome,
               (SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = d.id AND status = 'confirmado') as inscritos_confirmados,
               (SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = d.id AND status = 'lista_espera') as lista_espera_count
        FROM comercial_degustacoes d
        LEFT JOIN usuarios u ON u.id = d.criado_por";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY d.data DESC, d.criado_em DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$degustacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function getStatusBadge($status) {
    $badges = [
        'rascunho' => '<span class="badge badge-warning">Rascunho</span>',
        'publicado' => '<span class="badge badge-success">Publicado</span>',
        'encerrado' => '<span class="badge badge-secondary">Encerrado</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-secondary">' . $status . '</span>';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Degustaciones - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .comercial-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .status-select {
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .degustacoes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
        }
        
        .degustacao-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.2s;
        }
        
        .degustacao-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        
        .card-details {
            margin-bottom: 15px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .detail-label {
            color: #6b7280;
            font-weight: 500;
        }
        
        .detail-value {
            color: #1f2937;
        }
        
        .stats-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: #f8fafc;
            border-radius: 8px;
            flex: 1;
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #1e3a8a;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6b7280;
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
        }
        
        .btn-edit {
            background: #3b82f6;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-secondary {
            background: #e5e7eb;
            color: #374151;
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
    </style>
</head>
<body>
    <?php if (is_file(__DIR__.'/sidebar.php')) { include __DIR__.'/sidebar.php'; } ?>
    
    <div class="main-content">
        <div class="comercial-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">🍽️ Degustações</h1>
                <?php if (lc_can_edit_degustacoes()): ?>
                <a href="comercial_degustacao_editar.php" class="btn-primary">
                    <span>➕</span>
                    Nova Degustação
                </a>
                <?php endif; ?>
            </div>
            
            <!-- Mensagens -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    ✅ <?= h($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    ❌ <?= h($error_message) ?>
                </div>
            <?php endif; ?>
            
            <!-- Filtros -->
            <div class="filters">
                <input type="text" class="search-input" placeholder="Pesquisar por nome ou local..." 
                       value="<?= h($search) ?>" onkeyup="searchDegustacoes(this.value)">
                <select class="status-select" onchange="filterByStatus(this.value)">
                    <option value="">Todos os status</option>
                    <option value="rascunho" <?= $status_filter === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                    <option value="publicado" <?= $status_filter === 'publicado' ? 'selected' : '' ?>>Publicado</option>
                    <option value="encerrado" <?= $status_filter === 'encerrado' ? 'selected' : '' ?>>Encerrado</option>
                </select>
                <button class="btn-primary" onclick="searchDegustacoes()">🔍 Buscar</button>
            </div>
            
            <!-- Grid de Degustações -->
            <div class="degustacoes-grid">
                <?php foreach ($degustacoes as $degustacao): ?>
                    <div class="degustacao-card">
                        <div class="card-header">
                            <h3 class="card-title"><?= h($degustacao['nome']) ?></h3>
                            <?= getStatusBadge($degustacao['status']) ?>
                        </div>
                        
                        <div class="card-details">
                            <div class="detail-row">
                                <span class="detail-label">📅 Data:</span>
                                <span class="detail-value"><?= date('d/m/Y', strtotime($degustacao['data'])) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">🕐 Horário:</span>
                                <span class="detail-value"><?= date('H:i', strtotime($degustacao['hora_inicio'])) ?> - <?= date('H:i', strtotime($degustacao['hora_fim'])) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">📍 Local:</span>
                                <span class="detail-value"><?= h($degustacao['local']) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">📅 Data limite:</span>
                                <span class="detail-value"><?= date('d/m/Y', strtotime($degustacao['data_limite'])) ?></span>
                            </div>
                        </div>
                        
                        <div class="stats-row">
                            <div class="stat-item">
                                <div class="stat-value"><?= $degustacao['inscritos_confirmados'] ?></div>
                                <div class="stat-label">Inscritos</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?= $degustacao['capacidade'] ?></div>
                                <div class="stat-label">Capacidade</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?= $degustacao['lista_espera_count'] ?></div>
                                <div class="stat-label">Lista de Espera</div>
                            </div>
                        </div>
                        
                        <div class="card-actions">
                            <?php if (lc_can_edit_degustacoes()): ?>
                            <a href="comercial_degustacao_editar.php?id=<?= $degustacao['id'] ?>" class="btn-sm btn-edit">
                                ✏️ Editar
                            </a>
                            <?php endif; ?>
                            
                            <?php if (lc_can_manage_inscritos()): ?>
                            <a href="comercial_degust_inscritos.php?event_id=<?= $degustacao['id'] ?>" class="btn-sm btn-secondary">
                                👥 Inscritos
                            </a>
                            <?php endif; ?>
                            
                            <a href="comercial_degust_public.php?t=<?= $degustacao['token_publico'] ?>" class="btn-sm btn-secondary" target="_blank">
                                🔗 Link Público
                            </a>
                            
                            <?php if (lc_can_edit_degustacoes()): ?>
                                <?php if ($degustacao['status'] === 'rascunho'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="publicar">
                                    <input type="hidden" name="event_id" value="<?= $degustacao['id'] ?>">
                                    <button type="submit" class="btn-sm btn-success">📢 Publicar</button>
                                </form>
                                <?php elseif ($degustacao['status'] === 'publicado'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="encerrar">
                                    <input type="hidden" name="event_id" value="<?= $degustacao['id'] ?>">
                                    <button type="submit" class="btn-sm btn-warning">🔒 Encerrar</button>
                                </form>
                                <?php endif; ?>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="duplicar">
                                    <input type="hidden" name="event_id" value="<?= $degustacao['id'] ?>">
                                    <button type="submit" class="btn-sm btn-secondary">📋 Duplicar</button>
                                </form>
                                
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja apagar esta degustação?')">
                                    <input type="hidden" name="action" value="apagar">
                                    <input type="hidden" name="event_id" value="<?= $degustacao['id'] ?>">
                                    <button type="submit" class="btn-sm btn-danger">🗑️ Apagar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <script>
        function searchDegustacoes(query = '') {
            if (query === '') {
                query = document.querySelector('.search-input').value;
            }
            const status = document.querySelector('.status-select').value;
            let url = '?search=' + encodeURIComponent(query);
            if (status) {
                url += '&status=' + encodeURIComponent(status);
            }
            window.location.href = url;
        }
        
        function filterByStatus(status) {
            const search = document.querySelector('.search-input').value;
            let url = '?search=' + encodeURIComponent(search);
            if (status) {
                url += '&status=' + encodeURIComponent(status);
            }
            window.location.href = url;
        }
    </script>
</body>
</html>
