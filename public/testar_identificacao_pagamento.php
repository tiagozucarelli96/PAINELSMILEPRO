<?php
/**
 * Pagamento Degustação
 * Baixa manual e revisão de status de pagamento de inscrições.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/permissoes_boot.php';

if (empty($_SESSION['logado']) || (int)($_SESSION['logado'] ?? 0) !== 1) {
    header('Location: index.php?page=login');
    exit;
}

$is_superadmin = !empty($_SESSION['perm_superadmin']);
$perm_administrativo = !empty($_SESSION['perm_administrativo']);
if (!$is_superadmin && !$perm_administrativo) {
    header('Location: index.php?page=dashboard&error=' . urlencode('Você não tem permissão para acessar Pagamento Degustação.'));
    exit;
}

$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim((string)$_POST['action']);
    $inscricao_id = (int)($_POST['inscricao_id'] ?? 0);

    if ($inscricao_id <= 0) {
        $mensagem = 'Inscrição inválida.';
        $tipo_mensagem = 'error';
    } else {
        try {
            if (in_array($action, ['registrar_pagamento_manual', 'simular_pagamento'], true)) {
                $stmt = $pdo->prepare('SELECT id, nome FROM comercial_inscricoes WHERE id = :id');
                $stmt->execute([':id' => $inscricao_id]);
                $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$inscricao) {
                    throw new Exception('Inscrição não encontrada.');
                }

                $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'pago' WHERE id = :id");
                $stmt->execute([':id' => $inscricao_id]);

                $mensagem = "Pagamento confirmado manualmente para a inscrição #{$inscricao_id} ({$inscricao['nome']}).";
                $tipo_mensagem = 'success';
            }

            if (in_array($action, ['reabrir_pagamento', 'reverter_pagamento'], true)) {
                $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'aguardando' WHERE id = :id");
                $stmt->execute([':id' => $inscricao_id]);

                $mensagem = "Cobrança reaberta (status aguardando) para a inscrição #{$inscricao_id}.";
                $tipo_mensagem = 'success';
            }
        } catch (Exception $e) {
            $mensagem = 'Erro: ' . $e->getMessage();
            $tipo_mensagem = 'error';
        }
    }
}

$check_valor_cols = $pdo->query("\n    SELECT column_name\n    FROM information_schema.columns\n    WHERE table_name = 'comercial_inscricoes'\n      AND column_name IN ('valor_total', 'valor_pago')\n");
$valor_columns = $check_valor_cols ? $check_valor_cols->fetchAll(PDO::FETCH_COLUMN) : [];
$has_valor_total = in_array('valor_total', $valor_columns, true);
$has_valor_pago = in_array('valor_pago', $valor_columns, true);

if ($has_valor_total && $has_valor_pago) {
    $valor_expr = 'COALESCE(i.valor_total, i.valor_pago, 0) as valor';
} elseif ($has_valor_total) {
    $valor_expr = 'COALESCE(i.valor_total, 0) as valor';
} elseif ($has_valor_pago) {
    $valor_expr = 'COALESCE(i.valor_pago, 0) as valor';
} else {
    $valor_expr = '0 as valor';
}

$sql = "
    SELECT
        i.id,
        i.nome,
        i.email,
        i.pagamento_status,
        i.asaas_qr_code_id,
        {$valor_expr},
        d.nome as degustacao_nome,
        d.token_publico
    FROM comercial_inscricoes i
    LEFT JOIN comercial_degustacoes d ON i.degustacao_id = d.id
    ORDER BY i.id DESC
    LIMIT 50
";
$stmt = $pdo->query($sql);
$inscricoes = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

ob_start();
?>
<style>
    .pd-container { max-width: 1320px; margin: 0 auto; padding: 1.5rem; }
    .pd-title { margin: 0 0 .35rem 0; font-size: 2rem; color: #1e3a8a; font-weight: 800; }
    .pd-subtitle { margin: 0 0 1.2rem 0; color: #64748b; }
    .pd-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; box-shadow: 0 3px 10px rgba(15, 23, 42, .06); padding: 1.1rem; margin-bottom: 1rem; }
    .pd-card h2 { margin: 0 0 .85rem 0; color: #0f172a; font-size: 1.08rem; }
    .pd-info { background: #eff6ff; border-color: #bfdbfe; }
    .pd-info ol { margin: 0; padding-left: 1.1rem; color: #1e3a8a; }
    .pd-info li { margin-bottom: .35rem; }
    .pd-alert { padding: .85rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-weight: 600; border: 1px solid transparent; }
    .pd-alert.success { background: #dcfce7; color: #166534; border-color: #86efac; }
    .pd-alert.error { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
    .pd-table-wrap { overflow: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
    .pd-table { width: 100%; border-collapse: collapse; min-width: 1080px; }
    .pd-table th, .pd-table td { padding: .68rem .65rem; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: middle; }
    .pd-table th { background: #f8fafc; color: #0f172a; font-size: .84rem; text-transform: uppercase; letter-spacing: .03em; }
    .pd-small { color: #64748b; font-size: .8rem; }
    .pd-badge { border-radius: 999px; padding: .22rem .52rem; font-size: .75rem; font-weight: 700; display: inline-block; }
    .pd-badge-success { background: #dcfce7; color: #166534; }
    .pd-badge-warning { background: #fef3c7; color: #92400e; }
    .pd-btn {
        border: 0;
        border-radius: 9px;
        padding: .55rem .82rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        font-size: .82rem;
    }
    .pd-btn.primary { background: #1d4ed8; color: #fff; }
    .pd-btn.primary:hover { background: #1e40af; }
    .pd-btn.success { background: #059669; color: #fff; }
    .pd-btn.success:hover { background: #047857; }
    .pd-btn.secondary { background: #e2e8f0; color: #0f172a; }
    .pd-btn.secondary:hover { background: #cbd5e1; }
    .pd-actions { margin-top: .9rem; display: flex; gap: .6rem; flex-wrap: wrap; }

    @media (max-width: 960px) {
        .pd-container { padding: 1rem; }
        .pd-title { font-size: 1.5rem; }
    }
</style>

<div class="pd-container">
    <h1 class="pd-title">Pagamento Degustação</h1>
    <p class="pd-subtitle">Baixa manual e reabertura de cobrança de inscrições da degustação.</p>

    <?php if ($mensagem !== ''): ?>
        <div class="pd-alert <?= htmlspecialchars($tipo_mensagem) ?>"><?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>

    <div class="pd-card pd-info">
        <h2>Como usar</h2>
        <ol>
            <li>Localize a inscrição na lista abaixo.</li>
            <li>Use <strong>Confirmar Pagamento</strong> quando o cliente pagou fora do link (ex.: dinheiro).</li>
            <li>Use <strong>Reabrir Cobrança</strong> para voltar o status para aguardando.</li>
        </ol>
    </div>

    <div class="pd-card">
        <h2>Inscrições recentes</h2>

        <?php if (empty($inscricoes)): ?>
            <p class="pd-small">Nenhuma inscrição encontrada.</p>
        <?php else: ?>
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Degustação</th>
                        <th>Status</th>
                        <th>Valor</th>
                        <th>QR Code</th>
                        <th>Ações</th>
                        <th>Página Pública</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($inscricoes as $insc): ?>
                        <?php
                        $status_raw = (string)($insc['pagamento_status'] ?? '');
                        $status_is_pago = ($status_raw === 'pago');
                        $status_text = match($status_raw) {
                            'pago' => 'Pago',
                            'aguardando' => 'Aguardando',
                            'expirado' => 'Expirado',
                            'cancelado' => 'Cancelado',
                            default => 'N/A'
                        };
                        ?>
                        <tr>
                            <td><strong>#<?= (int)$insc['id'] ?></strong></td>
                            <td><?= htmlspecialchars((string)$insc['nome']) ?></td>
                            <td><?= htmlspecialchars((string)$insc['email']) ?></td>
                            <td><?= !empty($insc['degustacao_nome']) ? htmlspecialchars((string)$insc['degustacao_nome']) : '<span class="pd-small">N/A</span>' ?></td>
                            <td>
                                <span class="pd-badge <?= $status_is_pago ? 'pd-badge-success' : 'pd-badge-warning' ?>">
                                    <?= htmlspecialchars($status_text) ?>
                                </span>
                            </td>
                            <td>R$ <?= number_format((float)($insc['valor'] ?? 0), 2, ',', '.') ?></td>
                            <td>
                                <?php if (!empty($insc['asaas_qr_code_id'])): ?>
                                    <span class="pd-small" style="font-family: monospace;">
                                        <?= htmlspecialchars(substr((string)$insc['asaas_qr_code_id'], 0, 22)) ?>...
                                    </span>
                                <?php else: ?>
                                    <span class="pd-small">Sem QR</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$status_is_pago): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="registrar_pagamento_manual">
                                        <input type="hidden" name="inscricao_id" value="<?= (int)$insc['id'] ?>">
                                        <button
                                            type="submit"
                                            class="pd-btn success"
                                            onclick="return confirm('Confirmar pagamento manual para esta inscrição?');"
                                        >
                                            Confirmar Pagamento
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="reabrir_pagamento">
                                        <input type="hidden" name="inscricao_id" value="<?= (int)$insc['id'] ?>">
                                        <button
                                            type="submit"
                                            class="pd-btn secondary"
                                            onclick="return confirm('Reabrir cobrança e voltar para aguardando?');"
                                        >
                                            Reabrir Cobrança
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($insc['token_publico'])): ?>
                                    <a
                                        href="index.php?page=comercial_degust_public&t=<?= urlencode((string)$insc['token_publico']) ?>&qr_code=1&inscricao_id=<?= (int)$insc['id'] ?>"
                                        target="_blank"
                                        class="pd-btn primary"
                                    >
                                        Abrir
                                    </a>
                                <?php else: ?>
                                    <span class="pd-small">Sem token</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="pd-actions">
            <a href="index.php?page=administrativo" class="pd-btn secondary">Voltar ao Administrativo</a>
            <a href="index.php?page=comercial" class="pd-btn primary">Ir para Comercial</a>
        </div>
    </div>
</div>
<?php
$conteudo = ob_get_clean();

includeSidebar('Pagamento Degustação');
echo $conteudo;
endSidebar();
