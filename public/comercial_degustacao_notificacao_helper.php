<?php
declare(strict_types=1);

require_once __DIR__ . '/core/notification_dispatcher.php';

const DEGUSTACAO_NOTIFICACAO_TIMEZONE = 'America/Sao_Paulo';
const DEGUSTACAO_NOTIFICACAO_HORA = '09:00';

/**
 * Envia por WhatsApp o lembrete do dia às inscrições confirmadas.
 *
 * Cada combinação degustação + inscrição é persistida antes do disparo, para
 * que chamadas repetidas do cron não gerem mensagens duplicadas.
 */
function degustacao_notificacao_processar(PDO $pdo, array $options = []): array
{
    $agora = degustacao_notificacao_data_hora($options['ref_datetime'] ?? null);
    $force = !empty($options['force']);
    $dryRun = !empty($options['dry_run']);

    if (!$force && !degustacao_notificacao_horario_liberado($agora)) {
        return [
            'success' => true,
            'skipped' => true,
            'reason' => 'O envio diário começa às 09:00 (America/Sao_Paulo).',
            'ref_datetime' => $agora->format(DateTimeInterface::ATOM),
        ];
    }

    degustacao_notificacao_ensure_schema($pdo);
    $inscricoes = degustacao_notificacao_buscar_inscricoes($pdo, $agora->format('Y-m-d'));
    $dispatcher = $options['dispatcher'] ?? new NotificationDispatcher($pdo);
    $degustacoes = [];

    $resultado = [
        'success' => true,
        'dry_run' => $dryRun,
        'data' => $agora->format('Y-m-d'),
        'degustacoes' => 0,
        'participantes' => count($inscricoes),
        'enviados' => 0,
        'ignorados_duplicados' => 0,
        'sem_telefone' => 0,
        'falhas' => 0,
        'envios_incertos' => 0,
        'previews' => [],
    ];

    foreach ($inscricoes as $inscricao) {
        $degustacaoId = (int)$inscricao['degustacao_id'];
        $inscricaoId = (int)$inscricao['inscricao_id'];
        $telefone = trim((string)($inscricao['telefone'] ?? ''));
        $mensagem = degustacao_notificacao_montar_mensagem($inscricao);
        $degustacoes[$degustacaoId] = true;

        if ($dryRun) {
            $resultado['previews'][] = [
                'degustacao_id' => $degustacaoId,
                'inscricao_id' => $inscricaoId,
                'nome' => (string)($inscricao['participante_nome'] ?? ''),
                'telefone' => $telefone,
                'mensagem' => $mensagem,
            ];
            continue;
        }

        if ($telefone === '') {
            $resultado['sem_telefone']++;
            continue;
        }

        if (!degustacao_notificacao_reservar_envio($pdo, $degustacaoId, $inscricaoId, $telefone)) {
            $resultado['ignorados_duplicados']++;
            continue;
        }

        try {
            $ok = $dispatcher->sendWhatsappDirect(
                $telefone,
                $mensagem,
                (string)($inscricao['participante_nome'] ?? '')
            );
        } catch (Throwable $e) {
            $ok = false;
            error_log('[DEGUSTACAO_NOTIFICACAO] Falha na inscrição ' . $inscricaoId . ': ' . $e->getMessage());
        }

        if ($ok) {
            degustacao_notificacao_finalizar_envio($pdo, $degustacaoId, $inscricaoId, 'enviado');
            $resultado['enviados']++;
        } else {
            degustacao_notificacao_finalizar_envio(
                $pdo,
                $degustacaoId,
                $inscricaoId,
                'incerto',
                'Não foi possível confirmar a resposta do provedor. O envio não será repetido automaticamente.'
            );
            $resultado['falhas']++;
            $resultado['envios_incertos']++;
        }
    }

    $resultado['degustacoes'] = count($degustacoes);
    return $resultado;
}

function degustacao_notificacao_horario_liberado(DateTimeInterface $dataHora): bool
{
    return $dataHora->format('H:i') >= DEGUSTACAO_NOTIFICACAO_HORA;
}

function degustacao_notificacao_data_hora($raw): DateTimeImmutable
{
    $timezone = new DateTimeZone(DEGUSTACAO_NOTIFICACAO_TIMEZONE);
    $value = trim((string)$raw);

    return $value === ''
        ? new DateTimeImmutable('now', $timezone)
        : new DateTimeImmutable($value, $timezone);
}

function degustacao_notificacao_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS comercial_degustacao_notificacao_envios (
            id BIGSERIAL PRIMARY KEY,
            degustacao_id BIGINT NOT NULL,
            inscricao_id BIGINT NOT NULL,
            telefone VARCHAR(40),
            status VARCHAR(20) NOT NULL DEFAULT 'processando',
            tentativas INT NOT NULL DEFAULT 1,
            erro TEXT,
            reservado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            enviado_em TIMESTAMPTZ,
            atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS uq_comercial_deg_notif_envio
        ON comercial_degustacao_notificacao_envios (degustacao_id, inscricao_id)
    ");
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_comercial_deg_notif_status
        ON comercial_degustacao_notificacao_envios (status, atualizado_em)
    ");
}

function degustacao_notificacao_buscar_inscricoes(PDO $pdo, string $data): array
{
    $colunas = degustacao_notificacao_colunas_inscricao($pdo);
    $fk = in_array('degustacao_id', $colunas, true) ? 'degustacao_id' : 'event_id';
    $telefone = in_array('telefone', $colunas, true) ? 'telefone' : 'celular';

    $stmt = $pdo->prepare("
        SELECT
            d.id AS degustacao_id,
            d.nome AS degustacao_nome,
            d.data AS degustacao_data,
            d.hora_inicio,
            d.local,
            i.id AS inscricao_id,
            i.nome AS participante_nome,
            i.{$telefone} AS telefone
        FROM comercial_degustacoes d
        INNER JOIN comercial_inscricoes i ON i.{$fk} = d.id
        WHERE d.data = :data
          AND COALESCE(d.status, 'publicado') <> 'rascunho'
          AND i.status = 'confirmado'
        ORDER BY d.hora_inicio, d.id, i.id
    ");
    $stmt->execute([':data' => $data]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function degustacao_notificacao_colunas_inscricao(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'comercial_inscricoes'
          AND column_name IN ('degustacao_id', 'event_id', 'telefone', 'celular')
    ");
    $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    if (!in_array('degustacao_id', $colunas, true) && !in_array('event_id', $colunas, true)) {
        throw new RuntimeException('A tabela comercial_inscricoes não possui vínculo com a degustação.');
    }
    if (!in_array('telefone', $colunas, true) && !in_array('celular', $colunas, true)) {
        throw new RuntimeException('A tabela comercial_inscricoes não possui coluna de telefone.');
    }

    return $colunas;
}

function degustacao_notificacao_reservar_envio(
    PDO $pdo,
    int $degustacaoId,
    int $inscricaoId,
    string $telefone
): bool {
    $stmt = $pdo->prepare("
        INSERT INTO comercial_degustacao_notificacao_envios
            (degustacao_id, inscricao_id, telefone, status)
        VALUES
            (:degustacao_id, :inscricao_id, :telefone, 'processando')
        ON CONFLICT (degustacao_id, inscricao_id) DO NOTHING
        RETURNING id
    ");
    $stmt->execute([
        ':degustacao_id' => $degustacaoId,
        ':inscricao_id' => $inscricaoId,
        ':telefone' => $telefone,
    ]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    // Qualquer registro existente bloqueia uma nova tentativa. Uma falha de
    // comunicação pode acontecer depois que o provedor aceitou a mensagem;
    // reenviar nesse cenário causaria duplicidade.
    return false;
}

function degustacao_notificacao_finalizar_envio(
    PDO $pdo,
    int $degustacaoId,
    int $inscricaoId,
    string $status,
    ?string $erro = null
): void {
    $stmt = $pdo->prepare("
        UPDATE comercial_degustacao_notificacao_envios
        SET status = :status,
            erro = :erro,
            enviado_em = CASE WHEN :status_envio = 'enviado' THEN NOW() ELSE enviado_em END,
            atualizado_em = NOW()
        WHERE degustacao_id = :degustacao_id
          AND inscricao_id = :inscricao_id
    ");
    $stmt->execute([
        ':status' => $status,
        ':status_envio' => $status,
        ':erro' => $erro,
        ':degustacao_id' => $degustacaoId,
        ':inscricao_id' => $inscricaoId,
    ]);
}

function degustacao_notificacao_montar_mensagem(array $degustacao): string
{
    [$local, $endereco] = degustacao_notificacao_separar_local((string)($degustacao['local'] ?? ''));
    $horario = degustacao_notificacao_formatar_horario((string)($degustacao['hora_inicio'] ?? ''));

    $linhas = [
        'Hoje é o grande dia!',
        'Estamos ansiosos para recebê-los em nosso espaço para a degustação.',
        '',
        'Para que tudo ocorra da melhor forma, reforçamos algumas orientações importantes:',
        '',
        '• A degustação é exclusiva para as pessoas definidas previamente (3 para 15 anos e 2 para casamento), exceto nos casos em que houve acréscimo de convidados;',
        '• Atrasos superiores a 10 minutos resultam no cancelamento da degustação, e a entrada não será permitida após esse período.',
        '',
        'Detalhes da degustação:',
        'Data: Hoje',
        'Horário: ' . $horario,
        'Local: ' . ($local !== '' ? $local : 'Não informado'),
    ];
    if ($endereco !== '') {
        $linhas[] = 'Endereço: ' . $endereco;
    }
    $linhas[] = '';
    $linhas[] = 'Agradecemos sua compreensão e colaboração.';
    $linhas[] = 'Estamos felizes em recebê-los!';

    return implode("\n", $linhas);
}

function degustacao_notificacao_formatar_horario(string $hora): string
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($hora), $match)) {
        return trim($hora);
    }
    return sprintf('%02dh%02d', (int)$match[1], (int)$match[2]);
}

function degustacao_notificacao_separar_local(string $valor): array
{
    $partes = preg_split('/\s*:\s*/u', trim($valor), 2) ?: [];
    $local = trim((string)($partes[0] ?? ''));
    $endereco = trim((string)($partes[1] ?? ''));

    $aliases = [
        'Espaço Garden' => 'Lisbon Garden',
        'LISBON GARDEN - ESPAÇO GARDEN' => 'Lisbon Garden',
        'Espaço Cristal' => 'Cristal',
    ];
    $local = $aliases[$local] ?? $local;

    if (preg_match('/Padre Eugênio,\s*511/u', $endereco)) {
        $endereco = 'R. Padre Eugênio, 511, Jardim Jacinto, Jacareí/SP — 12322-690';
    }

    return [$local, $endereco];
}
