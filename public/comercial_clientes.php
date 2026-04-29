<?php
// comercial_clientes.php — Funil de conversão: quem foi × quem fechou
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

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


?>

<?php includeSidebar('Comercial clientes'); ?>

<style>
    .conversao-page {
        padding: 32px 24px 40px;
    }

    .conversao-container {
        max-width: 1320px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        flex-wrap: wrap;
        padding: 28px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 24px;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
    }

    .page-header-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .back-link {
        color: #2563eb;
        text-decoration: none;
        font-size: 0.95rem;
        font-weight: 600;
        margin-bottom: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .page-title {
        margin: 0;
        font-size: clamp(2rem, 3vw, 2.6rem);
        line-height: 1.05;
        color: #1e293b;
    }

    .page-subtitle {
        margin-top: 0.5rem;
        color: #64748b;
        font-size: 0.98rem;
    }

    .alert {
        padding: 16px 18px;
        border-radius: 16px;
        font-weight: 600;
        border: 1px solid transparent;
    }

    .alert-success {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
    }

    .alert-error {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .conversion-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
    }

    .stat-card {
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #dbeafe;
        border-radius: 22px;
        padding: 22px;
        box-shadow: 0 16px 32px rgba(37, 99, 235, 0.08);
    }

    .stat-value {
        font-size: clamp(2rem, 3vw, 2.8rem);
        font-weight: 800;
        color: #0f172a;
        line-height: 1;
    }

    .stat-label {
        margin-top: 10px;
        color: #475569;
        font-weight: 600;
    }

    .stat-percentage {
        margin-top: 12px;
        display: inline-flex;
        align-items: center;
        padding: 6px 10px;
        border-radius: 999px;
        background: #dbeafe;
        color: #1d4ed8;
        font-size: 0.9rem;
        font-weight: 700;
    }

    .degustacoes-stats,
    .filters,
    .inscricoes-table {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 24px;
        padding: 24px;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
    }

    .degustacoes-title {
        margin: 0 0 18px;
        font-size: 1.5rem;
        color: #1e293b;
    }

    .degustacoes-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
    }

    .degustacao-stat {
        padding: 18px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .degustacao-name {
        font-size: 1rem;
        font-weight: 700;
        color: #0f172a;
    }

    .degustacao-details {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        color: #475569;
        font-size: 0.92rem;
    }

    .degustacao-conversion {
        color: #2563eb;
        font-weight: 700;
    }

    .filters-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 16px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .form-label {
        font-size: 0.9rem;
        font-weight: 700;
        color: #334155;
    }

    .form-input,
    .form-select {
        width: 100%;
        min-height: 46px;
        padding: 0 14px;
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        background: #fff;
        color: #0f172a;
        font: inherit;
    }

    .form-input:focus,
    .form-select:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
    }

    .filters-actions {
        display: flex;
        gap: 12px;
        margin-top: 20px;
        flex-wrap: wrap;
    }

    .btn-primary,
    .btn-secondary,
    .btn-cancel,
    .btn-save,
    .btn-sm {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 44px;
        padding: 0 16px;
        border-radius: 12px;
        border: 1px solid transparent;
        text-decoration: none;
        cursor: pointer;
        font: inherit;
        font-weight: 700;
        transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .btn-primary,
    .btn-save {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: #fff;
        box-shadow: 0 12px 24px rgba(37, 99, 235, 0.22);
    }

    .btn-secondary,
    .btn-cancel {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }

    .btn-sm {
        min-height: 38px;
        padding: 0 12px;
        font-size: 0.9rem;
    }

    .btn-success {
        background: #dcfce7;
        color: #166534;
        border-color: #bbf7d0;
    }

    .btn-primary:hover,
    .btn-secondary:hover,
    .btn-cancel:hover,
    .btn-save:hover,
    .btn-sm:hover {
        transform: translateY(-1px);
    }

    .inscricoes-table {
        overflow: hidden;
        padding: 0;
    }

    .table-header,
    .table-row {
        display: grid;
        grid-template-columns: minmax(220px, 2fr) minmax(180px, 1.45fr) 140px 150px 120px 150px 150px;
        gap: 16px;
        align-items: center;
        padding: 18px 24px;
    }

    .table-header {
        background: #eff6ff;
        color: #1e3a8a;
        font-size: 0.84rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .table-row {
        border-top: 1px solid #e2e8f0;
        background: #ffffff;
    }

    .table-row:nth-child(even) {
        background: #f8fafc;
    }

    .participant-name,
    .degustacao-name {
        font-weight: 700;
        color: #0f172a;
    }

    .participant-email,
    .degustacao-date {
        margin-top: 4px;
        color: #64748b;
        font-size: 0.9rem;
    }

    .modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        padding: 24px;
        z-index: 1300;
        align-items: center;
        justify-content: center;
    }

    .modal.active {
        display: flex;
    }

    .modal-content {
        width: min(100%, 560px);
        background: #ffffff;
        border-radius: 24px;
        box-shadow: 0 30px 80px rgba(15, 23, 42, 0.3);
        padding: 24px;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        margin-bottom: 20px;
    }

    .modal-title {
        margin: 0;
        font-size: 1.25rem;
        color: #0f172a;
    }

    .close-btn {
        border: 0;
        background: transparent;
        font-size: 1.8rem;
        line-height: 1;
        color: #64748b;
        cursor: pointer;
    }

    .form-radio-group {
        display: flex;
        gap: 18px;
        flex-wrap: wrap;
    }

    .form-radio {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #334155;
    }

    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 24px;
    }

    @media (max-width: 1200px) {
        .filters-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .conversion-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .inscricoes-table {
            overflow-x: auto;
        }

        .table-header,
        .table-row {
            min-width: 1120px;
        }
    }

    @media (max-width: 768px) {
        .conversao-page {
            padding: 20px 14px 28px;
        }

        .page-header {
            padding: 20px;
        }

        .page-header-actions,
        .filters-actions,
        .form-actions {
            width: 100%;
        }

        .page-header-actions > *,
        .filters-actions > *,
        .form-actions > * {
            flex: 1 1 100%;
        }

        .conversion-stats,
        .filters-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="conversao-page">
    <div class="conversao-container">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <a href="index.php?page=comercial" class="back-link">← Voltar para Comercial</a>
                    <h1 class="page-title">📊 Funil de Conversão</h1>
                    <p class="page-subtitle">Acompanhe inscrições, contratos fechados e desempenho por degustação.</p>
                </div>
                <div class="page-header-actions">
                    <a href="index.php?page=comercial_degustacoes" class="btn-secondary">🍽️ Degustações</a>
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
                    <div class="stat-percentage"><?= number_format($total_inscricoes > 0 ? ($nao_fechou_contrato / $total_inscricoes) * 100 : 0, 1) ?>%</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?= $indefinido ?></div>
                    <div class="stat-label">Indefinido</div>
                    <div class="stat-percentage"><?= number_format($total_inscricoes > 0 ? ($indefinido / $total_inscricoes) * 100 : 0, 1) ?>%</div>
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
                        <a href="index.php?page=comercial_clientes" class="btn-secondary">Limpar</a>
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
            // Coletar dados da tabela
            const rows = document.querySelectorAll('.table-row');
            let csv = 'Participante,Email,Degustação,Data,Status,Tipo Festa,Pessoas,Fechou Contrato,Pagamento\n';
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('div');
                if (cells.length >= 7) {
                    const nome = cells[0].querySelector('.participant-name')?.textContent?.trim() || '';
                    const email = cells[0].querySelector('.participant-email')?.textContent?.trim() || '';
                    const degustacao = cells[1].querySelector('.degustacao-name')?.textContent?.trim() || '';
                    const data = cells[1].querySelector('.degustacao-date')?.textContent?.trim() || '';
                    const status = cells[2].textContent?.trim() || '';
                    const tipoFesta = cells[3].textContent?.trim() || '';
                    const pessoas = cells[4].textContent?.trim() || '';
                    const fechou = cells[5].textContent?.trim() || '';
                    const pagamento = ''; // Não disponível nesta view
                    
                    csv += `"${nome}","${email}","${degustacao}","${data}","${status}","${tipoFesta}","${pessoas}","${fechou}","${pagamento}"\n`;
                }
            });
            
            // Criar e baixar arquivo
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `funil_conversao_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Fechar modal ao clicar fora
        document.getElementById('contratoModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeContratoModal();
            }
        });
    </script>
</div>

<?php
// Finalizar sidebar
endSidebar();
?>
