<?php
/**
 * eventos_rascunhos.php
 * Listar reuniões em rascunho e permitir abrir ou excluir.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$success = '';
$error = '';

// Excluir rascunho
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        if (eventos_reuniao_excluir($pdo, $id)) {
            $success = 'Rascunho excluído com sucesso.';
        } else {
            $error = 'Não foi possível excluir o rascunho.';
        }
    }
}

$rascunhos = eventos_reuniao_listar($pdo, ['status' => 'rascunho']);

function nome_evento_reuniao(array $reuniao): string {
    $snapshot = $reuniao['me_event_snapshot'] ?? null;
    if (is_string($snapshot)) {
        $snapshot = json_decode($snapshot, true);
    }
    if (!is_array($snapshot)) {
        return 'Evento #' . ($reuniao['me_event_id'] ?? $reuniao['id']);
    }
    if (!empty($snapshot['nome'])) {
        return $snapshot['nome'];
    }
    if (!empty($snapshot['cliente']['nome'])) {
        return $snapshot['cliente']['nome'];
    }
    return 'Evento #' . ($reuniao['me_event_id'] ?? $reuniao['id']);
}

includeSidebar('Rascunhos - Reuniões');
?>

<style>
    .rascunhos-container {
        padding: 2rem;
        max-width: 900px;
        margin: 0 auto;
        background: #f8fafc;
    }
    .page-title { font-size: 1.5rem; font-weight: 700; color: #1e3a8a; margin: 0 0 0.5rem 0; }
    .page-subtitle { color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem; }
    .alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-error { background: #fee2e2; color: #991b1b; }
    .lista-rascunhos { list-style: none; padding: 0; margin: 0; }
    .rascunho-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.25rem;
        background: white;
        border-radius: 12px;
        margin-bottom: 0.75rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        border: 1px solid #e5e7eb;
    }
    .rascunho-item:hover { border-color: #1e40af; }
    .rascunho-info { flex: 1; min-width: 0; }
    .rascunho-nome { font-weight: 600; color: #1e293b; margin-bottom: 0.25rem; }
    .rascunho-meta { font-size: 0.8rem; color: #64748b; }
    .rascunho-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }
    .btn { padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.875rem; font-weight: 500; text-decoration: none; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem; }
    .btn-primary { background: #1e40af; color: white; }
    .btn-primary:hover { background: #1e3a8a; }
    .btn-danger { background: #dc2626; color: white; }
    .btn-danger:hover { background: #b91c1c; }
    .empty-state { text-align: center; padding: 3rem; color: #64748b; background: white; border-radius: 12px; border: 1px solid #e5e7eb; }
</style>

<div class="rascunhos-container">
    <h1 class="page-title">Rascunhos da Reunião</h1>
    <p class="page-subtitle">Reuniões em rascunho. Abra para editar ou exclua se não for mais necessária.</p>

    <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($rascunhos)): ?>
    <div class="empty-state">
        <p style="font-size: 2rem; margin-bottom: 0.5rem;">📝</p>
        <p><strong>Nenhum rascunho</strong></p>
        <p>Crie uma reunião em <a href="index.php?page=eventos_reuniao_final" style="color: #1e40af;">Reunião Final</a> para ela aparecer aqui enquanto estiver em rascunho.</p>
    </div>
    <?php else: ?>
    <ul class="lista-rascunhos">
        <?php foreach ($rascunhos as $r): ?>
        <li class="rascunho-item">
            <div class="rascunho-info">
                <div class="rascunho-nome"><?= htmlspecialchars(nome_evento_reuniao($r)) ?></div>
                <div class="rascunho-meta">
                    Criado em <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?>
                    <?php if (!empty($r['updated_at'])): ?>
                    · Atualizado em <?= date('d/m/Y H:i', strtotime($r['updated_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rascunho-actions">
                <a href="index.php?page=eventos_reuniao_final&id=<?= (int)$r['id'] ?>" class="btn btn-primary">Abrir</a>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Excluir este rascunho? Não é possível desfazer.');">
                    <input type="hidden" name="action" value="excluir">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn btn-danger">Excluir</button>
                </form>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <p style="margin-top: 1.5rem;"><a href="index.php?page=eventos" style="color: #1e40af;">← Voltar para Eventos</a></p>
</div>

<?php endSidebar(); ?>
