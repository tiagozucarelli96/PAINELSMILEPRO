<?php
// comercial_degust_inscritos.php — Lista de inscritos de uma degustação específica
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Verificar permissões
if (!lc_can_manage_inscritos()) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

$event_id = (int)($_GET['event_id'] ?? 0);
if (!$event_id) {
    header('Location: index.php?page=comercial_degustacoes&error=invalid_event');
    exit;
}

// Buscar dados da degustação
$stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
$stmt->execute([':id' => $event_id]);
$degustacao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$degustacao) {
    header('Location: index.php?page=comercial_degustacoes&error=event_not_found');
    exit;
}

// Processar ações
$action = $_POST['action'] ?? '';
$inscricao_id = (int)($_POST['inscricao_id'] ?? 0);

if ($action === 'marcar_comparecimento' && $inscricao_id > 0) {
    try {
        $compareceu = isset($_POST['compareceu']) ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET compareceu = :compareceu WHERE id = :id");
        $stmt->execute([':compareceu' => $compareceu, ':id' => $inscricao_id]);
        $success_message = "Comparecimento atualizado com sucesso!";
    } catch (Exception $e) {
        $error_message = "Erro ao atualizar comparecimento: " . $e->getMessage();
    }
}

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
    } catch (Exception $e) {
        $error_message = "Erro ao atualizar contrato: " . $e->getMessage();
    }
}

// Filtros
$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Verificar se a coluna é degustacao_id ou event_id
// Por padrão, usar event_id (conforme schema oficial)
$where = ['i.event_id = :event_id'];
$params = [':event_id' => $event_id];

// Log para debug
error_log("Buscando inscrições para degustação ID: $event_id");

if ($status_filter) {
    $where[] = 'i.status = :status';
    $params[':status'] = $status_filter;
}

if ($search) {
    $where[] = '(i.nome ILIKE :search OR i.email ILIKE :search)';
    $params[':search'] = "%$search%";
}

// Buscar inscrições
$sql = "SELECT i.*, 
               CASE WHEN i.fechou_contrato = 'sim' THEN 'Sim' 
                    WHEN i.fechou_contrato = 'nao' THEN 'Não' 
                    ELSE 'Indefinido' END as fechou_contrato_text,
               CASE WHEN i.pagamento_status = 'pago' THEN 'Pago' 
                    WHEN i.pagamento_status = 'aguardando' THEN 'Aguardando' 
                    WHEN i.pagamento_status = 'expirado' THEN 'Expirado' 
                    ELSE 'N/A' END as pagamento_text
        FROM comercial_inscricoes i
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.criado_em DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [
    'total' => count($inscricoes),
    'confirmados' => count(array_filter($inscricoes, fn($i) => $i['status'] === 'confirmado')),
    'lista_espera' => count(array_filter($inscricoes, fn($i) => $i['status'] === 'lista_espera')),
    'fechou_contrato' => count(array_filter($inscricoes, fn($i) => $i['fechou_contrato'] === 'sim')),
    'compareceram' => count(array_filter($inscricoes, fn($i) => $i['compareceu'] ?? false))
];



?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscritos - <?= h($degustacao['nome']) ?> - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .inscritos-container {
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
        
        .event-info {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .event-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e3a8a;
            margin: 0 0 10px 0;
        }
        
        .event-details {
            color: #6b7280;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0 0 5px 0;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
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
        
        .inscritos-table {
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
        
        .btn-edit {
            background: #3b82f6;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-danger {
            background: #ef4444;
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
        <div class="inscritos-container">
            <!-- Header -->
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <a href="index.php?page=comercial" style="color: #3b82f6; text-decoration: none; font-size: 0.875rem; margin-bottom: 0.5rem; display: inline-block;">← Voltar para Comercial</a>
                    <h1 class="page-title" style="margin: 0;">👥 Inscritos - <?= h($degustacao['nome']) ?></h1>
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <a href="index.php?page=comercial_degustacoes" class="btn-secondary" style="padding: 0.75rem 1.5rem; background: #e5e7eb; color: #374151; border-radius: 8px; text-decoration: none; font-weight: 500;">← Degustações</a>
                    <button class="btn-primary" onclick="exportCSV()" style="padding: 0.75rem 1.5rem; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">📊 Exportar CSV</button>
                </div>
            </div>
            
            <!-- Informações do Evento -->
            <div class="event-info">
                <h2 class="event-title"><?= h($degustacao['nome']) ?></h2>
                <div class="event-details">
                    📅 <?= date('d/m/Y', strtotime($degustacao['data'])) ?> 
                    🕐 <?= date('H:i', strtotime($degustacao['hora_inicio'])) ?> - <?= date('H:i', strtotime($degustacao['hora_fim'])) ?>
                    📍 <?= h($degustacao['local']) ?>
                    👥 Capacidade: <?= $degustacao['capacidade'] ?> pessoas
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
            
            <!-- Estatísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total de Inscrições</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['confirmados'] ?></div>
                    <div class="stat-label">Confirmados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['lista_espera'] ?></div>
                    <div class="stat-label">Lista de Espera</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['fechou_contrato'] ?></div>
                    <div class="stat-label">Fecharam Contrato</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['compareceram'] ?></div>
                    <div class="stat-label">Compareceram</div>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="filters">
                <input type="text" class="search-input" placeholder="Pesquisar por nome ou e-mail..." 
                       value="<?= h($search) ?>" onkeyup="searchInscritos(this.value)">
                <select class="status-select" onchange="filterByStatus(this.value)">
                    <option value="">Todos os status</option>
                    <option value="confirmado" <?= $status_filter === 'confirmado' ? 'selected' : '' ?>>Confirmados</option>
                    <option value="lista_espera" <?= $status_filter === 'lista_espera' ? 'selected' : '' ?>>Lista de Espera</option>
                    <option value="cancelado" <?= $status_filter === 'cancelado' ? 'selected' : '' ?>>Cancelados</option>
                </select>
                <button class="btn-primary" onclick="searchInscritos()">🔍 Buscar</button>
            </div>
            
            <!-- Tabela de Inscritos -->
            <div class="inscritos-table">
                <div class="table-header">
                    <div style="grid-column: 1;">Participante</div>
                    <div style="grid-column: 2;">Status</div>
                    <div style="grid-column: 3;">Tipo de Festa</div>
                    <div style="grid-column: 4;">Pessoas</div>
                    <div style="grid-column: 5;">Fechou Contrato</div>
                    <div style="grid-column: 6;">Pagamento</div>
                    <div style="grid-column: 7;">Ações</div>
                </div>
                
                <?php foreach ($inscricoes as $inscricao): ?>
                    <div class="table-row">
                        <div class="participant-info">
                            <div class="participant-name"><?= h($inscricao['nome']) ?></div>
                            <div class="participant-email"><?= h($inscricao['email']) ?></div>
                        </div>
                        
                        <div><?= getStatusBadge($inscricao['status']) ?></div>
                        
                        <div><?= ucfirst($inscricao['tipo_festa']) ?></div>
                        
                        <div><?= $inscricao['qtd_pessoas'] ?> pessoas</div>
                        
                        <div><?= $inscricao['fechou_contrato_text'] ?></div>
                        
                        <div><?= $inscricao['pagamento_text'] ?></div>
                        
                        <div style="display: flex; gap: 5px;">
                            <button class="btn-sm btn-edit" onclick="openComparecimentoModal(<?= $inscricao['id'] ?>, <?= $inscricao['compareceu'] ? 'true' : 'false' ?>)">
                                ✅ Comparecimento
                            </button>
                            <button class="btn-sm btn-success" onclick="openContratoModal(<?= $inscricao['id'] ?>, '<?= $inscricao['fechou_contrato'] ?>', '<?= h($inscricao['nome_titular_contrato']) ?>')">
                                📄 Contrato
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal de Comparecimento -->
    <div class="modal" id="comparecimentoModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Marcar Comparecimento</h3>
                <button class="close-btn" onclick="closeComparecimentoModal()">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="marcar_comparecimento">
                <input type="hidden" name="inscricao_id" id="comparecimentoInscricaoId">
                
                <div class="form-group">
                    <div class="form-radio-group">
                        <div class="form-radio">
                            <input type="radio" name="compareceu" value="1" id="compareceu_sim">
                            <label for="compareceu_sim">Compareceu</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="compareceu" value="0" id="compareceu_nao">
                            <label for="compareceu_nao">Não compareceu</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeComparecimentoModal()">Cancelar</button>
                    <button type="submit" class="btn-save">Salvar</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal de Contrato -->
    <div class="modal" id="contratoModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Status do Contrato</h3>
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
        function searchInscritos(query = '') {
            if (query === '') {
                query = document.querySelector('.search-input').value;
            }
            const status = document.querySelector('.status-select').value;
            let url = '?event_id=<?= $event_id ?>&search=' + encodeURIComponent(query);
            if (status) {
                url += '&status=' + encodeURIComponent(status);
            }
            window.location.href = url;
        }
        
        function filterByStatus(status) {
            const search = document.querySelector('.search-input').value;
            let url = '?event_id=<?= $event_id ?>&search=' + encodeURIComponent(search);
            if (status) {
                url += '&status=' + encodeURIComponent(status);
            }
            window.location.href = url;
        }
        
        function openComparecimentoModal(inscricaoId, compareceu) {
            document.getElementById('comparecimentoInscricaoId').value = inscricaoId;
            document.getElementById('comparecimentoModal').classList.add('active');
            
            if (compareceu) {
                document.getElementById('compareceu_sim').checked = true;
            } else {
                document.getElementById('compareceu_nao').checked = true;
            }
        }
        
        function closeComparecimentoModal() {
            document.getElementById('comparecimentoModal').classList.remove('active');
        }
        
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
            // Coletar dados da tabela
            const rows = document.querySelectorAll('.table-row');
            let csv = 'Participante,Email,Telefone,Status,Tipo Festa,Pessoas,Fechou Contrato,Pagamento,Observações\n';
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('div');
                if (cells.length >= 6) {
                    const nome = cells[0]?.textContent?.trim() || '';
                    const email = cells[1]?.textContent?.trim() || '';
                    const telefone = cells[2]?.textContent?.trim() || '';
                    const status = cells[3]?.textContent?.trim() || '';
                    const tipoFesta = cells[4]?.textContent?.trim() || '';
                    const pessoas = cells[5]?.textContent?.trim() || '';
                    const fechou = cells[6]?.textContent?.trim() || '';
                    const pagamento = cells[7]?.textContent?.trim() || '';
                    const observacoes = ''; // Não disponível nesta view
                    
                    csv += `"${nome}","${email}","${telefone}","${status}","${tipoFesta}","${pessoas}","${fechou}","${pagamento}","${observacoes}"\n`;
                }
            });
            
            // Criar e baixar arquivo
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `inscritos_degustacao_<?= $degustacao['id'] ?>_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Fechar modais ao clicar fora
        document.getElementById('comparecimentoModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeComparecimentoModal();
            }
        });
        
        document.getElementById('contratoModal').addEventListener('click', function(e) {
            if (e.target === this) {
            closeContratoModal();
        }
    });
</script>

<!-- Custom Modals CSS -->
<link rel="stylesheet" href="assets/css/custom_modals.css">
<!-- Custom Modals JS -->
<script src="assets/js/custom_modals.js"></script>
</body>
</html>
