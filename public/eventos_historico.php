<?php
/**
 * eventos_historico.php
 * Linha do tempo de alterações do evento.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/eventos_historico_helper.php';

if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_agenda_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function eventos_historico_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eventos_historico_fmt_data(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function eventos_historico_decode(?string $json): array
{
    $decoded = json_decode((string)$json, true);
    return is_array($decoded) ? $decoded : [];
}

function eventos_historico_resumo_dados(array $dados): string
{
    if (empty($dados)) {
        return '';
    }

    $parts = [];
    foreach ($dados as $key => $value) {
        if (is_array($value) || is_object($value)) {
            continue;
        }
        $label = ucfirst(str_replace('_', ' ', (string)$key));
        $parts[] = $label . ': ' . (is_bool($value) ? ($value ? 'sim' : 'não') : (string)$value);
        if (count($parts) >= 6) {
            break;
        }
    }

    return implode(' • ', $parts);
}

$meetingId = (int)($_GET['meeting_id'] ?? $_GET['id'] ?? 0);
$meEventId = (int)($_GET['me_event_id'] ?? 0);

if ($meetingId <= 0 && $meEventId > 0) {
    $meetingId = eventos_historico_meeting_por_me_event($pdo, $meEventId);
}

$reuniao = $meetingId > 0 ? eventos_reuniao_get($pdo, $meetingId) : null;
$snapshot = [];
if ($reuniao) {
    $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
}

$historico = $reuniao ? eventos_historico_listar($pdo, $meetingId, 160) : [];

$nomeEvento = trim((string)($snapshot['nome'] ?? 'Evento'));
$clienteNome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente não informado'));
$dataEvento = trim((string)($snapshot['data'] ?? ''));
$localEvento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$dataEventoFmt = $dataEvento !== '' && strtotime($dataEvento) ? date('d/m/Y', strtotime($dataEvento)) : ($dataEvento !== '' ? $dataEvento : '-');

includeSidebar('Histórico do evento');
?>

<style>
.event-history-page {
    max-width: 1180px;
    margin: 0 auto;
    padding: 1.5rem;
    background: #f8fafc;
}
.history-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}
.history-title {
    margin: 0;
    color: #1e3a8a;
    font-size: 1.65rem;
    font-weight: 800;
}
.history-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.94rem;
}
.history-actions {
    display: flex;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.history-btn {
    border: 1px solid #dbe3ef;
    border-radius: 999px;
    padding: 0.72rem 1rem;
    background: #fff;
    color: #1e293b;
    text-decoration: none;
    font-weight: 700;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.event-summary-card,
.empty-history,
.history-item {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
.event-summary-card {
    padding: 1rem;
    margin-bottom: 1rem;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.8rem;
}
.summary-label {
    color: #64748b;
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
}
.summary-value {
    margin-top: 0.2rem;
    color: #1e293b;
    font-weight: 700;
}
.history-list {
    display: grid;
    gap: 0.75rem;
}
.history-item {
    padding: 1rem;
    border-left: 4px solid #2563eb;
}
.history-item-top {
    display: flex;
    justify-content: space-between;
    gap: 0.8rem;
    flex-wrap: wrap;
}
.history-item-title {
    margin: 0;
    color: #1e293b;
    font-size: 1rem;
}
.history-item-date {
    color: #64748b;
    font-size: 0.86rem;
    font-weight: 700;
}
.history-meta {
    margin-top: 0.25rem;
    color: #475569;
    font-size: 0.86rem;
}
.history-description {
    margin-top: 0.75rem;
    color: #334155;
    line-height: 1.5;
}
.history-diff {
    margin-top: 0.75rem;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 0.75rem;
}
.history-diff-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.7rem;
    color: #475569;
    font-size: 0.84rem;
}
.empty-history {
    padding: 1.2rem;
    color: #64748b;
}
</style>

<div class="event-history-page">
    <div class="history-header">
        <div>
            <h1 class="history-title">Histórico do evento</h1>
            <p class="history-subtitle">Registro de alterações feitas no evento, com data e usuário responsável.</p>
        </div>
        <div class="history-actions">
            <?php if ($reuniao): ?>
                <a class="history-btn" href="index.php?page=eventos_organizacao&id=<?= (int)$meetingId ?>">← Organização</a>
            <?php elseif ($meEventId > 0): ?>
                <a class="history-btn" href="index.php?page=eventos_organizacao&me_event_id=<?= (int)$meEventId ?>">Organizar evento</a>
            <?php endif; ?>
            <a class="history-btn" href="index.php?page=agenda_eventos">Agenda Geral</a>
        </div>
    </div>

    <?php if (!$reuniao): ?>
        <div class="empty-history">
            Este evento ainda não possui organização vinculada no painel. O histórico começa a ser registrado quando o evento é organizado e alterado por aqui.
        </div>
    <?php else: ?>
        <div class="event-summary-card">
            <div>
                <div class="summary-label">Evento</div>
                <div class="summary-value"><?= eventos_historico_e($nomeEvento) ?></div>
            </div>
            <div>
                <div class="summary-label">Cliente</div>
                <div class="summary-value"><?= eventos_historico_e($clienteNome) ?></div>
            </div>
            <div>
                <div class="summary-label">Data</div>
                <div class="summary-value"><?= eventos_historico_e($dataEventoFmt) ?></div>
            </div>
            <div>
                <div class="summary-label">Local</div>
                <div class="summary-value"><?= eventos_historico_e($localEvento) ?></div>
            </div>
        </div>

        <?php if (empty($historico)): ?>
            <div class="empty-history">Nenhuma alteração registrada ainda para este evento.</div>
        <?php else: ?>
            <div class="history-list">
                <?php foreach ($historico as $item): ?>
                    <?php
                    $antes = eventos_historico_decode($item['dados_antes'] ?? null);
                    $depois = eventos_historico_decode($item['dados_depois'] ?? null);
                    $antesResumo = eventos_historico_resumo_dados($antes);
                    $depoisResumo = eventos_historico_resumo_dados($depois);
                    $usuario = trim((string)($item['usuario_nome'] ?? $item['usuario_nome_atual'] ?? $item['usuario_email'] ?? 'Sistema'));
                    ?>
                    <article class="history-item">
                        <div class="history-item-top">
                            <h2 class="history-item-title"><?= eventos_historico_e((string)$item['titulo']) ?></h2>
                            <div class="history-item-date"><?= eventos_historico_e(eventos_historico_fmt_data($item['criado_em'] ?? '')) ?></div>
                        </div>
                        <div class="history-meta">
                            <?= eventos_historico_e($usuario !== '' ? $usuario : 'Sistema') ?>
                            · <?= eventos_historico_e((string)($item['acao'] ?? 'alteracao')) ?>
                        </div>
                        <?php if (trim((string)($item['descricao'] ?? '')) !== ''): ?>
                            <div class="history-description"><?= eventos_historico_e((string)$item['descricao']) ?></div>
                        <?php endif; ?>
                        <?php if ($antesResumo !== '' || $depoisResumo !== ''): ?>
                            <div class="history-diff">
                                <?php if ($antesResumo !== ''): ?>
                                    <div class="history-diff-box"><strong>Antes:</strong><br><?= eventos_historico_e($antesResumo) ?></div>
                                <?php endif; ?>
                                <?php if ($depoisResumo !== ''): ?>
                                    <div class="history-diff-box"><strong>Depois:</strong><br><?= eventos_historico_e($depoisResumo) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
