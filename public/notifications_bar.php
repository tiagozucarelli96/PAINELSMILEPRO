<?php
// notifications_bar.php — Barra de alertas logísticos

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

        ob_start();
        ?>
        <style>
        .logistica-notifications-bar {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: .6rem .8rem;
        }
        .logistica-notifications-bar a {
            text-decoration: none;
        }
        .logistica-notification-pill {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .35rem .7rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: .9rem;
            color: #0f172a;
            background: #e2e8f0;
            border: 1px solid transparent;
        }
        .logistica-notification-pill.danger {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }
        .logistica-notification-pill.warning {
            background: #ffedd5;
            color: #9a3412;
            border-color: #fed7aa;
        }
        .logistica-notification-pill.info {
            background: #e0f2fe;
            color: #075985;
            border-color: #bae6fd;
        }
        .logistica-notification-link {
            margin-left: auto;
            font-size: .9rem;
            color: #2563eb;
            font-weight: 600;
        }
        </style>
        <div class="logistica-notifications-bar" role="status" aria-live="polite">
            <?php foreach ($alerts as $alert): ?>
                <span class="logistica-notification-pill <?= htmlspecialchars($alert['level']) ?>">
                    <?= htmlspecialchars($alert['text']) ?>
                </span>
            <?php endforeach; ?>
            <a class="logistica-notification-link" href="<?= htmlspecialchars($target_url) ?>">Ver tudo →</a>
        </div>
        <?php
        return ob_get_clean();
    }
}
