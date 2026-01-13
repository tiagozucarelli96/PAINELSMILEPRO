<?php
// contabilidade_admin_holerites.php — Gestão administrativa de Holerites
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
            
            if (!in_array($status, ['aberto', 'em_andamento', 'concluido', 'processado', 'cancelado'])) {
                throw new Exception('Status inválido');
            }
            
            $stmt = $pdo->prepare("
                UPDATE contabilidade_holerites 
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
            $stmt = $pdo->prepare("DELETE FROM contabilidade_holerites WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $mensagem = 'Holerite excluído com sucesso!';
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
}

// Buscar empresas para filtro
$empresas = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM contabilidade_empresas
        WHERE ativo = TRUE
        ORDER BY nome ASC
    ");
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir
}

// Filtros
$filtro_status = $_GET['status'] ?? '';
$filtro_mes = $_GET['mes'] ?? '';
$filtro_ajuste = $_GET['ajuste'] ?? '';
$filtro_empresa = $_GET['empresa'] ?? '';

$where_conditions = [];
$params = [];

if ($filtro_status) {
    $where_conditions[] = "h.status = :status";
    $params[':status'] = $filtro_status;
}

if ($filtro_mes) {
    $where_conditions[] = "h.mes_competencia = :mes";
    $params[':mes'] = $filtro_mes;
}

if ($filtro_ajuste === 'sim') {
    $where_conditions[] = "h.e_ajuste = TRUE";
} elseif ($filtro_ajuste === 'nao') {
    $where_conditions[] = "h.e_ajuste = FALSE";
}

if ($filtro_empresa) {
    $where_conditions[] = "h.empresa_id = :empresa";
    $params[':empresa'] = (int)$filtro_empresa;
}

$where_sql = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar holerites
$holerites = [];
try {
    $sql = "
        SELECT h.*, e.nome as empresa_nome, e.cnpj as empresa_cnpj
        FROM contabilidade_holerites h
        LEFT JOIN contabilidade_empresas e ON e.id = h.empresa_id
        $where_sql 
        ORDER BY h.mes_competencia DESC, h.criado_em DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $holerites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar holerites: " . $e->getMessage();
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
.badge-processado { background: #d1fae5; color: #065f46; }
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
        <h1>📄 Holerites - Gestão Administrativa</h1>
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
                        <option value="processado" <?= $filtro_status === 'processado' ? 'selected' : '' ?>>Processado</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Competência (MM/AAAA)</label>
                    <input type="text" name="mes" class="form-input" placeholder="01/2024" value="<?= htmlspecialchars($filtro_mes) ?>" pattern="\d{2}/\d{4}">
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo</label>
                    <select name="ajuste" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="sim" <?= $filtro_ajuste === 'sim' ? 'selected' : '' ?>>Ajustes</option>
                        <option value="nao" <?= $filtro_ajuste === 'nao' ? 'selected' : '' ?>>Normais</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Empresa</label>
                    <select name="empresa" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($empresas as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $filtro_empresa == $emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Tabela -->
    <table class="table">
        <thead>
            <tr>
                <th>Empresa</th>
                <th>Competência</th>
                <th>Tipo</th>
                <th>Status</th>
                <th>Observação</th>
                <th>Arquivo</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($holerites)): ?>
            <tr>
                <td colspan="7" style="text-align: center; padding: 3rem; color: #64748b;">
                    Nenhum holerite encontrado.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($holerites as $holerite): ?>
            <tr>
                <td><?= $holerite['empresa_nome'] ? htmlspecialchars($holerite['empresa_nome']) : '-' ?></td>
                <td><?= htmlspecialchars($holerite['mes_competencia']) ?></td>
                <td><?= $holerite['e_ajuste'] ? 'Ajuste' : 'Normal' ?></td>
                <td>
                    <span class="badge badge-<?= str_replace('_', '-', $holerite['status']) ?>">
                        <?= ucfirst(str_replace('_', ' ', $holerite['status'])) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($holerite['observacao'] ?? '-') ?></td>
                <td>
                    <?php if ($holerite['chave_storage'] || $holerite['arquivo_url']): ?>
                        <a href="contabilidade_download.php?tipo=holerite&id=<?= $holerite['id'] ?>" target="_blank" class="btn-action btn-download">📎 Baixar</a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="acao" value="alterar_status">
                        <input type="hidden" name="id" value="<?= $holerite['id'] ?>">
                        <select name="status" onchange="this.form.submit()" style="padding: 0.5rem; border-radius: 6px; border: 1px solid #d1d5db;">
                            <option value="aberto" <?= $holerite['status'] === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                            <option value="em_andamento" <?= $holerite['status'] === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                            <option value="concluido" <?= $holerite['status'] === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                            <option value="processado" <?= $holerite['status'] === 'processado' ? 'selected' : '' ?>>Processado</option>
                        </select>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza?');">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="id" value="<?= $holerite['id'] ?>">
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
includeSidebar('Contabilidade - Holerites');
echo $conteudo;
endSidebar();
?>
