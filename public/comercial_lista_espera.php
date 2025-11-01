<?php
// comercial_lista_espera.php — Lista de espera de todas as degustações
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

// Processar ações
$action = $_POST['action'] ?? '';
$inscricao_id = (int)($_POST['inscricao_id'] ?? 0);

// Ação de mover da lista de espera para confirmado
if ($action === 'promover_confirmado' && $inscricao_id > 0) {
    try {
        // Buscar inscrição
        $stmt = $pdo->prepare("SELECT i.*, d.capacidade, d.nome as degustacao_nome 
                               FROM comercial_inscricoes i 
                               JOIN comercial_degustacoes d ON i.degustacao_id = d.id 
                               WHERE i.id = :id");
        $stmt->execute([':id' => $inscricao_id]);
        $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$inscricao) {
            throw new Exception("Inscrição não encontrada");
        }
        
        // Verificar se ainda tem vaga
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comercial_inscricoes 
                               WHERE degustacao_id = :deg_id AND status = 'confirmado'");
        $stmt->execute([':deg_id' => $inscricao['degustacao_id']]);
        $inscritos_confirmados = $stmt->fetchColumn();
        
        if ($inscritos_confirmados >= $inscricao['capacidade']) {
            throw new Exception("Degustação '{$inscricao['degustacao_nome']}' já está lotada. Não é possível promover esta inscrição.");
        }
        
        // Promover para confirmado
        $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET status = 'confirmado' WHERE id = :id");
        $stmt->execute([':id' => $inscricao_id]);
        
        header("Location: index.php?page=comercial_lista_espera&success=promovido");
        exit;
    } catch (Exception $e) {
        $error_message = "Erro ao promover inscrito: " . $e->getMessage();
    }
}

// Filtros
$search = trim($_GET['search'] ?? '');
$degustacao_filter = (int)($_GET['degustacao_id'] ?? 0);

$where = ["i.status = 'lista_espera'"];
$params = [];

if ($search) {
    $where[] = '(i.nome ILIKE :search OR i.email ILIKE :search OR d.nome ILIKE :search)';
    $params[':search'] = "%$search%";
}

if ($degustacao_filter > 0) {
    $where[] = 'i.degustacao_id = :degustacao_id';
    $params[':degustacao_id'] = $degustacao_filter;
}

// Buscar todas as inscrições em lista de espera
$sql = "SELECT i.*, 
               d.nome as degustacao_nome,
               d.data as degustacao_data,
               d.local as degustacao_local,
               d.capacidade,
               (SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = d.id AND status = 'confirmado') as inscritos_confirmados
        FROM comercial_inscricoes i
        LEFT JOIN comercial_degustacoes d ON i.degustacao_id = d.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.criado_em ASC"; // Mais antigos primeiro

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar degustações para filtro
$degustacoes = [];
try {
    $stmt = $pdo->query("SELECT id, nome, data FROM comercial_degustacoes WHERE status = 'publicado' ORDER BY data DESC");
    $degustacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar degustações: " . $e->getMessage());
}

// Estatísticas
$stats = [
    'total' => count($inscricoes),
    'com_vagas' => 0, // Degustações com vagas disponíveis
    'lotadas' => 0 // Degustações completamente lotadas
];

foreach ($inscricoes as $insc) {
    if ($insc['inscritos_confirmados'] < $insc['capacidade']) {
        $stats['com_vagas']++;
    } else {
        $stats['lotadas']++;
    }
}

ob_start();
?>
<style>
    .lista-espera-container {
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
    
    .degustacao-select {
        padding: 12px 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 16px;
        min-width: 250px;
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
    
    .degustacao-info {
        font-weight: 600;
        color: #1e3a8a;
        margin-bottom: 5px;
    }
    
    .degustacao-meta {
        font-size: 0.875rem;
        color: #6b7280;
    }
    
    .vacancy-info {
        font-size: 0.875rem;
        padding: 4px 8px;
        border-radius: 4px;
        font-weight: 600;
    }
    
    .vacancy-available {
        background: #d1fae5;
        color: #065f46;
    }
    
    .vacancy-full {
        background: #fee2e2;
        color: #991b1b;
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
</style>

<div class="lista-espera-container">
    <!-- Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">⏳ Lista de Espera</h1>
            <div style="margin-top: 0.5rem; font-size: 1.125rem; font-weight: 600; color: #1e3a8a;">Inscritos aguardando vaga</div>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="index.php?page=comercial" class="btn-primary" style="background: #e5e7eb; color: #374151;">← Voltar</a>
        </div>
    </div>
    
    <!-- Mensagens -->
    <?php if (isset($_GET['success']) && $_GET['success'] === 'promovido'): ?>
        <div class="alert alert-success">
            ✅ Inscrito promovido para confirmado com sucesso!
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
            <div class="stat-label">Total na Lista de Espera</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['com_vagas'] ?></div>
            <div class="stat-label">Com Vagas Disponíveis</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['lotadas'] ?></div>
            <div class="stat-label">Aguardando Vaga</div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filters">
        <input type="text" class="search-input" placeholder="Pesquisar por nome, e-mail ou degustação..." 
               value="<?= h($search) ?>" onkeyup="searchListaEspera(this.value)">
        <select class="degustacao-select" onchange="filterByDegustacao(this.value)">
            <option value="">Todas as Degustações</option>
            <?php foreach ($degustacoes as $deg): ?>
                <option value="<?= $deg['id'] ?>" <?= $degustacao_filter == $deg['id'] ? 'selected' : '' ?>>
                    <?= h($deg['nome']) ?> - <?= date('d/m/Y', strtotime($deg['data'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn-primary" onclick="searchListaEspera()">🔍 Buscar</button>
    </div>
    
    <!-- Tabela de Lista de Espera -->
    <table class="inscritos-table">
        <thead>
            <tr class="table-header">
                <th class="table-header-cell" style="width: 5%;">#</th>
                <th class="table-header-cell" style="width: 18%;">Participante</th>
                <th class="table-header-cell" style="width: 20%;">Degustação</th>
                <th class="table-header-cell" style="width: 10%; text-align: center;">Tipo de Festa</th>
                <th class="table-header-cell" style="width: 8%; text-align: center;">Pessoas</th>
                <th class="table-header-cell" style="width: 12%; text-align: center;">Vagas</th>
                <th class="table-header-cell" style="width: 12%; text-align: center;">Inscrito Em</th>
                <th class="table-header-cell" style="width: 15%; text-align: center;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($inscricoes)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px; color: #6b7280;">
                        Nenhuma inscrição na lista de espera.
                    </td>
                </tr>
            <?php else: ?>
                <?php $posicao = 1; ?>
                <?php foreach ($inscricoes as $inscricao): ?>
                    <?php 
                    $vagas_disponiveis = $inscricao['capacidade'] - $inscricao['inscritos_confirmados'];
                    $tem_vaga = $vagas_disponiveis > 0;
                    ?>
                    <tr class="table-row">
                        <td class="table-cell" style="text-align: center; font-weight: 600; color: #f59e0b;">
                            #<?= $posicao ?>
                        </td>
                        
                        <td class="table-cell">
                            <div class="participant-info">
                                <div class="participant-name"><?= h($inscricao['nome']) ?></div>
                                <div class="participant-email"><?= h($inscricao['email']) ?></div>
                            </div>
                        </td>
                        
                        <td class="table-cell">
                            <div class="degustacao-info"><?= h($inscricao['degustacao_nome'] ?? 'N/A') ?></div>
                            <?php if ($inscricao['degustacao_data']): ?>
                                <div class="degustacao-meta">
                                    📅 <?= date('d/m/Y', strtotime($inscricao['degustacao_data'])) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($inscricao['degustacao_local']): ?>
                                <div class="degustacao-meta">
                                    📍 <?= h($inscricao['degustacao_local']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?= ucfirst($inscricao['tipo_festa'] ?? 'N/A') ?>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?= $inscricao['qtd_pessoas'] ?? 0 ?> pessoas
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <span class="vacancy-info <?= $tem_vaga ? 'vacancy-available' : 'vacancy-full' ?>">
                                <?= $tem_vaga ? "✅ $vagas_disponiveis vagas" : '❌ Lotado' ?>
                            </span>
                            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 4px;">
                                <?= $inscricao['inscritos_confirmados'] ?>/<?= $inscricao['capacidade'] ?>
                            </div>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?php if ($inscricao['criado_em']): ?>
                                <?= date('d/m/Y H:i', strtotime($inscricao['criado_em'])) ?>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        
                        <td class="table-cell" style="text-align: center;">
                            <?php if ($tem_vaga): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Promover este inscrito da lista de espera para confirmado?');">
                                    <input type="hidden" name="action" value="promover_confirmado">
                                    <input type="hidden" name="inscricao_id" value="<?= $inscricao['id'] ?>">
                                    <button type="submit" class="btn-sm btn-success">
                                        ✅ Promover
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="color: #9ca3af; font-size: 0.875rem;">Aguardando vaga</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php $posicao++; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function searchListaEspera(query = '') {
    if (query === '') {
        query = document.querySelector('.search-input').value;
    }
    const degustacao = document.querySelector('.degustacao-select').value;
    let url = '?search=' + encodeURIComponent(query);
    if (degustacao) {
        url += '&degustacao_id=' + encodeURIComponent(degustacao);
    }
    window.location.href = url;
}

function filterByDegustacao(degustacaoId) {
    const search = document.querySelector('.search-input').value;
    let url = '?search=' + encodeURIComponent(search);
    if (degustacaoId) {
        url += '&degustacao_id=' + encodeURIComponent(degustacaoId);
    }
    window.location.href = url;
}
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Lista de Espera');
echo $conteudo;
endSidebar();
?>

