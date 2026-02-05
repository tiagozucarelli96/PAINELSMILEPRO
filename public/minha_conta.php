<?php
/**
 * Minha conta — Página do usuário logado (acessada ao clicar no nome na sidebar).
 * Primeiro card: Holerite individual (lista dos holerites do usuário).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/setup_holerites_individual.php';

if (empty($_SESSION['logado'])) {
    header('Location: index.php?page=login');
    exit;
}

$usuario_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
if ($usuario_id <= 0) {
    header('Location: index.php?page=dashboard');
    exit;
}

$holerites = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, mes_competencia, arquivo_nome, criado_em
        FROM contabilidade_holerites_individual
        WHERE usuario_id = :uid
        ORDER BY mes_competencia DESC, criado_em DESC
    ");
    $stmt->execute([':uid' => $usuario_id]);
    $holerites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Minha conta - holerites: " . $e->getMessage());
}

$nome_user = $_SESSION['nome'] ?? 'Usuário';
includeSidebar('Minha conta');
?>

<style>
    .minha-conta {
        padding: 2rem;
        max-width: 1200px;
        margin: 0 auto;
        background: #f8fafc;
    }
    .page-title {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e3a8a;
        margin: 0 0 0.25rem 0;
    }
    .page-subtitle {
        color: #64748b;
        font-size: 0.95rem;
        margin-bottom: 2rem;
    }
    .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 1.5rem;
    }
    .card-box {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e5e7eb;
    }
    .card-box h2 {
        font-size: 1.125rem;
        font-weight: 600;
        color: #1e3a8a;
        margin: 0 0 1rem 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .holerites-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .holerites-list li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem 0;
        border-bottom: 1px solid #f1f5f9;
        gap: 0.75rem;
    }
    .holerites-list li:last-child {
        border-bottom: none;
    }
    .holerite-mes {
        font-weight: 500;
        color: #1e293b;
    }
    .holerite-link {
        background: #1e40af;
        color: white;
        padding: 0.375rem 0.75rem;
        border-radius: 6px;
        text-decoration: none;
        font-size: 0.875rem;
        white-space: nowrap;
    }
    .holerite-link:hover {
        background: #1e3a8a;
    }
    .empty-holerites {
        color: #64748b;
        font-size: 0.9rem;
        padding: 1rem 0;
    }
</style>

<div class="minha-conta">
    <h1 class="page-title">Minha conta</h1>
    <p class="page-subtitle">Olá, <?= htmlspecialchars($nome_user) ?>. Aqui você acessa seus documentos e informações.</p>

    <div class="cards-grid">
        <div class="card-box">
            <h2>📄 Holerite</h2>
            <?php if (empty($holerites)): ?>
                <p class="empty-holerites">Nenhum holerite disponível no momento.</p>
            <?php else: ?>
                <ul class="holerites-list">
                    <?php foreach ($holerites as $h): ?>
                        <li>
                            <span class="holerite-mes"><?= htmlspecialchars($h['mes_competencia']) ?></span>
                            <a href="contabilidade_download.php?tipo=holerite_individual&id=<?= (int)$h['id'] ?>" class="holerite-link" target="_blank">Ver / Baixar</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endSidebar(); ?>
