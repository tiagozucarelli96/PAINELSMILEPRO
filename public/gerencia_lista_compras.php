<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$_GET['page'] = $_GET['page'] ?? 'gerencia_lista_compras';

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/logistica_cardapio_helper.php';

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

function glc_unidade_usa_decimal(string $unidadeNome): bool
{
    $normalized = glc_normalize_unit_name($unidadeNome);
    return in_array($normalized, ['kg', 'ml', 'l', 'lt', 'litro', 'litros'], true);
}

function glc_format_quantidade(float $value, string $unidadeNome = ''): string
{
    $decimals = glc_unidade_usa_decimal($unidadeNome) ? 3 : 0;
    return number_format($value, $decimals, ',', '.');
}

function glc_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $marker = sys_get_temp_dir() . '/gerencia_lista_compras_schema_checked_v7';
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

    $pdo->exec("
        ALTER TABLE logistica_tipologias_insumo
            ADD COLUMN IF NOT EXISTS calculo_por_grupo BOOLEAN DEFAULT FALSE,
            ADD COLUMN IF NOT EXISTS grupo_pessoas_base NUMERIC(12,3),
            ADD COLUMN IF NOT EXISTS grupo_quantidade_base NUMERIC(12,3),
            ADD COLUMN IF NOT EXISTS grupo_unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id),
            ADD COLUMN IF NOT EXISTS grupo_distribuir_igual BOOLEAN DEFAULT TRUE,
            ADD COLUMN IF NOT EXISTS grupo_arredondar_inteiro BOOLEAN DEFAULT TRUE,
            ADD COLUMN IF NOT EXISTS grupo_aplicar_margem BOOLEAN DEFAULT TRUE
    ");
    $pdo->exec("
        ALTER TABLE logistica_insumos
            ADD COLUMN IF NOT EXISTS calculo_lista_metodo VARCHAR(20) DEFAULT 'rendimento',
            ADD COLUMN IF NOT EXISTS calculo_lista_metodo_adulto VARCHAR(20),
            ADD COLUMN IF NOT EXISTS calculo_lista_metodo_infantil VARCHAR(20),
            ADD COLUMN IF NOT EXISTS arredondar_rendimento_lista BOOLEAN DEFAULT FALSE,
            ADD COLUMN IF NOT EXISTS rendimento_quantidade_base NUMERIC(12,3) NOT NULL DEFAULT 1,
            ADD COLUMN IF NOT EXISTS grupo_pessoas_base NUMERIC(12,3),
            ADD COLUMN IF NOT EXISTS grupo_quantidade_base NUMERIC(12,3),
            ADD COLUMN IF NOT EXISTS grupo_unidade_medida_id INTEGER REFERENCES logistica_unidades_medida(id),
            ADD COLUMN IF NOT EXISTS grupo_arredondar_inteiro BOOLEAN DEFAULT TRUE,
            ADD COLUMN IF NOT EXISTS grupo_aplicar_margem BOOLEAN DEFAULT TRUE
    ");
    $pdo->exec("
        UPDATE logistica_insumos i
        SET grupo_arredondar_inteiro = COALESCE(i.grupo_arredondar_inteiro, COALESCE(t.grupo_arredondar_inteiro, TRUE)),
            grupo_aplicar_margem = COALESCE(i.grupo_aplicar_margem, COALESCE(t.grupo_aplicar_margem, TRUE)),
            grupo_pessoas_base = COALESCE(i.grupo_pessoas_base, t.grupo_pessoas_base),
            grupo_quantidade_base = COALESCE(i.grupo_quantidade_base, t.grupo_quantidade_base),
            grupo_unidade_medida_id = COALESCE(i.grupo_unidade_medida_id, t.grupo_unidade_medida_id)
        FROM logistica_tipologias_insumo t
        WHERE t.id = i.tipologia_insumo_id
          AND COALESCE(t.calculo_por_grupo, FALSE) = TRUE
          AND (
              i.grupo_arredondar_inteiro IS NULL
              OR i.grupo_aplicar_margem IS NULL
              OR i.grupo_pessoas_base IS NULL
              OR i.grupo_quantidade_base IS NULL
              OR i.grupo_unidade_medida_id IS NULL
          )
    ");
    $pdo->exec("
        UPDATE logistica_insumos i
        SET calculo_lista_metodo = CASE
                WHEN COALESCE(t.calculo_por_grupo, FALSE) = TRUE
                     AND COALESCE(i.grupo_pessoas_base, t.grupo_pessoas_base) IS NOT NULL
                     AND COALESCE(i.grupo_quantidade_base, t.grupo_quantidade_base) IS NOT NULL
                    THEN 'grupo'
                ELSE 'rendimento'
            END
        FROM logistica_tipologias_insumo t
        WHERE t.id = i.tipologia_insumo_id
          AND i.calculo_lista_metodo IS NULL
    ");
    $pdo->exec("UPDATE logistica_insumos SET calculo_lista_metodo = 'rendimento' WHERE calculo_lista_metodo IS NULL OR calculo_lista_metodo NOT IN ('rendimento', 'grupo')");
    $pdo->exec("
        UPDATE logistica_insumos
        SET calculo_lista_metodo_adulto = COALESCE(NULLIF(calculo_lista_metodo_adulto, ''), calculo_lista_metodo, 'rendimento'),
            calculo_lista_metodo_infantil = COALESCE(NULLIF(calculo_lista_metodo_infantil, ''), calculo_lista_metodo, 'rendimento')
        WHERE calculo_lista_metodo_adulto IS NULL
           OR calculo_lista_metodo_adulto NOT IN ('rendimento', 'grupo')
           OR calculo_lista_metodo_infantil IS NULL
           OR calculo_lista_metodo_infantil NOT IN ('rendimento', 'grupo')
    ");
    $pdo->exec("UPDATE logistica_insumos SET rendimento_quantidade_base = 1 WHERE rendimento_quantidade_base IS NULL OR rendimento_quantidade_base <= 0");

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
        ':fim' => date('Y-m-d', strtotime('+30 days')),
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
               MAX(r.id) AS meeting_id,
               MAX(r.pacote_evento_id) AS pacote_evento_id,
               MAX(p.nome) AS pacote_nome,
               MAX(r.tipo_evento_real) AS tipo_evento_real,
               MAX(cr.id) AS resposta_id,
               MAX(cr.submitted_at) AS submitted_at,
               COUNT(DISTINCT ri.id)::int AS cardapio_itens,
               CASE
                   WHEN MAX(r.id) IS NULL THEN FALSE
                   WHEN MAX(cr.id) IS NULL THEN FALSE
                   WHEN COUNT(DISTINCT ri.id) = 0 THEN FALSE
                   ELSE TRUE
               END AS disponivel_calculo,
               CASE
                   WHEN MAX(r.id) IS NULL THEN 'Sem reunião final vinculada'
                   WHEN MAX(cr.id) IS NULL THEN 'Sem resposta de cardápio'
                   WHEN COUNT(DISTINCT ri.id) = 0 THEN 'Cardápio sem itens'
                   ELSE ''
               END AS motivo_indisponivel
        FROM logistica_eventos_espelho e
        LEFT JOIN eventos_reunioes r ON r.me_event_id = e.me_event_id
        LEFT JOIN logistica_pacotes_evento p ON p.id = r.pacote_evento_id AND p.deleted_at IS NULL
        LEFT JOIN eventos_cardapio_respostas cr ON cr.meeting_id = r.id
        LEFT JOIN eventos_cardapio_resposta_itens ri ON ri.resposta_id = cr.id
        LEFT JOIN logistica_unidades u ON u.id = e.unidade_interna_id
        WHERE e.arquivado IS FALSE
          AND e.data_evento BETWEEN :ini AND :fim
          AND {$scopeSql}
        GROUP BY e.id, u.nome
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
               r.pacote_evento_id,
               p.nome AS pacote_nome,
               r.tipo_evento_real,
               r.me_event_snapshot,
               cr.id AS resposta_id,
               cr.submitted_at
        FROM logistica_eventos_espelho e
        JOIN eventos_reunioes r ON r.me_event_id = e.me_event_id
        LEFT JOIN logistica_pacotes_evento p ON p.id = r.pacote_evento_id AND p.deleted_at IS NULL
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

function glc_evento_eh_infantil(array $event): bool
{
    return logistica_cardapio_tipo_evento_eh_infantil(
        isset($event['tipo_evento_real']) ? (string)$event['tipo_evento_real'] : '',
        isset($event['pacote_nome']) ? (string)$event['pacote_nome'] : ''
    );
}

function glc_ensure_cardapio_infantil_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS visivel_portal_infantil BOOLEAN NOT NULL DEFAULT FALSE");
    $done = true;
}

function glc_fetch_itens_fixos_infantil(PDO $pdo, array $events): array
{
    glc_ensure_cardapio_infantil_schema($pdo);

    $pacoteIds = [];
    foreach ($events as $event) {
        if (!glc_evento_eh_infantil($event)) {
            continue;
        }
        $pacoteId = (int)($event['pacote_evento_id'] ?? 0);
        if ($pacoteId > 0) {
            $pacoteIds[$pacoteId] = $pacoteId;
        }
    }

    if (empty($pacoteIds)) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach (array_values($pacoteIds) as $index => $pacoteId) {
        $key = ':pacote_' . $index;
        $placeholders[] = $key;
        $params[$key] = $pacoteId;
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
               ps.pacote_evento_id,
               ps.secao_cardapio_id,
               s.nome AS secao_nome,
               s.ordem AS secao_ordem,
               lower(s.nome) AS secao_nome_ordem,
               ip.item_tipo,
               ip.item_id,
               CASE
                   WHEN ip.item_tipo = 'insumo' THEN i.nome_oficial
                   WHEN ip.item_tipo = 'receita' THEN r.nome
                   ELSE ''
               END AS item_nome
        FROM logistica_pacotes_evento_secoes ps
        JOIN logistica_cardapio_secoes s
            ON s.id = ps.secao_cardapio_id
           AND s.deleted_at IS NULL
           AND s.ativo = TRUE
           AND COALESCE(s.visivel_portal_infantil, FALSE) = FALSE
        JOIN logistica_cardapio_item_pacotes ip
            ON ip.pacote_evento_id = ps.pacote_evento_id
        JOIN logistica_cardapio_item_secoes isec
            ON isec.item_tipo = ip.item_tipo
           AND isec.item_id = ip.item_id
           AND isec.secao_cardapio_id = ps.secao_cardapio_id
        LEFT JOIN logistica_insumos i
            ON ip.item_tipo = 'insumo'
           AND i.id = ip.item_id
        LEFT JOIN logistica_receitas r
            ON ip.item_tipo = 'receita'
           AND r.id = ip.item_id
        WHERE ps.pacote_evento_id IN (" . implode(', ', $placeholders) . ")
          AND (
              (ip.item_tipo = 'insumo' AND i.ativo = TRUE AND COALESCE(i.visivel_na_lista, TRUE) = TRUE)
              OR
              (ip.item_tipo = 'receita' AND r.ativo = TRUE AND COALESCE(r.visivel_na_lista, TRUE) = TRUE)
          )
        ORDER BY ps.pacote_evento_id ASC, secao_ordem ASC, secao_nome_ordem ASC, item_nome ASC
    ");
    $stmt->execute($params);

    $itemsByPacote = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pacoteId = (int)($row['pacote_evento_id'] ?? 0);
        if ($pacoteId <= 0) {
            continue;
        }
        if (!isset($itemsByPacote[$pacoteId])) {
            $itemsByPacote[$pacoteId] = [];
        }
        $itemsByPacote[$pacoteId][] = $row;
    }

    $itemsByEvent = [];
    foreach ($events as $eventId => $event) {
        if (!glc_evento_eh_infantil($event)) {
            continue;
        }
        $pacoteId = (int)($event['pacote_evento_id'] ?? 0);
        $itemsByEvent[(int)$eventId] = $itemsByPacote[$pacoteId] ?? [];
    }

    return $itemsByEvent;
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
                           COALESCE(i.visivel_na_lista, TRUE) AS visivel_na_lista,
                           COALESCE(i.rendimento_base_pessoas, 100) AS rendimento_base_pessoas,
                           COALESCE(i.rendimento_quantidade_base, 1) AS rendimento_quantidade_base,
                           COALESCE(
                               NULLIF(i.calculo_lista_metodo, ''),
                               CASE
                                   WHEN COALESCE(t.calculo_por_grupo, FALSE) = TRUE
                                        AND COALESCE(i.grupo_pessoas_base, t.grupo_pessoas_base) IS NOT NULL
                                        AND COALESCE(i.grupo_quantidade_base, t.grupo_quantidade_base) IS NOT NULL
                                       THEN 'grupo'
                                   ELSE 'rendimento'
                               END
                           ) AS calculo_lista_metodo,
                           COALESCE(NULLIF(i.calculo_lista_metodo_adulto, ''), NULLIF(i.calculo_lista_metodo, ''), 'rendimento') AS calculo_lista_metodo_adulto,
                           COALESCE(NULLIF(i.calculo_lista_metodo_infantil, ''), NULLIF(i.calculo_lista_metodo, ''), 'rendimento') AS calculo_lista_metodo_infantil,
                           COALESCE(i.arredondar_rendimento_lista, FALSE) AS arredondar_rendimento_lista,
                           t.nome AS tipologia_nome,
                           COALESCE(t.visivel_na_lista, TRUE) AS tipologia_visivel_na_lista,
                           COALESCE(t.calculo_por_grupo, FALSE) AS calculo_por_grupo,
                           COALESCE(i.grupo_pessoas_base, t.grupo_pessoas_base) AS grupo_pessoas_base,
                           COALESCE(i.grupo_quantidade_base, t.grupo_quantidade_base) AS grupo_quantidade_base,
                           COALESCE(i.grupo_unidade_medida_id, t.grupo_unidade_medida_id) AS grupo_unidade_medida_id,
                           COALESCE(t.grupo_distribuir_igual, TRUE) AS grupo_distribuir_igual,
                           COALESCE(i.grupo_arredondar_inteiro, COALESCE(t.grupo_arredondar_inteiro, TRUE)) AS grupo_arredondar_inteiro,
                           COALESCE(i.grupo_aplicar_margem, COALESCE(t.grupo_aplicar_margem, TRUE)) AS grupo_aplicar_margem,
                           u.nome AS unidade_nome
                    FROM logistica_insumos i
                    LEFT JOIN logistica_tipologias_insumo t ON t.id = i.tipologia_insumo_id
                    LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
                ) x
            ), '[]'::json) AS insumos_json,
            COALESCE((
                SELECT json_agg(row_to_json(x))
                FROM (
                    SELECT id,
                           nome,
                           COALESCE(visivel_na_lista, TRUE) AS visivel_na_lista,
                           COALESCE(rendimento_base_pessoas, 100) AS rendimento_base_pessoas
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

function glc_normalize_unit_name(string $value): string
{
    $normalized = strtolower(trim($value));
    return strtr($normalized, [
        'á' => 'a',
        'à' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'é' => 'e',
        'ê' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c',
    ]);
}

function glc_find_unidade_id(array $unidades, array $nomes): ?int
{
    $normalizedNames = array_map('glc_normalize_unit_name', $nomes);
    foreach ($unidades as $id => $nome) {
        if (in_array(glc_normalize_unit_name((string)$nome), $normalizedNames, true)) {
            return (int)$id;
        }
    }
    return null;
}

function glc_normalizar_totais_cento(array $totals, array $unidades): array
{
    $centoId = glc_find_unidade_id($unidades, ['Cento']);
    $unidadeId = glc_find_unidade_id($unidades, ['Un', 'Unidade', 'Unidades']);
    if ($centoId === null || $unidadeId === null) {
        return $totals;
    }

    $normalized = [];
    foreach ($totals as $total) {
        if ((int)($total['unidade_medida_id'] ?? 0) === $centoId) {
            $total['unidade_medida_id'] = $unidadeId;
            $total['quantidade'] = (float)$total['quantidade'] * 100;
            foreach ($total['eventos'] as $eventId => $eventQty) {
                $total['eventos'][$eventId] = (float)$eventQty * 100;
            }
            foreach ($total['origens'] as $index => $origin) {
                if (isset($origin['quantidade'])) {
                    $total['origens'][$index]['quantidade'] = (float)$origin['quantidade'] * 100;
                }
            }
        }

        $key = (int)$total['insumo_id'] . ':' . ((int)($total['unidade_medida_id'] ?? 0));
        if (!isset($normalized[$key])) {
            $normalized[$key] = $total;
            continue;
        }

        $normalized[$key]['quantidade'] += (float)$total['quantidade'];
        foreach ($total['eventos'] as $eventId => $eventQty) {
            $normalized[$key]['eventos'][$eventId] = ($normalized[$key]['eventos'][$eventId] ?? 0) + (float)$eventQty;
        }
        $normalized[$key]['origens'] = array_merge($normalized[$key]['origens'], $total['origens']);
    }

    return $normalized;
}

function glc_insumo_metodo_calculo_evento(array $insumo, array $event): string
{
    $field = glc_evento_eh_infantil($event) ? 'calculo_lista_metodo_infantil' : 'calculo_lista_metodo_adulto';
    $method = (string)($insumo[$field] ?? ($insumo['calculo_lista_metodo'] ?? 'rendimento'));
    return in_array($method, ['rendimento', 'grupo'], true) ? $method : 'rendimento';
}

function glc_insumo_usa_calculo_grupo(array $insumo, array $event): bool
{
    return glc_insumo_metodo_calculo_evento($insumo, $event) === 'grupo'
        && (float)($insumo['grupo_pessoas_base'] ?? 0) > 0
        && (float)($insumo['grupo_quantidade_base'] ?? 0) > 0;
}

function glc_tipologia_permite_lista(array $insumo): bool
{
    if (!array_key_exists('tipologia_visivel_na_lista', $insumo)) {
        return true;
    }

    $value = $insumo['tipologia_visivel_na_lista'];
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return !in_array($normalized, ['', '0', 'f', 'false'], true);
}

function glc_bool_config_ativa($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return !in_array($normalized, ['', '0', 'f', 'false'], true);
}

function glc_insumo_permite_lista(array $insumo): bool
{
    return glc_bool_config_ativa($insumo['visivel_na_lista'] ?? true)
        && glc_tipologia_permite_lista($insumo);
}

function glc_receita_permite_lista(array $receita): bool
{
    return glc_bool_config_ativa($receita['visivel_na_lista'] ?? true);
}

function glc_add_totais_grupo_tipologia(
    array $event,
    array $eventItems,
    array $insumos,
    array &$totals,
    array &$warnings
): void {
    $eventId = (int)($event['id'] ?? 0);
    $convidados = max(0, (int)($event['convidados'] ?? 0));
    if ($eventId <= 0 || $convidados <= 0) {
        return;
    }

    $groups = [];
    foreach ($eventItems as $item) {
        if (strtolower(trim((string)($item['item_tipo'] ?? ''))) !== 'insumo') {
            continue;
        }

        $itemId = (int)($item['item_id'] ?? 0);
        if ($itemId <= 0 || empty($insumos[$itemId]) || !glc_insumo_usa_calculo_grupo($insumos[$itemId], $event)) {
            continue;
        }

        $insumo = $insumos[$itemId];
        if (!glc_insumo_permite_lista($insumo)) {
            continue;
        }

        $tipologiaId = (int)($insumo['tipologia_insumo_id'] ?? 0);
        if ($tipologiaId <= 0) {
            $warnings[] = 'Insumo com cálculo por grupo sem tipologia: ' . (string)($insumo['nome_oficial'] ?? ('#' . $itemId));
            continue;
        }

        if (!isset($groups[$tipologiaId])) {
            $groups[$tipologiaId] = [
                'config' => $insumo,
                'items' => [],
            ];
        }
        $groups[$tipologiaId]['items'][$itemId] = $item;
    }

    foreach ($groups as $group) {
        $items = array_values($group['items']);
        $count = count($items);
        if ($count === 0) {
            continue;
        }

        $config = $group['config'];
        $pessoasBase = max(0.001, (float)($config['grupo_pessoas_base'] ?? 0));
        $quantidadeBase = (float)($config['grupo_quantidade_base'] ?? 0);
        $totalGrupo = ($convidados / $pessoasBase) * $quantidadeBase;
        $distribuirIgual = !empty($config['grupo_distribuir_igual']);
        $baseQuantityPerItem = $distribuirIgual ? ($totalGrupo / $count) : $totalGrupo;

        $unitId = !empty($config['grupo_unidade_medida_id']) ? (int)$config['grupo_unidade_medida_id'] : null;
        foreach ($items as $item) {
            $itemId = (int)($item['item_id'] ?? 0);
            $insumo = $insumos[$itemId] ?? [];
            $quantityPerItem = $baseQuantityPerItem * (!empty($insumo['grupo_aplicar_margem']) ? GLC_MARGEM_SEGURANCA : 1.0);
            if (!empty($insumo['grupo_arredondar_inteiro'])) {
                $quantityPerItem = ceil($quantityPerItem);
            }
            $itemUnitId = $unitId ?: (!empty($insumo['unidade_medida_padrao_id']) ? (int)$insumo['unidade_medida_padrao_id'] : null);
            glc_add_total($totals, $eventId, $itemId, $itemUnitId ?: null, $quantityPerItem, [
                'tipo' => 'tipologia por grupo',
                'origem' => (string)($item['item_nome'] ?? ($insumo['nome_oficial'] ?? '')),
                'tipologia' => (string)($config['tipologia_nome'] ?? ''),
            ]);
        }
    }
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
        if (!glc_insumo_permite_lista($insumos[$itemId])) {
            continue;
        }
        if (!empty($insumos[$itemId]['arredondar_rendimento_lista'])) {
            $quantity = ceil($quantity);
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
    $fixedItemsByEvent = glc_fetch_itens_fixos_infantil($pdo, $events);

    $totals = [];
    $warnings = [];
    $eventsSummary = [];

    foreach ($events as $eventId => $event) {
        $convidados = max(0, (int)($event['convidados'] ?? 0));
        $eventItems = $itemsByResposta[(int)$event['resposta_id']] ?? [];
        foreach (($fixedItemsByEvent[(int)$eventId] ?? []) as $fixedItem) {
            $duplicateKey = (string)($fixedItem['item_tipo'] ?? '') . ':' . (int)($fixedItem['item_id'] ?? 0) . ':' . (int)($fixedItem['secao_cardapio_id'] ?? 0);
            $hasDuplicate = false;
            foreach ($eventItems as $currentItem) {
                $currentKey = (string)($currentItem['item_tipo'] ?? '') . ':' . (int)($currentItem['item_id'] ?? 0) . ':' . (int)($currentItem['secao_cardapio_id'] ?? 0);
                if ($currentKey === $duplicateKey) {
                    $hasDuplicate = true;
                    break;
                }
            }
            if (!$hasDuplicate) {
                $fixedItem['origem_fixa_infantil'] = true;
                $eventItems[] = $fixedItem;
            }
        }
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

        glc_add_totais_grupo_tipologia($event, $eventItems, $insumos, $totals, $warnings);

        foreach ($eventItems as $item) {
            $tipo = strtolower(trim((string)($item['item_tipo'] ?? '')));
            $itemId = (int)($item['item_id'] ?? 0);
            $itemName = trim((string)($item['item_nome'] ?? ''));
            if ($tipo === 'insumo') {
                if (!isset($insumos[$itemId])) {
                    $warnings[] = 'Insumo do cardápio não encontrado: ' . ($itemName ?: ('#' . $itemId));
                    continue;
                }
                if (!glc_insumo_permite_lista($insumos[$itemId])) {
                    continue;
                }
                if (glc_insumo_usa_calculo_grupo($insumos[$itemId], $event)) {
                    continue;
                }
                $yield = max(1, (int)($insumos[$itemId]['rendimento_base_pessoas'] ?? 100));
                $baseQuantity = max(0.001, (float)($insumos[$itemId]['rendimento_quantidade_base'] ?? 1));
                $quantity = ($convidados / $yield) * $baseQuantity * GLC_MARGEM_SEGURANCA;
                if (!empty($insumos[$itemId]['arredondar_rendimento_lista'])) {
                    $quantity = ceil($quantity);
                }
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
                if (!glc_receita_permite_lista($receitas[$itemId])) {
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

    $totals = glc_normalizar_totais_cento($totals, $unidades);

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

function glc_pdf_filename(array $events): string
{
    $dates = [];
    foreach ($events as $event) {
        $date = trim((string)($event['data_evento'] ?? ''));
        if ($date !== '') {
            $dates[] = date('Y-m-d', strtotime($date));
        }
    }
    $suffix = !empty($dates) ? min($dates) : date('Y-m-d');
    return 'Lista_Compras_' . $suffix . '.pdf';
}

function glc_render_pdf_html(array $events, array $calculo): string
{
    $totalsByType = [];
    foreach ($calculo['totals'] as $total) {
        $insumo = $calculo['insumos'][(int)$total['insumo_id']] ?? [];
        $typeName = trim((string)($insumo['tipologia_nome'] ?? 'Sem tipo')) ?: 'Sem tipo';
        if (!isset($totalsByType[$typeName])) {
            $totalsByType[$typeName] = [];
        }
        $totalsByType[$typeName][] = $total;
    }

    $totalGuests = 0;
    $unitNames = [];
    foreach ($events as $event) {
        $totalGuests += (int)($event['convidados'] ?? 0);
        $unitName = trim((string)($event['unidade_nome'] ?? $event['space_visivel'] ?? ''));
        if ($unitName !== '') {
            $unitNames[$unitName] = true;
        }
    }
    $unitSummary = count($unitNames) === 1 ? array_key_first($unitNames) : 'Múltiplas unidades';

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Compras</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:22px;color:#111827;font-size:12px}
.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111827;padding-bottom:12px;margin-bottom:14px}
h1{font-size:22px;margin:0 0 6px;color:#111827}
h2{font-size:15px;margin:16px 0 6px;color:#1f2937}
.meta{color:#4b5563;line-height:1.45}
.summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.pill{border:1px solid #cbd5e1;border-radius:999px;padding:3px 8px;font-size:11px;color:#334155}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border-bottom:1px solid #e5e7eb;padding:7px;text-align:left;vertical-align:top}
th{background:#f3f4f6;color:#374151;font-size:10px;text-transform:uppercase}
.right{text-align:right}
.group td{background:#eef2ff;color:#1e3a8a;font-weight:bold;text-transform:uppercase;border-top:1px solid #c7d2fe}
.warn{border:1px solid #fcd34d;background:#fffbeb;color:#92400e;padding:8px;border-radius:6px;margin:6px 0}
.no-print{margin-bottom:12px}
button{padding:7px 12px;border:0;border-radius:6px;background:#1e3a8a;color:#fff;font-weight:bold;cursor:pointer}
@media print{.no-print{display:none}body{padding:0}.header{margin-top:0}}
</style>
</head>
<body>
<div class="no-print"><button onclick="window.print()">Imprimir / salvar PDF</button></div>
<div class="header">
  <div>
    <h1>Lista de Compras</h1>
    <div class="meta">
      Gerado em <?= h(date('d/m/Y H:i')) ?> · Margem automática de 5%<br>
      <?= h($unitSummary) ?> · <?= count($events) ?> evento(s) · <?= $totalGuests ?> convidados
    </div>
  </div>
</div>

<?php if (!empty($calculo['warnings'])): ?>
  <h2>Avisos</h2>
  <?php foreach ($calculo['warnings'] as $warning): ?>
    <div class="warn"><?= h($warning) ?></div>
  <?php endforeach; ?>
<?php endif; ?>

<h2>Eventos usados no cálculo</h2>
<table>
  <thead>
    <tr>
      <th>Evento</th>
      <th>Data</th>
      <th>Hora</th>
      <th>Local</th>
      <th>Unidade</th>
      <th class="right">Convidados</th>
      <th class="right">Cardápio</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($calculo['events_summary'] as $summary): ?>
      <?php $event = $summary['event']; ?>
      <tr>
        <td><?= h($event['nome_evento'] ?? 'Evento') ?></td>
        <td><?= h(brDateOnly((string)($event['data_evento'] ?? ''))) ?></td>
        <td><?= h(substr((string)($event['hora_inicio'] ?? ''), 0, 5)) ?></td>
        <td><?= h($event['localevento'] ?? '') ?></td>
        <td><?= h($event['unidade_nome'] ?? $event['space_visivel'] ?? '') ?></td>
        <td class="right"><?= (int)($event['convidados'] ?? 0) ?></td>
        <td class="right"><?= count($summary['items'] ?? []) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h2>Necessidade bruta consolidada</h2>
<table>
  <thead>
    <tr>
      <th>Insumo</th>
      <th>Unidade</th>
      <th class="right">Quantidade total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($totalsByType as $typeName => $typeTotals): ?>
      <tr class="group">
        <td colspan="3"><?= h($typeName) ?> · <?= count($typeTotals) ?> item(ns)</td>
      </tr>
      <?php foreach ($typeTotals as $total): ?>
        <?php
        $insumo = $calculo['insumos'][(int)$total['insumo_id']] ?? [];
        $unidadeNome = !empty($total['unidade_medida_id'])
            ? (string)($calculo['unidades'][(int)$total['unidade_medida_id']] ?? '')
            : (string)($insumo['unidade_nome'] ?? '');
        ?>
        <tr>
          <td><?= h($insumo['nome_oficial'] ?? ('Insumo #' . (int)$total['insumo_id'])) ?></td>
          <td><?= h($unidadeNome) ?></td>
          <td class="right"><strong><?= glc_format_quantidade((float)$total['quantidade'], $unidadeNome) ?></strong></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
    <?php
    return ob_get_clean();
}

function glc_output_pdf(array $events, array $calculo): void
{
    $html = glc_render_pdf_html($events, $calculo);
    $autoloads = [
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];
    foreach ($autoloads as $autoload) {
        if (file_exists($autoload)) {
            require_once $autoload;
            break;
        }
    }

    if (class_exists('\\Dompdf\\Dompdf')) {
        try {
            $dompdf = new \Dompdf\Dompdf([
                'isRemoteEnabled' => true,
                'defaultPaperSize' => 'a4',
                'isHtml5ParserEnabled' => true,
            ]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $dompdf->stream(glc_pdf_filename($events), ['Attachment' => false]);
            exit;
        } catch (Throwable $e) {
            error_log('glc_output_pdf dompdf: ' . $e->getMessage());
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
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
            } elseif ($action === 'pdf') {
                glc_output_pdf($selectedEvents, $calculo);
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
    .glc-event.inactive { border-color: #fecaca; background: #fff1f2; cursor: help; }
    .glc-event.inactive strong { color: #991b1b; }
    .glc-event.inactive .glc-event-meta { color: #9f1239; }
    .glc-event.inactive input { cursor: not-allowed; }
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
    .glc-group-row td { background: #eef2ff; color: #1e3a8a; font-weight: 900; text-transform: uppercase; letter-spacing: .02em; border-top: 1px solid #c7d2fe; }
    .glc-group-count { color: #64748b; font-size: .78rem; font-weight: 800; text-transform: none; margin-left: .35rem; }
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
            <p class="glc-muted">Mostrando eventos dos próximos 30 dias. Eventos em vermelho ainda não podem entrar no cálculo.</p>
            <div class="glc-toolbar">
                <input class="glc-search" id="glcSearch" type="search" placeholder="Buscar por evento, data, local ou unidade">
                <button class="glc-btn secondary" type="button" onclick="glcClearSelection()">Limpar seleção</button>
                <span class="glc-muted" id="glcSelectedCount">0 selecionado(s)</span>
            </div>

            <?php if (empty($eventosDisponiveis)): ?>
                <p class="glc-muted">Nenhum evento foi encontrado para sua unidade nos próximos 30 dias.</p>
            <?php else: ?>
                <div class="glc-events" id="glcEvents">
                    <?php foreach ($eventosDisponiveis as $evento): ?>
                        <?php
                        $eventId = (int)$evento['id'];
                        $isAvailable = !array_key_exists('disponivel_calculo', $evento) || !empty($evento['disponivel_calculo']);
                        $motivoIndisponivel = trim((string)($evento['motivo_indisponivel'] ?? 'Evento indisponível para cálculo'));
                        $checked = $isAvailable && in_array($eventId, $selectedIds, true);
                        $searchText = strtolower(implode(' ', [
                            $evento['nome_evento'] ?? '',
                            $evento['data_evento'] ?? '',
                            brDateOnly((string)($evento['data_evento'] ?? '')),
                            $evento['localevento'] ?? '',
                            $evento['space_visivel'] ?? '',
                            $evento['unidade_nome'] ?? '',
                            $motivoIndisponivel,
                        ]));
                        ?>
                        <label class="glc-event<?= $isAvailable ? '' : ' inactive' ?>" data-search="<?= h($searchText) ?>" title="<?= $isAvailable ? '' : h($motivoIndisponivel) ?>">
                            <input type="checkbox" name="event_ids[]" value="<?= $eventId ?>" <?= $checked ? 'checked' : '' ?> <?= $isAvailable ? '' : 'disabled' ?> onchange="glcUpdateCount()">
                            <span>
                                <strong><?= h($evento['nome_evento'] ?? 'Evento') ?></strong>
                                <span class="glc-event-meta">
                                    <?= h(brDateOnly((string)($evento['data_evento'] ?? ''))) ?>
                                    <?= h(substr((string)($evento['hora_inicio'] ?? ''), 0, 5)) ?><br>
                                    <?= h($evento['localevento'] ?? '') ?><br>
                                    <?= h($evento['unidade_nome'] ?? $evento['space_visivel'] ?? '') ?>
                                    · <?= (int)($evento['convidados'] ?? 0) ?> convidados
                                    · <?= (int)($evento['cardapio_itens'] ?? 0) ?> item(ns)
                                    <?php if (!$isAvailable): ?>
                                        <br><strong><?= h($motivoIndisponivel) ?></strong>
                                    <?php endif; ?>
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
                <button class="glc-btn secondary" type="submit" formtarget="_blank" id="glcPdfBtn" onclick="return glcSubmit('pdf', this)">Exportar PDF</button>
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
            <?php if (empty($calculo['totals'])): ?>
                <p class="glc-muted">Nenhum item calculado.</p>
            <?php else: ?>
                <?php
                $totalsByType = [];
                foreach ($calculo['totals'] as $total) {
                    $insumo = $calculo['insumos'][(int)$total['insumo_id']] ?? [];
                    $typeName = trim((string)($insumo['tipologia_nome'] ?? 'Sem tipo')) ?: 'Sem tipo';
                    if (!isset($totalsByType[$typeName])) {
                        $totalsByType[$typeName] = [];
                    }
                    $totalsByType[$typeName][] = $total;
                }
                ?>
                <div class="glc-table-wrap">
                    <table class="glc-table">
                        <thead>
                            <tr>
                                <th>Insumo</th>
                                <th>Unidade</th>
                                <th>Quantidade total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($totalsByType as $typeName => $typeTotals): ?>
                                <tr class="glc-group-row">
                                    <td colspan="3">
                                        <?= h($typeName) ?>
                                        <span class="glc-group-count"><?= count($typeTotals) ?> item(ns)</span>
                                    </td>
                                </tr>
                                <?php foreach ($typeTotals as $total): ?>
                                    <?php
                                    $insumo = $calculo['insumos'][(int)$total['insumo_id']] ?? [];
                                    $unidadeNome = !empty($total['unidade_medida_id'])
                                        ? (string)($calculo['unidades'][(int)$total['unidade_medida_id']] ?? '')
                                        : (string)($insumo['unidade_nome'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?= h($insumo['nome_oficial'] ?? ('Insumo #' . (int)$total['insumo_id'])) ?></td>
                                        <td><?= h($unidadeNome) ?></td>
                                        <td><strong><?= glc_format_quantidade((float)$total['quantidade'], $unidadeNome) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
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
