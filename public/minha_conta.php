<?php
/**
 * Minha conta — área do usuário logado.
 * Mostra documentos disponibilizados para o usuário e holerites legados.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/setup_holerites_individual.php';
require_once __DIR__ . '/setup_gestao_documentos.php';

if (empty($_SESSION['logado'])) {
    header('Location: index.php?page=login');
    exit;
}

$usuario_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
if ($usuario_id <= 0) {
    header('Location: index.php?page=dashboard');
    exit;
}

setupGestaoDocumentos($pdo);

$documentos = [];
try {
    $stmt = $pdo->prepare(
        "SELECT id, tipo_documento, titulo, competencia, arquivo_nome, criado_em,
                exigir_assinatura, status_assinatura, clicksign_sign_url
         FROM administrativo_documentos_colaboradores
         WHERE usuario_id = :uid
           AND exibir_minha_conta = TRUE
         ORDER BY criado_em DESC"
    );
    $stmt->execute([':uid' => $usuario_id]);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Minha conta - documentos: ' . $e->getMessage());
}

$holerites_legado = [];
try {
    $stmt = $pdo->prepare(
        "SELECT id, mes_competencia, arquivo_nome, criado_em
         FROM contabilidade_holerites_individual
         WHERE usuario_id = :uid
         ORDER BY mes_competencia DESC, criado_em DESC"
    );
    $stmt->execute([':uid' => $usuario_id]);
    $holerites_legado = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Minha conta - holerites legado: ' . $e->getMessage());
}

function minhaContaTipoLabel(string $tipo): string
{
    $map = [
        'holerite' => 'Holerite',
        'folha_ponto' => 'Folha de ponto',
        'outro' => 'Documento',
    ];

    return $map[$tipo] ?? ucfirst($tipo);
}

function minhaContaStatusAssinaturaLabel(string $status): string
{
    $map = [
        'nao_solicitada' => 'Sem assinatura',
        'pendente_envio' => 'Pendente envio',
        'enviado' => 'Enviado para assinar',
        'assinado' => 'Assinado',
        'cancelado' => 'Cancelado',
        'recusado' => 'Recusado',
        'erro' => 'Erro no envio',
    ];

    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function minhaContaStatusAssinaturaClass(string $status): string
{
    $map = [
        'nao_solicitada' => 'status-neutral',
        'pendente_envio' => 'status-warning',
        'enviado' => 'status-info',
        'assinado' => 'status-ok',
        'cancelado' => 'status-neutral',
        'recusado' => 'status-error',
        'erro' => 'status-error',
    ];

    return $map[$status] ?? 'status-neutral';
}

$nome_user = $_SESSION['nome'] ?? 'Usuário';
includeSidebar('Minha conta');
?>

<style>
    .minha-conta { padding: 2rem; max-width: 1280px; margin: 0 auto; background: #f8fafc; }
    .page-title { font-size: 1.9rem; font-weight: 700; color: #1e3a8a; margin: 0 0 .3rem 0; }
    .page-subtitle { color: #64748b; font-size: 0.95rem; margin-bottom: 1.5rem; }
    .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 1rem; }
    .card-box { background: white; border-radius: 12px; padding: 1.15rem; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #e5e7eb; }
    .card-box h2 { font-size: 1.05rem; font-weight: 700; color: #1e3a8a; margin: 0 0 .95rem 0; display: flex; align-items: center; gap: .45rem; }
    .docs-list { list-style: none; padding: 0; margin: 0; }
    .docs-list li { display: flex; flex-direction: column; gap: .45rem; padding: .8rem 0; border-bottom: 1px solid #eef2f7; }
    .docs-list li:last-child { border-bottom: none; }
    .doc-top { display: flex; justify-content: space-between; gap: .8rem; align-items: center; }
    .doc-title { font-weight: 700; color: #1f2937; }
    .doc-meta { color: #64748b; font-size: .84rem; }
    .doc-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
    .doc-link {
        background: #1e40af; color: white; padding: 0.38rem 0.72rem; border-radius: 6px;
        text-decoration: none; font-size: 0.84rem; white-space: nowrap; font-weight: 700;
    }
    .doc-link:hover { background: #1e3a8a; }
    .doc-link.secondary { background: #e2e8f0; color: #0f172a; }
    .doc-link.secondary:hover { background: #cbd5e1; }
    .status-badge { border-radius: 999px; padding: .22rem .52rem; font-size: .74rem; font-weight: 700; }
    .status-neutral { background: #e2e8f0; color: #334155; }
    .status-warning { background: #fef3c7; color: #92400e; }
    .status-info { background: #dbeafe; color: #1d4ed8; }
    .status-ok { background: #dcfce7; color: #166534; }
    .status-error { background: #fee2e2; color: #991b1b; }
    .empty { color: #64748b; font-size: 0.9rem; padding: .7rem 0; }
</style>

<div class="minha-conta">
    <h1 class="page-title">Minha conta</h1>
    <p class="page-subtitle">Olá, <?= htmlspecialchars($nome_user) ?>. Aqui você acessa seus documentos disponibilizados pelo administrativo.</p>

    <div class="cards-grid">
        <div class="card-box">
            <h2>🗂️ Documentos</h2>
            <?php if (empty($documentos)): ?>
                <p class="empty">Nenhum documento disponível no momento.</p>
            <?php else: ?>
                <ul class="docs-list">
                    <?php foreach ($documentos as $doc): ?>
                        <li>
                            <div class="doc-top">
                                <div>
                                    <div class="doc-title"><?= htmlspecialchars($doc['titulo'] ?? '') ?></div>
                                    <div class="doc-meta">
                                        <?= htmlspecialchars(minhaContaTipoLabel((string)($doc['tipo_documento'] ?? 'outro'))) ?>
                                        <?= !empty($doc['competencia']) ? ' • ' . htmlspecialchars((string)$doc['competencia']) : '' ?>
                                        <?= !empty($doc['arquivo_nome']) ? ' • ' . htmlspecialchars((string)$doc['arquivo_nome']) : '' ?>
                                    </div>
                                </div>
                                <?php if (!empty($doc['exigir_assinatura'])): ?>
                                    <span class="status-badge <?= minhaContaStatusAssinaturaClass((string)($doc['status_assinatura'] ?? 'nao_solicitada')) ?>">
                                        <?= htmlspecialchars(minhaContaStatusAssinaturaLabel((string)($doc['status_assinatura'] ?? 'nao_solicitada'))) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="doc-actions">
                                <a href="contabilidade_download.php?tipo=gestao_documento&id=<?= (int)$doc['id'] ?>" class="doc-link" target="_blank">Ver / Baixar</a>

                                <?php if (!empty($doc['exigir_assinatura']) && !empty($doc['clicksign_sign_url'])): ?>
                                    <a href="<?= htmlspecialchars((string)$doc['clicksign_sign_url']) ?>" class="doc-link secondary" target="_blank">Assinar</a>
                                <?php elseif (!empty($doc['exigir_assinatura']) && ($doc['status_assinatura'] ?? '') === 'enviado'): ?>
                                    <span class="doc-meta">Assinatura enviada por e-mail via Clicksign.</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card-box">
            <h2>📄 Holerites (legado)</h2>
            <?php if (empty($holerites_legado)): ?>
                <p class="empty">Nenhum holerite legado disponível.</p>
            <?php else: ?>
                <ul class="docs-list">
                    <?php foreach ($holerites_legado as $h): ?>
                        <li>
                            <div class="doc-top">
                                <div class="doc-title"><?= htmlspecialchars((string)$h['mes_competencia']) ?></div>
                                <div class="doc-meta"><?= !empty($h['arquivo_nome']) ? htmlspecialchars((string)$h['arquivo_nome']) : '' ?></div>
                            </div>
                            <div class="doc-actions">
                                <a href="contabilidade_download.php?tipo=holerite_individual&id=<?= (int)$h['id'] ?>" class="doc-link" target="_blank">Ver / Baixar</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endSidebar(); ?>
