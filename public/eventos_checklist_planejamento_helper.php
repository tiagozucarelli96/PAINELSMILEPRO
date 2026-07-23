<?php
/**
 * Domínio do checklist de planejamento.
 *
 * Este módulo é deliberadamente independente de checklists_operacionais.
 */

declare(strict_types=1);

function eventos_checklist_planejamento_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $schemaReady = $pdo->query("
            SELECT
                to_regclass('eventos_checklist_modelos') IS NOT NULL
                AND to_regclass('eventos_checklist_modelo_tarefas') IS NOT NULL
                AND to_regclass('eventos_checklist_tarefas') IS NOT NULL
                AND to_regclass('eventos_checklist_historico') IS NOT NULL
                AND to_regclass('eventos_checklist_config') IS NOT NULL
                AND EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_schema = CURRENT_SCHEMA()
                      AND table_name = 'comercial_eventos_painel'
                      AND column_name = 'pacote_evento_id'
                )
                AND EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_schema = CURRENT_SCHEMA()
                      AND table_name = 'comercial_eventos_painel'
                      AND column_name = 'status'
                )
        ")->fetchColumn();
        if (!empty($schemaReady)) {
            $done = true;
            return;
        }
    } catch (Throwable $ignored) {
    }

    $sql = file_get_contents(__DIR__ . '/../sql/108_eventos_checklist_planejamento.sql');
    if ($sql === false) {
        throw new RuntimeException('Migração do checklist de planejamento não encontrada.');
    }
    $pdo->exec($sql);
    $done = true;
}

function eventos_checklist_planejamento_user_id(): int
{
    return (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? $_SESSION['id_usuario'] ?? 0);
}

function eventos_checklist_planejamento_is_superadmin(): bool
{
    return !empty($_SESSION['perm_superadmin']);
}

function eventos_checklist_planejamento_json($value): ?string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function eventos_checklist_planejamento_evento(PDO $pdo, int $eventoId): ?array
{
    eventos_checklist_planejamento_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT e.id,
               e.data_evento::text AS data_evento,
               COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento') AS nome_evento,
               COALESCE(NULLIF(TRIM(e.space_visivel), ''), NULLIF(TRIM(e.localevento), ''), 'Unidade não informada') AS unidade,
               COALESCE(e.cliente_cadastro_id, 0) AS cliente_cadastro_id,
               COALESCE(NULLIF(TRIM(c.nome_completo), ''), 'Cliente') AS cliente_nome,
               COALESCE(NULLIF(TRIM(c.telefone_whatsapp), ''), NULLIF(TRIM(e.whatsapp_cliente), ''), NULLIF(TRIM(e.telefone_cliente), ''), '') AS cliente_whatsapp,
               COALESCE(NULLIF(TRIM(cep.tipo_evento_real), ''), '') AS tipo_evento_real,
               COALESCE(cep.pacote_evento_id, 0) AS pacote_evento_id,
               COALESCE(cep.created_at::date, e.synced_at::date, CURRENT_DATE) AS data_cadastro,
               COALESCE(NULLIF(TRIM(p.nome), ''), '') AS pacote_nome,
               COALESCE(NULLIF(TRIM(cep.status), ''), 'criado_painel') AS painel_status
        FROM logistica_eventos_espelho e
        LEFT JOIN LATERAL (
            SELECT tipo_evento_real, pacote_evento_id, created_at, status
            FROM comercial_eventos_painel
            WHERE espelho_evento_id = e.id
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ) cep ON TRUE
        LEFT JOIN comercial_cadastro_clientes c ON c.id = e.cliente_cadastro_id
        LEFT JOIN logistica_pacotes_evento p ON p.id = cep.pacote_evento_id
        WHERE e.id = :id
          AND COALESCE(e.arquivado, FALSE) = FALSE
        LIMIT 1
    ");
    $stmt->execute([':id' => $eventoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function eventos_checklist_planejamento_calcular_vencimento(
    string $regra,
    int $dias,
    string $dataEvento,
    string $dataCadastro,
    ?string $dataInsercao = null
): ?string {
    $dias = max(0, $dias);
    $dataInsercao = $dataInsercao ?: date('Y-m-d');

    if ($regra === 'sem_data') {
        return null;
    }

    $base = $dataEvento;
    $sinal = 1;
    if ($regra === 'antes_evento') {
        $sinal = -1;
    } elseif ($regra === 'depois_cadastro') {
        $base = $dataCadastro;
    } elseif ($regra === 'depois_insercao') {
        $base = $dataInsercao;
    }

    if ($regra === 'dia_evento') {
        $dias = 0;
    }

    try {
        $date = new DateTimeImmutable($base);
        if ($dias > 0) {
            $date = $date->modify(($sinal < 0 ? '-' : '+') . $dias . ' days');
        }
        return $date->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function eventos_checklist_planejamento_historico(
    PDO $pdo,
    int $tarefaId,
    int $eventoId,
    string $acao,
    string $detalhes = '',
    ?array $antes = null,
    ?array $depois = null,
    int $atorUsuarioId = 0,
    string $atorTipo = 'interno'
): void {
    $stmt = $pdo->prepare("
        INSERT INTO eventos_checklist_historico
            (tarefa_id, evento_id, acao, detalhes, dados_antes, dados_depois, ator_usuario_id, ator_tipo)
        VALUES
            (:tarefa_id, :evento_id, :acao, :detalhes, CAST(:antes AS jsonb), CAST(:depois AS jsonb), :ator, :ator_tipo)
    ");
    $stmt->execute([
        ':tarefa_id' => $tarefaId,
        ':evento_id' => $eventoId,
        ':acao' => $acao,
        ':detalhes' => $detalhes,
        ':antes' => eventos_checklist_planejamento_json($antes),
        ':depois' => eventos_checklist_planejamento_json($depois),
        ':ator' => $atorUsuarioId > 0 ? $atorUsuarioId : null,
        ':ator_tipo' => in_array($atorTipo, ['interno', 'cliente', 'sistema'], true) ? $atorTipo : 'interno',
    ]);
}

function eventos_checklist_planejamento_gerar_para_evento(PDO $pdo, int $eventoId, int $atorUsuarioId = 0): array
{
    eventos_checklist_planejamento_ensure_schema($pdo);
    $evento = eventos_checklist_planejamento_evento($pdo, $eventoId);
    if (!$evento) {
        return ['ok' => false, 'error' => 'Evento não encontrado.', 'adicionadas' => 0];
    }
    if ((string)($evento['painel_status'] ?? '') === 'cancelado') {
        return ['ok' => false, 'error' => 'Reative o evento antes de gerar novas tarefas.', 'adicionadas' => 0];
    }

    $tipo = trim((string)($evento['tipo_evento_real'] ?? ''));
    $pacoteId = (int)($evento['pacote_evento_id'] ?? 0);
    if ($tipo === '' || $pacoteId <= 0) {
        return ['ok' => false, 'error' => 'Tipo e pacote são obrigatórios para gerar o checklist.', 'adicionadas' => 0];
    }

    $stmt = $pdo->prepare("
        SELECT m.id AS modelo_id, m.origem, m.versao,
               t.id AS modelo_tarefa_id, t.titulo, t.descricao, t.ordem,
               t.responsabilidade, t.responsavel_usuario_id, t.responsavel_setor,
               t.visivel_cliente, t.exige_validacao, t.regra_vencimento,
               t.dias, t.whatsapp_mensagem
        FROM eventos_checklist_modelos m
        JOIN eventos_checklist_modelo_tarefas t ON t.modelo_id = m.id AND t.ativo = TRUE
        WHERE m.ativo = TRUE
          AND m.deleted_at IS NULL
          AND (
              (m.origem = 'tipo' AND m.tipo_evento_key = :tipo)
              OR
              (m.origem = 'pacote' AND m.pacote_evento_id = :pacote_id)
          )
        ORDER BY CASE WHEN m.origem = 'tipo' THEN 1 ELSE 2 END, m.id, t.ordem, t.id
    ");
    $stmt->execute([':tipo' => $tipo, ':pacote_id' => $pacoteId]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $insert = $pdo->prepare("
        INSERT INTO eventos_checklist_tarefas (
            evento_id, modelo_id, modelo_tarefa_id, modelo_versao, origem_modelo,
            titulo, descricao, ordem, responsabilidade, responsavel_usuario_id,
            responsavel_setor, visivel_cliente, exige_validacao, regra_vencimento,
            dias, vencimento, whatsapp_mensagem, status, created_at, updated_at
        ) VALUES (
            :evento_id, :modelo_id, :modelo_tarefa_id, :modelo_versao, :origem_modelo,
            :titulo, :descricao, :ordem, :responsabilidade, :responsavel_usuario_id,
            :responsavel_setor, :visivel_cliente, :exige_validacao, :regra_vencimento,
            :dias, :vencimento, :whatsapp_mensagem, 'pendente', NOW(), NOW()
        )
        ON CONFLICT (evento_id, modelo_tarefa_id) WHERE modelo_tarefa_id IS NOT NULL DO NOTHING
        RETURNING id
    ");

    $adicionadas = 0;
    foreach ($templates as $template) {
        $vencimento = eventos_checklist_planejamento_calcular_vencimento(
            (string)$template['regra_vencimento'],
            (int)$template['dias'],
            (string)$evento['data_evento'],
            (string)$evento['data_cadastro']
        );
        $insert->execute([
            ':evento_id' => $eventoId,
            ':modelo_id' => (int)$template['modelo_id'],
            ':modelo_tarefa_id' => (int)$template['modelo_tarefa_id'],
            ':modelo_versao' => (int)$template['versao'],
            ':origem_modelo' => (string)$template['origem'],
            ':titulo' => (string)$template['titulo'],
            ':descricao' => (string)$template['descricao'],
            ':ordem' => (int)$template['ordem'],
            ':responsabilidade' => (string)$template['responsabilidade'],
            ':responsavel_usuario_id' => !empty($template['responsavel_usuario_id']) ? (int)$template['responsavel_usuario_id'] : null,
            ':responsavel_setor' => trim((string)$template['responsavel_setor']) ?: null,
            ':visivel_cliente' => !empty($template['visivel_cliente']) ? 't' : 'f',
            ':exige_validacao' => !empty($template['exige_validacao']) ? 't' : 'f',
            ':regra_vencimento' => (string)$template['regra_vencimento'],
            ':dias' => (int)$template['dias'],
            ':vencimento' => $vencimento,
            ':whatsapp_mensagem' => (string)$template['whatsapp_mensagem'],
        ]);
        $tarefaId = (int)$insert->fetchColumn();
        if ($tarefaId > 0) {
            $adicionadas++;
            eventos_checklist_planejamento_historico(
                $pdo,
                $tarefaId,
                $eventoId,
                'criada_do_modelo',
                'Tarefa criada automaticamente a partir do modelo.',
                null,
                ['modelo_tarefa_id' => (int)$template['modelo_tarefa_id'], 'vencimento' => $vencimento],
                $atorUsuarioId,
                $atorUsuarioId > 0 ? 'interno' : 'sistema'
            );
        }
    }

    return ['ok' => true, 'adicionadas' => $adicionadas, 'modelos_encontrados' => count($templates)];
}

function eventos_checklist_planejamento_resumo_evento(PDO $pdo, int $eventoId): array
{
    eventos_checklist_planejamento_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT COUNT(*)::int AS total,
               COUNT(*) FILTER (WHERE status = 'concluida')::int AS concluidas,
               COUNT(*) FILTER (WHERE status = 'desativada')::int AS desativadas,
               COUNT(*) FILTER (WHERE status IN ('pendente', 'em_andamento', 'aguardando_validacao'))::int AS abertas,
               COUNT(*) FILTER (
                   WHERE status IN ('pendente', 'em_andamento', 'aguardando_validacao')
                     AND vencimento < CURRENT_DATE
               )::int AS atrasadas
        FROM eventos_checklist_tarefas
        WHERE evento_id = :evento_id
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalConsiderado = max(0, (int)($row['total'] ?? 0) - (int)($row['desativadas'] ?? 0));
    $concluidas = (int)($row['concluidas'] ?? 0);
    $row['percentual'] = $totalConsiderado > 0 ? (int)round(($concluidas / $totalConsiderado) * 100) : 0;
    return $row + ['total' => 0, 'concluidas' => 0, 'desativadas' => 0, 'abertas' => 0, 'atrasadas' => 0, 'percentual' => 0];
}

function eventos_checklist_planejamento_usuario(PDO $pdo, int $usuarioId): array
{
    if ($usuarioId <= 0) {
        return ['id' => 0, 'cargo' => ''];
    }
    $stmt = $pdo->prepare("SELECT id, COALESCE(NULLIF(TRIM(cargo), ''), '') AS cargo FROM usuarios WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $usuarioId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $usuarioId, 'cargo' => ''];
}

function eventos_checklist_planejamento_pode_editar(PDO $pdo, array $tarefa, int $usuarioId, bool $isSuperadmin): bool
{
    if ($isSuperadmin) {
        return true;
    }
    if ($usuarioId <= 0 || (string)($tarefa['responsabilidade'] ?? '') === 'cliente') {
        return false;
    }
    if ((string)($tarefa['responsabilidade'] ?? '') === 'usuario') {
        return (int)($tarefa['responsavel_usuario_id'] ?? 0) === $usuarioId;
    }
    if ((string)($tarefa['responsabilidade'] ?? '') === 'setor') {
        $usuario = eventos_checklist_planejamento_usuario($pdo, $usuarioId);
        return mb_strtolower(trim((string)($usuario['cargo'] ?? '')), 'UTF-8')
            === mb_strtolower(trim((string)($tarefa['responsavel_setor'] ?? '')), 'UTF-8');
    }
    return false;
}

function eventos_checklist_planejamento_recalcular_evento(PDO $pdo, int $eventoId, int $atorUsuarioId = 0): int
{
    eventos_checklist_planejamento_ensure_schema($pdo);
    $evento = eventos_checklist_planejamento_evento($pdo, $eventoId);
    if (!$evento) {
        return 0;
    }
    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_checklist_tarefas
        WHERE evento_id = :evento_id
          AND status NOT IN ('concluida', 'desativada')
          AND vencimento_manual = FALSE
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $update = $pdo->prepare("UPDATE eventos_checklist_tarefas SET vencimento = :vencimento, updated_at = NOW() WHERE id = :id");
    $alteradas = 0;
    foreach ($tarefas as $tarefa) {
        $novo = eventos_checklist_planejamento_calcular_vencimento(
            (string)$tarefa['regra_vencimento'],
            (int)$tarefa['dias'],
            (string)$evento['data_evento'],
            (string)$evento['data_cadastro'],
            substr((string)$tarefa['created_at'], 0, 10)
        );
        $anterior = $tarefa['vencimento'] !== null ? (string)$tarefa['vencimento'] : null;
        if ($novo === $anterior) {
            continue;
        }
        $update->execute([':vencimento' => $novo, ':id' => (int)$tarefa['id']]);
        eventos_checklist_planejamento_historico(
            $pdo,
            (int)$tarefa['id'],
            $eventoId,
            'vencimento_recalculado',
            'Vencimento recalculado após alteração da data do evento.',
            ['vencimento' => $anterior],
            ['vencimento' => $novo],
            $atorUsuarioId,
            $atorUsuarioId > 0 ? 'interno' : 'sistema'
        );
        $alteradas++;
    }
    return $alteradas;
}

function eventos_checklist_planejamento_definir_cancelamento(
    PDO $pdo,
    int $eventoId,
    bool $cancelado,
    int $atorUsuarioId = 0
): int {
    eventos_checklist_planejamento_ensure_schema($pdo);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare("
            UPDATE comercial_eventos_painel
            SET status = :status, updated_at = NOW()
            WHERE id = (
                SELECT id
                FROM comercial_eventos_painel
                WHERE espelho_evento_id = :evento_id
                ORDER BY updated_at DESC NULLS LAST, id DESC
                LIMIT 1
            )
        ")->execute([
            ':status' => $cancelado ? 'cancelado' : 'criado_painel',
            ':evento_id' => $eventoId,
        ]);

        $sql = $cancelado
            ? "
                SELECT *
                FROM eventos_checklist_tarefas
                WHERE evento_id = :evento_id
                  AND status IN ('pendente', 'em_andamento', 'aguardando_validacao')
                FOR UPDATE
            "
            : "
                SELECT *
                FROM eventos_checklist_tarefas
                WHERE evento_id = :evento_id
                  AND status = 'desativada'
                  AND motivo_desativacao = 'Evento cancelado'
                FOR UPDATE
            ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':evento_id' => $eventoId]);
        $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $disable = $pdo->prepare("
            UPDATE eventos_checklist_tarefas
            SET status = 'desativada', desativada_em = NOW(), desativada_por = :ator,
                motivo_desativacao = 'Evento cancelado', updated_at = NOW()
            WHERE id = :id
        ");
        $reactivate = $pdo->prepare("
            UPDATE eventos_checklist_tarefas
            SET status = 'pendente', desativada_em = NULL, desativada_por = NULL,
                motivo_desativacao = '', updated_at = NOW()
            WHERE id = :id
        ");

        foreach ($tarefas as $tarefa) {
            if ($cancelado) {
                $disable->execute([
                    ':ator' => $atorUsuarioId > 0 ? $atorUsuarioId : null,
                    ':id' => (int)$tarefa['id'],
                ]);
            } else {
                $reactivate->execute([':id' => (int)$tarefa['id']]);
            }
            eventos_checklist_planejamento_historico(
                $pdo,
                (int)$tarefa['id'],
                $eventoId,
                $cancelado ? 'desativada_evento_cancelado' : 'reativada_evento',
                $cancelado
                    ? 'Tarefa desativada pelo cancelamento do evento.'
                    : 'Tarefa reativada após reativação do evento.',
                ['status' => $tarefa['status']],
                ['status' => $cancelado ? 'desativada' : 'pendente'],
                $atorUsuarioId,
                $atorUsuarioId > 0 ? 'interno' : 'sistema'
            );
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return count($tarefas);
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function eventos_checklist_planejamento_listar_minhas(PDO $pdo, int $usuarioId, int $limit = 30): array
{
    eventos_checklist_planejamento_ensure_schema($pdo);
    $usuario = eventos_checklist_planejamento_usuario($pdo, $usuarioId);
    $cargo = trim((string)($usuario['cargo'] ?? ''));
    $stmt = $pdo->prepare("
        SELECT t.*,
               e.nome_evento,
               e.nome_evento AS evento_nome,
               e.data_evento::text AS data_evento,
               t.vencimento::text AS data_vencimento,
               u.nome AS responsavel_nome
        FROM eventos_checklist_tarefas t
        JOIN logistica_eventos_espelho e ON e.id = t.evento_id
        LEFT JOIN usuarios u ON u.id = t.responsavel_usuario_id
        WHERE t.status IN ('pendente', 'em_andamento', 'aguardando_validacao')
          AND t.vencimento IS NOT NULL
          AND t.vencimento <= CURRENT_DATE
          AND COALESCE(e.arquivado, FALSE) = FALSE
          AND (
              (t.responsabilidade = 'usuario' AND t.responsavel_usuario_id = :usuario_id)
              OR
              (t.responsabilidade = 'setor' AND LOWER(COALESCE(t.responsavel_setor, '')) = LOWER(:cargo))
          )
        ORDER BY t.vencimento ASC, e.data_evento ASC, t.ordem ASC, t.id ASC
        LIMIT :limite
    ");
    $stmt->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
    $stmt->bindValue(':cargo', $cargo);
    $stmt->bindValue(':limite', max(1, min(100, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_checklist_planejamento_config(PDO $pdo): array
{
    eventos_checklist_planejamento_ensure_schema($pdo);
    $row = $pdo->query("SELECT * FROM eventos_checklist_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    return $row ?: [
        'portal_cliente_ativo' => false,
        'whatsapp_cliente_ativo' => false,
        'whatsapp_hora' => '09:00:00',
    ];
}
