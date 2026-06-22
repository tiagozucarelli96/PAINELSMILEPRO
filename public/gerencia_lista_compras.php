<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$_GET['page'] = $_GET['page'] ?? 'gerencia_lista_compras';

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['logado'])) {
    header('Location: login.php');
    exit;
}

$canAccess = !empty($_SESSION['perm_gerencia']) || !empty($_SESSION['perm_superadmin']);
if (!$canAccess) {
    includeSidebar('Lista de compras');
    echo '<div style="padding: 2rem; text-align: center;">
            <h2 style="color: #dc2626;">Acesso negado</h2>
            <p>Você não tem permissão para acessar esta página.</p>
            <a href="index.php?page=gerencia" style="color: #1e3a8a;">Voltar</a>
          </div>';
    endSidebar();
    exit;
}

const GLC_MARGEM_SEGURANCA = 1.05;

function glc_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $marker = sys_get_temp_dir() . '/gerencia_lista_compras_schema_checked_v1';
    $markerMtime = @filemtime($marker);
    if ($markerMtime !== false && (time() - $markerMtime) < 3600) {
        $done = true;
        return;
    }

    $stmt = $pdo->query("
        SELECT
            EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = 'logistica_insumos'
                  AND column_name = 'rendimento_base_pessoas'
            ) AS has_insumo_rendimento,
            EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = 'logistica_receitas'
                  AND column_name = 'rendimento_base_pessoas'
            ) AS has_receita_rendimento,
            EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_name = 'logistica_lista_evento_itens'
            ) AS has_lista_evento_itens,
            EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = 'logistica_listas'
                  AND column_name = 'status'
            ) AS has_lista_status
    ");
    $schema = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $missing = [];
    if (empty($schema['has_insumo_rendimento'])) {
        $missing[] = 'logistica_insumos.rendimento_base_pessoas';
    }
    if (empty($schema['has_receita_rendimento'])) {
        $missing[] = 'logistica_receitas.rendimento_base_pessoas';
    }
    if (empty($schema['has_lista_evento_itens'])) {
        $missing[] = 'logistica_lista_evento_itens';
    }
    if (empty($schema['has_lista_status'])) {
        $missing[] = 'logistica_listas.status';
    }
    if (!empty($missing)) {
        throw new RuntimeException('Estrutura pendente. Execute sql/083_gerencia_lista_compras.sql. Faltando: ' . implode(', ', $missing));
    }

    @touch($marker);

    $done = true;
}

function glc_user_id(): int
{
    return (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
}

function glc_fetch_user_scope(PDO $pdo): array
{
    if (!empty($_SESSION['perm_superadmin']) || ($_SESSION['unidade_scope'] ?? '') !== '') {
        return [
            'scope' => (string)($_SESSION['unidade_scope'] ?? 'nenhuma'),
            'unidade_id' => isset($_SESSION['unidade_id']) && $_SESSION['unidade_id'] !== '' ? (int)$_SESSION['unidade_id'] : null,
            'superadmin' => !empty($_SESSION['perm_superadmin']),
        ];
    }

    $userId = glc_user_id();
    if ($userId <= 0) {
        return ['scope' => 'nenhuma', 'unidade_id' => null, 'superadmin' => false];
    }

    static $columns = null;
    if ($columns === null) {
        $columns = [];
        try {
            $stmtCols = $pdo->query("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_name = 'usuarios'
                  AND column_name IN ('unidade_scope', 'unidade_id', 'perm_superadmin')
            ");
            foreach ($stmtCols->fetchAll(PDO::FETCH_COLUMN) ?: [] as $column) {
                $columns[(string)$column] = true;
            }
        } catch (Throwable $e) {
            error_log('glc_fetch_user_scope columns: ' . $e->getMessage());
        }
    }

    $scopeSql = isset($columns['unidade_scope'])
        ? "COALESCE(unidade_scope, 'nenhuma') AS unidade_scope"
        : "'todas' AS unidade_scope";
    $unitSql = isset($columns['unidade_id'])
        ? "unidade_id"
        : "NULL::integer AS unidade_id";
    $superSql = isset($columns['perm_superadmin'])
        ? "COALESCE(perm_superadmin, FALSE) AS perm_superadmin"
        : "FALSE AS perm_superadmin";

    $stmt = $pdo->prepare("
        SELECT {$scopeSql},
               {$unitSql},
               {$superSql}
        FROM usuarios
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'scope' => (string)($row['unidade_scope'] ?? 'nenhuma'),
        'unidade_id' => isset($row['unidade_id']) && $row['unidade_id'] !== '' ? (int)$row['unidade_id'] : null,
        'superadmin' => !empty($row['perm_superadmin']) || !empty($_SESSION['perm_superadmin']),
    ];
}

function glc_allowed_event_condition(array $scope, array &$params, string $alias = 'e'): string
{
    if (!empty($scope['superadmin']) || ($scope['scope'] ?? '') === 'todas') {
        return '1=1';
    }

    if (($scope['scope'] ?? '') === 'unidade' && !empty($scope['unidade_id'])) {
        $params[':scope_unidade_id'] = (int)$scope['unidade_id'];
        return "{$alias}.unidade_interna_id = :scope_unidade_id";
    }

    return '1=0';
}

function glc_normalize_event_ids(array $raw): array
{
    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function glc_fetch_eventos_disponiveis(PDO $pdo, array $scope): array
{
    $params = [
        ':ini' => date('Y-m-d'),
        ':fim' => date('Y-m-d', strtotime('+365 days')),
    ];
    $scopeSql = glc_allowed_event_condition($scope, $params, 'e');

    $stmt = $pdo->prepare("
        SELECT e.id,
               e.me_event_id,
               e.data_evento,
               e.hora_inicio,
               COALESCE(e.convidados, 0) AS convidados,
               e.localevento,
               e.nome_evento,
               e.space_visivel,
               e.unidade_interna_id,
               u.nome AS unidade_nome,
               r.id AS meeting_id,
               cr.submitted_at,
               COUNT(ri.id)::int AS cardapio_itens
        FROM logistica_eventos_espelho e
        JOIN eventos_reunioes r ON r.me_event_id = e.me_event_id
        JOIN eventos_cardapio_respostas cr ON cr.meeting_id = r.id
        JOIN eventos_cardapio_resposta_itens ri ON ri.resposta_id = cr.id
        LEFT JOIN logistica_unidades u ON u.id = e.unidade_interna_id
        WHERE e.arquivado IS FALSE
          AND e.data_evento BETWEEN :ini AND :fim
          AND {$scopeSql}
        GROUP BY e.id, u.nome, r.id, cr.submitted_at
        HAVING COUNT(ri.id) > 0
        ORDER BY e.data_evento ASC, e.hora_inicio ASC NULLS LAST, e.nome_evento ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function glc_fetch_eventos_by_ids(PDO $pdo, array $scope, array $eventIds): array
{
    $eventIds = glc_normalize_event_ids($eventIds);
    if (empty($eventIds)) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($eventIds as $index => $id) {
        $key = ':event_' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    $scopeSql = glc_allowed_event_condition($scope, $params, 'e');
    $params[':today'] = date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT e.id,
               e.me_event_id,
               e.data_evento,
               e.hora_inicio,
               COALESCE(e.convidados, 0) AS convidados,
               e.localevento,
               e.nome_evento,
               e.space_visivel,
               e.unidade_interna_id,
               u.nome AS unidade_nome,
               r.id AS meeting_id,
               r.me_event_snapshot,
               cr.id AS resposta_id,
               cr.submitted_at
        FROM logistica_eventos_espelho e
        JOIN eventos_reunioes r ON r.me_event_id = e.me_event_id
        JOIN eventos_cardapio_respostas cr ON cr.meeting_id = r.id
        LEFT JOIN logistica_unidades u ON u.id = e.unidade_interna_id
        WHERE e.id IN (" . implode(', ', $placeholders) . ")
          AND e.arquivado IS FALSE
          AND e.data_evento >= :today
          AND {$scopeSql}
        ORDER BY e.data_evento ASC, e.hora_inicio ASC NULLS LAST, e.nome_evento ASC
    ");
    $stmt->execute($params);

    $events = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $events[(int)$row['id']] = $row;
    }
    return $events;
}

function glc_fetch_cardapio_items(PDO $pdo, array $respostaIds): array
{
    $respostaIds = glc_normalize_event_ids($respostaIds);
    if (empty($respostaIds)) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($respostaIds as $index => $id) {
        $key = ':resp_' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }

    $stmt = $pdo->prepare("
        SELECT ri.resposta_id,
               ri.secao_cardapio_id,
               s.nome AS secao_nome,
               ri.item_tipo,
               ri.item_id,
               CASE
                   WHEN ri.item_tipo = 'insumo' THEN i.nome_oficial
                   WHEN ri.item_tipo = 'receita' THEN r.nome
                   ELSE ''
               END AS item_nome
        FROM eventos_cardapio_resposta_itens ri
        LEFT JOIN logistica_cardapio_secoes s ON s.id = ri.secao_cardapio_id
        LEFT JOIN logistica_insumos i ON ri.item_tipo = 'insumo' AND i.id = ri.item_id
        LEFT JOIN logistica_receitas r ON ri.item_tipo = 'receita' AND r.id = ri.item_id
        WHERE ri.resposta_id IN (" . implode(', ', $placeholders) . ")
        ORDER BY s.ordem ASC NULLS LAST, s.nome ASC, item_nome ASC
    ");
    $stmt->execute($params);

    $itemsByResposta = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rid = (int)$row['resposta_id'];
        if (!isset($itemsByResposta[$rid])) {
            $itemsByResposta[$rid] = [];
        }
        $itemsByResposta[$rid][] = $row;
    }
    return $itemsByResposta;
}

function glc_fetch_catalogo(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            COALESCE((
                SELECT json_agg(row_to_json(x))
                FROM (
                    SELECT i.id,
                           i.nome_oficial,
                           i.tipologia_insumo_id,
                           i.unidade_medida_padrao_id,
                           COALESCE(i.rendimento_base_pessoas, 100) AS rendimento_base_pessoas,
                           t.nome AS tipologia_nome,
                           u.nome AS unidade_nome
                    FROM logistica_insumos i
                    LEFT JOIN logistica_tipologias_insumo t ON t.id = i.tipologia_insumo_id
                    LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
                ) x
            ), '[]'::json) AS insumos_json,
            COALESCE((
                SELECT json_agg(row_to_json(x))
                FROM (
                    SELECT id, nome, COALESCE(rendimento_base_pessoas, 100) AS rendimento_base_pessoas
                    FROM logistica_receitas
                ) x
            ), '[]'::json) AS receitas_json,
            COALESCE((
                SELECT json_agg(row_to_json(x))
                FROM (
                    SELECT *
                    FROM logistica_receita_componentes
                    ORDER BY receita_id ASC, id ASC
                ) x
            ), '[]'::json) AS componentes_json,
            COALESCE((
                SELECT json_agg(row_to_json(x))
                FROM (
                    SELECT id, nome
                    FROM logistica_unidades_medida
                ) x
            ), '[]'::json) AS unidades_json
    ");
    $payload = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $insumos = [];
    $insumosRows = json_decode((string)($payload['insumos_json'] ?? '[]'), true);
    foreach (is_array($insumosRows) ? $insumosRows : [] as $row) {
        $insumos[(int)$row['id']] = $row;
    }

    $receitas = [];
    $receitasRows = json_decode((string)($payload['receitas_json'] ?? '[]'), true);
    foreach (is_array($receitasRows) ? $receitasRows : [] as $row) {
        $receitas[(int)$row['id']] = $row;
    }

    $componentes = [];
    $componentesRows = json_decode((string)($payload['componentes_json'] ?? '[]'), true);
    foreach (is_array($componentesRows) ? $componentesRows : [] as $row) {
        $rid = (int)($row['receita_id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        if (!isset($componentes[$rid])) {
            $componentes[$rid] = [];
        }
        $componentes[$rid][] = $row;
    }

    $unidades = [];
    $unidadesRows = json_decode((string)($payload['unidades_json'] ?? '[]'), true);
    foreach (is_array($unidadesRows) ? $unidadesRows : [] as $row) {
        $unidades[(int)$row['id']] = (string)$row['nome'];
    }

    return [$insumos, $receitas, $componentes, $unidades];
}

function glc_add_total(array &$totals, int $eventId, int $insumoId, ?int $unidadeMedidaId, float $quantity, array $origin): void
{
    if ($insumoId <= 0 || $quantity <= 0) {
        return;
    }

    $unitKey = $unidadeMedidaId ?: 0;
    $key = $insumoId . ':' . $unitKey;
    if (!isset($totals[$key])) {
        $totals[$key] = [
            'insumo_id' => $insumoId,
            'unidade_medida_id' => $unidadeMedidaId,
            'quantidade' => 0.0,
            'eventos' => [],
            'origens' => [],
        ];
    }

    $totals[$key]['quantidade'] += $quantity;
    $totals[$key]['eventos'][$eventId] = ($totals[$key]['eventos'][$eventId] ?? 0) + $quantity;
    $totals[$key]['origens'][] = $origin + ['quantidade' => $quantity];
}

function glc_component_quantity(array $component): float
{
    foreach (['peso_bruto', 'qtde_bruta', 'quantidade_base', 'peso_liquido', 'qtde_liquida'] as $field) {
        if (isset($component[$field]) && (float)$component[$field] > 0) {
            return (float)$component[$field];
        }
    }
    return 0.0;
}

function glc_expand_receita(
    int $receitaId,
    float $multiplier,
    int $eventId,
    string $originName,
    array $insumos,
    array $receitas,
    array $componentes,
    array &$totals,
    array &$warnings,
    array &$stack
): void {
    if ($receitaId <= 0 || $multiplier <= 0) {
        return;
    }
    if (isset($stack[$receitaId])) {
        $warnings[] = 'Loop detectado em sub-receitas na receita #' . $receitaId . '.';
        return;
    }
    if (empty($componentes[$receitaId])) {
        $warnings[] = 'Receita sem componentes: ' . ($receitas[$receitaId]['nome'] ?? ('#' . $receitaId));
        return;
    }

    $stack[$receitaId] = true;
    foreach ($componentes[$receitaId] as $component) {
        $tipo = strtolower(trim((string)($component['item_tipo'] ?? 'insumo')));
        $itemId = (int)($component['item_id'] ?? $component['insumo_id'] ?? 0);
        $baseQty = glc_component_quantity($component);
        if ($itemId <= 0 || $baseQty <= 0) {
            continue;
        }

        $quantity = $baseQty * $multiplier;
        if ($tipo === 'receita') {
            $yield = max(1, (int)($receitas[$itemId]['rendimento_base_pessoas'] ?? 100));
            glc_expand_receita(
                $itemId,
                $quantity / $yield,
                $eventId,
                $originName . ' > ' . (string)($receitas[$itemId]['nome'] ?? ('Receita #' . $itemId)),
                $insumos,
                $receitas,
                $componentes,
                $totals,
                $warnings,
                $stack
            );
            continue;
        }

        $quantity *= GLC_MARGEM_SEGURANCA;
        if (!isset($insumos[$itemId])) {
            $warnings[] = 'Componente com insumo não encontrado: #' . $itemId . '.';
            continue;
        }

        $unitId = isset($component['unidade_medida_id']) && $component['unidade_medida_id'] !== ''
            ? (int)$component['unidade_medida_id']
            : (isset($insumos[$itemId]['unidade_medida_padrao_id']) ? (int)$insumos[$itemId]['unidade_medida_padrao_id'] : null);

        glc_add_total($totals, $eventId, $itemId, $unitId ?: null, $quantity, [
            'tipo' => 'receita',
            'origem' => $originName,
        ]);
    }
    unset($stack[$receitaId]);
}

function glc_calcular_lista(PDO $pdo, array $events): array
{
    [$insumos, $receitas, $componentes, $unidades] = glc_fetch_catalogo($pdo);
    $respostaIds = [];
    foreach ($events as $event) {
        $respostaIds[] = (int)$event['resposta_id'];
    }
    $itemsByResposta = glc_fetch_cardapio_items($pdo, $respostaIds);

    $totals = [];
    $warnings = [];
    $eventsSummary = [];

    foreach ($events as $eventId => $event) {
        $convidados = max(0, (int)($event['convidados'] ?? 0));
        $eventItems = $itemsByResposta[(int)$event['resposta_id']] ?? [];
        $eventsSummary[$eventId] = [
            'event' => $event,
            'items' => $eventItems,
        ];

        if ($convidados <= 0) {
            $warnings[] = 'Evento sem convidados contratados: ' . (string)($event['nome_evento'] ?? ('#' . $eventId));
            continue;
        }

        if (empty($eventItems)) {
            $warnings[] = 'Evento sem itens de cardápio: ' . (string)($event['nome_evento'] ?? ('#' . $eventId));
            continue;
        }

        foreach ($eventItems as $item) {
            $tipo = strtolower(trim((string)($item['item_tipo'] ?? '')));
            $itemId = (int)($item['item_id'] ?? 0);
            $itemName = trim((string)($item['item_nome'] ?? ''));
            if ($tipo === 'insumo') {
                if (!isset($insumos[$itemId])) {
                    $warnings[] = 'Insumo do cardápio não encontrado: ' . ($itemName ?: ('#' . $itemId));
                    continue;
                }
                $yield = max(1, (int)($insumos[$itemId]['rendimento_base_pessoas'] ?? 100));
                $quantity = ($convidados / $yield) * GLC_MARGEM_SEGURANCA;
                $unitId = isset($insumos[$itemId]['unidade_medida_padrao_id']) ? (int)$insumos[$itemId]['unidade_medida_padrao_id'] : null;
                glc_add_total($totals, (int)$eventId, $itemId, $unitId ?: null, $quantity, [
                    'tipo' => 'insumo direto',
                    'origem' => $itemName,
                ]);
                continue;
            }

            if ($tipo === 'receita') {
                if (!isset($receitas[$itemId])) {
                    $warnings[] = 'Receita do cardápio não encontrada: ' . ($itemName ?: ('#' . $itemId));
                    continue;
                }
                $yield = max(1, (int)($receitas[$itemId]['rendimento_base_pessoas'] ?? 100));
                $stack = [];
                glc_expand_receita(
                    $itemId,
                    $convidados / $yield,
                    (int)$eventId,
                    $itemName ?: (string)$receitas[$itemId]['nome'],
                    $insumos,
                    $receitas,
                    $componentes,
                    $totals,
                    $warnings,
                    $stack
                );
            }
        }
    }

    uasort($totals, static function (array $a, array $b) use ($insumos): int {
        $tipA = (string)($insumos[(int)$a['insumo_id']]['tipologia_nome'] ?? '');
        $tipB = (string)($insumos[(int)$b['insumo_id']]['tipologia_nome'] ?? '');
        $cmp = strcmp($tipA, $tipB);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp(
            (string)($insumos[(int)$a['insumo_id']]['nome_oficial'] ?? ''),
            (string)($insumos[(int)$b['insumo_id']]['nome_oficial'] ?? '')
        );
    });

    return [
        'totals' => $totals,
        'warnings' => array_values(array_unique($warnings)),
        'events_summary' => $eventsSummary,
        'insumos' => $insumos,
        'unidades' => $unidades,
    ];
}

function glc_salvar_lista(PDO $pdo, array $events, array $calculo): int
{
    if (empty($events) || empty($calculo['totals'])) {
        throw new RuntimeException('Não há itens calculados para salvar.');
    }

    $firstEvent = reset($events);
    $unitId = (int)($firstEvent['unidade_interna_id'] ?? 0);
    $space = trim((string)($firstEvent['space_visivel'] ?? '')) ?: null;
    foreach ($events as $event) {
        if ((int)($event['unidade_interna_id'] ?? 0) !== $unitId) {
            throw new RuntimeException('Não é possível salvar uma lista misturando unidades.');
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO logistica_listas (unidade_interna_id, space_visivel, criado_por, criado_em, status)
            VALUES (:unidade_interna_id, :space_visivel, :criado_por, NOW(), 'gerada')
            RETURNING id
        ");
        $stmt->execute([
            ':unidade_interna_id' => $unitId > 0 ? $unitId : null,
            ':space_visivel' => $space,
            ':criado_por' => glc_user_id() ?: null,
        ]);
        $listaId = (int)$stmt->fetchColumn();

        $stmtEvento = $pdo->prepare("
            INSERT INTO logistica_lista_eventos
                (lista_id, evento_id, me_event_id, nome_evento, data_evento, hora_inicio, convidados, localevento, space_visivel, unidade_interna_id)
            VALUES
                (:lista_id, :evento_id, :me_event_id, :nome_evento, :data_evento, :hora_inicio, :convidados, :localevento, :space_visivel, :unidade_interna_id)
        ");
        foreach ($events as $event) {
            $stmtEvento->execute([
                ':lista_id' => $listaId,
                ':evento_id' => (int)$event['id'],
                ':me_event_id' => (int)$event['me_event_id'],
                ':nome_evento' => (string)$event['nome_evento'],
                ':data_evento' => $event['data_evento'],
                ':hora_inicio' => $event['hora_inicio'] ?: null,
                ':convidados' => (int)$event['convidados'],
                ':localevento' => (string)$event['localevento'],
                ':space_visivel' => trim((string)$event['space_visivel']) ?: null,
                ':unidade_interna_id' => (int)$event['unidade_interna_id'] ?: null,
            ]);
        }

        $stmtItem = $pdo->prepare("
            INSERT INTO logistica_lista_itens
                (lista_id, insumo_id, tipologia_insumo_id, unidade_medida_id, quantidade_total_bruto, observacao)
            VALUES
                (:lista_id, :insumo_id, :tipologia_insumo_id, :unidade_medida_id, :quantidade_total_bruto, :observacao)
        ");
        $stmtEventoItem = $pdo->prepare("
            INSERT INTO logistica_lista_evento_itens
                (lista_id, evento_id, insumo_id, unidade_medida_id, quantidade_total_bruto)
            VALUES
                (:lista_id, :evento_id, :insumo_id, :unidade_medida_id, :quantidade_total_bruto)
        ");

        $insumos = $calculo['insumos'];
        foreach ($calculo['totals'] as $total) {
            $insumoId = (int)$total['insumo_id'];
            $insumo = $insumos[$insumoId] ?? [];
            $stmtItem->execute([
                ':lista_id' => $listaId,
                ':insumo_id' => $insumoId,
                ':tipologia_insumo_id' => !empty($insumo['tipologia_insumo_id']) ? (int)$insumo['tipologia_insumo_id'] : null,
                ':unidade_medida_id' => !empty($total['unidade_medida_id']) ? (int)$total['unidade_medida_id'] : null,
                ':quantidade_total_bruto' => (float)$total['quantidade'],
                ':observacao' => 'Gerada pela Gerência com margem automática de 5%.',
            ]);

            foreach ($total['eventos'] as $eventId => $eventQty) {
                $stmtEventoItem->execute([
                    ':lista_id' => $listaId,
                    ':evento_id' => (int)$eventId,
                    ':insumo_id' => $insumoId,
                    ':unidade_medida_id' => !empty($total['unidade_medida_id']) ? (int)$total['unidade_medida_id'] : null,
                    ':quantidade_total_bruto' => (float)$eventQty,
                ]);
            }
        }

        $pdo->commit();
        return $listaId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

glc_ensure_schema($pdo);

$scope = glc_fetch_user_scope($pdo);
$messages = [];
$errors = [];
$requestInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$rawEventIds = $requestInput['event_ids'] ?? ($requestInput['event_ids[]'] ?? []);
$selectedEventIdsCsv = trim((string)($requestInput['selected_event_ids'] ?? ''));
if ($selectedEventIdsCsv !== '') {
    $rawEventIds = preg_split('/\s*,\s*/', $selectedEventIdsCsv, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
$action = (string)($requestInput['action'] ?? '');
$selectedIds = glc_normalize_event_ids(is_array($rawEventIds) ? $rawEventIds : [$rawEventIds]);
$isFormRequest = $_SERVER['REQUEST_METHOD'] === 'POST' || $action === 'preview' || !empty($selectedIds);
$selectedEvents = [];
$calculo = null;
$savedListId = 0;

if ($isFormRequest) {
    try {
        $selectedEvents = glc_fetch_eventos_by_ids($pdo, $scope, $selectedIds);
        if (empty($selectedIds)) {
            $errors[] = 'Selecione pelo menos um evento.';
        } elseif (count($selectedEvents) !== count($selectedIds)) {
            $errors[] = 'Um ou mais eventos selecionados não estão disponíveis para sua unidade.';
        }

        if (!$errors) {
            $unitIds = array_values(array_unique(array_map(static fn($event) => (int)$event['unidade_interna_id'], $selectedEvents)));
            if (count($unitIds) > 1) {
                $errors[] = 'Selecione eventos de uma única unidade por lista.';
            }
        }

        if (!$errors) {
            $calculo = glc_calcular_lista($pdo, $selectedEvents);
            if (empty($calculo['totals'])) {
                $errors[] = 'Nenhum insumo foi calculado a partir do cardápio selecionado.';
            } elseif ($action === 'save') {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    $errors[] = 'Use o botão Salvar lista para gravar a lista.';
                } else {
                    $savedListId = glc_salvar_lista($pdo, $selectedEvents, $calculo);
                    $messages[] = 'Lista salva com sucesso. ID #' . $savedListId . '.';
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Erro ao gerar lista: ' . $e->getMessage();
    }
}

$eventosDisponiveis = [];
if ($isFormRequest && !empty($selectedEvents)) {
    $eventosDisponiveis = array_values($selectedEvents);
} else {
    try {
        $eventosDisponiveis = glc_fetch_eventos_disponiveis($pdo, $scope);
    } catch (Throwable $e) {
        $errors[] = 'Erro ao carregar eventos: ' . $e->getMessage();
    }
}

ob_start();
?>

<style>
    .glc-page { max-width: 1320px; margin: 0 auto; padding: 2rem; color: #0f172a; }
    .glc-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
    .glc-header h1 { margin: 0 0 .35rem; font-size: 1.75rem; color: #1e293b; }
    .glc-header p { margin: 0; color: #64748b; }
    .glc-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.15rem; margin-bottom: 1rem; box-shadow: 0 1px 4px rgba(15,23,42,.06); }
    .glc-panel h2 { margin: 0 0 .8rem; font-size: 1.08rem; color: #1e293b; }
    .glc-toolbar { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; margin-bottom: .9rem; }
    .glc-search { min-width: min(420px, 100%); flex: 1; border: 1px solid #cbd5e1; border-radius: 8px; padding: .72rem .8rem; font-size: .94rem; }
    .glc-events { display: grid; grid-template-columns: repeat(auto-fit, minmax(285px, 1fr)); gap: .75rem; max-height: 460px; overflow: auto; padding-right: .25rem; }
    .glc-event { border: 1px solid #e2e8f0; border-radius: 8px; padding: .85rem; display: flex; gap: .7rem; align-items: flex-start; background: #fff; }
    .glc-event.hidden { display: none; }
    .glc-event input { margin-top: .25rem; }
    .glc-event strong { display: block; color: #0f172a; margin-bottom: .25rem; }
    .glc-event-meta { color: #64748b; font-size: .86rem; line-height: 1.45; }
    .glc-btn { border: 0; border-radius: 8px; padding: .72rem 1rem; font-weight: 800; cursor: pointer; background: #1e3a8a; color: #fff; }
    .glc-btn.secondary { background: #e2e8f0; color: #0f172a; }
    .glc-alert { border-radius: 8px; padding: .82rem 1rem; margin-bottom: 1rem; border: 1px solid; }
    .glc-alert.ok { background: #ecfdf5; border-color: #bbf7d0; color: #065f46; }
    .glc-alert.err { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
    .glc-alert.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
    .glc-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px; }
    .glc-table { width: 100%; border-collapse: collapse; min-width: 780px; background: #fff; }
    .glc-table th { background: #f8fafc; color: #475569; text-align: left; padding: .72rem; font-size: .8rem; text-transform: uppercase; }
    .glc-table td { border-top: 1px solid #e2e8f0; padding: .72rem; vertical-align: top; }
    .glc-muted { color: #64748b; }
    .glc-pill { display: inline-flex; border-radius: 999px; padding: .18rem .48rem; background: #eef2ff; color: #1e3a8a; font-size: .75rem; font-weight: 800; }
</style>

<div class="glc-page">
    <header class="glc-header">
        <div>
            <h1>Lista de compras</h1>
            <p>Gere a necessidade bruta a partir do cardápio da reunião final, convidados contratados e margem automática de 5%.</p>
        </div>
        <a class="glc-btn secondary" href="index.php?page=gerencia">Voltar</a>
    </header>

    <?php foreach ($messages as $message): ?>
        <div class="glc-alert ok"><?= h($message) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="glc-alert err"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if (($scope['scope'] ?? '') === 'nenhuma' && empty($scope['superadmin'])): ?>
        <div class="glc-alert warn">Seu usuário não possui unidade vinculada. Ajuste o cadastro do usuário para listar eventos.</div>
    <?php endif; ?>

    <form method="GET" action="index.php" id="glcForm">
        <input type="hidden" name="page" value="gerencia_lista_compras">
        <input type="hidden" name="action" id="glcAction" value="preview">
        <input type="hidden" name="selected_event_ids" id="glcSelectedEventIds" value="<?= h(implode(',', $selectedIds)) ?>">

        <section class="glc-panel">
            <h2>Eventos disponíveis</h2>
            <div class="glc-toolbar">
                <input class="glc-search" id="glcSearch" type="search" placeholder="Buscar por evento, data, local ou unidade">
                <button class="glc-btn secondary" type="button" onclick="glcClearSelection()">Limpar seleção</button>
                <span class="glc-muted" id="glcSelectedCount">0 selecionado(s)</span>
            </div>

            <?php if (empty($eventosDisponiveis)): ?>
                <p class="glc-muted">Nenhum evento com cardápio salvo foi encontrado para sua unidade.</p>
            <?php else: ?>
                <div class="glc-events" id="glcEvents">
                    <?php foreach ($eventosDisponiveis as $evento): ?>
                        <?php
                        $eventId = (int)$evento['id'];
                        $checked = in_array($eventId, $selectedIds, true);
                        $searchText = strtolower(implode(' ', [
                            $evento['nome_evento'] ?? '',
                            $evento['data_evento'] ?? '',
                            brDateOnly((string)($evento['data_evento'] ?? '')),
                            $evento['localevento'] ?? '',
                            $evento['space_visivel'] ?? '',
                            $evento['unidade_nome'] ?? '',
                        ]));
                        ?>
                        <label class="glc-event" data-search="<?= h($searchText) ?>">
                            <input type="checkbox" name="event_ids[]" value="<?= $eventId ?>" <?= $checked ? 'checked' : '' ?> onchange="glcUpdateCount()">
                            <span>
                                <strong><?= h($evento['nome_evento'] ?? 'Evento') ?></strong>
                                <span class="glc-event-meta">
                                    <?= h(brDateOnly((string)($evento['data_evento'] ?? ''))) ?>
                                    <?= h(substr((string)($evento['hora_inicio'] ?? ''), 0, 5)) ?><br>
                                    <?= h($evento['localevento'] ?? '') ?><br>
                                    <?= h($evento['unidade_nome'] ?? $evento['space_visivel'] ?? '') ?>
                                    · <?= (int)($evento['convidados'] ?? 0) ?> convidados
                                    · <?= (int)($evento['cardapio_itens'] ?? 0) ?> item(ns)
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="glc-toolbar">
            <button class="glc-btn" type="submit" id="glcPreviewBtn" onclick="return glcSubmit('preview', this)">Gerar prévia</button>
            <?php if ($calculo && !empty($calculo['totals'])): ?>
                <button class="glc-btn" type="submit" formmethod="POST" formaction="index.php?page=gerencia_lista_compras" id="glcSaveBtn" onclick="return glcSubmit('save', this)">Salvar lista</button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($calculo): ?>
        <?php if (!empty($calculo['warnings'])): ?>
            <section class="glc-panel">
                <h2>Avisos</h2>
                <?php foreach ($calculo['warnings'] as $warning): ?>
                    <div class="glc-alert warn"><?= h($warning) ?></div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="glc-panel" id="glcPreview">
            <h2>Eventos usados no cálculo</h2>
            <div class="glc-table-wrap">
                <table class="glc-table">
                    <thead>
                        <tr>
                            <th>Evento</th>
                            <th>Data</th>
                            <th>Unidade</th>
                            <th>Convidados</th>
                            <th>Cardápio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calculo['events_summary'] as $summary): ?>
                            <?php $event = $summary['event']; ?>
                            <tr>
                                <td><?= h($event['nome_evento'] ?? 'Evento') ?></td>
                                <td><?= h(brDateOnly((string)($event['data_evento'] ?? ''))) ?></td>
                                <td><?= h($event['unidade_nome'] ?? $event['space_visivel'] ?? '') ?></td>
                                <td><?= (int)($event['convidados'] ?? 0) ?></td>
                                <td><?= count($summary['items'] ?? []) ?> item(ns)</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="glc-panel">
            <h2>Necessidade bruta consolidada</h2>
            <p class="glc-muted">
                A unidade vem do cadastro do insumo. Quando o item vem de uma receita, usa a unidade definida no componente da receita; se o componente não tiver unidade, usa a unidade padrão do insumo. A quantidade total considera convidados contratados, rendimento base e margem automática de 5%.
            </p>
            <?php if (empty($calculo['totals'])): ?>
                <p class="glc-muted">Nenhum item calculado.</p>
            <?php else: ?>
                <div class="glc-table-wrap">
                    <table class="glc-table">
                        <thead>
                            <tr>
                                <th>Tipologia</th>
                                <th>Insumo</th>
                                <th>Unidade</th>
                                <th>Quantidade total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calculo['totals'] as $total): ?>
                                <?php
                                $insumo = $calculo['insumos'][(int)$total['insumo_id']] ?? [];
                                $unidadeNome = !empty($total['unidade_medida_id'])
                                    ? (string)($calculo['unidades'][(int)$total['unidade_medida_id']] ?? '')
                                    : (string)($insumo['unidade_nome'] ?? '');
                                ?>
                                <tr>
                                    <td><?= h($insumo['tipologia_nome'] ?? 'Sem tipologia') ?></td>
                                    <td><?= h($insumo['nome_oficial'] ?? ('Insumo #' . (int)$total['insumo_id'])) ?></td>
                                    <td><?= h($unidadeNome) ?></td>
                                    <td><strong><?= number_format((float)$total['quantidade'], 4, ',', '.') ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<script>
function glcUpdateCount() {
    const total = document.querySelectorAll('input[name="event_ids[]"]:checked').length;
    const target = document.getElementById('glcSelectedCount');
    if (target) target.textContent = `${total} selecionado(s)`;
}

function glcClearSelection() {
    document.querySelectorAll('input[name="event_ids[]"]').forEach(input => input.checked = false);
    glcUpdateCount();
}

function glcSubmit(action, btn) {
    const checkedInputs = Array.from(document.querySelectorAll('input[name="event_ids[]"]:checked'));
    if (checkedInputs.length <= 0) {
        alert('Selecione pelo menos um evento.');
        return false;
    }
    document.getElementById('glcAction').value = action;
    document.getElementById('glcSelectedEventIds').value = checkedInputs.map(input => input.value).join(',');
    if (btn) {
        btn.textContent = action === 'save' ? 'Salvando...' : 'Gerando...';
    }
    return true;
}

document.getElementById('glcForm')?.addEventListener('submit', function (event) {
    const checked = document.querySelectorAll('input[name="event_ids[]"]:checked').length;
    if (checked <= 0) {
        event.preventDefault();
        alert('Selecione pelo menos um evento.');
    }
});

document.getElementById('glcSearch')?.addEventListener('input', function () {
    const value = (this.value || '').toLowerCase().trim();
    document.querySelectorAll('#glcEvents .glc-event').forEach(card => {
        const text = card.dataset.search || '';
        card.classList.toggle('hidden', value !== '' && !text.includes(value));
    });
});

glcUpdateCount();
<?php if ($calculo): ?>
document.getElementById('glcPreview')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
<?php endif; ?>
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Lista de compras');
echo $conteudo;
endSidebar();
