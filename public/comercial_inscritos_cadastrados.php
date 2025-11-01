<?php
// comercial_inscritos_cadastrados.php — Banco geral de todos os inscritos cadastrados
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Verificar permissões
if (!lc_can_manage_inscritos()) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

$pdo = $GLOBALS['pdo'];

// Filtros
$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = ['1=1'];
$params = [];

if ($status_filter) {
    $where[] = 'i.status = :status';
    $params[':status'] = $status_filter;
}

if ($search) {
    $where[] = '(i.nome ILIKE :search OR i.email ILIKE :search)';
    $params[':search'] = "%$search%";
}

// Verificar colunas existentes
$has_compareceu = false;
$has_fechou_contrato = false;
$has_valor_pago = false;
try {
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_name = 'comercial_inscricoes' 
                         AND column_name IN ('compareceu', 'fechou_contrato', 'valor_pago')");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $has_compareceu = in_array('compareceu', $columns);
    $has_fechou_contrato = in_array('fechou_contrato', $columns);
    $has_valor_pago = in_array('valor_pago', $columns);
} catch (PDOException $e) {
    error_log("Erro ao verificar colunas: " . $e->getMessage());
}

// Buscar todas as inscrições
$sql = "SELECT i.*, 
               d.nome as degustacao_nome,
               d.data as degustacao_data,
               d.local as degustacao_local,
               CASE WHEN i.fechou_contrato = 'sim' THEN 'Sim' 
                    WHEN i.fechou_contrato = 'nao' THEN 'Não' 
                    ELSE 'N/A' END as fechou_contrato_text,
               CASE WHEN i.pagamento_status = 'pago' THEN 'Pago' 
                    WHEN i.pagamento_status = 'aguardando' THEN 'Aguardando' 
                    WHEN i.pagamento_status = 'expirado' THEN 'Expirado' 
                    ELSE 'N/A' END as pagamento_text" . 
        ($has_valor_pago ? ", i.valor_pago" : ", NULL::numeric as valor_pago") . "
        FROM comercial_inscricoes i
        LEFT JOIN comercial_degustacoes d ON i.degustacao_id = d.id
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
    'fechou_contrato' => count(array_filter($inscricoes, fn($i) => ($i['fechou_contrato'] ?? '') === 'sim')),
    'compareceram' => count(array_filter($inscricoes, fn($i) => ($i['compareceu'] ?? 0) == 1)),
    'pagos' => count(array_filter($inscricoes, fn($i) => ($i['pagamento_status'] ?? '') === 'pago'))
];

ob_start();
?>
<link rel="stylesheet" href="assets/css/custom_modals.css">
<style>
    .inscritos-container {
        width: 100%;
        max-width: none;
        margin: 0;
        padding: 0;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding: 1.25rem 1.5rem;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border-bottom: 2px solid #e5e7eb;
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
        width: 100%;
        border-collapse: collapse;
    }
    
    .table-header {
        background: #f8fafc;
        padding: 15px 20px;
        border-bottom: 1px solid #e5e7eb;
        font-weight: 600;
        color: #374151;
    }
    
    .table-header-cell {
        padding: 15px 20px;
        border-right: 1px solid #e5e7eb;
        text-align: left;
    }
    
    .table-header-cell:last-child {
        border-right: none;
    }
    
    .table-row {
        border-bottom: 1px solid #e5e7eb;
    }
    
    .table-row:hover {
        background: #f8fafc;
    }
    
    .table-cell {
        padding: 15px 20px;
        border-right: 1px solid #e5e7eb;
        vertical-align: middle;
    }
    
    .table-cell:last-child {
        border-right: none;
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
    
    .checkbox-container {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    
    .checkbox-container input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
</style>

<div class="inscritos-container">
    <!-- Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">📋 Inscritos Cadastrados</h1>
            <div style="margin-top: 0.5rem; font-size: 1.125rem; font-weight: 600; color: #1e3a8a;">Banco Geral de Inscritos</div>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="index.php?page=comercial" class="btn-primary" style="background: #e5e7eb; color: #374151;">← Voltar</a>
            <button class="btn-primary" onclick="exportCSV()">📊 Exportar CSV</button>
        </div>
    </div>
    
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
        <div class="stat-card">
            <div class="stat-value"><?= $stats['pagos'] ?></div>
            <div class="stat-label">Pagamentos Confirmados</div>
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
    <table class="inscritos-table">
        <thead>
            <tr class="table-header">
                <th class="table-header-cell" style="width: 20%;">Participante</th>
                <th class="table-header-cell" style="width: 15%; text-align: center;">Degustação</th>
                <th class="table-header-cell" style="width: 10%; text-align: center;">Status</th>
                <th class="table-header-cell" style="width: 10%; text-align: center;">Tipo de Festa</th>
                <th class="table-header-cell" style="width: 8%; text-align: center;">Pessoas</th>
                <th class="table-header-cell" style="width: 12%; text-align: center;">Fechou Contrato</th>
                <th class="table-header-cell" style="width: 12%; text-align: center;">Compareceu</th>
                <th class="table-header-cell" style="width: 13%; text-align: center;">Pagamento</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($inscricoes)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px; color: #6b7280;">
                        Nenhuma inscrição encontrada.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($inscricoes as $inscricao): ?>
                    <tr class="table-row">
                        <td class="table-cell">
                            <div class="participant-info">
                                <div class="participant-name"><?= h($inscricao['nome']) ?></div>
                                <div class="participant-email"><?= h($inscricao['email']) ?></div>
                            </div>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?php if ($inscricao['degustacao_nome']): ?>
                                <div style="font-weight: 600; color: #1e3a8a; margin-bottom: 5px;">
                                    <?= h($inscricao['degustacao_nome']) ?>
                                </div>
                                <?php if ($inscricao['degustacao_data']): ?>
                                    <div style="font-size: 0.875rem; color: #6b7280;">
                                        <?= date('d/m/Y', strtotime($inscricao['degustacao_data'])) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: #9ca3af;">N/A</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?= getStatusBadge($inscricao['status']) ?>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?= ucfirst($inscricao['tipo_festa'] ?? 'N/A') ?>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?= $inscricao['qtd_pessoas'] ?? 0 ?> pessoas
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?php 
                            $fechou = ($inscricao['fechou_contrato'] ?? '') === 'sim';
                            ?>
                            <div class="checkbox-container">
                                <input type="checkbox" 
                                       <?= $fechou ? 'checked' : '' ?>
                                       disabled
                                       style="opacity: 0.6;">
                                <span style="font-size: 12px; color: <?= $fechou ? '#10b981' : '#6b7280' ?>;">
                                    <?= $fechou ? '✓ Sim' : 'Não' ?>
                                </span>
                            </div>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?php 
                            $compareceu = ($inscricao['compareceu'] ?? 0) == 1;
                            ?>
                            <div class="checkbox-container">
                                <input type="checkbox" 
                                       <?= $compareceu ? 'checked' : '' ?>
                                       disabled
                                       style="opacity: 0.6;">
                                <span style="font-size: 12px; color: <?= $compareceu ? '#10b981' : '#6b7280' ?>;">
                                    <?= $compareceu ? '✓ Sim' : 'Não' ?>
                                </span>
                            </div>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <div style="margin-bottom: 5px;"><?= $inscricao['pagamento_text'] ?? 'N/A' ?></div>
                            <?php if (isset($inscricao['valor_pago']) && $inscricao['valor_pago'] > 0): ?>
                                <div style="font-size: 0.875rem; color: #6b7280;">R$ <?= number_format($inscricao['valor_pago'], 2, ',', '.') ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function searchInscritos(query = '') {
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

function exportCSV() {
    const rows = document.querySelectorAll('.table-row');
    let csv = 'Participante,Email,Degustação,Data,Status,Tipo Festa,Pessoas,Fechou Contrato,Compareceu,Pagamento,Valor\n';
    
    rows.forEach(row => {
        const cells = row.querySelectorAll('.table-cell');
        if (cells.length >= 8) {
            const nome = cells[0]?.querySelector('.participant-name')?.textContent?.trim() || '';
            const email = cells[0]?.querySelector('.participant-email')?.textContent?.trim() || '';
            const degustacao = cells[1]?.textContent?.trim() || '';
            const status = cells[2]?.textContent?.trim() || '';
            const tipoFesta = cells[3]?.textContent?.trim() || '';
            const pessoas = cells[4]?.textContent?.trim() || '';
            const fechou = cells[5]?.textContent?.trim() || '';
            const compareceu = cells[6]?.textContent?.trim() || '';
            const pagamento = cells[7]?.textContent?.trim() || '';
            
            csv += `"${nome}","${email}","${degustacao}","","${status}","${tipoFesta}","${pessoas}","${fechou}","${compareceu}","${pagamento}",""\n`;
        }
    });
    
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `inscritos_cadastrados_${new Date().toISOString().split('T')[0]}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Inscritos Cadastrados');
echo $conteudo;
endSidebar();
?>

