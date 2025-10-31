<?php
// comercial_degust_inscricoes.php — Todas as inscrições de todas as degustações
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_unified.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar permissões
if (!lc_can_manage_inscritos()) {
    header('Location: index.php?page=dashboard&error=permission_denied');
    exit;
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



?>

<div class="page-container">
    
    
    <div class="main-content">
        <div class="inscricoes-container">
            <!-- Header -->
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <a href="index.php?page=comercial" style="color: #3b82f6; text-decoration: none; font-size: 0.875rem; margin-bottom: 0.5rem; display: inline-block;">← Voltar para Comercial</a>
                    <h1 class="page-title" style="margin: 0;">📋 Todas as Inscrições</h1>
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <a href="index.php?page=comercial_degustacoes" class="btn-secondary" style="padding: 0.75rem 1.5rem; background: #e5e7eb; color: #374151; border-radius: 8px; text-decoration: none; font-weight: 500;">🍽️ Degustações</a>
                    <button class="btn-primary" onclick="exportCSV()" style="padding: 0.75rem 1.5rem; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">📊 Exportar CSV</button>
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
                        
                        <div><?= $inscricao['pagamento_text'] ?></div>
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
    
    <script>
        function exportCSV() {
            // Coletar dados da tabela
            const rows = document.querySelectorAll('.table-row');
            let csv = 'Participante,Email,Degustação,Data,Local,Status,Tipo Festa,Pessoas,Fechou Contrato,Pagamento,Criado Em\n';
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('div');
                if (cells.length >= 6) {
                    const nome = cells[0].querySelector('.participant-name')?.textContent?.trim() || '';
                    const email = cells[0].querySelector('.participant-email')?.textContent?.trim() || '';
                    const degustacao = cells[1].querySelector('.degustacao-name')?.textContent?.trim() || '';
                    const data = cells[1].querySelector('.degustacao-date')?.textContent?.trim() || '';
                    const local = ''; // Não disponível nesta view
                    const status = cells[2].textContent?.trim() || '';
                    const tipoFesta = cells[3].textContent?.trim() || '';
                    const pessoas = cells[4].textContent?.trim() || '';
                    const fechou = cells[5].textContent?.trim() || '';
                    const pagamento = ''; // Não disponível nesta view
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