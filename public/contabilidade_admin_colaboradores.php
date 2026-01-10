<?php
// contabilidade_admin_colaboradores.php — Gestão administrativa de Colaboradores
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
    
    if ($acao === 'excluir_documento') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM contabilidade_colaborador_documentos WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $mensagem = 'Documento excluído com sucesso!';
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
}

// Buscar colaboradores com documentos
$colaboradores = [];
try {
    $sql = "
        SELECT u.id, u.nome, u.cargo,
               COUNT(cd.id) as total_documentos
        FROM usuarios u
        LEFT JOIN contabilidade_colaborador_documentos cd ON cd.colaborador_id = u.id
        WHERE u.ativo = TRUE
        GROUP BY u.id, u.nome, u.cargo
        ORDER BY u.nome
    ";
    $stmt = $pdo->query($sql);
    $colaboradores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar colaboradores: " . $e->getMessage();
}

// Buscar documentos de um colaborador específico
$colaborador_id = $_GET['colaborador_id'] ?? null;
$documentos = [];
if ($colaborador_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT cd.*, u.nome as colaborador_nome
            FROM contabilidade_colaborador_documentos cd
            JOIN usuarios u ON u.id = cd.colaborador_id
            WHERE cd.colaborador_id = :id
            ORDER BY cd.criado_em DESC
        ");
        $stmt->execute([':id' => $colaborador_id]);
        $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $erro = "Erro ao buscar documentos: " . $e->getMessage();
    }
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
.table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
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
.btn-download { background: #6b7280; color: white; }
.btn-delete { background: #dc2626; color: white; }
</style>

<div class="container">
    <div class="header">
        <h1>👥 Colaboradores - Gestão Administrativa</h1>
        <a href="index.php?page=contabilidade" class="btn-back">← Voltar</a>
    </div>
    
    <?php if ($mensagem): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    
    <?php if (!$colaborador_id): ?>
    <!-- Lista de Colaboradores -->
    <table class="table">
        <thead>
            <tr>
                <th>Nome</th>
                <th>Cargo</th>
                <th>Documentos</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($colaboradores)): ?>
            <tr>
                <td colspan="4" style="text-align: center; padding: 3rem; color: #64748b;">
                    Nenhum colaborador encontrado.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($colaboradores as $colab): ?>
            <tr>
                <td><?= htmlspecialchars($colab['nome']) ?></td>
                <td><?= htmlspecialchars($colab['cargo'] ?? '-') ?></td>
                <td><?= $colab['total_documentos'] ?></td>
                <td>
                    <a href="?colaborador_id=<?= $colab['id'] ?>" class="btn-action btn-view">📄 Ver Documentos</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php else: ?>
    <!-- Documentos do Colaborador -->
    <div style="margin-bottom: 1rem;">
        <a href="?" class="btn-back" style="display: inline-block; background: #1e40af; color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none;">← Voltar para Lista</a>
    </div>
    
    <h2 style="margin-bottom: 1rem; color: #1e40af;">
        Documentos de <?= htmlspecialchars($documentos[0]['colaborador_nome'] ?? 'Colaborador') ?>
    </h2>
    
    <table class="table">
        <thead>
            <tr>
                <th>Tipo</th>
                <th>Descrição</th>
                <th>Data</th>
                <th>Arquivo</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($documentos)): ?>
            <tr>
                <td colspan="5" style="text-align: center; padding: 3rem; color: #64748b;">
                    Nenhum documento encontrado.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($documentos as $doc): ?>
            <tr>
                <td><?= htmlspecialchars($doc['tipo_documento']) ?></td>
                <td><?= htmlspecialchars($doc['descricao'] ?? '-') ?></td>
                <td><?= date('d/m/Y H:i', strtotime($doc['criado_em'])) ?></td>
                <td>
                    <?php if ($doc['arquivo_url']): ?>
                        <a href="<?= htmlspecialchars($doc['arquivo_url']) ?>" target="_blank" class="btn-action btn-download">📎 Baixar</a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza?');">
                        <input type="hidden" name="acao" value="excluir_documento">
                        <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                        <button type="submit" class="btn-action btn-delete">🗑️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Contabilidade - Colaboradores');
echo $conteudo;
endSidebar();
?>
