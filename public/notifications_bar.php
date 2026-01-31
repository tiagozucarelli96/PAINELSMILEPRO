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
            if ($filter_unit && $uid !== $unit_id) { continue; }
            $ultima = $row['ultima'] ?? null;
            $days = $ultima ? (int)floor((time() - strtotime($ultima)) / 86400) : 999;
            if ($days >= 5) {
                $level = $days > 7 ? 'danger' : 'warning';
                $alerts[] = [
                    'level' => $level,
                    'text' => "Contagem atrasada: {$row['nome']} ({$days} dias)"
                ];
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
            $alerts[] = [
                'level' => 'danger',
                'text' => "Transferências em trânsito: {$counts['em_transito']}"
            ];
        }
        if (!empty($counts['rascunho'])) {
            $alerts[] = [
                'level' => 'warning',
                'text' => "Transferências em rascunho: {$counts['rascunho']}"
            ];
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
            $alerts[] = [
                'level' => 'danger',
                'text' => "Ajustes grandes na contagem: {$ajustes}"
            ];
        }

        // Faltas em eventos próximos
        foreach (array_slice($eventos_alertas['faltas'], 0, 3) as $ev) {
            $alerts[] = [
                'level' => 'danger',
                'text' => "Faltando insumos para " . ($ev['nome_evento'] ?: 'Evento') . " ({$ev['space_visivel']}): {$ev['faltas_total']} de {$ev['itens_total']}",
                'link' => 'index.php?page=logistica_faltas_evento&event_id=' . (int)$ev['id']
            ];
        }
        if (count($eventos_alertas['faltas']) > 3) {
            $alerts[] = [
                'level' => 'danger',
                'text' => 'Mais eventos com faltas: ' . (count($eventos_alertas['faltas']) - 3)
            ];
        }

        // Eventos sem lista pronta
        if (!empty($eventos_alertas['sem_lista'])) {
            $alerts[] = [
                'level' => 'warning',
                'text' => 'Evento sem lista pronta: ' . count($eventos_alertas['sem_lista'])
            ];
        }
        if (!empty($eventos_alertas['conflitos'])) {
            $alert = [
                'level' => 'danger',
                'text' => 'Conflito de listas prontas: ' . count($eventos_alertas['conflitos'])
            ];
            if ($is_superadmin) {
                $alert['link'] = 'index.php?page=logistica_resolver_conflitos';
            }
            $alerts[] = $alert;
        }
        if (!empty($eventos_alertas['sem_detalhe'])) {
            $alerts[] = [
                'level' => 'warning',
                'text' => 'Lista antiga sem detalhamento — gere nova lista: ' . count($eventos_alertas['sem_detalhe'])
            ];
        }

        if ($is_superadmin) {
            $mes_atual = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m');
            $stmt = $pdo->prepare("SELECT MAX(criado_em) FROM logistica_custos_log");
            $stmt->execute();
            $ultima = $stmt->fetchColumn();
            $mes_ultima = $ultima ? date('Y-m', strtotime($ultima)) : null;
            if ($mes_ultima !== $mes_atual) {
                $alerts[] = [
                    'level' => 'warning',
                    'text' => 'Revisar custos do mês',
                    'link' => 'index.php?page=logistica_revisar_custos'
                ];
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
                $alerts[] = [
                    'level' => 'danger',
                    'text' => "Bloqueios por saldo negativo: {$bloqueios}"
                ];
            }
        } catch (Throwable $e) {
            // tabela ainda pode não existir em ambientes desatualizados
        }

        if (!$alerts) {
            return '';
        }

        $priority = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, function ($a, $b) use ($priority) {
            $pa = $priority[$a['level']] ?? 9;
            $pb = $priority[$b['level']] ?? 9;
            return $pa <=> $pb;
        });

        // Separar alertas por tipo para exibição organizada
        $danger_alerts = array_filter($alerts, fn($a) => $a['level'] === 'danger');
        $warning_alerts = array_filter($alerts, fn($a) => $a['level'] === 'warning');
        $info_alerts = array_filter($alerts, fn($a) => $a['level'] === 'info');
        
        ob_start();
        ?>
        <style>
        .notifications-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .notifications-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        
        .notifications-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .notifications-title svg {
            width: 16px;
            height: 16px;
        }
        
        .notifications-count {
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            min-width: 20px;
            text-align: center;
        }
        
        .notifications-link {
            font-size: 0.8rem;
            color: #3b82f6;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            transition: color 0.2s;
        }
        
        .notifications-link:hover {
            color: #1d4ed8;
        }
        
        .notifications-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .notification-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.8rem;
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        
        .notification-chip:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .notification-chip svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }
        
        .notification-chip.danger {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #b91c1c;
            border-color: #fecaca;
        }
        
        .notification-chip.danger:hover {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        }
        
        .notification-chip.warning {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            color: #b45309;
            border-color: #fde68a;
        }
        
        .notification-chip.warning:hover {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        }
        
        .notification-chip.info {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            color: #1d4ed8;
            border-color: #bfdbfe;
        }
        
        .notification-chip.info:hover {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        }
        
        .notification-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        
        @media (max-width: 768px) {
            .notifications-grid {
                flex-direction: column;
            }
            
            .notification-chip {
                width: 100%;
            }
            
            .notification-text {
                max-width: none;
            }
        }
        </style>
        <div class="notifications-container" role="status" aria-live="polite">
            <div class="notifications-header">
                <div class="notifications-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    Alertas
                    <span class="notifications-count"><?= count($alerts) ?></span>
                </div>
                <a class="notifications-link" href="<?= htmlspecialchars($target_url) ?>">
                    Ver tudo
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
            
            <div class="notifications-grid">
                <?php foreach ($alerts as $alert): 
                    $icon = match($alert['level']) {
                        'danger' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
                        'warning' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                        default => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
                    };
                ?>
                    <?php if (!empty($alert['link'])): ?>
                        <a href="<?= htmlspecialchars($alert['link']) ?>" class="notification-chip <?= htmlspecialchars($alert['level']) ?>">
                            <?= $icon ?>
                            <span class="notification-text"><?= htmlspecialchars($alert['text']) ?></span>
                        </a>
                    <?php else: ?>
                        <span class="notification-chip <?= htmlspecialchars($alert['level']) ?>">
                            <?= $icon ?>
                            <span class="notification-text"><?= htmlspecialchars($alert['text']) ?></span>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
