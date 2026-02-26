<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/portao_helper.php';

$pdo = $GLOBALS['pdo'] ?? ($pdo ?? null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    includeSidebar('Portao');
    echo '<div style="padding:1.5rem;"><div class="alert-error">Conexao com banco indisponivel.</div></div>';
    endSidebar();
    exit;
}

if (empty($_SESSION['logado'])) {
    header('Location: index.php?page=login');
    exit;
}

portao_ensure_schema($pdo);

$canAccess = !empty($_SESSION['perm_portao']) || !empty($_SESSION['perm_superadmin']);

if (!$canAccess) {
    includeSidebar('Portao');
    echo '<div style="padding:1.5rem;"><div class="alert-error">Acesso negado ao modulo Portao.</div></div>';
    endSidebar();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = strtolower(trim((string)($_POST['acao'] ?? '')));
    $ctx = portao_user_context();
    $result = ['ok' => false, 'message' => 'Acao invalida.'];

    if (in_array($acao, ['abrir', 'fechar'], true)) {
        $result = portao_execute_action($pdo, $acao, $ctx, 'manual');
    } elseif ($acao === 'auto_close_now' && !empty($_SESSION['perm_superadmin'])) {
        $result = portao_process_auto_close($pdo, true);
        if (!isset($result['message'])) {
            if (!empty($result['executed']) && !empty($result['ok'])) {
                $result['message'] = 'Auto-fechamento executado.';
            } elseif (!empty($result['executed'])) {
                $result['message'] = 'Auto-fechamento tentou executar, mas houve falha.';
            } else {
                $result['message'] = 'Nao havia auto-fechamento pendente.';
            }
        }
    }

    $_SESSION['portao_flash'] = [
        'ok' => !empty($result['ok']),
        'message' => (string)($result['message'] ?? 'Operacao concluida.'),
    ];
    header('Location: index.php?page=portao');
    exit;
}

// Best effort: quando alguem acessa a tela, tenta processar auto-close pendente.
$autoCloseProbe = portao_process_auto_close($pdo, false);
$state = portao_get_estado($pdo);
$logs = portao_list_logs($pdo, 120);
$provider = portao_provider_mode();
$autoMinutes = portao_auto_close_minutes();
$flash = $_SESSION['portao_flash'] ?? null;
unset($_SESSION['portao_flash']);

$badgeColor = '#64748b';
if (($state['estado'] ?? '') === 'aberto') {
    $badgeColor = '#059669';
} elseif (($state['estado'] ?? '') === 'fechado') {
    $badgeColor = '#dc2626';
}

$estadoLabel = ucfirst((string)($state['estado'] ?? 'desconhecido'));

ob_start();
?>
<style>
.portao-wrap {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.5rem;
}
.portao-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}
.portao-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1rem;
}
.portao-title {
    margin: 0 0 .5rem;
    color: #1e3a8a;
    font-size: 1.5rem;
}
.status-badge {
    display: inline-block;
    padding: .35rem .75rem;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 700;
    color: #fff;
}
.portao-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    margin-top: .75rem;
}
.btn-portao {
    border: none;
    border-radius: 8px;
    padding: .65rem 1rem;
    font-weight: 700;
    cursor: pointer;
}
.btn-open { background: #059669; color: #fff; }
.btn-close { background: #dc2626; color: #fff; }
.btn-aux { background: #e2e8f0; color: #0f172a; }
.table-wrap {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
}
.portao-table {
    width: 100%;
    border-collapse: collapse;
}
.portao-table th, .portao-table td {
    padding: .65rem .75rem;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    font-size: .9rem;
    vertical-align: top;
}
.portao-table thead th {
    background: #f8fafc;
    font-size: .8rem;
    text-transform: uppercase;
    color: #475569;
}
.portao-muted {
    color: #64748b;
    font-size: .85rem;
}
</style>

<div class="portao-wrap">
    <h1 class="portao-title">Controle de Portao</h1>

    <?php if (is_array($flash) && !empty($flash['message'])): ?>
        <div class="<?= !empty($flash['ok']) ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:1rem;">
            <?= h((string)$flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($autoCloseProbe['executed']) && empty($autoCloseProbe['ok'])): ?>
        <div class="alert-error" style="margin-bottom:1rem;">
            Falha ao processar auto-fechamento pendente.
        </div>
    <?php endif; ?>

    <div class="portao-grid">
        <div class="portao-card">
            <div class="portao-muted">Estado atual</div>
            <div style="margin-top:.35rem;">
                <span class="status-badge" style="background:<?= h($badgeColor) ?>;">
                    <?= h($estadoLabel) ?>
                </span>
            </div>
            <div class="portao-muted" style="margin-top:.75rem;">
                Ultima acao: <strong><?= h((string)($state['ultima_acao'] ?? '-')) ?></strong><br>
                Ultimo resultado: <strong><?= h((string)($state['ultima_resultado'] ?? '-')) ?></strong><br>
                Ultima atualizacao: <strong><?= h((string)brDate((string)($state['ultima_acao_em'] ?? ''))) ?></strong>
            </div>
        </div>

        <div class="portao-card">
            <div class="portao-muted">Integracao</div>
            <div style="margin-top:.35rem; font-weight:700; color:#0f172a;">
                Provider: <?= h($provider) ?>
            </div>
            <div class="portao-muted" style="margin-top:.75rem;">
                Auto-fechamento: <strong><?= (int)$autoMinutes ?> min</strong><br>
                Proximo fechamento: <strong><?= h(!empty($state['auto_close_at']) ? brDate((string)$state['auto_close_at']) : '-') ?></strong>
            </div>
            <?php if (in_array($provider, ['simulado', 'mock', 'teste'], true)): ?>
                <div class="portao-muted" style="margin-top:.6rem;">
                    Modo simulado ativo. Nenhum dispositivo fisico sera acionado.
                </div>
            <?php endif; ?>
        </div>

        <div class="portao-card">
            <div class="portao-muted">Acoes</div>
            <form method="post" class="portao-actions">
                <input type="hidden" name="acao" value="abrir">
                <button type="submit" class="btn-portao btn-open">Abrir</button>
            </form>
            <form method="post" class="portao-actions">
                <input type="hidden" name="acao" value="fechar">
                <button type="submit" class="btn-portao btn-close">Fechar</button>
            </form>
            <?php if (!empty($_SESSION['perm_superadmin'])): ?>
                <form method="post" class="portao-actions">
                    <input type="hidden" name="acao" value="auto_close_now">
                    <button type="submit" class="btn-portao btn-aux">Rodar auto-close agora</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-wrap">
        <table class="portao-table">
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Acao</th>
                    <th>Origem</th>
                    <th>Resultado</th>
                    <th>Usuario</th>
                    <th>Detalhe</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="portao-muted">Nenhum log registrado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= h(brDate((string)($log['criado_em'] ?? ''))) ?></td>
                            <td><strong><?= h((string)($log['acao'] ?? '-')) ?></strong></td>
                            <td><?= h((string)($log['origem'] ?? '-')) ?></td>
                            <td><?= h((string)($log['resultado'] ?? '-')) ?></td>
                            <td>
                                <?= h((string)($log['usuario_nome'] ?? 'sistema')) ?>
                                <?php if (!empty($log['usuario_id'])): ?>
                                    <span class="portao-muted">(ID <?= (int)$log['usuario_id'] ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= h((string)($log['detalhe'] ?? '-')) ?>
                                <?php if (!empty($log['ip'])): ?>
                                    <div class="portao-muted">IP: <?= h((string)$log['ip']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
includeSidebar('Portao');
echo $content;
endSidebar();

