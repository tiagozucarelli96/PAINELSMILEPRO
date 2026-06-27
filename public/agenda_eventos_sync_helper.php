<?php
/**
 * agenda_eventos_sync_helper.php
 * Mantem a tabela local da Agenda Geral atualizada a partir de payloads da ME.
 */

if (!function_exists('agenda_eventos_sync_table_exists')) {
    function agenda_eventos_sync_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT to_regclass(:table)');
            $stmt->execute([':table' => $table]);
            return trim((string)$stmt->fetchColumn()) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('agenda_eventos_sync_column_exists')) {
    function agenda_eventos_sync_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM pg_attribute
                WHERE attrelid = to_regclass(:table)
                  AND attname = :column
                  AND NOT attisdropped
                LIMIT 1
            ");
            $stmt->execute([':table' => $table, ':column' => $column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('agenda_eventos_sync_pick')) {
    function agenda_eventos_sync_pick(array $data, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && trim((string)$data[$key]) !== '') {
                return $data[$key];
            }
        }

        return $default;
    }
}

if (!function_exists('agenda_eventos_sync_date')) {
    function agenda_eventos_sync_date($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $value, $m) === 1) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}

if (!function_exists('agenda_eventos_sync_time')) {
    function agenda_eventos_sync_time($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}/', $value) === 1) {
            return substr($value, 0, 5);
        }

        $ts = strtotime($value);
        return $ts ? date('H:i', $ts) : null;
    }
}

if (!function_exists('agenda_eventos_sync_mapeamento')) {
    function agenda_eventos_sync_mapeamento(PDO $pdo, int $idLocalEvento, string $localEvento): ?array
    {
        static $cacheById = [];
        static $cacheByName = [];

        if (!agenda_eventos_sync_table_exists($pdo, 'logistica_me_locais')) {
            return null;
        }

        if ($idLocalEvento > 0) {
            if (array_key_exists($idLocalEvento, $cacheById)) {
                return $cacheById[$idLocalEvento];
            }

            $stmt = $pdo->prepare("
                SELECT unidade_interna_id, space_visivel
                FROM logistica_me_locais
                WHERE me_local_id = :me_local_id
                LIMIT 1
            ");
            $stmt->execute([':me_local_id' => $idLocalEvento]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $cacheById[$idLocalEvento] = $row;
                return $row;
            }

            $cacheById[$idLocalEvento] = null;
        }

        $nameKey = strtolower(trim($localEvento));
        if (array_key_exists($nameKey, $cacheByName)) {
            return $cacheByName[$nameKey];
        }

        $stmt = $pdo->prepare("
            SELECT unidade_interna_id, space_visivel
            FROM logistica_me_locais
            WHERE LOWER(TRIM(me_local_nome)) = LOWER(TRIM(:me_local_nome))
            LIMIT 1
        ");
        $stmt->execute([':me_local_nome' => $localEvento]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $cacheByName[$nameKey] = is_array($row) ? $row : null;
        return $cacheByName[$nameKey];
    }
}

if (!function_exists('agenda_eventos_sync_me_payload')) {
    function agenda_eventos_sync_me_payload(PDO $pdo, array $eventoData, string $webhookEvent = ''): array
    {
        static $schemaEnsured = false;
        static $hasNomeEvento = null;
        static $hasWhatsappCliente = null;

        if (!agenda_eventos_sync_table_exists($pdo, 'logistica_eventos_espelho')) {
            return ['ok' => false, 'error' => 'Tabela logistica_eventos_espelho ausente.'];
        }

        if (!$schemaEnsured) {
            try {
                $pdo->exec("ALTER TABLE logistica_eventos_espelho ADD COLUMN IF NOT EXISTS whatsapp_cliente VARCHAR(40)");
                $pdo->exec("ALTER TABLE logistica_eventos_espelho ADD COLUMN IF NOT EXISTS telefone_cliente VARCHAR(40)");
                $schemaEnsured = true;
            } catch (Throwable $e) {
                error_log('[AGENDA SYNC] Falha ao garantir telefone do cliente: ' . $e->getMessage());
            }
        }

        $meEventId = (int)agenda_eventos_sync_pick($eventoData, ['id', 'id_evento', 'idevento'], 0);
        if ($meEventId <= 0) {
            return ['ok' => false, 'error' => 'Payload ME sem id de evento.'];
        }

        $webhookEvent = strtolower(trim($webhookEvent));
        if (in_array($webhookEvent, ['event_canceled', 'event_deleted', 'deleted'], true)) {
            $stmt = $pdo->prepare("
                UPDATE logistica_eventos_espelho
                SET arquivado = TRUE,
                    updated_at = NOW()
                WHERE me_event_id = :me_event_id
            ");
            $stmt->execute([':me_event_id' => $meEventId]);
            return ['ok' => true, 'action' => 'archived', 'me_event_id' => $meEventId];
        }

        $dataEvento = agenda_eventos_sync_date(agenda_eventos_sync_pick($eventoData, ['dataevento', 'data_evento', 'data']));
        $localEvento = trim((string)agenda_eventos_sync_pick($eventoData, ['localevento', 'local_evento', 'local', 'nomelocal'], ''));
        if ($dataEvento === null || $localEvento === '') {
            return ['ok' => false, 'error' => 'Payload ME sem data/local suficientes para atualizar agenda.'];
        }

        $idLocalEvento = (int)agenda_eventos_sync_pick($eventoData, ['idlocalevento', 'id_local_evento', 'local_id'], 0);
        $mapping = agenda_eventos_sync_mapeamento($pdo, $idLocalEvento, $localEvento);
        $statusMapeamento = $mapping ? 'MAPEADO' : 'PENDENTE';
        $unidadeId = $mapping['unidade_interna_id'] ?? null;
        $spaceVisivel = $mapping['space_visivel'] ?? null;
        if ($hasNomeEvento === null) {
            $hasNomeEvento = agenda_eventos_sync_column_exists($pdo, 'logistica_eventos_espelho', 'nome_evento');
        }

        $nomeEvento = trim((string)agenda_eventos_sync_pick($eventoData, ['nomeevento', 'nome_evento', 'nome', 'titulo'], ''));
        if ($nomeEvento === '') {
            $nomeCliente = trim((string)agenda_eventos_sync_pick($eventoData, ['nomeCliente', 'nomecliente', 'client_name'], ''));
            $tipoEvento = trim((string)agenda_eventos_sync_pick($eventoData, ['tipoEvento', 'tipoevento', 'tipo'], ''));
            $nomeEvento = trim($nomeCliente . ($tipoEvento !== '' ? ' - ' . $tipoEvento : ''));
        }
        if ($nomeEvento === '') {
            $nomeEvento = 'Evento';
        }

        $telefoneCliente = trim((string)agenda_eventos_sync_pick($eventoData, [
            'celular',
            'telefone',
            'telefone2',
            'whatsapp',
            'client_phone',
            'client_whatsapp',
            'phone',
            'mobile',
        ], ''));
        $ddiCliente = trim((string)agenda_eventos_sync_pick($eventoData, [
            'ddicelular',
            'dditelefone',
            'ddi',
            'client_phone_ddi',
        ], ''));
        $whatsappCliente = trim($ddiCliente . ($ddiCliente !== '' && $telefoneCliente !== '' ? ' ' : '') . $telefoneCliente);

        $params = [
            ':me_event_id' => $meEventId,
            ':data_evento' => $dataEvento,
            ':hora_inicio' => agenda_eventos_sync_time(agenda_eventos_sync_pick($eventoData, ['horaevento', 'hora_inicio', 'horainicio', 'hora'])),
            ':convidados' => (int)agenda_eventos_sync_pick($eventoData, ['convidados', 'nconvidados', 'num_convidados'], 0),
            ':idlocalevento' => $idLocalEvento,
            ':localevento' => $localEvento,
            ':unidade_interna_id' => $unidadeId,
            ':space_visivel' => $spaceVisivel,
            ':status_mapeamento' => $statusMapeamento,
        ];

        if ($hasNomeEvento) {
            $params[':nome_evento'] = $nomeEvento;
            $sql = "
                INSERT INTO logistica_eventos_espelho
                (me_event_id, data_evento, hora_inicio, convidados, idlocalevento, localevento, nome_evento,
                 unidade_interna_id, space_visivel, status_mapeamento, arquivado, synced_at, updated_at)
                VALUES
                (:me_event_id, :data_evento, :hora_inicio, :convidados, :idlocalevento, :localevento, :nome_evento,
                 :unidade_interna_id, :space_visivel, :status_mapeamento, FALSE, NOW(), NOW())
                ON CONFLICT (me_event_id) DO UPDATE SET
                    data_evento = EXCLUDED.data_evento,
                    hora_inicio = EXCLUDED.hora_inicio,
                    convidados = EXCLUDED.convidados,
                    idlocalevento = EXCLUDED.idlocalevento,
                    localevento = EXCLUDED.localevento,
                    nome_evento = EXCLUDED.nome_evento,
                    unidade_interna_id = EXCLUDED.unidade_interna_id,
                    space_visivel = EXCLUDED.space_visivel,
                    status_mapeamento = EXCLUDED.status_mapeamento,
                    arquivado = FALSE,
                    synced_at = NOW(),
                    updated_at = NOW()
            ";
        } else {
            $sql = "
                INSERT INTO logistica_eventos_espelho
                (me_event_id, data_evento, hora_inicio, convidados, idlocalevento, localevento,
                 unidade_interna_id, space_visivel, status_mapeamento, arquivado, synced_at, updated_at)
                VALUES
                (:me_event_id, :data_evento, :hora_inicio, :convidados, :idlocalevento, :localevento,
                 :unidade_interna_id, :space_visivel, :status_mapeamento, FALSE, NOW(), NOW())
                ON CONFLICT (me_event_id) DO UPDATE SET
                    data_evento = EXCLUDED.data_evento,
                    hora_inicio = EXCLUDED.hora_inicio,
                    convidados = EXCLUDED.convidados,
                    idlocalevento = EXCLUDED.idlocalevento,
                    localevento = EXCLUDED.localevento,
                    unidade_interna_id = EXCLUDED.unidade_interna_id,
                    space_visivel = EXCLUDED.space_visivel,
                    status_mapeamento = EXCLUDED.status_mapeamento,
                    arquivado = FALSE,
                    synced_at = NOW(),
                    updated_at = NOW()
            ";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($hasWhatsappCliente === null) {
            $hasWhatsappCliente = agenda_eventos_sync_column_exists($pdo, 'logistica_eventos_espelho', 'whatsapp_cliente');
        }

        if ($whatsappCliente !== '' && $hasWhatsappCliente) {
            $stmtPhone = $pdo->prepare("
                UPDATE logistica_eventos_espelho
                SET whatsapp_cliente = :whatsapp_cliente,
                    telefone_cliente = :telefone_cliente,
                    updated_at = NOW()
                WHERE me_event_id = :me_event_id
            ");
            $stmtPhone->execute([
                ':whatsapp_cliente' => $whatsappCliente,
                ':telefone_cliente' => $telefoneCliente !== '' ? $telefoneCliente : $whatsappCliente,
                ':me_event_id' => $meEventId,
            ]);
        }

        return [
            'ok' => true,
            'action' => 'upserted',
            'me_event_id' => $meEventId,
            'status_mapeamento' => $statusMapeamento,
            'space_visivel' => $spaceVisivel,
        ];
    }
}
