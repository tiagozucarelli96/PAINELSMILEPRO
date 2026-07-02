<?php
/**
 * Pendências de eventos criados diretamente na ME.
 *
 * O webhook cria a reunião/portal automaticamente e registra uma pendência
 * para o comercial completar pacote e dados mínimos de PJ.
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_me_helper.php';

function me_eventos_pendencias_marker_fresh(): bool
{
    $path = sys_get_temp_dir() . '/me_eventos_pendencias_schema_ready';
    $mtime = @filemtime($path);
    return $mtime !== false && (time() - $mtime) < 900;
}

function me_eventos_pendencias_touch_marker(): void
{
    @touch(sys_get_temp_dir() . '/me_eventos_pendencias_schema_ready');
}

function me_eventos_pendencias_ensure_schema(PDO $pdo): void
{
    if (me_eventos_pendencias_marker_fresh()) {
        return;
    }

    require_once __DIR__ . '/eventos_reuniao_helper.php';
    require_once __DIR__ . '/pacotes_evento_helper.php';
    if (function_exists('eventos_reuniao_ensure_schema')) {
        eventos_reuniao_ensure_schema($pdo);
    }
    if (function_exists('pacotes_evento_ensure_schema')) {
        pacotes_evento_ensure_schema($pdo);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS me_eventos_pendencias_comercial (
            id BIGSERIAL PRIMARY KEY,
            me_event_id BIGINT NOT NULL UNIQUE,
            meeting_id BIGINT NULL REFERENCES eventos_reunioes(id) ON DELETE SET NULL,
            portal_id BIGINT NULL REFERENCES eventos_cliente_portais(id) ON DELETE SET NULL,
            evento_nome VARCHAR(255) NULL,
            data_evento DATE NULL,
            cliente_nome VARCHAR(255) NULL,
            cliente_email VARCHAR(255) NULL,
            cliente_telefone VARCHAR(60) NULL,
            origem VARCHAR(40) NOT NULL DEFAULT 'me_manual',
            status VARCHAR(20) NOT NULL DEFAULT 'pendente',
            payload_json JSONB NULL,
            tipo_evento_real VARCHAR(60) NULL,
            pacote_evento_id BIGINT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE SET NULL,
            pj_razao_social VARCHAR(255) NULL,
            pj_nome_fantasia VARCHAR(255) NULL,
            pj_cnpj VARCHAR(20) NULL,
            pj_responsavel_nome VARCHAR(255) NULL,
            pj_responsavel_cpf VARCHAR(20) NULL,
            observacoes TEXT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            completed_at TIMESTAMPTZ NULL,
            completed_by_user_id BIGINT NULL REFERENCES usuarios(id) ON DELETE SET NULL
        )
    ");
    $pdo->exec("ALTER TABLE me_eventos_pendencias_comercial ADD COLUMN IF NOT EXISTS tipo_evento_real VARCHAR(60) NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_me_eventos_pendencias_status ON me_eventos_pendencias_comercial(status, created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_me_eventos_pendencias_me_event ON me_eventos_pendencias_comercial(me_event_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_me_eventos_pendencias_meeting ON me_eventos_pendencias_comercial(meeting_id)");

    me_eventos_pendencias_touch_marker();
}

function me_eventos_pendencias_pick(array $data, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && trim((string)$data[$key]) !== '') {
            return trim((string)$data[$key]);
        }
    }
    return $default;
}

function me_eventos_pendencias_normalizar_data(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}

function me_eventos_pendencias_registrar(
    PDO $pdo,
    int $meEventId,
    ?int $meetingId,
    ?int $portalId,
    array $eventoData,
    string $origem = 'me_manual'
): array {
    me_eventos_pendencias_ensure_schema($pdo);
    if ($meEventId <= 0) {
        return ['ok' => false, 'error' => 'Evento ME inválido.'];
    }
    if (eventos_me_evento_cancelado($eventoData) || eventos_me_evento_cancelado_por_webhook($pdo, $meEventId)) {
        me_eventos_pendencias_cancelar_por_me_evento($pdo, $meEventId, 'Evento cancelado na ME.');
        return ['ok' => false, 'error' => 'Evento cancelado na ME; pendência comercial não criada.'];
    }

    $nomeEvento = me_eventos_pendencias_pick($eventoData, ['nomeevento', 'nome_evento', 'nome'], 'Evento sem nome');
    $dataEvento = me_eventos_pendencias_normalizar_data(me_eventos_pendencias_pick($eventoData, ['dataevento', 'data_evento', 'data']));
    $clienteNome = me_eventos_pendencias_pick($eventoData, ['client_name', 'nomecliente', 'cliente_nome', 'nome_cliente']);
    $clienteEmail = me_eventos_pendencias_pick($eventoData, ['client_email', 'emailcliente', 'cliente_email', 'email_cliente']);
    $clienteTelefone = me_eventos_pendencias_pick($eventoData, ['client_phone', 'client_whatsapp', 'telefonecliente', 'celularcliente', 'cliente_telefone']);
    $payloadJson = json_encode($eventoData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO me_eventos_pendencias_comercial (
                me_event_id, meeting_id, portal_id, evento_nome, data_evento,
                cliente_nome, cliente_email, cliente_telefone, origem, status,
                payload_json, created_at, updated_at
            ) VALUES (
                :me_event_id, :meeting_id, :portal_id, :evento_nome, :data_evento,
                :cliente_nome, :cliente_email, :cliente_telefone, :origem, 'pendente',
                CAST(:payload_json AS jsonb), NOW(), NOW()
            )
            ON CONFLICT (me_event_id) DO UPDATE SET
                meeting_id = COALESCE(EXCLUDED.meeting_id, me_eventos_pendencias_comercial.meeting_id),
                portal_id = COALESCE(EXCLUDED.portal_id, me_eventos_pendencias_comercial.portal_id),
                evento_nome = EXCLUDED.evento_nome,
                data_evento = EXCLUDED.data_evento,
                cliente_nome = COALESCE(NULLIF(EXCLUDED.cliente_nome, ''), me_eventos_pendencias_comercial.cliente_nome),
                cliente_email = COALESCE(NULLIF(EXCLUDED.cliente_email, ''), me_eventos_pendencias_comercial.cliente_email),
                cliente_telefone = COALESCE(NULLIF(EXCLUDED.cliente_telefone, ''), me_eventos_pendencias_comercial.cliente_telefone),
                payload_json = EXCLUDED.payload_json,
                updated_at = NOW()
            RETURNING *
        ");
        $stmt->execute([
            ':me_event_id' => $meEventId,
            ':meeting_id' => $meetingId && $meetingId > 0 ? $meetingId : null,
            ':portal_id' => $portalId && $portalId > 0 ? $portalId : null,
            ':evento_nome' => $nomeEvento,
            ':data_evento' => $dataEvento,
            ':cliente_nome' => $clienteNome !== '' ? $clienteNome : null,
            ':cliente_email' => $clienteEmail !== '' ? $clienteEmail : null,
            ':cliente_telefone' => $clienteTelefone !== '' ? $clienteTelefone : null,
            ':origem' => substr($origem, 0, 40),
            ':payload_json' => $payloadJson ?: '{}',
        ]);
        return ['ok' => true, 'pendencia' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null];
    } catch (Throwable $e) {
        error_log('me_eventos_pendencias_registrar: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao registrar pendência comercial.'];
    }
}

function me_eventos_pendencias_cancelar_por_me_evento(PDO $pdo, int $meEventId, string $motivo = 'Evento cancelado na ME.'): array
{
    me_eventos_pendencias_ensure_schema($pdo);
    if ($meEventId <= 0) {
        return ['ok' => false, 'updated' => 0];
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE me_eventos_pendencias_comercial
            SET status = 'cancelado',
                observacoes = TRIM(BOTH E'\n' FROM CONCAT(COALESCE(observacoes, ''), CASE WHEN COALESCE(observacoes, '') = '' THEN '' ELSE E'\n' END, CAST(:motivo AS text))),
                updated_at = NOW()
            WHERE me_event_id = :me_event_id
              AND status = 'pendente'
        ");
        $stmt->execute([
            ':me_event_id' => $meEventId,
            ':motivo' => $motivo,
        ]);
        return ['ok' => true, 'updated' => $stmt->rowCount()];
    } catch (Throwable $e) {
        error_log('me_eventos_pendencias_cancelar_por_me_evento: ' . $e->getMessage());
        return ['ok' => false, 'updated' => 0, 'error' => $e->getMessage()];
    }
}

function me_eventos_pendencias_row_cancelada(PDO $pdo, array $row): bool
{
    $meEventId = (int)($row['me_event_id'] ?? 0);

    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    if (is_array($payload) && eventos_me_evento_cancelado($payload)) {
        return true;
    }

    $snapshot = json_decode((string)($row['me_event_snapshot'] ?? ''), true);
    if (is_array($snapshot) && eventos_me_evento_cancelado($snapshot)) {
        return true;
    }

    if ($meEventId > 0 && eventos_me_evento_cancelado_por_webhook($pdo, $meEventId)) {
        return true;
    }

    if ($meEventId > 0 && function_exists('eventos_me_request')) {
        $resp = eventos_me_request('GET', '/api/v1/events/' . $meEventId);
        if (empty($resp['ok']) && in_array((int)($resp['code'] ?? 0), [404, 410], true)) {
            return true;
        }
        if (!empty($resp['ok'])) {
            $event = $resp['data']['data'] ?? $resp['data'] ?? null;
            if (is_array($event) && eventos_me_evento_cancelado($event)) {
                return true;
            }
        }
    }

    return false;
}

function me_eventos_pendencias_buscar_proxima(PDO $pdo): ?array
{
    me_eventos_pendencias_ensure_schema($pdo);
    $stmt = $pdo->query("
        SELECT p.*, r.pacote_evento_id, r.me_event_snapshot
        FROM me_eventos_pendencias_comercial p
        LEFT JOIN eventos_reunioes r ON r.id = p.meeting_id
        WHERE p.status = 'pendente'
          AND NOT EXISTS (
              SELECT 1
              FROM vendas_pre_contratos v
              WHERE v.me_event_id = p.me_event_id
              LIMIT 1
          )
        ORDER BY p.created_at ASC, p.id ASC
        LIMIT 100
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (me_eventos_pendencias_row_cancelada($pdo, $row)) {
            me_eventos_pendencias_cancelar_por_me_evento($pdo, (int)($row['me_event_id'] ?? 0), 'Evento cancelado na ME; aviso comercial arquivado automaticamente.');
            continue;
        }
        unset($row['me_event_snapshot']);
        return $row;
    }
    return null;
}

function me_eventos_pendencias_concluir(PDO $pdo, int $pendenciaId, array $dados, int $userId): array
{
    me_eventos_pendencias_ensure_schema($pdo);
    if ($pendenciaId <= 0) {
        return ['ok' => false, 'error' => 'Pendência inválida.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM me_eventos_pendencias_comercial WHERE id = :id AND status = 'pendente' LIMIT 1");
    $stmt->execute([':id' => $pendenciaId]);
    $pendencia = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$pendencia) {
        return ['ok' => false, 'error' => 'Pendência não encontrada ou já concluída.'];
    }

    $tipoEventoReal = eventos_reuniao_normalizar_tipo_evento_real((string)($dados['tipo_evento_real'] ?? ''), $pdo);
    if ($tipoEventoReal === '') {
        return ['ok' => false, 'error' => 'Informe o tipo real do evento.'];
    }

    $pacoteId = (int)($dados['pacote_evento_id'] ?? 0);
    if ($pacoteId <= 0) {
        return ['ok' => false, 'error' => 'Informe o pacote do evento.'];
    }

    require_once __DIR__ . '/pacotes_evento_helper.php';
    $pacote = pacotes_evento_get($pdo, $pacoteId);
    if (!$pacote) {
        return ['ok' => false, 'error' => 'Pacote do evento não encontrado.'];
    }

    $razaoSocial = trim((string)($dados['pj_razao_social'] ?? ''));
    $cnpj = preg_replace('/\D+/', '', (string)($dados['pj_cnpj'] ?? ''));
    $responsavelNome = trim((string)($dados['pj_responsavel_nome'] ?? ''));

    if ($razaoSocial === '') {
        return ['ok' => false, 'error' => 'Informe a razão social da pessoa jurídica.'];
    }
    if ($cnpj === '' || strlen($cnpj) !== 14) {
        return ['ok' => false, 'error' => 'Informe um CNPJ com 14 dígitos.'];
    }
    if ($responsavelNome === '') {
        return ['ok' => false, 'error' => 'Informe o responsável pela PJ.'];
    }

    $meetingId = (int)($pendencia['meeting_id'] ?? 0);
    if ($meetingId <= 0) {
        return ['ok' => false, 'error' => 'Reunião local não vinculada ao evento ME.'];
    }

    $pdo->beginTransaction();
    try {
        $pkgResult = eventos_reuniao_atualizar_pacote_evento($pdo, $meetingId, $pacoteId);
        if (empty($pkgResult['ok'])) {
            throw new RuntimeException((string)($pkgResult['error'] ?? 'Erro ao vincular pacote.'));
        }
        $tipoResult = eventos_reuniao_atualizar_tipo_evento_real($pdo, $meetingId, $tipoEventoReal, $userId);
        if (empty($tipoResult['ok'])) {
            throw new RuntimeException((string)($tipoResult['error'] ?? 'Erro ao salvar tipo real do evento.'));
        }

        $snapshotStmt = $pdo->prepare("SELECT me_event_snapshot FROM eventos_reunioes WHERE id = :id LIMIT 1");
        $snapshotStmt->execute([':id' => $meetingId]);
        $snapshot = json_decode((string)$snapshotStmt->fetchColumn(), true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
        $snapshot['cliente_pj'] = [
            'razao_social' => $razaoSocial,
            'nome_fantasia' => trim((string)($dados['pj_nome_fantasia'] ?? '')),
            'cnpj' => $cnpj,
            'responsavel_nome' => $responsavelNome,
            'responsavel_cpf' => preg_replace('/\D+/', '', (string)($dados['pj_responsavel_cpf'] ?? '')),
        ];
        $snapshot['pacote_evento_id'] = $pacoteId;
        $snapshot['pacote_evento_nome'] = (string)($pacote['nome'] ?? '');
        $snapshot['tipo_evento_real'] = $tipoEventoReal;
        $snapshot['me_manual_complementado_at'] = date('Y-m-d H:i:s');

        $updateMeeting = $pdo->prepare("
            UPDATE eventos_reunioes
            SET me_event_snapshot = :snapshot,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updateMeeting->execute([
            ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $meetingId,
        ]);

        $updatePendencia = $pdo->prepare("
            UPDATE me_eventos_pendencias_comercial
            SET status = 'concluido',
                tipo_evento_real = :tipo_evento_real,
                pacote_evento_id = :pacote_evento_id,
                pj_razao_social = :pj_razao_social,
                pj_nome_fantasia = :pj_nome_fantasia,
                pj_cnpj = :pj_cnpj,
                pj_responsavel_nome = :pj_responsavel_nome,
                pj_responsavel_cpf = :pj_responsavel_cpf,
                observacoes = :observacoes,
                completed_at = NOW(),
                completed_by_user_id = :completed_by_user_id,
                updated_at = NOW()
            WHERE id = :id
            RETURNING *
        ");
        $updatePendencia->execute([
            ':id' => $pendenciaId,
            ':tipo_evento_real' => $tipoEventoReal,
            ':pacote_evento_id' => $pacoteId,
            ':pj_razao_social' => $razaoSocial,
            ':pj_nome_fantasia' => trim((string)($dados['pj_nome_fantasia'] ?? '')) ?: null,
            ':pj_cnpj' => $cnpj,
            ':pj_responsavel_nome' => $responsavelNome,
            ':pj_responsavel_cpf' => preg_replace('/\D+/', '', (string)($dados['pj_responsavel_cpf'] ?? '')) ?: null,
            ':observacoes' => trim((string)($dados['observacoes'] ?? '')) ?: null,
            ':completed_by_user_id' => $userId > 0 ? $userId : null,
        ]);

        if (function_exists('eventos_historico_registrar')) {
            eventos_historico_registrar(
                $pdo,
                $meetingId,
                'me_manual_complementado',
                'Evento ME manual complementado',
                'Comercial informou pacote e dados de pessoa jurídica para evento criado diretamente na ME.',
                null,
                ['pendencia_id' => $pendenciaId, 'pacote_evento_id' => $pacoteId],
                ['pendencia_id' => $pendenciaId, 'pacote_evento_id' => $pacoteId, 'tipo_evento_real' => $tipoEventoReal],
                $userId
            );
        }

        $pdo->commit();
        return ['ok' => true, 'pendencia' => $updatePendencia->fetch(PDO::FETCH_ASSOC) ?: null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('me_eventos_pendencias_concluir: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
