<?php
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/notification_dispatcher.php';

function eventos_feedback_whatsapp_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_name = :table AND column_name = :column
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function eventos_feedback_whatsapp_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_feedback_whatsapp_envios (
            id BIGSERIAL PRIMARY KEY,
            evento_id BIGINT NOT NULL,
            me_event_id BIGINT,
            tipo VARCHAR(40) NOT NULL DEFAULT 'fim_semana',
            data_evento DATE,
            telefone VARCHAR(40),
            status VARCHAR(20) NOT NULL DEFAULT 'pendente',
            tentativas INT NOT NULL DEFAULT 0,
            enviado_em TIMESTAMP,
            ultimo_erro TEXT,
            criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
            atualizado_em TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (evento_id, tipo)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_feedback_whatsapp_status ON eventos_feedback_whatsapp_envios(status, data_evento)");
}

function eventos_feedback_whatsapp_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $digits = ltrim($digits, '0');
    if ($digits === '') {
        return '';
    }

    if (strlen($digits) === 10 || strlen($digits) === 11) {
        $digits = '55' . $digits;
    }

    return strlen($digits) >= 12 ? $digits : '';
}

function eventos_feedback_whatsapp_message(): string
{
    return trim(
        "Olá! Tudo bem? 😊\n\n" .
        "✨ Esperamos que sua festa tenha sido incrível!\n\n" .
        "Gostaríamos muito de saber como foi sua experiência com a nossa equipe.\n\n" .
        "💙 Seu feedback é super importante para continuarmos melhorando e entregando momentos especiais.\n\n" .
        "Se puder, responda esta mensagem contando como foi. Vamos adorar saber!\n\n" .
        "Muito obrigada pela confiança.\n" .
        "Equipe Smile Eventos"
    );
}

function eventos_feedback_whatsapp_mark(PDO $pdo, array $evento, string $status, ?string $erro, string $telefone): void
{
    $stmt = $pdo->prepare("
        INSERT INTO eventos_feedback_whatsapp_envios (
            evento_id, me_event_id, tipo, data_evento, telefone, status, tentativas,
            enviado_em, ultimo_erro, atualizado_em
        ) VALUES (
            :evento_id, :me_event_id, 'fim_semana', :data_evento, :telefone, :status,
            CASE WHEN :status = 'erro' THEN 1 ELSE 0 END,
            CASE WHEN :status = 'enviada' THEN NOW() ELSE NULL END,
            :erro, NOW()
        )
        ON CONFLICT (evento_id, tipo) DO UPDATE SET
            telefone = EXCLUDED.telefone,
            status = EXCLUDED.status,
            tentativas = CASE WHEN EXCLUDED.status = 'erro' THEN eventos_feedback_whatsapp_envios.tentativas + 1 ELSE eventos_feedback_whatsapp_envios.tentativas END,
            enviado_em = CASE WHEN EXCLUDED.status = 'enviada' THEN NOW() ELSE eventos_feedback_whatsapp_envios.enviado_em END,
            ultimo_erro = EXCLUDED.ultimo_erro,
            atualizado_em = NOW()
    ");
    $stmt->execute([
        ':evento_id' => (int)($evento['id'] ?? 0),
        ':me_event_id' => isset($evento['me_event_id']) ? (int)$evento['me_event_id'] : null,
        ':data_evento' => (string)($evento['data_evento'] ?? ''),
        ':telefone' => $telefone,
        ':status' => $status,
        ':erro' => $erro,
    ]);
}

function eventos_feedback_whatsapp_processar(PDO $pdo, array $options = []): array
{
    eventos_feedback_whatsapp_ensure_schema($pdo);

    $tz = new DateTimeZone('America/Sao_Paulo');
    $dryRun = !empty($options['dry_run']);
    $force = !empty($options['force']);
    $limit = max(1, min(500, (int)($options['limit'] ?? 200)));
    $refDateRaw = trim((string)($options['ref_date'] ?? ''));
    $today = $refDateRaw !== ''
        ? new DateTimeImmutable($refDateRaw, $tz)
        : new DateTimeImmutable('today', $tz);

    if (!$force && (int)$today->format('N') !== 1) {
        return [
            'success' => true,
            'skipped' => true,
            'reason' => 'Este cron envia apenas às segundas-feiras.',
            'dry_run' => $dryRun,
        ];
    }

    $sabado = $today->modify('previous saturday')->format('Y-m-d');
    $domingo = $today->modify('previous sunday')->format('Y-m-d');

    $phoneExpr = "COALESCE(NULLIF(TRIM(e.whatsapp_cliente), ''), NULLIF(TRIM(e.telefone_cliente), ''))";
    $joinClientes = '';
    if (
        eventos_feedback_whatsapp_has_column($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id')
        && eventos_feedback_whatsapp_has_column($pdo, 'comercial_cadastro_clientes', 'telefone_whatsapp')
    ) {
        $joinClientes = "LEFT JOIN comercial_cadastro_clientes cc ON cc.id = e.cliente_cadastro_id";
        $phoneExpr = "COALESCE(NULLIF(TRIM(e.whatsapp_cliente), ''), NULLIF(TRIM(e.telefone_cliente), ''), NULLIF(TRIM(cc.telefone_whatsapp), ''))";
    }

    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.me_event_id,
            e.data_evento::text AS data_evento,
            COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento') AS nome_evento,
            {$phoneExpr} AS telefone_cliente
        FROM logistica_eventos_espelho e
        {$joinClientes}
        LEFT JOIN eventos_feedback_whatsapp_envios env
          ON env.evento_id = e.id AND env.tipo = 'fim_semana' AND env.status = 'enviada'
        WHERE COALESCE(e.arquivado, FALSE) = FALSE
          AND e.data_evento BETWEEN :sabado AND :domingo
          AND env.id IS NULL
        ORDER BY e.data_evento ASC, e.hora_inicio ASC NULLS LAST, e.id ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':sabado', $sabado);
    $stmt->bindValue(':domingo', $domingo);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dispatcher = new NotificationDispatcher($pdo);
    $message = eventos_feedback_whatsapp_message();
    $resultado = [
        'success' => true,
        'periodo' => ['inicio' => $sabado, 'fim' => $domingo],
        'dry_run' => $dryRun,
        'encontrados' => count($eventos),
        'enviados' => 0,
        'falhas' => 0,
        'sem_telefone' => 0,
    ];

    foreach ($eventos as $evento) {
        $telefone = eventos_feedback_whatsapp_normalize_phone((string)($evento['telefone_cliente'] ?? ''));
        if ($telefone === '') {
            $resultado['sem_telefone']++;
            if (!$dryRun) {
                eventos_feedback_whatsapp_mark($pdo, $evento, 'erro', 'Telefone do cliente ausente ou inválido.', '');
            }
            continue;
        }

        if ($dryRun) {
            continue;
        }

        $ok = $dispatcher->sendWhatsappDirect($telefone, $message, (string)($evento['nome_evento'] ?? $telefone));
        if ($ok) {
            eventos_feedback_whatsapp_mark($pdo, $evento, 'enviada', null, $telefone);
            $resultado['enviados']++;
        } else {
            eventos_feedback_whatsapp_mark($pdo, $evento, 'erro', 'Falha ao enviar WhatsApp pela SMClick.', $telefone);
            $resultado['falhas']++;
        }
    }

    return $resultado;
}
