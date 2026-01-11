<?php
// contabilidade_admin_conversas.php — Gestão administrativa de Conversas
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
            
            if (!in_array($status, ['aberto', 'em_andamento', 'concluido'])) {
                throw new Exception('Status inválido');
            }
            
            $stmt = $pdo->prepare("
                UPDATE contabilidade_conversas 
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
            // Excluir mensagens primeiro
            $stmt = $pdo->prepare("DELETE FROM contabilidade_conversas_mensagens WHERE conversa_id = :id");
            $stmt->execute([':id' => $id]);
            // Depois a conversa
            $stmt = $pdo->prepare("DELETE FROM contabilidade_conversas WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $mensagem = 'Conversa excluída com sucesso!';
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
    
    if ($acao === 'criar_conversa') {
        try {
            $assunto = trim($_POST['assunto'] ?? '');
            if (empty($assunto)) {
                throw new Exception('Assunto é obrigatório');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO contabilidade_conversas (assunto, criado_por)
                VALUES (:assunto, 'admin')
                RETURNING id
            ");
            $stmt->execute([':assunto' => $assunto]);
            $conversa_id = $stmt->fetchColumn();
            
            header('Location: contabilidade_conversas.php?id=' . $conversa_id);
            exit;
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
}

// Filtros
$filtro_status = $_GET['status'] ?? '';

$where_conditions = [];
$params = [];

if ($filtro_status) {
    $where_conditions[] = "status = :status";
    $params[':status'] = $filtro_status;
}

$where_sql = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar conversas com contagem de mensagens
$conversas = [];
try {
    $sql = "
        SELECT c.*, 
               COUNT(m.id) as total_mensagens,
               MAX(m.criado_em) as ultima_mensagem
        FROM contabilidade_conversas c
        LEFT JOIN contabilidade_conversas_mensagens m ON m.conversa_id = c.id
        $where_sql
        GROUP BY c.id
        ORDER BY COALESCE(MAX(m.criado_em), c.criado_em) DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conversas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar conversas: " . $e->getMessage();
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
.form-group { display: flex; flex-direction: column; max-width: 300px; }
.form-label { font-weight: 500; color: #374151; margin-bottom: 0.5rem; }
.form-select {
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
.btn-view { background: #1e40af; color: white; }
.btn-delete { background: #dc2626; color: white; }
.btn-primary {
    background: #1e40af;
    color: white;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    font-size: 1rem;
}
.btn-secondary {
    background: #6b7280;
    color: white;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    font-size: 1rem;
}
.form-input {
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
    width: 100%;
}
.form-input:focus {
    outline: none;
    border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}
</style>

<div class="container">
    <div class="header">
        <h1>💬 Conversas - Gestão Administrativa</h1>
        <div style="display: flex; gap: 1rem; align-items: center;">
            <button onclick="abrirModalNovaConversa()" style="background: white; color: #1e40af; padding: 0.5rem 1rem; border-radius: 6px; border: none; font-weight: 600; cursor: pointer;">
                ➕ Nova Conversa
            </button>
            <a href="index.php?page=contabilidade" class="btn-back">← Voltar</a>
        </div>
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
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <option value="aberto" <?= $filtro_status === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                    <option value="em_andamento" <?= $filtro_status === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                    <option value="concluido" <?= $filtro_status === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                </select>
            </div>
        </form>
    </div>
    
    <!-- Tabela -->
    <table class="table">
        <thead>
            <tr>
                <th>Assunto</th>
                <th>Mensagens</th>
                <th>Última Mensagem</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($conversas)): ?>
            <tr>
                <td colspan="5" style="text-align: center; padding: 3rem; color: #64748b;">
                    Nenhuma conversa encontrada.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($conversas as $conversa): ?>
            <tr>
                <td><?= htmlspecialchars($conversa['assunto']) ?></td>
                <td><?= $conversa['total_mensagens'] ?></td>
                <td>
                    <?php if ($conversa['ultima_mensagem']): ?>
                        <?= date('d/m/Y H:i', strtotime($conversa['ultima_mensagem'])) ?>
                    <?php else: ?>
                        <?= date('d/m/Y H:i', strtotime($conversa['criado_em'])) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= str_replace('_', '-', $conversa['status']) ?>">
                        <?= ucfirst(str_replace('_', ' ', $conversa['status'])) ?>
                    </span>
                </td>
                <td>
                    <a href="contabilidade_conversas.php?id=<?= $conversa['id'] ?>" class="btn-action btn-view">👁️ Ver</a>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="acao" value="alterar_status">
                        <input type="hidden" name="id" value="<?= $conversa['id'] ?>">
                        <select name="status" onchange="this.form.submit()" style="padding: 0.5rem; border-radius: 6px; border: 1px solid #d1d5db;">
                            <option value="aberto" <?= $conversa['status'] === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                            <option value="em_andamento" <?= $conversa['status'] === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                            <option value="concluido" <?= $conversa['status'] === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                        </select>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza?');">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="id" value="<?= $conversa['id'] ?>">
                        <button type="submit" class="btn-action btn-delete">🗑️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Modal Nova Conversa -->
    <div id="modal-nova-conversa" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;" onclick="if(event.target === this) this.style.display='none'">
        <div style="background: white; border-radius: 12px; padding: 2rem; max-width: 500px; width: 90%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);" onclick="event.stopPropagation()">
            <h3 style="margin-bottom: 1.5rem; color: #1e40af; font-size: 1.5rem;">Nova Conversa</h3>
            <form method="POST">
                <input type="hidden" name="acao" value="criar_conversa">
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label class="form-label">Assunto *</label>
                    <input type="text" name="assunto" class="form-input" required autofocus placeholder="Digite o assunto da conversa">
                </div>
                <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn-primary">Criar</button>
                    <button type="button" class="btn-secondary" onclick="document.getElementById('modal-nova-conversa').style.display='none'">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
#modal-nova-conversa {
    display: none;
}
#modal-nova-conversa[style*="display: block"], 
#modal-nova-conversa[style*="display:flex"] {
    display: flex !important;
}
</style>

<script>
function abrirModalNovaConversa() {
    const modal = document.getElementById('modal-nova-conversa');
    modal.style.display = 'flex';
    const input = modal.querySelector('input[name="assunto"]');
    if (input) {
        setTimeout(() => input.focus(), 100);
    }
}
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Contabilidade - Conversas');
echo $conteudo;
endSidebar();
?>
