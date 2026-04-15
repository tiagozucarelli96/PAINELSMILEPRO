<?php
/**
 * eventos_cliente_reuniao.php
 * Página pública dedicada à Reunião Final do cliente.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/eventos_cliente_portal_ui.php';

$token = trim((string)($_GET['token'] ?? ''));
$error = '';

$portal = null;
$reuniao = null;
$snapshot = [];
$links_observacoes_portal = [];
$links_dj_portal = [];
$links_formulario_portal = [];
$visivel_reuniao = false;
$editavel_reuniao = false;
$visivel_dj = false;
$editavel_dj = false;

function eventos_cliente_reuniao_filtrar_links_observacoes(array $links_observacoes): array
{
    $result = [];
    $has_rules = false;
    foreach ($links_observacoes as $link_item) {
        if (!empty($link_item['portal_configured'])) {
            $has_rules = true;
            break;
        }
    }

    foreach ($links_observacoes as $link_item) {
        if (empty($link_item['is_active'])) {
            continue;
        }
        if ($has_rules && empty($link_item['portal_visible'])) {
            continue;
        }
        $result[] = $link_item;
    }

    usort($result, static function (array $a, array $b): int {
        $slotA = max(1, (int)($a['slot_index'] ?? 1));
        $slotB = max(1, (int)($b['slot_index'] ?? 1));
        if ($slotA !== $slotB) {
            return $slotA <=> $slotB;
        }
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    return $result;
}

function eventos_cliente_reuniao_tem_campos_formulario($schema): bool
{
    if (!is_array($schema)) {
        return false;
    }
    foreach ($schema as $field) {
        if (!is_array($field)) {
            continue;
        }
        $field_id = trim((string)($field['id'] ?? ''));
        if (strpos($field_id, 'legacy_portal_text_') === 0) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        if (in_array($type, ['text', 'textarea', 'yesno', 'select', 'file'], true)) {
            return true;
        }
    }
    return false;
}

function eventos_cliente_reuniao_filtrar_links_dj(array $links_dj): array
{
    $result = [];
    $has_rules = false;
    foreach ($links_dj as $link_item) {
        if (!empty($link_item['portal_configured'])) {
            $has_rules = true;
            break;
        }
    }

    foreach ($links_dj as $link_item) {
        if (empty($link_item['is_active'])) {
            continue;
        }
        if ($has_rules && empty($link_item['portal_visible'])) {
            continue;
        }
        $result[] = $link_item;
    }

    usort($result, static function (array $a, array $b): int {
        $slotA = max(1, (int)($a['slot_index'] ?? 1));
        $slotB = max(1, (int)($b['slot_index'] ?? 1));
        if ($slotA !== $slotB) {
            return $slotA <=> $slotB;
        }
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    return $result;
}

function eventos_cliente_reuniao_filtrar_links_formulario(array $links_formulario): array
{
    $result = [];
    foreach ($links_formulario as $link_item) {
        if (empty($link_item['is_active']) || empty($link_item['portal_visible'])) {
            continue;
        }
        if (!eventos_cliente_reuniao_tem_campos_formulario($link_item['form_schema'] ?? null)) {
            continue;
        }
        $result[] = $link_item;
    }

    usort($result, static function (array $a, array $b): int {
        $slotA = max(1, (int)($a['slot_index'] ?? 1));
        $slotB = max(1, (int)($b['slot_index'] ?? 1));
        if ($slotA !== $slotB) {
            return $slotA <=> $slotB;
        }
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    return $result;
}

if ($token === '') {
    $error = 'Link inválido.';
} else {
    $portal = eventos_cliente_portal_get_by_token($pdo, $token);
    if (!$portal || empty($portal['is_active'])) {
        $error = 'Portal não encontrado ou desativado.';
    } else {
        $reuniao = eventos_reuniao_get($pdo, (int)($portal['meeting_id'] ?? 0));
        if (!$reuniao) {
            $error = 'Reunião não encontrada.';
        } else {
            $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
            if (!is_array($snapshot)) {
                $snapshot = [];
            }

            $visivel_reuniao = !empty($portal['visivel_reuniao']);
            $editavel_reuniao = !empty($portal['editavel_reuniao']);
            $visivel_dj = !empty($portal['visivel_dj']);
            $editavel_dj = !empty($portal['editavel_dj']);
            if (!$visivel_reuniao) {
                $error = 'A área de reunião final ainda não está habilitada para este evento.';
            } else {
                try {
                    $sync_result = eventos_cliente_portal_sincronizar_link_reuniao(
                        $pdo,
                        (int)$reuniao['id'],
                        $visivel_reuniao,
                        $editavel_reuniao,
                        0
                    );
                    if (empty($sync_result['ok'])) {
                        error_log('eventos_cliente_reuniao sync reuniao: ' . (string)($sync_result['error'] ?? 'erro desconhecido'));
                    }

                    $links_observacoes = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_observacoes');
                    $links_observacoes_portal = eventos_cliente_reuniao_filtrar_links_observacoes($links_observacoes);

                    if ($visivel_dj) {
                        $links_dj = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_dj');
                        $links_dj_portal = eventos_cliente_reuniao_filtrar_links_dj($links_dj);
                    }

                    $links_formulario = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_formulario');
                    $links_formulario_portal = eventos_cliente_reuniao_filtrar_links_formulario($links_formulario);
                } catch (Throwable $e) {
                    error_log('eventos_cliente_reuniao load/sync: ' . $e->getMessage());
                    $error = 'Não foi possível carregar o formulário da reunião final neste momento.';
                }
            }
        }
    }
}

$evento_nome = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$horario_evento = eventos_cliente_ui_horario_evento($snapshot, '-');
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));
$abas_disponiveis = [];
if (!empty($links_observacoes_portal)) {
    $abas_disponiveis['decoracao'] = '🎨 Decoração';
    $abas_disponiveis['observacoes_gerais'] = '📝 Observações Gerais';
}
if ($visivel_dj && !empty($links_dj_portal)) {
    $abas_disponiveis['dj_protocolo'] = '🎧 DJ / Protocolos';
}
if (!empty($links_formulario_portal)) {
    $abas_disponiveis['formulario'] = '📋 Formulário';
}
$aba_ativa = trim((string)($_GET['aba'] ?? ''));
if ($aba_ativa === '' || !array_key_exists($aba_ativa, $abas_disponiveis)) {
    $aba_ativa = (string)(array_key_first($abas_disponiveis) ?? 'decoracao');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reunião Final - Portal do Cliente</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.12), transparent 24%),
                linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%);
            color: #1e293b;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #123c9c 0%, #2563eb 60%, #3b82f6 100%);
            color: #fff;
            padding: 2rem 1rem;
            text-align: center;
        }

        .header img { max-width: 170px; margin-bottom: 0.8rem; }
        .header h1 { font-size: 1.55rem; margin-bottom: 0.3rem; }

        .container {
            max-width: 980px;
            margin: 0 auto;
            padding: 1.2rem;
        }

        .alert {
            border-radius: 10px;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }

        .event-box,
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .event-box h2 {
            color: #1e3a8a;
            margin-bottom: 0.6rem;
        }

        .event-meta {
            display: grid;
            gap: 0.55rem;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            font-size: 0.92rem;
            color: #334155;
        }

        .actions-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-top: 0.75rem;
        }

        .card h3 {
            color: #0f172a;
            font-size: 1.06rem;
            margin-bottom: 0.45rem;
        }

        .card-subtitle {
            color: #64748b;
            font-size: 0.88rem;
            margin-bottom: 0.8rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.86rem;
            font-weight: 700;
            padding: 0.56rem 0.9rem;
            gap: 0.45rem;
        }

        .btn-primary { background: #1e3a8a; color: #fff; }
        .btn-primary:hover { background: #254ac9; }
        .btn-secondary { background: #f1f5f9; border-color: #dbe3ef; color: #334155; }

        .empty-text {
            color: #64748b;
            font-style: italic;
            font-size: 0.9rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.2rem 0.55rem;
            font-size: 0.72rem;
            font-weight: 700;
            border: 1px solid transparent;
            margin-left: 0.4rem;
        }

        .status-editavel {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1e40af;
        }

        .status-visualizacao {
            background: #e2e8f0;
            border-color: #cbd5e1;
            color: #334155;
        }

        .form-grid {
            display: grid;
            gap: 0.7rem;
        }

        .form-item {
            margin-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            padding: 0.85rem 0.9rem;
        }

        .form-item-title {
            font-weight: 700;
            color: #0f172a;
        }

        .form-item-subtitle {
            font-size: 0.82rem;
            color: #64748b;
        }

        .tabs-wrap {
            margin-top: 0.8rem;
        }

        .tabs-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.85rem;
        }

        .tab-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            text-decoration: none;
            border: 1px solid #dbe3ef;
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            border-radius: 999px;
            padding: 0.5rem 0.78rem;
            font-size: 0.83rem;
        }

        .tab-link.is-active {
            border-color: #93c5fd;
            background: #dbeafe;
            color: #1e40af;
        }

        .tab-panel {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.85rem;
            background: #fff;
        }

        .tab-panel-title {
            font-size: 0.95rem;
            color: #0f172a;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .tab-panel-subtitle {
            color: #64748b;
            font-size: 0.84rem;
            margin-bottom: 0.75rem;
        }

        @media (max-width: 780px) {
            .container {
                padding: 1rem 0.8rem 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
        <h1>📝 Reunião Final</h1>
        <p>Área exclusiva da reunião final do evento</p>
    </div>

    <div class="container">
        <?php if ($error !== ''): ?>
        <div class="alert alert-error"><strong>Erro:</strong> <?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <div class="event-box">
                <h2><?= htmlspecialchars($evento_nome) ?></h2>
                <div class="event-meta">
                    <div><strong>📅 Data:</strong> <?= htmlspecialchars($data_evento_fmt) ?></div>
                    <div><strong>⏰ Horário:</strong> <?= htmlspecialchars($horario_evento) ?></div>
                    <div><strong>📍 Local:</strong> <?= htmlspecialchars($local_evento) ?></div>
                    <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($cliente_nome) ?></div>
                </div>
                <div class="actions-row">
                    <a class="btn btn-secondary" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>">← Voltar ao portal</a>
                </div>
            </div>

            <section class="card">
                <h3>
                    Guias de organização
                    <span class="status-badge status-visualizacao">Acesso conforme liberação</span>
                </h3>
                <div class="card-subtitle">Escolha uma guia para continuar conforme o que está disponível para seu portal.</div>

                <?php if (!empty($abas_disponiveis)): ?>
                <div class="tabs-wrap">
                    <div class="tabs-nav">
                        <?php foreach ($abas_disponiveis as $aba_id => $aba_label): ?>
                        <a
                            class="tab-link <?= $aba_ativa === $aba_id ? 'is-active' : '' ?>"
                            href="index.php?page=eventos_cliente_reuniao&token=<?= urlencode($token) ?>&aba=<?= urlencode($aba_id) ?>"
                        >
                            <?= htmlspecialchars($aba_label) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="tab-panel">
                        <?php if ($aba_ativa === 'decoracao' || $aba_ativa === 'observacoes_gerais'): ?>
                        <div class="tab-panel-title"><?= $aba_ativa === 'decoracao' ? 'Decoração' : 'Observações Gerais' ?></div>
                        <div class="tab-panel-subtitle">Formulários da reunião final liberados para este evento.</div>
                        <div class="form-grid">
                            <?php foreach ($links_observacoes_portal as $link_item): ?>
                            <?php
                                $slot = max(1, (int)($link_item['slot_index'] ?? 1));
                                $title = trim((string)($link_item['form_title'] ?? ''));
                                if ($title === '') {
                                    $title = 'Reunião Final - Quadro ' . $slot;
                                }
                                $item_is_editable = !empty($link_item['portal_editable']) && empty($link_item['submitted_at']) && $editavel_reuniao;
                            ?>
                            <div class="form-item">
                                <div>
                                    <div class="form-item-title"><?= htmlspecialchars($title) ?></div>
                                    <div class="form-item-subtitle">
                                        <?php if (!empty($link_item['submitted_at'])): ?>
                                            Enviado • somente visualização
                                        <?php elseif ($item_is_editable): ?>
                                            Aguardando preenchimento
                                        <?php else: ?>
                                            Somente visualização
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$link_item['token']) ?>&secao=<?= urlencode($aba_ativa) ?>">
                                    <?= $item_is_editable ? 'Abrir formulário' : 'Visualizar formulário' ?>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php elseif ($aba_ativa === 'dj_protocolo'): ?>
                        <div class="tab-panel-title">DJ / Protocolos</div>
                        <div class="tab-panel-subtitle">Formulários de música e protocolos disponibilizados pela equipe.</div>
                        <div class="form-grid">
                            <?php foreach ($links_dj_portal as $link_item): ?>
                            <?php
                                $slot = max(1, (int)($link_item['slot_index'] ?? 1));
                                $title = trim((string)($link_item['form_title'] ?? ''));
                                if ($title === '') {
                                    $title = 'DJ / Protocolos - Quadro ' . $slot;
                                }
                                $item_is_editable = !empty($link_item['portal_editable']) && empty($link_item['submitted_at']) && $editavel_dj;
                            ?>
                            <div class="form-item">
                                <div>
                                    <div class="form-item-title"><?= htmlspecialchars($title) ?></div>
                                    <div class="form-item-subtitle">
                                        <?php if (!empty($link_item['submitted_at'])): ?>
                                            Enviado • somente visualização
                                        <?php elseif ($item_is_editable): ?>
                                            Aguardando preenchimento
                                        <?php else: ?>
                                            Somente visualização
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$link_item['token']) ?>">
                                    <?= $item_is_editable ? 'Abrir formulário' : 'Visualizar formulário' ?>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php elseif ($aba_ativa === 'formulario'): ?>
                        <div class="tab-panel-title">Formulário</div>
                        <div class="tab-panel-subtitle">Demais formulários públicos vinculados ao evento.</div>
                        <div class="form-grid">
                            <?php foreach ($links_formulario_portal as $link_item): ?>
                            <?php
                                $slot = max(1, (int)($link_item['slot_index'] ?? 1));
                                $title = trim((string)($link_item['form_title'] ?? ''));
                                if ($title === '') {
                                    $title = 'Formulário - Quadro ' . $slot;
                                }
                                $item_is_editable = !empty($link_item['portal_editable']) && empty($link_item['submitted_at']);
                            ?>
                            <div class="form-item">
                                <div>
                                    <div class="form-item-title"><?= htmlspecialchars($title) ?></div>
                                    <div class="form-item-subtitle">
                                        <?php if (!empty($link_item['submitted_at'])): ?>
                                            Enviado • somente visualização
                                        <?php elseif ($item_is_editable): ?>
                                            Aguardando preenchimento
                                        <?php else: ?>
                                            Somente visualização
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$link_item['token']) ?>">
                                    <?= $item_is_editable ? 'Abrir formulário' : 'Visualizar formulário' ?>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-text">Nenhuma guia está disponível no momento para este portal.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>
