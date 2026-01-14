<?php
// logistica_baixa_helper.php — Baixa automática por evento
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/logistica_alertas_helper.php';

function logistica_log_alert(PDO $pdo, array $data): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO logistica_alertas_log
                (tipo, unidade_id, insumo_id, referencia_tipo, referencia_id, mensagem, criado_em, criado_por)
            VALUES
                (:tipo, :unidade_id, :insumo_id, :ref_tipo, :ref_id, :mensagem, NOW(), :criado_por)
        ");
        $stmt->execute([
            ':tipo' => $data['tipo'],
            ':unidade_id' => $data['unidade_id'] ?? null,
            ':insumo_id' => $data['insumo_id'] ?? null,
            ':ref_tipo' => $data['ref_tipo'] ?? null,
            ':ref_id' => $data['ref_id'] ?? null,
            ':mensagem' => $data['mensagem'] ?? null,
            ':criado_por' => $data['criado_por'] ?? null
        ]);
    } catch (Throwable $e) {
        // não bloquear fluxo principal
    }
}

function logistica_processar_baixa_eventos(PDO $pdo, string $data, bool $filter_unit, int $unit_id, int $user_id): array {
    $summary = [
        'eventos_processados' => 0,
        'eventos_baixados' => 0,
        'eventos_bloqueados' => 0,
        'eventos_sem_lista' => 0,
        'eventos_conflito' => 0
    ];

    $params = [':data' => $data];
    $where = "WHERE e.arquivado IS FALSE AND e.data_evento = :data";
    if ($filter_unit) {
        $where .= " AND e.unidade_interna_id = :uid";
        $params[':uid'] = $unit_id;
    }
    $stmt = $pdo->prepare("
        SELECT e.*
        FROM logistica_eventos_espelho e
        $where
        ORDER BY e.hora_inicio ASC NULLS LAST
    ");
    $stmt->execute($params);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($eventos as $ev) {
        $summary['eventos_processados']++;
        $evento_id = (int)$ev['id'];
        $unidade_evento = (int)($ev['unidade_interna_id'] ?? 0);

        $resolve = logistica_resolve_lista_pronta($pdo, $evento_id);
        if ($resolve['status'] === 'conflito') {
            $summary['eventos_conflito']++;
            logistica_log_alert($pdo, [
                'tipo' => 'conflito_listas',
                'unidade_id' => $unidade_evento,
                'referencia_tipo' => 'evento',
                'referencia_id' => $evento_id,
                'mensagem' => 'Conflito de listas prontas para o evento.',
                'criado_por' => $user_id
            ]);
            continue;
        }
        if ($resolve['status'] === 'sem_lista' || $resolve['status'] === 'sem_detalhe') {
            $summary['eventos_sem_lista']++;
            logistica_log_alert($pdo, [
                'tipo' => 'evento_sem_lista_pronta',
                'unidade_id' => $unidade_evento,
                'referencia_tipo' => 'evento',
                'referencia_id' => $evento_id,
                'mensagem' => $resolve['status'] === 'sem_detalhe'
                    ? 'Lista pronta sem detalhamento por evento.'
                    : 'Evento sem lista pronta.',
                'criado_por' => $user_id
            ]);
            continue;
        }

        $lista_id = (int)$resolve['lista_id'];
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM logistica_evento_baixas
            WHERE lista_id = :lid AND evento_id = :eid
        ");
        $stmt->execute([':lid' => $lista_id, ':eid' => $evento_id]);
        if ((int)$stmt->fetchColumn() > 0) {
            continue;
        }

        $calc = logistica_fetch_faltas_evento($pdo, $evento_id, $lista_id, $unidade_evento);
        if (!empty($calc['faltas'])) {
            $summary['eventos_bloqueados']++;
            logistica_log_alert($pdo, [
                'tipo' => 'saida_evento_bloqueada',
                'unidade_id' => $unidade_evento,
                'referencia_tipo' => 'evento',
                'referencia_id' => $evento_id,
                'mensagem' => 'Saldo insuficiente para baixa automática do evento.',
                'criado_por' => $user_id
            ]);
            continue;
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT li.insumo_id, li.quantidade_total_bruto, li.unidade_medida_id
                FROM logistica_lista_evento_itens li
                WHERE li.lista_id = :lid AND li.evento_id = :eid
            ");
            $stmt->execute([':lid' => $lista_id, ':eid' => $evento_id]);
            $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$itens) {
                throw new RuntimeException('Sem itens para baixa.');
            }

            foreach ($itens as $it) {
                $insumo_id = (int)$it['insumo_id'];
                $quantidade = (float)$it['quantidade_total_bruto'];
                if ($quantidade <= 0) { continue; }

                $saldo_stmt = $pdo->prepare("SELECT quantidade_atual FROM logistica_estoque_saldos WHERE unidade_id = :uid AND insumo_id = :iid FOR UPDATE");
                $saldo_stmt->execute([':uid' => $unidade_evento, ':iid' => $insumo_id]);
                $saldo_atual = (float)($saldo_stmt->fetchColumn() ?? 0);
                if ($saldo_atual < $quantidade) {
                    throw new RuntimeException('Saldo insuficiente para baixa.');
                }

                $pdo->prepare("
                    UPDATE logistica_estoque_saldos
                    SET quantidade_atual = quantidade_atual - :quantidade, updated_at = NOW()
                    WHERE unidade_id = :uid AND insumo_id = :iid
                ")->execute([
                    ':quantidade' => $quantidade,
                    ':uid' => $unidade_evento,
                    ':iid' => $insumo_id
                ]);

                $mov = $pdo->prepare("
                    INSERT INTO logistica_estoque_movimentos
                        (unidade_id_origem, unidade_id_destino, insumo_id, tipo, quantidade, referencia_tipo, referencia_id, criado_por, criado_em, observacao)
                    VALUES
                        (:origem, NULL, :insumo_id, 'saida_evento', :quantidade, 'evento', :ref_id, :criado_por, NOW(), :observacao)
                ");
                $mov->execute([
                    ':origem' => $unidade_evento,
                    ':insumo_id' => $insumo_id,
                    ':quantidade' => $quantidade,
                    ':ref_id' => $evento_id,
                    ':criado_por' => $user_id,
                    ':observacao' => 'Baixa automática lista #' . $lista_id
                ]);
            }

            $pdo->prepare("
                INSERT INTO logistica_evento_baixas (lista_id, evento_id, unidade_id, baixado_em, baixado_por)
                VALUES (:lid, :eid, :uid, NOW(), :user)
            ")->execute([
                ':lid' => $lista_id,
                ':eid' => $evento_id,
                ':uid' => $unidade_evento,
                ':user' => $user_id
            ]);

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM logistica_lista_eventos WHERE lista_id = :lid
            ");
            $stmt->execute([':lid' => $lista_id]);
            $total_eventos = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM logistica_evento_baixas WHERE lista_id = :lid
            ");
            $stmt->execute([':lid' => $lista_id]);
            $baixados = (int)$stmt->fetchColumn();
            if ($total_eventos > 0 && $total_eventos === $baixados) {
                $pdo->prepare("
                    UPDATE logistica_listas
                    SET status = 'baixada', baixada_em = NOW(), baixada_por = :user
                    WHERE id = :id
                ")->execute([':user' => $user_id, ':id' => $lista_id]);
            }

            $pdo->commit();
            $summary['eventos_baixados']++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $summary['eventos_bloqueados']++;
            logistica_log_alert($pdo, [
                'tipo' => 'saida_evento_bloqueada',
                'unidade_id' => $unidade_evento,
                'referencia_tipo' => 'evento',
                'referencia_id' => $evento_id,
                'mensagem' => 'Erro ao baixar evento: ' . $e->getMessage(),
                'criado_por' => $user_id
            ]);
        }
    }

    return $summary;
}
