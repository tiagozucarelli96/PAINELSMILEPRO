<?php
// comercial_clientes.php — Funil de conversão: quem foi × quem fechou
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Verificar permissões
if (!lc_can_view_conversao()) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

// Filtros
$degustacao_filter = (int)($_GET['degustacao_id'] ?? 0);
$fechou_filter = $_GET['fechou_contrato'] ?? '';
$pago_filter = $_GET['pago'] ?? '';
$search = trim($_GET['search'] ?? '');
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';

$where = [];
$params = [];

if ($degustacao_filter) {
    $where[] = 'i.degustacao_id = :degustacao_id';
    $params[':degustacao_id'] = $degustacao_filter;
}

if ($fechou_filter) {
    $where[] = 'i.fechou_contrato = :fechou_contrato';
    $params[':fechou_contrato'] = $fechou_filter;
}

if ($pago_filter) {
    if ($pago_filter === 'sim') {
        $where[] = 'i.pagamento_status = :pagamento_status';
        $params[':pagamento_status'] = 'pago';
    } elseif ($pago_filter === 'nao') {
        $where[] = 'i.pagamento_status != :pagamento_status';
        $params[':pagamento_status'] = 'pago';
    }
}

if ($search) {
    $where[] = '(i.nome ILIKE :search OR i.email ILIKE :search OR d.nome ILIKE :search)';
    $params[':search'] = "%$search%";
}

if ($data_inicio) {
    $where[] = 'd.data >= :data_inicio';
    $params[':data_inicio'] = $data_inicio;
}

if ($data_fim) {
    $where[] = 'd.data <= :data_fim';
    $params[':data_fim'] = $data_fim;
}

// Buscar inscrições
$sql = "SELECT i.*, d.nome as degustacao_nome, d.data as degustacao_data, d.local as degustacao_local,
               CASE WHEN i.fechou_contrato = 'sim' THEN 'Sim' 
                    WHEN i.fechou_contrato = 'nao' THEN 'Não' 
                    ELSE 'Indefinido' END as fechou_contrato_text,
               CASE WHEN i.pagamento_status = 'pago' THEN 'Pago' 
                    WHEN i.pagamento_status = 'aguardando' THEN 'Aguardando' 
                    WHEN i.pagamento_status = 'expirado' THEN 'Expirado' 
                    ELSE 'N/A' END as pagamento_text
        FROM comercial_inscricoes i
        LEFT JOIN comercial_degustacoes d ON d.id = i.degustacao_id";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY i.criado_em DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar degustações para filtro
$stmt = $pdo->query("SELECT id, nome, data FROM comercial_degustacoes ORDER BY data DESC");
$degustacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas de conversão
$total_inscricoes = count($inscricoes);
$fechou_contrato = count(array_filter($inscricoes, fn($i) => $i['fechou_contrato'] === 'sim'));
$nao_fechou_contrato = count(array_filter($inscricoes, fn($i) => $i['fechou_contrato'] === 'nao'));
$indefinido = count(array_filter($inscricoes, fn($i) => $i['fechou_contrato'] === 'indefinido'));

$taxa_conversao = $total_inscricoes > 0 ? ($fechou_contrato / $total_inscricoes) * 100 : 0;

// Estatísticas por degustação
$stats_por_degustacao = [];
foreach ($degustacoes as $degustacao) {
    $inscricoes_degustacao = array_filter($inscricoes, fn($i) => $i['degustacao_id'] == $degustacao['id']);
    $total_deg = count($inscricoes_degustacao);
    $fechou_deg = count(array_filter($inscricoes_degustacao, fn($i) => $i['fechou_contrato'] === 'sim'));
    $taxa_deg = $total_deg > 0 ? ($fechou_deg / $total_deg) * 100 : 0;
    
    $stats_por_degustacao[] = [
        'degustacao' => $degustacao,
        'total' => $total_deg,
        'fechou' => $fechou_deg,
        'taxa' => $taxa_deg
    ];
}

// Processar ações
$action = $_POST['action'] ?? '';
$inscricao_id = (int)($_POST['inscricao_id'] ?? 0);

if ($action === 'marcar_fechou_contrato' && $inscricao_id > 0) {
    try {
        $fechou_contrato = $_POST['fechou_contrato'] ?? 'nao';
        $nome_titular = trim($_POST['nome_titular_contrato'] ?? '');
        $cpf_3_digitos = trim($_POST['cpf_3_digitos'] ?? '');
        
        if ($fechou_contrato === 'sim' && (!$nome_titular || !$cpf_3_digitos)) {
            throw new Exception("Preencha o nome do titular e os 3 dígitos do CPF");
        }
        
        $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET fechou_contrato = :fechou_contrato, nome_titular_contrato = :nome_titular WHERE id = :id");
        $stmt->execute([
            ':fechou_contrato' => $fechou_contrato,
            ':nome_titular' => $nome_titular,
            ':id' => $inscricao_id
        ]);
        
        $success_message = "Status de contrato atualizado com sucesso!";
        
        // Recarregar página para atualizar dados
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
        
    } catch (Exception $e) {
        $error_message = "Erro ao atualizar contrato: " . $e->getMessage();
    }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function getStatusBadge($status) {
    $badges = [
        'confirmado' => '<span class="badge badge-success">Confirmado</span>',
        'lista_espera' => '<span class="badge badge-warning">Lista de Espera</span>',
        'cancelado' => '<span class="badge badge-danger">Cancelado</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-secondary">' . $status . '</span>';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funil de Conversão - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .conversao-container {
            max-width: 1400px;
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
        
        .btn-secondary {
            background: #6b7280;
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
        
        .conversion-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0 0 10px 0;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 16px;
            margin: 0 0 15px 0;
        }
        
        .stat-percentage {
            font-size: 24px;
            font-weight: 600;
            color: #10b981;
        }
        
        .stat-percentage.negative {
            color: #ef4444;
        }
        
        .filters {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .form-input {
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-select {
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }
        
        .filters-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .degustacoes-stats {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .degustacoes-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e3a8a;
            margin: 0 0 20px 0;
        }
        
        .degustacoes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .degustacao-stat {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
        }
        
        .degustacao-name {
            font-weight: 600;
            color: #1e3a8a;
            margin: 0 0 10px 0;
        }
        
        .degustacao-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
            color: #6b7280;
        }
        
        .degustacao-conversion {
            font-size: 18px;
            font-weight: 600;
            color: #10b981;
        }
        
        .inscricoes-table {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table-header {
            background: #f8fafc;
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            color: #374151;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr auto;
            gap: 15px;
        }
        
        .table-row {
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: center;
        }
        
        .table-row:hover {
            background: #f8fafc;
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .participant-info {
            display: flex;
            flex-direction: column;
        }
        
        .participant-name {
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 5px 0;
        }
        
        .participant-email {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }
        
        .degustacao-info {
            display: flex;
            flex-direction: column;
        }
        
        .degustacao-name {
            font-weight: 600;
            color: #1e3a8a;
            margin: 0 0 5px 0;
        }
        
        .degustacao-date {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
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
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-sm {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
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
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .form-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-radio-group {
            display: flex;
            gap: 15px;
        }
        
        .form-radio {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .form-radio input[type="radio"] {
            width: 16px;
            height: 16px;
            accent-color: #3b82f6;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .btn-cancel {
            background: #6b7280;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .btn-save {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php if (is_file(__DIR__.'/sidebar.php')) { include __DIR__.'/sidebar.php'; } ?>
    
    <div class="main-content">
        <div class="conversao-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">📊 Funil de Conversão</h1>
                <div>
                    <a href="comercial_degustacoes.php" class="btn-secondary">← Degustações</a>
                    <button class="btn-primary" onclick="exportCSV()">📊 Exportar CSV</button>
                </div>
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
            
            <!-- Estatísticas de Conversão -->
            <div class="conversion-stats">
                <div class="stat-card">
                    <div class="stat-value"><?= $total_inscricoes ?></div>
                    <div class="stat-label">Total de Inscrições</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= $fechou_contrato ?></div>
                    <div class="stat-label">Fecharam Contrato</div>
                    <div class="stat-percentage"><?= number_format($taxa_conversao, 1) ?>%</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= $nao_fechou_contrato ?></div>
                    <div class="stat-label">Não Fecharam</div>
                    <div class="stat-percentage"><?= number_format(($nao_fechou_contrato / $total_inscricoes) * 100, 1) ?>%</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= $indefinido ?></div>
                    <div class="stat-label">Indefinido</div>
                    <div class="stat-percentage"><?= number_format(($indefinido / $total_inscricoes) * 100, 1) ?>%</div>
                </div>
            </div>
            
            <!-- Estatísticas por Degustação -->
            <div class="degustacoes-stats">
                <h2 class="degustacoes-title">📈 Conversão por Degustação</h2>
                <div class="degustacoes-grid">
                    <?php foreach ($stats_por_degustacao as $stat): ?>
                        <div class="degustacao-stat">
                            <div class="degustacao-name"><?= h($stat['degustacao']['nome']) ?></div>
                            <div class="degustacao-details">
                                <span>Total: <?= $stat['total'] ?></span>
                                <span>Fecharam: <?= $stat['fechou'] ?></span>
                            </div>
                            <div class="degustacao-conversion">
                                Taxa: <?= number_format($stat['taxa'], 1) ?>%
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="filters">
                <form method="GET" id="filtersForm">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label class="form-label">Degustação</label>
                            <select name="degustacao_id" class="form-select">
                                <option value="">Todas as degustações</option>
                                <?php foreach ($degustacoes as $degustacao): ?>
                                    <option value="<?= $degustacao['id'] ?>" <?= $degustacao_filter == $degustacao['id'] ? 'selected' : '' ?>>
                                        <?= h($degustacao['nome']) ?> - <?= date('d/m/Y', strtotime($degustacao['data'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Fechou Contrato</label>
                            <select name="fechou_contrato" class="form-select">
                                <option value="">Todos</option>
                                <option value="sim" <?= $fechou_filter === 'sim' ? 'selected' : '' ?>>Sim</option>
                                <option value="nao" <?= $fechou_filter === 'nao' ? 'selected' : '' ?>>Não</option>
                                <option value="indefinido" <?= $fechou_filter === 'indefinido' ? 'selected' : '' ?>>Indefinido</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Pagamento</label>
                            <select name="pago" class="form-select">
                                <option value="">Todos</option>
                                <option value="sim" <?= $pago_filter === 'sim' ? 'selected' : '' ?>>Pago</option>
                                <option value="nao" <?= $pago_filter === 'nao' ? 'selected' : '' ?>>Não Pago</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Data Início</label>
                            <input type="date" name="data_inicio" class="form-input" value="<?= h($data_inicio) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Data Fim</label>
                            <input type="date" name="data_fim" class="form-input" value="<?= h($data_fim) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Pesquisar</label>
                            <input type="text" name="search" class="form-input" placeholder="Nome, e-mail ou degustação..." value="<?= h($search) ?>">
                        </div>
                    </div>
                    
                    <div class="filters-actions">
                        <button type="submit" class="btn-primary">🔍 Filtrar</button>
                        <a href="comercial_clientes.php" class="btn-secondary">Limpar</a>
                    </div>
                </form>
            </div>
            
            <!-- Tabela de Inscrições -->
            <div class="inscricoes-table">
                <div class="table-header">
                    <div>Participante</div>
                    <div>Degustação</div>
                    <div>Status</div>
                    <div>Tipo de Festa</div>
                    <div>Pessoas</div>
                    <div>Fechou Contrato</div>
                    <div>Ações</div>
                </div>
                
                <?php foreach ($inscricoes as $inscricao): ?>
                    <div class="table-row">
                        <div class="participant-info">
                            <div class="participant-name"><?= h($inscricao['nome']) ?></div>
                            <div class="participant-email"><?= h($inscricao['email']) ?></div>
                        </div>
                        
                        <div class="degustacao-info">
                            <div class="degustacao-name"><?= h($inscricao['degustacao_nome']) ?></div>
                            <div class="degustacao-date"><?= date('d/m/Y', strtotime($inscricao['degustacao_data'])) ?></div>
                        </div>
                        
                        <div><?= getStatusBadge($inscricao['status']) ?></div>
                        
                        <div><?= ucfirst($inscricao['tipo_festa']) ?></div>
                        
                        <div><?= $inscricao['qtd_pessoas'] ?> pessoas</div>
                        
                        <div><?= $inscricao['fechou_contrato_text'] ?></div>
                        
                        <div>
                            <button class="btn-sm btn-success" onclick="openContratoModal(<?= $inscricao['id'] ?>, '<?= $inscricao['fechou_contrato'] ?>', '<?= h($inscricao['nome_titular_contrato']) ?>')">
                                📄 Marcar Fechou
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (empty($inscricoes)): ?>
                <div style="text-align: center; padding: 40px; color: #6b7280;">
                    <h3>Nenhuma inscrição encontrada</h3>
                    <p>Experimente ajustar os filtros de pesquisa</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal de Contrato -->
    <div class="modal" id="contratoModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Marcar Fechou Contrato</h3>
                <button class="close-btn" onclick="closeContratoModal()">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="marcar_fechou_contrato">
                <input type="hidden" name="inscricao_id" id="contratoInscricaoId">
                
                <div class="form-group">
                    <label class="form-label">Fechou contrato?</label>
                    <div class="form-radio-group">
                        <div class="form-radio">
                            <input type="radio" name="fechou_contrato" value="sim" id="fechou_sim">
                            <label for="fechou_sim">Sim</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="fechou_contrato" value="nao" id="fechou_nao">
                            <label for="fechou_nao">Não</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nome do titular do contrato</label>
                    <input type="text" name="nome_titular_contrato" class="form-input" id="nomeTitular">
                </div>
                
                <div class="form-group">
                    <label class="form-label">3 primeiros dígitos do CPF</label>
                    <input type="text" name="cpf_3_digitos" class="form-input" maxlength="3" pattern="[0-9]{3}">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeContratoModal()">Cancelar</button>
                    <button type="submit" class="btn-save">Salvar</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openContratoModal(inscricaoId, fechouContrato, nomeTitular) {
            document.getElementById('contratoInscricaoId').value = inscricaoId;
            document.getElementById('nomeTitular').value = nomeTitular;
            document.getElementById('contratoModal').classList.add('active');
            
            if (fechouContrato === 'sim') {
                document.getElementById('fechou_sim').checked = true;
            } else {
                document.getElementById('fechou_nao').checked = true;
            }
        }
        
        function closeContratoModal() {
            document.getElementById('contratoModal').classList.remove('active');
        }
        
        function exportCSV() {
            // TODO: Implementar exportação CSV
            alert('Funcionalidade de exportação será implementada em breve');
        }
        
        // Fechar modal ao clicar fora
        document.getElementById('contratoModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeContratoModal();
            }
        });
    </script>
</body>
</html>
