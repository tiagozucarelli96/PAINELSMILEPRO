<?php
// comercial_degustacoes.php — Lista e gestão de degustações
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

// Garantir que $pdo está disponível
if (!isset($pdo)) {
    global $pdo;
    if (isset($GLOBALS['pdo'])) {
        $pdo = $GLOBALS['pdo'];
    }
}

// Garantir acesso ao $pdo global se necessário
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    } else {
        die('Erro: Conexão com banco de dados não disponível');
    }
}

// Verificar permissões (index.php já verifica login)
if (!lc_can_access_comercial()) {
    header('Location: index.php?page=dashboard&error=permission_denied');
    exit;
}

// Processar ações
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$degustacao_id = (int)($_POST['degustacao_id'] ?? $_GET['id'] ?? 0);

if ($action === 'publicar' && $degustacao_id > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE comercial_degustacoes SET status = 'publicado' WHERE id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $success_message = "Degustação publicada com sucesso!";
    } catch (Exception $e) {
        $error_message = "Erro ao publicar: " . $e->getMessage();
    }
}

if ($action === 'encerrar' && $degustacao_id > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE comercial_degustacoes SET status = 'encerrado' WHERE id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $success_message = "Degustação encerrada com sucesso!";
    } catch (Exception $e) {
        $error_message = "Erro ao encerrar: " . $e->getMessage();
    }
}

if ($action === 'duplicar' && $degustacao_id > 0) {
    try {
        // Buscar dados da degustação original
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($original) {
            // Criar nova degustação baseada na original
            $sql = "INSERT INTO comercial_degustacoes (nome, data, hora_inicio, hora_fim, local, capacidade, data_limite, lista_espera, preco_casamento, incluidos_casamento, preco_15anos, incluidos_15anos, preco_extra, instrutivo_html, email_confirmacao_html, msg_sucesso_html, campos_json, status, criado_por) 
                    VALUES (:nome, :data, :hora_inicio, :hora_fim, :local, :capacidade, :data_limite, :lista_espera, :preco_casamento, :incluidos_casamento, :preco_15anos, :incluidos_15anos, :preco_extra, :instrutivo_html, :email_confirmacao_html, :msg_sucesso_html, :campos_json, 'rascunho', :criado_por)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nome' => ($original['nome'] ?? '') . ' (Cópia)',
                ':data' => $original['data'] ?? null,
                ':hora_inicio' => $original['hora_inicio'] ?? null,
                ':hora_fim' => $original['hora_fim'] ?? null,
                ':local' => $original['local'] ?? null,
                ':capacidade' => $original['capacidade'] ?? 0,
                ':data_limite' => $original['data_limite'] ?? null,
                ':lista_espera' => $original['lista_espera'] ?? false,
                ':preco_casamento' => $original['preco_casamento'] ?? 0.00,
                ':incluidos_casamento' => $original['incluidos_casamento'] ?? null,
                ':preco_15anos' => $original['preco_15anos'] ?? 0.00,
                ':incluidos_15anos' => $original['incluidos_15anos'] ?? null,
                ':preco_extra' => $original['preco_extra'] ?? 0.00,
                ':instrutivo_html' => $original['instrutivo_html'] ?? null,
                ':email_confirmacao_html' => $original['email_confirmacao_html'] ?? null,
                ':msg_sucesso_html' => $original['msg_sucesso_html'] ?? null,
                ':campos_json' => $original['campos_json'] ?? null,
                ':criado_por' => $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 1
            ]);
            
            $success_message = "Degustação duplicada com sucesso!";
        }
    } catch (Exception $e) {
        $error_message = "Erro ao duplicar: " . $e->getMessage();
    }
}

if ($action === 'apagar' && $degustacao_id > 0) {
    try {
        // Verificar se tem inscrições
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $inscricoes_count = $stmt->fetchColumn();
        
        if ($inscricoes_count > 0) {
            $error_message = "Não é possível apagar degustação com inscrições. As inscrições serão preservadas.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM comercial_degustacoes WHERE id = :id");
            $stmt->execute([':id' => $degustacao_id]);
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

$sql .= " ORDER BY d.data DESC, d.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$degustacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Suprimir warnings de variáveis undefined durante renderização
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', 0);

// Criar conteúdo da página
ob_start();
?>

<style>
.comercial-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem 2rem;
}

.page-header {
    margin-bottom: 2rem;
}

/* Grid de Degustações */
.degustacoes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 1.5rem;
    margin-top: 1.5rem;
}

/* Card de Degustação */
.degustacao-card {
    background: white;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
    overflow: hidden;
}

.degustacao-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
}

.degustacao-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 2px solid #e5e7eb;
}

.degustacao-card .card-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0;
}

/* Detalhes do Card */
.card-details {
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    font-weight: 600;
    color: #64748b;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.detail-value {
    color: #1e293b;
    font-weight: 500;
    text-align: right;
}

/* Estatísticas - parte do mesmo card */
.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    background: #f1f5f9;
    border-top: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    margin-top: 0;
}

.stat-item {
    text-align: center;
    padding: 1rem;
    background: white;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #3b82f6;
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.75rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Botões de Ação */
.card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 1rem 1.5rem;
    background: #f8fafc;
}

.btn-sm {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.btn-sm:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.btn-edit {
    background: #3b82f6;
    color: white;
}

.btn-edit:hover {
    background: #2563eb;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover {
    background: #059669;
}

.btn-warning {
    background: #f59e0b;
    color: white;
}

.btn-warning:hover {
    background: #d97706;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

/* Filtros */
.filters {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.search-input, .status-select {
    padding: 0.75rem 1rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.875rem;
    flex: 1;
    min-width: 200px;
}

.search-input:focus, .status-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Alerts */
.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #10b981;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #ef4444;
}

/* Status Badge */
.badge {
    display: inline-block;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-warning {
    background: #fef3c7;
    color: #92400e;
}

.badge-success {
    background: #d1fae5;
    color: #065f46;
}

.badge-secondary {
    background: #e5e7eb;
    color: #374151;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #64748b;
}

.empty-state-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.3;
}

@media (max-width: 768px) {
    .comercial-container {
        padding: 1rem;
    }
    
    .degustacoes-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-row {
        grid-template-columns: 1fr;
    }
    
    .filters {
        flex-direction: column;
    }
}
</style>

<div class="page-container">
    <div class="main-content">
        <div class="comercial-container">
            <!-- Header -->
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid #e5e7eb;">
                <h1 class="page-title" style="margin: 0; font-size: 1.875rem; font-weight: 700; color: #1e3a8a;">🍽️ Degustações</h1>
                <?php if (lc_can_edit_degustacoes()): ?>
                <a href="index.php?page=comercial_degustacao_editar" class="btn-primary" style="padding: 0.75rem 1.5rem; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.2s; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3); display: inline-flex; align-items: center; gap: 0.5rem;">
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
                       value="<?= h($search) ?>" 
                       onkeypress="if(event.key === 'Enter') searchDegustacoes()"
                       id="searchInput">
                <select class="status-select" id="statusSelect" onchange="filterByStatus(this.value)">
                    <option value="">Todos os status</option>
                    <option value="rascunho" <?= $status_filter === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                    <option value="publicado" <?= $status_filter === 'publicado' ? 'selected' : '' ?>>Publicado</option>
                    <option value="encerrado" <?= $status_filter === 'encerrado' ? 'selected' : '' ?>>Encerrado</option>
                </select>
                <button class="btn-primary" onclick="searchDegustacoes()" type="button">🔍 Buscar</button>
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
                                <span class="detail-value">
                                    <?php if (!empty($degustacao['hora_inicio']) && !empty($degustacao['hora_fim']) && $degustacao['hora_inicio'] !== null && $degustacao['hora_fim'] !== null): ?>
                                        <?= date('H:i', strtotime($degustacao['hora_inicio'])) ?> - <?= date('H:i', strtotime($degustacao['hora_fim'])) ?>
                                    <?php else: ?>
                                        Não definido
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">📍 Local:</span>
                                <span class="detail-value"><?= h($degustacao['local']) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">📅 Data limite:</span>
                                <span class="detail-value">
                                    <?php if (!empty($degustacao['data_limite']) && $degustacao['data_limite'] !== null): ?>
                                        <?= date('d/m/Y', strtotime($degustacao['data_limite'])) ?>
                                    <?php else: ?>
                                        Não definida
                                    <?php endif; ?>
                                </span>
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
                            <a href="index.php?page=comercial_degustacao_editar&id=<?= $degustacao['id'] ?>" class="btn-sm btn-edit">
                                ✏️ Editar
                            </a>
                            <?php endif; ?>
                            
                            <?php if (lc_can_manage_inscritos()): ?>
                            <a href="index.php?page=comercial_degust_inscritos&degustacao_id=<?= $degustacao['id'] ?>" class="btn-sm btn-secondary">
                                👥 Inscritos
                            </a>
                            <?php endif; ?>
                            
                            <?php 
                            // Verificar se coluna token_publico existe e tem valor
                            $token_publico = (isset($degustacao['token_publico']) && !empty($degustacao['token_publico'])) 
                                ? trim($degustacao['token_publico']) 
                                : '';
                            if (!empty($token_publico)): 
                            ?>
                            <a href="index.php?page=comercial_degust_public&t=<?= htmlspecialchars($token_publico, ENT_QUOTES, 'UTF-8') ?>" class="btn-sm btn-secondary" target="_blank">
                                🔗 Link Público
                            </a>
                            <?php else: ?>
                            <span class="btn-sm btn-secondary" style="opacity: 0.5; cursor: not-allowed;" title="Link público não disponível">
                                🔗 Link Público
                            </span>
                            <?php endif; ?>
                            
                            <?php if (lc_can_edit_degustacoes()): ?>
                                <?php if ($degustacao['status'] === 'rascunho'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="publicar">
                                    <input type="hidden" name="degustacao_id" value="<?= $degustacao['id'] ?>">
                                    <button type="submit" class="btn-sm btn-success">📢 Publicar</button>
                                </form>
                                <?php elseif ($degustacao['status'] === 'publicado'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="encerrar">
                                    <input type="hidden" name="degustacao_id" value="<?= $degustacao['id'] ?>">
                                    <button type="submit" class="btn-sm btn-warning">🔒 Encerrar</button>
                                </form>
                                <?php endif; ?>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="duplicar">
                                    <input type="hidden" name="degustacao_id" value="<?= $degustacao['id'] ?>">
                                    <button type="submit" class="btn-sm btn-secondary">📋 Duplicar</button>
                                </form>
                                
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja apagar esta degustação?')">
                                    <input type="hidden" name="action" value="apagar">
                                    <input type="hidden" name="degustacao_id" value="<?= $degustacao['id'] ?>">
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
                const input = document.getElementById('searchInput');
                query = input ? input.value : '';
            }
            const select = document.getElementById('statusSelect');
            const status = select ? select.value : '';
            let url = 'index.php?page=comercial_degustacoes&search=' + encodeURIComponent(query);
            if (status) {
                url += '&status=' + encodeURIComponent(status);
            }
            window.location.href = url;
        }
        
        function filterByStatus(status) {
            const input = document.getElementById('searchInput');
            const search = input ? input.value : '';
            let url = 'index.php?page=comercial_degustacoes&search=' + encodeURIComponent(search);
            if (status) {
                url += '&status=' + encodeURIComponent(status);
            }
            window.location.href = url;
        }
    </script>
</div>


<?php
// Restaurar error_reporting antes de incluir sidebar
error_reporting(E_ALL);
@ini_set('display_errors', 0);

$conteudo = ob_get_clean();

// Verificar se houve algum erro no buffer
if (ob_get_level() > 0) {
    ob_end_clean();
}

includeSidebar('Degustações');
echo $conteudo;
endSidebar();
?>