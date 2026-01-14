<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_alertas_helper.php — Alertas operacionais e faltas por evento

function logistica_fetch_eventos_proximos(PDO $pdo, int $dias, bool $filter_unit, int $unit_id): array {
    $params = [
        ':ini' => date('Y-m-d'),
        ':fim' => date('Y-m-d', strtotime('+' . $dias . ' days'))
    ];
    $where = "WHERE e.arquivado IS FALSE AND e.data_evento BETWEEN :ini AND :fim";
    if ($filter_unit) {
        $where .= " AND e.unidade_interna_id = :uid";
        $params[':uid'] = $unit_id;
    }
    $stmt = $pdo->prepare("
        SELECT e.id, e.nome_evento, e.data_evento, e.hora_inicio, e.localevento,
               e.unidade_interna_id, e.space_visivel
        FROM logistica_eventos_espelho e
        $where
        ORDER BY e.data_evento ASC, e.hora_inicio ASC NULLS LAST
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function logistica_resolve_lista_pronta(PDO $pdo, int $event_id): array {
    $stmt = $pdo->prepare("
        SELECT l.id
        FROM logistica_lista_eventos le
        JOIN logistica_listas l ON l.id = le.lista_id
        WHERE le.evento_id = :eid
          AND l.excluida IS FALSE
          AND l.status = 'pronta'
        ORDER BY l.criado_em DESC
    ");
    $stmt->execute([':eid' => $event_id]);
    $listas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($listas) > 1) {
        return ['status' => 'conflito', 'listas' => $listas];
    }
    if (count($listas) === 0) {
        return ['status' => 'sem_lista', 'listas' => []];
    }

    $lista_id = (int)$listas[0];
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM logistica_lista_evento_itens
        WHERE lista_id = :lid AND evento_id = :eid
    ");
    try {
        $stmt->execute([':lid' => $lista_id, ':eid' => $event_id]);
        $count = (int)$stmt->fetchColumn();
        if ($count === 0) {
            return ['status' => 'sem_detalhe', 'listas' => [$lista_id]];
        }
    } catch (Throwable $e) {
        return ['status' => 'sem_detalhe', 'listas' => [$lista_id]];
    }

    return ['status' => 'ok', 'lista_id' => $lista_id, 'listas' => [$lista_id]];
}

function logistica_fetch_faltas_evento(PDO $pdo, int $event_id, int $lista_id, int $unidade_id): array {
    $stmt = $pdo->prepare("
        SELECT li.insumo_id, li.quantidade_total_bruto, li.unidade_medida_id,
               i.nome_oficial, u.nome AS unidade_nome,
               COALESCE(s.quantidade_atual, 0) AS saldo_atual
        FROM logistica_lista_evento_itens li
        LEFT JOIN logistica_insumos i ON i.id = li.insumo_id
        LEFT JOIN logistica_unidades_medida u ON u.id = li.unidade_medida_id
        LEFT JOIN logistica_estoque_saldos s
            ON s.insumo_id = li.insumo_id AND s.unidade_id = :uid
        WHERE li.lista_id = :lid AND li.evento_id = :eid
        ORDER BY i.nome_oficial
    ");
    $stmt->execute([
        ':uid' => $unidade_id,
        ':lid' => $lista_id,
        ':eid' => $event_id
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $faltas = [];
    foreach ($rows as $r) {
        $planejado = (float)($r['quantidade_total_bruto'] ?? 0);
        $saldo = (float)($r['saldo_atual'] ?? 0);
        if ($saldo < $planejado) {
            $r['faltando'] = $planejado - $saldo;
            $faltas[] = $r;
        }
    }
    return [
        'total_itens' => count($rows),
        'faltas' => $faltas
    ];
}

function logistica_compute_alertas_eventos(PDO $pdo, int $dias, bool $filter_unit, int $unit_id): array {
    $eventos = logistica_fetch_eventos_proximos($pdo, $dias, $filter_unit, $unit_id);
    $conflitos = [];
    $sem_lista = [];
    $sem_detalhe = [];
    $faltas = [];

    foreach ($eventos as $ev) {
        $resolve = logistica_resolve_lista_pronta($pdo, (int)$ev['id']);
        if ($resolve['status'] === 'conflito') {
            $ev['listas'] = $resolve['listas'];
            $conflitos[] = $ev;
            continue;
        }
        if ($resolve['status'] === 'sem_lista') {
            $sem_lista[] = $ev;
            continue;
        }
        if ($resolve['status'] === 'sem_detalhe') {
            $ev['lista_id'] = (int)($resolve['listas'][0] ?? 0);
            $sem_detalhe[] = $ev;
            continue;
        }

        $lista_id = (int)$resolve['lista_id'];
        $unidade_id = (int)($ev['unidade_interna_id'] ?? 0);
        $calc = logistica_fetch_faltas_evento($pdo, (int)$ev['id'], $lista_id, $unidade_id);
        if (!empty($calc['faltas'])) {
            $ev['lista_id'] = $lista_id;
            $ev['faltas_total'] = count($calc['faltas']);
            $ev['itens_total'] = (int)$calc['total_itens'];
            $faltas[] = $ev;
        }
    }

    return [
        'eventos_total' => count($eventos),
        'conflitos' => $conflitos,
        'sem_lista' => $sem_lista,
        'sem_detalhe' => $sem_detalhe,
        'faltas' => $faltas
    ];
}
