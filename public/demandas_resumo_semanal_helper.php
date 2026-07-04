<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/notification_dispatcher.php';

function demandas_resumo_semanal_processar(PDO $pdo, array $options = []): array
{
    $refDate = demandas_resumo_semanal_ref_date($options['ref_date'] ?? null);
    $force = !empty($options['force']);
    $dryRun = !empty($options['dry_run']);
    $limit = max(1, min(500, (int)($options['limit'] ?? 200)));

    if (!$force && $refDate->format('N') !== '1') {
        return [
            'success' => true,
            'skipped' => true,
            'reason' => 'Este cron envia apenas na segunda-feira.',
            'ref_date' => $refDate->format('Y-m-d'),
        ];
    }

    demandas_resumo_semanal_ensure_schema($pdo);

    $weekStart = $refDate->modify('monday this week')->setTime(0, 0, 0);
    $weekEnd = $weekStart->modify('+5 days')->setTime(23, 59, 59);
    $demandas = demandas_resumo_semanal_buscar_demandas($pdo, $weekStart, $weekEnd, $limit);
    $porUsuario = demandas_resumo_semanal_agrupar_por_usuario($pdo, $demandas);
    $demandasJordao = demandas_resumo_semanal_filtrar_jordao($pdo, $demandas);

    $dispatcher = new NotificationDispatcher($pdo);
    $resultado = [
        'success' => true,
        'dry_run' => $dryRun,
        'week_start' => $weekStart->format('Y-m-d'),
        'week_end' => $weekEnd->format('Y-m-d'),
        'total_demandas' => count($demandas),
        'total_responsaveis' => count($porUsuario),
        'enviados' => 0,
        'ignorados_duplicados' => 0,
        'falhas' => 0,
        'sem_telefone' => 0,
        'jordao_enviado' => false,
        'jordao_ignorado_duplicado' => false,
        'jordao_total_demandas' => count($demandasJordao),
        'previews' => [],
    ];

    foreach ($porUsuario as $usuarioId => $grupo) {
        $usuarioId = (int)$usuarioId;
        $message = demandas_resumo_semanal_montar_mensagem($grupo['usuario'], $grupo['demandas'], $weekStart, $weekEnd);
        $resultado['previews'][] = [
            'usuario_id' => $usuarioId,
            'nome' => (string)($grupo['usuario']['nome'] ?? ''),
            'total_demandas' => count($grupo['demandas']),
            'mensagem' => $message,
        ];

        if ($dryRun) {
            continue;
        }

        if (!$force && demandas_resumo_semanal_ja_enviado($pdo, $weekStart, 'usuario:' . $usuarioId)) {
            $resultado['ignorados_duplicados']++;
            continue;
        }

        $dispatch = $dispatcher->dispatch([[
            'id' => $usuarioId,
            'nome' => (string)($grupo['usuario']['nome'] ?? ''),
            'phone' => (string)($grupo['usuario']['phone'] ?? ''),
        ]], [
            'tipo' => 'demandas_resumo_semanal',
            'titulo' => 'Resumo semanal de demandas',
            'mensagem' => 'Resumo das demandas pendentes de segunda a sábado.',
            'url_destino' => demandas_resumo_semanal_url(),
            'whatsapp_mensagem' => $message,
        ], [
            'whatsapp' => true,
        ]);

        if (($dispatch['enviados_whatsapp'] ?? 0) > 0) {
            demandas_resumo_semanal_registrar_envio($pdo, $weekStart, 'usuario:' . $usuarioId, $usuarioId, count($grupo['demandas']));
            $resultado['enviados']++;
            continue;
        }

        if (($dispatch['whatsapps_sem_numero'] ?? 0) > 0) {
            $resultado['sem_telefone']++;
        } else {
            $resultado['falhas']++;
        }
    }

    if ($demandasJordao) {
        $message = demandas_resumo_semanal_montar_mensagem(['nome' => 'Jordão'], $demandasJordao, $weekStart, $weekEnd);
        $resultado['previews'][] = [
            'usuario_id' => null,
            'nome' => 'Jordão',
            'total_demandas' => count($demandasJordao),
            'mensagem' => $message,
        ];

        if (!$dryRun) {
            if (!$force && demandas_resumo_semanal_ja_enviado($pdo, $weekStart, 'jordao')) {
                $resultado['jordao_ignorado_duplicado'] = true;
            } else {
                $okJordao = $dispatcher->sendWhatsappDirect('+5512981497097', $message, 'Jordão');
                if ($okJordao) {
                    demandas_resumo_semanal_registrar_envio($pdo, $weekStart, 'jordao', null, count($demandasJordao));
                    $resultado['jordao_enviado'] = true;
                } else {
                    $resultado['falhas']++;
                }
            }
        }
    }

    return $resultado;
}

function demandas_resumo_semanal_ref_date($raw): DateTimeImmutable
{
    $timezone = new DateTimeZone('America/Sao_Paulo');
    $value = trim((string)$raw);
    if ($value === '') {
        return new DateTimeImmutable('now', $timezone);
    }

    return new DateTimeImmutable($value, $timezone);
}

function demandas_resumo_semanal_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demandas_internas_resumo_semanal_envios (
            id SERIAL PRIMARY KEY,
            semana_inicio DATE NOT NULL,
            destinatario_chave VARCHAR(80),
            usuario_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
            total_demandas INTEGER NOT NULL DEFAULT 0,
            enviado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("ALTER TABLE demandas_internas_resumo_semanal_envios ADD COLUMN IF NOT EXISTS destinatario_chave VARCHAR(80)");
    $pdo->exec("ALTER TABLE demandas_internas_resumo_semanal_envios ALTER COLUMN usuario_id DROP NOT NULL");
    $pdo->exec("
        UPDATE demandas_internas_resumo_semanal_envios
        SET destinatario_chave = 'usuario:' || usuario_id::text
        WHERE destinatario_chave IS NULL
          AND usuario_id IS NOT NULL
    ");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_demandas_resumo_semana_destinatario ON demandas_internas_resumo_semanal_envios(semana_inicio, destinatario_chave)");
    $pdo->exec("ALTER TABLE demandas_internas ADD COLUMN IF NOT EXISTS enviar_jordao BOOLEAN NOT NULL DEFAULT FALSE");
}

function demandas_resumo_semanal_buscar_demandas(PDO $pdo, DateTimeImmutable $weekStart, DateTimeImmutable $weekEnd, int $limit): array
{
    $stmt = $pdo->prepare("
        SELECT
            d.id,
            d.titulo,
            d.descricao,
            d.prioridade,
            d.prazo,
            d.status,
            d.responsavel_tipo,
            d.responsavel_id,
            d.responsavel_setor,
            d.enviar_jordao,
            u.nome AS responsavel_nome,
            u.celular AS responsavel_celular,
            u.telefone AS responsavel_telefone
        FROM demandas_internas d
        LEFT JOIN usuarios u ON u.id = d.responsavel_id
        WHERE d.status IN ('aberta', 'em_andamento', 'aguardando')
          AND d.prazo BETWEEN :inicio AND :fim
        ORDER BY
            CASE d.prioridade
                WHEN 'urgente' THEN 1
                WHEN 'alta' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'baixa' THEN 4
                ELSE 5
            END,
            d.prazo ASC,
            d.id ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':inicio', $weekStart->format('Y-m-d'), PDO::PARAM_STR);
    $stmt->bindValue(':fim', $weekEnd->format('Y-m-d'), PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function demandas_resumo_semanal_agrupar_por_usuario(PDO $pdo, array $demandas): array
{
    $setores = [];
    foreach ($demandas as $demanda) {
        if (($demanda['responsavel_tipo'] ?? '') === 'setor') {
            $setor = trim((string)($demanda['responsavel_setor'] ?? ''));
            if ($setor !== '') {
                $setores[$setor] = true;
            }
        }
    }

    $usuariosPorSetor = demandas_resumo_semanal_usuarios_por_setor($pdo, array_keys($setores));
    $grupos = [];

    foreach ($demandas as $demanda) {
        $destinatarios = [];
        if (($demanda['responsavel_tipo'] ?? '') === 'usuario') {
            $id = (int)($demanda['responsavel_id'] ?? 0);
            if ($id > 0) {
                $destinatarios[] = [
                    'id' => $id,
                    'nome' => (string)($demanda['responsavel_nome'] ?? ''),
                    'phone' => (string)($demanda['responsavel_celular'] ?: $demanda['responsavel_telefone'] ?: ''),
                ];
            }
        } else {
            $setor = trim((string)($demanda['responsavel_setor'] ?? ''));
            $destinatarios = $usuariosPorSetor[$setor] ?? [];
        }

        foreach ($destinatarios as $usuario) {
            $usuarioId = (int)($usuario['id'] ?? 0);
            if ($usuarioId <= 0) {
                continue;
            }
            if (!isset($grupos[$usuarioId])) {
                $grupos[$usuarioId] = [
                    'usuario' => $usuario,
                    'demandas' => [],
                ];
            }
            $grupos[$usuarioId]['demandas'][(int)$demanda['id']] = $demanda;
        }
    }

    foreach ($grupos as &$grupo) {
        $grupo['demandas'] = array_values($grupo['demandas']);
    }
    unset($grupo);

    ksort($grupos);
    return $grupos;
}

function demandas_resumo_semanal_filtrar_jordao(PDO $pdo, array $demandas): array
{
    $buffer = [];
    foreach ($demandas as $demanda) {
        if (empty($demanda['enviar_jordao'])) {
            continue;
        }
        if (($demanda['responsavel_tipo'] ?? '') !== 'usuario') {
            continue;
        }
        if (!demandas_resumo_semanal_usuario_eh_gustavo($pdo, (int)($demanda['responsavel_id'] ?? 0))) {
            continue;
        }
        $buffer[(int)$demanda['id']] = $demanda;
    }

    return array_values($buffer);
}

function demandas_resumo_semanal_usuario_eh_gustavo(PDO $pdo, int $usuarioId): bool
{
    if ($usuarioId <= 0) {
        return false;
    }

    static $cache = [];
    if (array_key_exists($usuarioId, $cache)) {
        return $cache[$usuarioId];
    }

    $stmt = $pdo->prepare("SELECT nome, login FROM usuarios WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $label = trim((string)($usuario['nome'] ?? '') . ' ' . (string)($usuario['login'] ?? ''));
    $label = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    $cache[$usuarioId] = strpos($label, 'gustavo') !== false;

    return $cache[$usuarioId];
}

function demandas_resumo_semanal_usuarios_por_setor(PDO $pdo, array $setores): array
{
    $setores = array_values(array_unique(array_filter(array_map('trim', $setores))));
    if (!$setores) {
        return [];
    }

    $setoresLower = array_map(static function (string $setor): string {
        $setor = function_exists('mb_strtolower') ? mb_strtolower($setor, 'UTF-8') : strtolower($setor);
        return trim($setor);
    }, $setores);
    $placeholders = implode(',', array_fill(0, count($setores), '?'));
    $stmt = $pdo->prepare("
        SELECT id, nome, cargo, COALESCE(NULLIF(TRIM(celular), ''), NULLIF(TRIM(telefone), '')) AS phone
        FROM usuarios
        WHERE cargo IS NOT NULL
          AND LOWER(TRIM(cargo)) IN ({$placeholders})
          AND COALESCE(ativo, TRUE) = TRUE
        ORDER BY nome ASC
    ");
    $stmt->execute($setoresLower);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $usuario) {
        $setor = trim((string)($usuario['cargo'] ?? ''));
        $map[$setor][] = [
            'id' => (int)$usuario['id'],
            'nome' => (string)($usuario['nome'] ?? ''),
            'phone' => (string)($usuario['phone'] ?? ''),
        ];
    }

    return $map;
}

function demandas_resumo_semanal_montar_mensagem(array $usuario, array $demandas, DateTimeImmutable $weekStart, DateTimeImmutable $weekEnd): string
{
    $nome = trim((string)($usuario['nome'] ?? ''));
    $primeiroNome = $nome !== '' ? preg_split('/\s+/', $nome)[0] : '';
    $saudacao = $primeiroNome !== '' ? "Bom dia, {$primeiroNome}." : 'Bom dia.';

    $linhas = [
        $saudacao,
        '',
        'Resumo automatico das demandas pendentes desta semana (' . $weekStart->format('d/m') . ' a ' . $weekEnd->format('d/m') . '), organizado por prioridade e depois por prazo:',
        '',
    ];

    foreach ($demandas as $index => $demanda) {
        $prazo = demandas_resumo_semanal_formatar_data((string)($demanda['prazo'] ?? ''));
        $prioridade = demandas_resumo_semanal_prioridade_label((string)($demanda['prioridade'] ?? 'normal'));
        $titulo = trim((string)($demanda['titulo'] ?? 'Demanda sem titulo'));
        $descricao = demandas_resumo_semanal_resumir((string)($demanda['descricao'] ?? ''), 140);

        $linhas[] = ($index + 1) . ". [{$prioridade}] {$titulo}";
        $linhas[] = "Prazo: {$prazo} | Codigo: #" . (int)($demanda['id'] ?? 0);
        if ($descricao !== '') {
            $linhas[] = $descricao;
        }
        $linhas[] = '';
    }

    $linhas[] = 'Acesse o painel para atualizar status, anexar arquivos ou registrar andamento:';
    $linhas[] = demandas_resumo_semanal_url();

    return trim(implode("\n", $linhas));
}

function demandas_resumo_semanal_prioridade_label(string $prioridade): string
{
    return match ($prioridade) {
        'urgente' => 'URGENTE',
        'alta' => 'ALTA',
        'baixa' => 'BAIXA',
        default => 'NORMAL',
    };
}

function demandas_resumo_semanal_formatar_data(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return 'sem prazo';
    }

    try {
        return (new DateTimeImmutable($date))->format('d/m/Y');
    } catch (Throwable $e) {
        return $date;
    }
}

function demandas_resumo_semanal_resumir(string $text, int $max): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
        return mb_substr($text, 0, $max - 3, 'UTF-8') . '...';
    }

    if (!function_exists('mb_strlen') && strlen($text) > $max) {
        return substr($text, 0, $max - 3) . '...';
    }

    return $text;
}

function demandas_resumo_semanal_url(): string
{
    $base = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));
    if ($base === '') {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'painelsmilepro-production.up.railway.app'));
        $proto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https'));
        $base = $proto . '://' . $host;
    }

    return rtrim($base, '/') . '/index.php?page=demandas';
}

function demandas_resumo_semanal_ja_enviado(PDO $pdo, DateTimeImmutable $weekStart, string $destinatarioChave): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM demandas_internas_resumo_semanal_envios
        WHERE semana_inicio = :semana_inicio
          AND destinatario_chave = :destinatario_chave
        LIMIT 1
    ");
    $stmt->execute([
        ':semana_inicio' => $weekStart->format('Y-m-d'),
        ':destinatario_chave' => $destinatarioChave,
    ]);

    return (bool)$stmt->fetchColumn();
}

function demandas_resumo_semanal_registrar_envio(PDO $pdo, DateTimeImmutable $weekStart, string $destinatarioChave, ?int $usuarioId, int $totalDemandas): void
{
    $stmt = $pdo->prepare("
        INSERT INTO demandas_internas_resumo_semanal_envios (semana_inicio, destinatario_chave, usuario_id, total_demandas)
        VALUES (:semana_inicio, :destinatario_chave, :usuario_id, :total_demandas)
        ON CONFLICT (semana_inicio, destinatario_chave)
        DO UPDATE SET total_demandas = EXCLUDED.total_demandas, enviado_em = NOW()
    ");
    $stmt->execute([
        ':semana_inicio' => $weekStart->format('Y-m-d'),
        ':destinatario_chave' => $destinatarioChave,
        ':usuario_id' => $usuarioId,
        ':total_demandas' => $totalDemandas,
    ]);
}
