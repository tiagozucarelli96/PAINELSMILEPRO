<?php
// contabilidade_admin_honorarios.php — Gestão administrativa de Honorários
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';

$mensagem = '';
$erro = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'alterar_status') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            
            if (!in_array($status, ['aberto', 'em_andamento', 'concluido', 'pago', 'vencido', 'cancelado'])) {
                throw new Exception('Status inválido');
            }
            
            $stmt = $pdo->prepare("
                UPDATE contabilidade_honorarios 
                SET status = :status, atualizado_em = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':status' => $status, ':id' => $id]);
            $mensagem = 'Status atualizado com sucesso!';
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
    
    if ($acao === 'excluir') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM contabilidade_honorarios WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $mensagem = 'Honorário excluído com sucesso!';
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
}

// Filtros
$filtro_status = $_GET['status'] ?? '';
$filtro_mes = $_GET['mes'] ?? '';

$where_conditions = [];
$params = [];

if ($filtro_status) {
    $where_conditions[] = "status = :status";
    $params[':status'] = $filtro_status;
}

if ($filtro_mes) {
    $where_conditions[] = "TO_CHAR(data_vencimento, 'YYYY-MM') = :mes";
    $params[':mes'] = $filtro_mes;
}

$where_sql = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar honorários
$honorarios = [];
try {
    $sql = "SELECT * FROM contabilidade_honorarios $where_sql ORDER BY data_vencimento DESC, criado_em DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $honorarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar honorários: " . $e->getMessage();
}

ob_start();
?>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
.container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
.header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: white;
    padding: 1.5rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    border-radius: 12px;
}
.header h1 { font-size: 1.5rem; font-weight: 700; }
.btn-back { background: rgba(255,255,255,0.2); color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; }
.alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
.alert-success { background: #d1fae5; color: #065f46; }
.alert-error { background: #fee2e2; color: #991b1b; }
.filters-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}
.form-group { display: flex; flex-direction: column; }
.form-label { font-weight: 500; color: #374151; margin-bottom: 0.5rem; }
.form-input, .form-select {
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
}
.table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.table th {
    background: #1e40af;
    color: white;
    padding: 1rem;
    text-align: left;
    font-weight: 600;
}
.table td {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
}
.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 500;
}
.badge-aberto { background: #fef3c7; color: #92400e; }
.badge-em_andamento { background: #dbeafe; color: #1e40af; }
.badge-concluido { background: #d1fae5; color: #065f46; }
.badge-pago { background: #d1fae5; color: #065f46; }
.btn-action {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin-right: 0.5rem;
}
.btn-download { background: #6b7280; color: white; }
.btn-delete { background: #dc2626; color: white; }
</style>

<div class="container">
    <div class="header">
        <h1>💼 Honorários - Gestão Administrativa</h1>
        <a href="index.php?page=contabilidade" class="btn-back">← Voltar</a>
    </div>
    
    <?php if ($mensagem): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    
    <!-- Filtros -->
    <div class="filters-section">
        <form method="GET" style="display: contents;">
            <div class="filters-grid">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="aberto" <?= $filtro_status === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                        <option value="em_andamento" <?= $filtro_status === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                        <option value="concluido" <?= $filtro_status === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                        <option value="pago" <?= $filtro_status === 'pago' ? 'selected' : '' ?>>Pago</option>
                        <option value="vencido" <?= $filtro_status === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Mês (YYYY-MM)</label>
                    <input type="month" name="mes" class="form-input" value="<?= htmlspecialchars($filtro_mes) ?>" onchange="this.form.submit()">
                </div>
            </div>
        </form>
    </div>
    
    <!-- Tabela -->
    <table class="table">
        <thead>
            <tr>
                <th>Descrição</th>
                <th>Vencimento</th>
                <th>Status</th>
                <th>Arquivo</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($honorarios)): ?>
            <tr>
                <td colspan="5" style="text-align: center; padding: 3rem; color: #64748b;">
                    Nenhum honorário encontrado.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($honorarios as $honorario): ?>
            <tr>
                <td><?= htmlspecialchars($honorario['descricao']) ?></td>
                <td><?= $honorario['data_vencimento'] ? date('d/m/Y', strtotime($honorario['data_vencimento'])) : '-' ?></td>
                <td>
                    <span class="badge badge-<?= str_replace('_', '-', $honorario['status']) ?>">
                        <?= ucfirst(str_replace('_', ' ', $honorario['status'])) ?>
                    </span>
                </td>
                <td>
                    <?php if ($honorario['arquivo_url']): ?>
                        <a href="<?= htmlspecialchars($honorario['arquivo_url']) ?>" target="_blank" class="btn-action btn-download">📎 Baixar</a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="acao" value="alterar_status">
                        <input type="hidden" name="id" value="<?= $honorario['id'] ?>">
                        <select name="status" onchange="this.form.submit()" style="padding: 0.5rem; border-radius: 6px; border: 1px solid #d1d5db;">
                            <option value="aberto" <?= $honorario['status'] === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                            <option value="em_andamento" <?= $honorario['status'] === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                            <option value="concluido" <?= $honorario['status'] === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                            <option value="pago" <?= $honorario['status'] === 'pago' ? 'selected' : '' ?>>Pago</option>
                            <option value="vencido" <?= $honorario['status'] === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                        </select>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza?');">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="id" value="<?= $honorario['id'] ?>">
                        <button type="submit" class="btn-action btn-delete">🗑️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Contabilidade - Honorários');
echo $conteudo;
endSidebar();
?>
