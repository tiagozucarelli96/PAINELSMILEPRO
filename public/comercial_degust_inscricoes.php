<?php
// comercial_degust_inscricoes.php — Todas as inscrições de todas as degustações
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar permissões
if (!lc_can_manage_inscritos()) {
    header('Location: index.php?page=dashboard&error=permission_denied');
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
$inscricao_excluida_msg = '';

// Excluir inscrições de teste (one-off pelos e-mails da imagem) ou excluir uma por ID
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $action = $_POST['action'] ?? '';
    if ($action === 'excluir_teste') {
        $emails_teste = ['sdsd@mffmf.com', 'joao@email.com', 'maria@email.com', 'pedro@email.com'];
        $placeholders = implode(',', array_fill(0, count($emails_teste), '?'));
        $stmt = $pdo->prepare("DELETE FROM comercial_inscricoes WHERE email IN ($placeholders)");
        $stmt->execute($emails_teste);
        $inscricao_excluida_msg = 'Cadastros de teste excluídos com sucesso.';
        header('Location: index.php?page=comercial_degust_inscricoes&excluido=1&msg=' . urlencode($inscricao_excluida_msg));
        exit;
    }
    if ($action === 'excluir_inscricao') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM comercial_inscricoes WHERE id = ?');
            $stmt->execute([$id]);
            header('Location: index.php?page=comercial_degust_inscricoes&excluido=1');
            exit;
        }
    }
}

// Filtros
$event_filter = (int)($_GET['event_id'] ?? 0);
$status_filter = $_GET['status'] ?? '';
$fechou_filter = $_GET['fechou_contrato'] ?? '';
$search = trim($_GET['search'] ?? '');
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';

$where = [];
$params = [];

if ($event_filter) {
    $where[] = 'i.event_id = :event_id';
    $params[':event_id'] = $event_filter;
}

if ($status_filter) {
    $where[] = 'i.status = :status';
    $params[':status'] = $status_filter;
}

if ($fechou_filter) {
    $where[] = 'i.fechou_contrato = :fechou_contrato';
    $params[':fechou_contrato'] = $fechou_filter;
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

// Estatísticas gerais
$stats = [
    'total' => count($inscricoes),
    'confirmados' => count(array_filter($inscricoes, fn($i) => $i['status'] === 'confirmado')),
    'lista_espera' => count(array_filter($inscricoes, fn($i) => $i['status'] === 'lista_espera')),
    'fechou_contrato' => count(array_filter($inscricoes, fn($i) => $i['fechou_contrato'] === 'sim')),
    'nao_fechou_contrato' => count(array_filter($inscricoes, fn($i) => $i['fechou_contrato'] === 'nao')),
    'pagamentos_pagos' => count(array_filter($inscricoes, fn($i) => $i['pagamento_status'] === 'pago'))
];

// Iniciar sidebar
includeSidebar('Todas as Inscrições - Comercial');
?>

<style>
/* Container principal */
.inscricoes-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 1.5rem;
}

/* Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.page-header h1 {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1e3a5f;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.back-link {
    color: #3b82f6;
    text-decoration: none;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
    display: inline-block;
}

.back-link:hover {
    text-decoration: underline;
}

.header-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

/* Estatísticas */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e3a5f;
    line-height: 1.2;
}

.stat-label {
    font-size: 0.75rem;
    color: #64748b;
    margin-top: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

/* Filtros */
.filters {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.form-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
}

.form-select,
.form-input {
    padding: 0.55rem 0.75rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.9rem;
    background: #fff;
    width: 100%;
    box-sizing: border-box;
}

.form-select:focus,
.form-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.filters-actions {
    display: flex;
    gap: 0.75rem;
    padding-top: 0.5rem;
    border-top: 1px solid #f1f5f9;
}

/* Botões */
.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 1.25rem;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 4px 12px rgba(37,99,235,0.3);
}

.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 1.25rem;
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-secondary:hover {
    background: #e2e8f0;
}

/* Tabela */
.inscricoes-table {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.table-header {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1fr 1fr 0.8fr 1fr 0.8fr;
    gap: 1rem;
    padding: 0.85rem 1.25rem;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.table-row {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1fr 1fr 0.8fr 1fr 0.8fr;
    gap: 1rem;
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid #f1f5f9;
    align-items: center;
    font-size: 0.9rem;
}

.table-row:last-child {
    border-bottom: none;
}

.table-row:hover {
    background: #f8fafc;
}

.participant-name {
    font-weight: 500;
    color: #1e293b;
}

.participant-email {
    font-size: 0.8rem;
    color: #64748b;
}

.degustacao-name {
    font-weight: 500;
    color: #334155;
}

.degustacao-date {
    font-size: 0.8rem;
    color: #64748b;
}

/* Badges */
.badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-success {
    background: #dcfce7;
    color: #15803d;
}

.badge-warning {
    background: #fef3c7;
    color: #b45309;
}

.badge-danger {
    background: #fee2e2;
    color: #b91c1c;
}

.badge-info {
    background: #dbeafe;
    color: #1d4ed8;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
    color: #6b7280;
}

.empty-state h3 {
    color: #374151;
    margin-bottom: 0.5rem;
}

/* Responsivo */
@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    
    .filters-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .inscricoes-container {
        padding: 1rem;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
    }
    
    .header-actions .btn-primary,
    .header-actions .btn-secondary {
        flex: 1;
        justify-content: center;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filters-grid {
        grid-template-columns: 1fr;
    }
    
    .filters-actions {
        flex-direction: column;
    }
    
    .filters-actions .btn-primary,
    .filters-actions .btn-secondary {
        width: 100%;
        justify-content: center;
    }
    
    /* Tabela responsiva */
    .table-header {
        display: none;
    }
    
    .table-row {
        grid-template-columns: 1fr;
        gap: 0.5rem;
        padding: 1rem;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .table-row > div::before {
        content: attr(data-label);
        display: block;
        font-size: 0.7rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 0.15rem;
    }
}
</style>

<div class="inscricoes-container">
    
    
    <div class="main-content">
            <!-- Header -->
    <div class="page-header">
                <div>
            <a href="index.php?page=comercial" class="back-link">← Voltar para Comercial</a>
            <h1>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:24px;height:24px;color:#3b82f6;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                </svg>
                Todas as Inscrições
            </h1>
                </div>
        <div class="header-actions">
            <a href="index.php?page=comercial_degustacoes" class="btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                Degustações
            </a>
            <button class="btn-primary" onclick="exportCSV()">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Exportar CSV
            </button>
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
                    <div class="stat-value"><?= $stats['nao_fechou_contrato'] ?></div>
                    <div class="stat-label">Não Fecharam</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['pagamentos_pagos'] ?></div>
                    <div class="stat-label">Pagamentos Pagos</div>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="filters">
                <form method="GET" id="filtersForm">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label class="form-label">Degustação</label>
                            <select name="event_id" class="form-select">
                                <option value="">Todas as degustações</option>
                                <?php foreach ($degustacoes as $degustacao): ?>
                                    <option value="<?= $degustacao['id'] ?>" <?= $event_filter == $degustacao['id'] ? 'selected' : '' ?>>
                                        <?= h($degustacao['nome']) ?> - <?= date('d/m/Y', strtotime($degustacao['data'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">Todos os status</option>
                                <option value="confirmado" <?= $status_filter === 'confirmado' ? 'selected' : '' ?>>Confirmados</option>
                                <option value="lista_espera" <?= $status_filter === 'lista_espera' ? 'selected' : '' ?>>Lista de Espera</option>
                                <option value="cancelado" <?= $status_filter === 'cancelado' ? 'selected' : '' ?>>Cancelados</option>
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
                        <a href="comercial_degust_inscricoes.php" class="btn-secondary">Limpar</a>
                    </div>
                </form>
            </div>
            
            <?php if (!empty($_GET['excluido']) || !empty($_GET['msg'])): ?>
            <div class="alert alert-success" style="margin-bottom:1rem;padding:0.75rem;border-radius:8px;background:#d1fae5;color:#065f46;">
                <?= h($_GET['msg'] ?? 'Inscrição excluída com sucesso.') ?>
            </div>
            <?php endif; ?>
            
            <?php
            $emails_teste = ['sdsd@mffmf.com', 'joao@email.com', 'maria@email.com', 'pedro@email.com'];
            $tem_algum_teste = count(array_intersect($emails_teste, array_column($inscricoes, 'email'))) > 0;
            if ($tem_algum_teste): ?>
            <form method="POST" style="margin-bottom:1rem;" onsubmit="return confirm('Excluir todos os cadastros de teste (titnfn, João Silva, Maria Santos, Pedro Costa)?');">
                <input type="hidden" name="action" value="excluir_teste">
                <button type="submit" class="btn-secondary" style="background:#dc2626;color:#fff;border-color:#dc2626;">Excluir cadastros de teste</button>
            </form>
            <?php endif; ?>
            
            <!-- Tabela de Inscrições -->
            <div class="inscricoes-table">
                <div class="table-header">
                    <div>Participante</div>
                    <div>Degustação</div>
                    <div>Status</div>
                    <div>Tipo de Festa</div>
                    <div>Pessoas</div>
                    <div>Fechou Contrato</div>
                    <div>Pagamento</div>
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
                            <div class="degustacao-date"><?php $d = $inscricao['degustacao_data'] ?? ''; echo $d !== '' && $d !== null ? date('d/m/Y', strtotime($d)) : '—'; ?></div>
                        </div>
                        
                        <div><?= getStatusBadge($inscricao['status']) ?></div>
                        
                        <div><?= ucfirst((string)($inscricao['tipo_festa'] ?? '')) ?></div>
                        
                        <div><?= $inscricao['qtd_pessoas'] ?> pessoas</div>
                        
                        <div><?= $inscricao['fechou_contrato_text'] ?></div>
                        
                        <div><?= $inscricao['pagamento_text'] ?></div>
                        
                        <div>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir inscrição de <?= h(addslashes($inscricao['nome'])) ?>?');">
                                <input type="hidden" name="action" value="excluir_inscricao">
                                <input type="hidden" name="id" value="<?= (int)$inscricao['id'] ?>">
                                <button type="submit" class="btn-secondary" style="font-size:0.8rem;padding:4px 8px;background:#dc2626;color:#fff;border-color:#dc2626;">Excluir</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (empty($inscricoes)): ?>
        <div class="empty-state">
                    <h3>Nenhuma inscrição encontrada</h3>
                    <p>Experimente ajustar os filtros de pesquisa</p>
                </div>
            <?php endif; ?>
    
    <script>
        function exportCSV() {
            // Coletar dados da tabela
            const rows = document.querySelectorAll('.table-row');
            let csv = 'Participante,Email,Degustação,Data,Local,Status,Tipo Festa,Pessoas,Fechou Contrato,Pagamento,Criado Em\n';
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('div');
                if (cells.length >= 7) {
                    const nome = cells[0].querySelector('.participant-name')?.textContent?.trim() || '';
                    const email = cells[0].querySelector('.participant-email')?.textContent?.trim() || '';
                    const degustacao = cells[1].querySelector('.degustacao-name')?.textContent?.trim() || '';
                    const data = cells[1].querySelector('.degustacao-date')?.textContent?.trim() || '';
                    const local = ''; // Não disponível nesta view
                    const status = cells[2].textContent?.trim() || '';
                    const tipoFesta = cells[3].textContent?.trim() || '';
                    const pessoas = cells[4].textContent?.trim() || '';
                    const fechou = cells[5].textContent?.trim() || '';
                    const pagamento = cells[6].textContent?.trim() || '';
                    const criadoEm = ''; // Não disponível nesta view
                    
                    csv += `"${nome}","${email}","${degustacao}","${data}","${local}","${status}","${tipoFesta}","${pessoas}","${fechou}","${pagamento}","${criadoEm}"\n`;
                }
            });
            
            // Criar e baixar arquivo
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `todas_inscricoes_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>

</div>

<?php
// Finalizar sidebar
endSidebar();
?>