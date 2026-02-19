<?php
require_once __DIR__ . '/logistica_tz.php';
// notifications_bar.php — Barra de alertas logísticos

require_once __DIR__ . '/logistica_alertas_helper.php';

if (!function_exists('build_logistica_notifications_bar')) {
    function build_logistica_notifications_bar(PDO $pdo): string {
        $is_superadmin = !empty($_SESSION['perm_superadmin']);
        $can_view = $is_superadmin || !empty($_SESSION['perm_logistico']) || !empty($_SESSION['perm_logistico_divergencias']);
        if (!$can_view) {
            return '';
        }

        $scope = $_SESSION['unidade_scope'] ?? 'todas';
        if (!$is_superadmin && $scope === 'nenhuma') {
            return '';
        }
        $unit_id = (int)($_SESSION['unidade_id'] ?? 0);
        $filter_unit = !$is_superadmin && ($scope === 'unidade' && $unit_id > 0);

        $alerts = [];
        $target_url = ($is_superadmin || !empty($_SESSION['perm_logistico_divergencias']))
            ? 'index.php?page=logistica_divergencias'
            : 'index.php?page=logistica_estoque';

        $add_alert = static function (array &$buffer, string $level, string $text, ?string $link = null): void {
            $key_seed = $level . '|' . $text . '|' . ($link ?? '');
            $alert = [
                'level' => $level,
                'text' => $text,
                'key' => substr(sha1($key_seed), 0, 20),
            ];
            if ($link) {
                $alert['link'] = $link;
            }
            $buffer[] = $alert;
        };

        $eventos_alertas = logistica_compute_alertas_eventos($pdo, 3, $filter_unit, $unit_id);

        // Contagens atrasadas
        $stmt = $pdo->query("
            SELECT u.id, u.nome, MAX(c.finalizada_em) AS ultima
            FROM logistica_unidades u
            LEFT JOIN logistica_estoque_contagens c
                ON c.unidade_id = u.id AND c.status = 'finalizada'
            WHERE u.ativo IS TRUE
            GROUP BY u.id, u.nome
            ORDER BY u.nome
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int)$row['id'];
            if ($filter_unit && $uid !== $unit_id) {
                continue;
            }
            $ultima = $row['ultima'] ?? null;
            $days = $ultima ? (int)floor((time() - strtotime($ultima)) / 86400) : 999;
            if ($days >= 5) {
                $level = $days > 7 ? 'danger' : 'warning';
                $add_alert(
                    $alerts,
                    $level,
                    "Contagem atrasada: {$row['nome']} ({$days} dias)",
                    'index.php?page=logistica_contagem'
                );
            }
        }

        // Transferências pendentes
        $params = [];
        $where = "WHERE t.status IN ('rascunho','em_transito')";
        if ($filter_unit) {
            $where .= " AND (t.unidade_destino_id = :uid OR (:uid = t.unidade_destino_id) OR (t.space_destino = 'Cristal' AND t.unidade_destino_id = :uid))";
            $params[':uid'] = $unit_id;
        }
        $stmt = $pdo->prepare("
            SELECT t.status, COUNT(*) AS total
            FROM logistica_transferencias t
            $where
            GROUP BY t.status
        ");
        $stmt->execute($params);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($counts['em_transito'])) {
            $add_alert(
                $alerts,
                'danger',
                "Transferências em trânsito: {$counts['em_transito']}",
                'index.php?page=logistica_transferencias'
            );
        }
        if (!empty($counts['rascunho'])) {
            $add_alert(
                $alerts,
                'warning',
                "Transferências em rascunho: {$counts['rascunho']}",
                'index.php?page=logistica_transferencias'
            );
        }

        // Ajustes grandes nas contagens (últimos 7 dias)
        $params = [];
        $where = "WHERE m.tipo = 'ajuste_contagem' AND m.criado_em >= (NOW() - INTERVAL '7 days') AND m.quantidade >= 2";
        if ($filter_unit) {
            $where .= " AND (m.unidade_id_destino = :uid OR m.unidade_id_origem = :uid)";
            $params[':uid'] = $unit_id;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM logistica_estoque_movimentos m $where");
        $stmt->execute($params);
        $ajustes = (int)$stmt->fetchColumn();
        if ($ajustes > 0) {
            $add_alert(
                $alerts,
                'danger',
                "Ajustes grandes na contagem: {$ajustes}",
                'index.php?page=logistica_divergencias'
            );
        }

        // Faltas em eventos próximos
        foreach (array_slice($eventos_alertas['faltas'], 0, 3) as $ev) {
            $add_alert(
                $alerts,
                'danger',
                "Faltando insumos para " . ($ev['nome_evento'] ?: 'Evento') . " ({$ev['space_visivel']}): {$ev['faltas_total']} de {$ev['itens_total']}",
                'index.php?page=logistica_faltas_evento&event_id=' . (int)$ev['id']
            );
        }
        if (count($eventos_alertas['faltas']) > 3) {
            $add_alert(
                $alerts,
                'danger',
                'Mais eventos com faltas: ' . (count($eventos_alertas['faltas']) - 3),
                'index.php?page=logistica_divergencias'
            );
        }

        // Eventos sem lista pronta
        if (!empty($eventos_alertas['sem_lista'])) {
            $add_alert(
                $alerts,
                'warning',
                'Evento sem lista pronta: ' . count($eventos_alertas['sem_lista']),
                'index.php?page=logistica_divergencias'
            );
        }
        if (!empty($eventos_alertas['conflitos'])) {
            $link = $is_superadmin ? 'index.php?page=logistica_resolver_conflitos' : 'index.php?page=logistica_divergencias';
            $add_alert(
                $alerts,
                'danger',
                'Conflito de listas prontas: ' . count($eventos_alertas['conflitos']),
                $link
            );
        }
        if (!empty($eventos_alertas['sem_detalhe'])) {
            $add_alert(
                $alerts,
                'warning',
                'Lista antiga sem detalhamento — gere nova lista: ' . count($eventos_alertas['sem_detalhe']),
                'index.php?page=logistica_divergencias'
            );
        }

        if ($is_superadmin) {
            $mes_atual = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m');
            $stmt = $pdo->prepare("SELECT MAX(criado_em) FROM logistica_custos_log");
            $stmt->execute();
            $ultima = $stmt->fetchColumn();
            $mes_ultima = $ultima ? date('Y-m', strtotime($ultima)) : null;
            if ($mes_ultima !== $mes_atual) {
                $add_alert(
                    $alerts,
                    'warning',
                    'Revisar custos do mês',
                    'index.php?page=logistica_revisar_custos'
                );
            }
        }

        // Alertas operacionais (saldo negativo bloqueado)
        $params = [];
        $where = "WHERE tipo = 'saldo_negativo_bloqueado' AND criado_em >= (NOW() - INTERVAL '7 days')";
        if ($filter_unit) {
            $where .= " AND unidade_id = :uid";
            $params[':uid'] = $unit_id;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM logistica_alertas_log $where");
        try {
            $stmt->execute($params);
            $bloqueios = (int)$stmt->fetchColumn();
            if ($bloqueios > 0) {
                $add_alert(
                    $alerts,
                    'danger',
                    "Bloqueios por saldo negativo: {$bloqueios}",
                    'index.php?page=logistica_divergencias'
                );
            }
        } catch (Throwable $e) {
            // tabela ainda pode não existir em ambientes desatualizados
        }

        if (!$alerts) {
            return '';
        }

        $priority = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, static function ($a, $b) use ($priority) {
            $pa = $priority[$a['level']] ?? 9;
            $pb = $priority[$b['level']] ?? 9;
            return $pa <=> $pb;
        });

        $totals = ['danger' => 0, 'warning' => 0, 'info' => 0];
        foreach ($alerts as $alert) {
            $lvl = $alert['level'] ?? 'info';
            if (array_key_exists($lvl, $totals)) {
                $totals[$lvl]++;
            }
        }

        $compact_limit = 4;
        $alerts_uid = 'alerts-' . substr(sha1(uniqid((string)mt_rand(), true)), 0, 10);
        $user_storage_id = (string)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 'guest');
        $storage_key = 'smile.dashboard.alertas.vistos.' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $user_storage_id);

        ob_start();
        ?>
        <style>
        .notifications-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 0.95rem 1.1rem;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
        }

        .notifications-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.55rem;
        }

        .notifications-title {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.85rem;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .notifications-title svg {
            width: 15px;
            height: 15px;
        }

        .notifications-count {
            background: #ef4444;
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.12rem 0.48rem;
            border-radius: 999px;
            min-width: 20px;
            text-align: center;
        }

        .notifications-actions {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .notifications-action-btn {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            border-radius: 8px;
            padding: 0.24rem 0.52rem;
            font-size: 0.74rem;
            font-weight: 600;
            cursor: pointer;
            line-height: 1.2;
            transition: all 0.16s ease;
        }

        .notifications-action-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        .notifications-link {
            font-size: 0.78rem;
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
            border-radius: 8px;
            padding: 0.24rem 0.52rem;
            border: 1px solid rgba(37, 99, 235, 0.22);
            transition: all 0.16s ease;
            line-height: 1.2;
        }

        .notifications-link:hover {
            color: #1d4ed8;
            background: #eff6ff;
            border-color: rgba(29, 78, 216, 0.34);
        }

        .notifications-summary {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            flex-wrap: wrap;
            margin-bottom: 0.6rem;
        }

        .summary-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.14rem 0.48rem;
            font-size: 0.72rem;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .summary-pill.danger {
            color: #b91c1c;
            background: #fff1f2;
            border-color: #fecdd3;
        }

        .summary-pill.warning {
            color: #b45309;
            background: #fffbeb;
            border-color: #fde68a;
        }

        .summary-pill.info {
            color: #1d4ed8;
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .notifications-list {
            display: grid;
            gap: 0.46rem;
        }

        .notification-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.42rem 0.48rem;
            background: #fff;
            transition: border-color 0.16s ease, background 0.16s ease, opacity 0.16s ease;
        }

        .notification-item.danger {
            border-color: #fecaca;
            background: #fff5f5;
        }

        .notification-item.warning {
            border-color: #fde68a;
            background: #fffbeb;
        }

        .notification-item.info {
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .notification-item.is-dismissed {
            opacity: 0.72;
        }

        .notification-main {
            flex: 1;
            min-width: 0;
        }

        .notification-content {
            display: inline-flex;
            align-items: center;
            gap: 0.38rem;
            width: 100%;
            color: inherit;
            text-decoration: none;
            min-width: 0;
        }

        .notification-content:hover {
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .notification-content svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }

        .notification-text {
            font-size: 0.81rem;
            font-weight: 600;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
        }

        .notification-dismiss {
            border: 1px solid rgba(148, 163, 184, 0.62);
            background: #fff;
            color: #475569;
            border-radius: 7px;
            padding: 0.16rem 0.42rem;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.16s ease;
        }

        .notification-dismiss:hover {
            border-color: #64748b;
            color: #1e293b;
            background: #f8fafc;
        }

        .notifications-empty {
            margin-top: 0.5rem;
            padding: 0.58rem 0.68rem;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            color: #475569;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        @media (max-width: 768px) {
            .notifications-header {
                flex-direction: column;
            }

            .notifications-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .notification-item {
                align-items: flex-start;
            }

            .notification-text {
                white-space: normal;
            }
        }
        </style>
        <div
            class="notifications-container"
            data-alerts-id="<?= htmlspecialchars($alerts_uid, ENT_QUOTES, 'UTF-8') ?>"
            data-storage-key="<?= htmlspecialchars($storage_key, ENT_QUOTES, 'UTF-8') ?>"
            data-compact-limit="<?= $compact_limit ?>"
            role="status"
            aria-live="polite"
        >
            <div class="notifications-header">
                <div class="notifications-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    Alertas
                    <span class="notifications-count" data-role="active-count"><?= count($alerts) ?></span>
                </div>
                <div class="notifications-actions">
                    <button type="button" class="notifications-action-btn" data-role="toggle-list" hidden>Ver mais</button>
                    <button type="button" class="notifications-action-btn" data-role="toggle-viewed" hidden>Vistos</button>
                    <a class="notifications-link" href="<?= htmlspecialchars($target_url, ENT_QUOTES, 'UTF-8') ?>">Abrir logística</a>
                </div>
            </div>

            <div class="notifications-summary">
                <?php if ($totals['danger'] > 0): ?>
                    <span class="summary-pill danger"><?= $totals['danger'] ?> críticos</span>
                <?php endif; ?>
                <?php if ($totals['warning'] > 0): ?>
                    <span class="summary-pill warning"><?= $totals['warning'] ?> atenção</span>
                <?php endif; ?>
                <?php if ($totals['info'] > 0): ?>
                    <span class="summary-pill info"><?= $totals['info'] ?> info</span>
                <?php endif; ?>
            </div>

            <div class="notifications-list" data-role="alerts-list">
                <?php foreach ($alerts as $alert):
                    $icon = match($alert['level']) {
                        'danger' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
                        'warning' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                        default => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
                    };
                ?>
                    <article class="notification-item <?= htmlspecialchars($alert['level'], ENT_QUOTES, 'UTF-8') ?>" data-alert-key="<?= htmlspecialchars($alert['key'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="notification-main">
                            <?php if (!empty($alert['link'])): ?>
                                <a href="<?= htmlspecialchars($alert['link'], ENT_QUOTES, 'UTF-8') ?>" class="notification-content">
                                    <?= $icon ?>
                                    <span class="notification-text"><?= htmlspecialchars($alert['text'], ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                            <?php else: ?>
                                <div class="notification-content">
                                    <?= $icon ?>
                                    <span class="notification-text"><?= htmlspecialchars($alert['text'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="notification-dismiss" data-role="dismiss-alert" aria-label="Marcar alerta como visto">Visto</button>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="notifications-empty" data-role="empty-state" hidden>
                <span>Você marcou todos os alertas como vistos.</span>
                <button type="button" class="notifications-action-btn" data-role="restore-all" hidden>Restaurar vistos</button>
            </div>
        </div>
        <script>
        (() => {
            const root = document.querySelector('.notifications-container[data-alerts-id="<?= htmlspecialchars($alerts_uid, ENT_QUOTES, 'UTF-8') ?>"]');
            if (!root) return;

            const compactLimit = Number(root.dataset.compactLimit || '4');
            const storageKey = root.dataset.storageKey || '';
            const list = root.querySelector('[data-role="alerts-list"]');
            if (!list) return;

            const items = Array.from(list.querySelectorAll('.notification-item'));
            const countBadge = root.querySelector('[data-role="active-count"]');
            const toggleListBtn = root.querySelector('[data-role="toggle-list"]');
            const toggleViewedBtn = root.querySelector('[data-role="toggle-viewed"]');
            const emptyState = root.querySelector('[data-role="empty-state"]');
            const restoreAllBtn = root.querySelector('[data-role="restore-all"]');

            let expanded = false;
            let showViewed = false;

            const hasStorage = (() => {
                try {
                    const testKey = '__smile_alerts_test__';
                    window.localStorage.setItem(testKey, '1');
                    window.localStorage.removeItem(testKey);
                    return true;
                } catch (err) {
                    return false;
                }
            })();

            const readDismissed = () => {
                if (!hasStorage || !storageKey) return new Set();
                try {
                    const raw = window.localStorage.getItem(storageKey);
                    if (!raw) return new Set();
                    const parsed = JSON.parse(raw);
                    if (!Array.isArray(parsed)) return new Set();
                    return new Set(parsed.filter((value) => typeof value === 'string'));
                } catch (err) {
                    return new Set();
                }
            };

            const writeDismissed = (set) => {
                if (!hasStorage || !storageKey) return;
                try {
                    window.localStorage.setItem(storageKey, JSON.stringify(Array.from(set)));
                } catch (err) {
                    // sem persistência, segue sem bloquear UX
                }
            };

            const getKey = (item) => item.getAttribute('data-alert-key') || '';
            const dismissed = readDismissed();

            const applyState = () => {
                const activeItems = [];
                const dismissedItems = [];

                items.forEach((item) => {
                    const key = getKey(item);
                    const isDismissed = key && dismissed.has(key);
                    const dismissBtn = item.querySelector('[data-role="dismiss-alert"]');

                    item.classList.toggle('is-dismissed', !!isDismissed);
                    if (dismissBtn) {
                        dismissBtn.textContent = isDismissed ? 'Desfazer' : 'Visto';
                        dismissBtn.setAttribute('aria-label', isDismissed ? 'Reativar alerta' : 'Marcar alerta como visto');
                    }

                    if (isDismissed) {
                        dismissedItems.push(item);
                    } else {
                        activeItems.push(item);
                    }
                });

                activeItems.forEach((item, index) => {
                    const shouldShow = expanded || index < compactLimit;
                    item.hidden = !shouldShow;
                });
                dismissedItems.forEach((item) => {
                    item.hidden = !showViewed;
                });

                const extraCount = Math.max(0, activeItems.length - compactLimit);
                if (toggleListBtn) {
                    if (extraCount > 0) {
                        toggleListBtn.hidden = false;
                        toggleListBtn.textContent = expanded ? 'Ver menos' : `Ver mais (${extraCount})`;
                    } else {
                        expanded = false;
                        toggleListBtn.hidden = true;
                    }
                }

                if (toggleViewedBtn) {
                    if (dismissedItems.length > 0) {
                        toggleViewedBtn.hidden = false;
                        toggleViewedBtn.textContent = showViewed
                            ? `Ocultar vistos (${dismissedItems.length})`
                            : `Vistos (${dismissedItems.length})`;
                    } else {
                        showViewed = false;
                        toggleViewedBtn.hidden = true;
                    }
                }

                if (countBadge) {
                    countBadge.textContent = String(activeItems.length);
                }

                if (emptyState) {
                    const shouldShowEmpty = activeItems.length === 0 && (!showViewed || dismissedItems.length === 0);
                    emptyState.hidden = !shouldShowEmpty;
                }

                if (restoreAllBtn) {
                    restoreAllBtn.hidden = dismissedItems.length === 0;
                }
            };

            list.addEventListener('click', (event) => {
                const dismissBtn = event.target.closest('[data-role="dismiss-alert"]');
                if (!dismissBtn) return;

                event.preventDefault();
                event.stopPropagation();

                const item = dismissBtn.closest('.notification-item');
                if (!item) return;

                const key = getKey(item);
                if (!key) return;

                if (dismissed.has(key)) {
                    dismissed.delete(key);
                } else {
                    dismissed.add(key);
                }

                writeDismissed(dismissed);
                applyState();
            });

            if (toggleListBtn) {
                toggleListBtn.addEventListener('click', () => {
                    expanded = !expanded;
                    applyState();
                });
            }

            if (toggleViewedBtn) {
                toggleViewedBtn.addEventListener('click', () => {
                    showViewed = !showViewed;
                    applyState();
                });
            }

            if (restoreAllBtn) {
                restoreAllBtn.addEventListener('click', () => {
                    dismissed.clear();
                    showViewed = false;
                    writeDismissed(dismissed);
                    applyState();
                });
            }

            applyState();
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
