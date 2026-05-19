<?php
/**
 * eventos_notificacoes.php
 * Helper para disparo de notificações do módulo Eventos
 * Reutiliza sistema existente de push e e-mail
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/email_global_helper.php';
require_once __DIR__ . '/core/notification_dispatcher.php';

function eventos_dispatcher(PDO $pdo): ?NotificationDispatcher {
    static $dispatcher = null;

    if ($dispatcher instanceof NotificationDispatcher) {
        return $dispatcher;
    }

    try {
        $dispatcher = new NotificationDispatcher($pdo);
        $dispatcher->ensureInternalSchema();
        return $dispatcher;
    } catch (Throwable $e) {
        error_log("Erro ao iniciar dispatcher de eventos: " . $e->getMessage());
        return null;
    }
}

function eventos_notificacoes_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function eventos_notificacoes_formatar_data(?string $data): string {
    $data = trim((string)$data);
    if ($data === '') {
        return '-';
    }
    $ts = strtotime($data);
    return $ts ? date('d/m/Y', $ts) : $data;
}

function eventos_notificacoes_cortar_texto(string $texto, int $limite): string {
    if ($limite <= 0) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($texto, 'UTF-8') > $limite ? mb_substr($texto, 0, $limite, 'UTF-8') : $texto;
    }
    return strlen($texto) > $limite ? substr($texto, 0, $limite) : $texto;
}

function eventos_notificacoes_data_na_janela_5_dias(?string $data): bool {
    $data = trim((string)$data);
    if ($data === '') {
        return false;
    }

    try {
        $hoje = new DateTimeImmutable('today');
        $evento = new DateTimeImmutable(substr($data, 0, 10));
        $limite = $hoje->modify('+5 days');
        return $evento >= $hoje && $evento <= $limite;
    } catch (Throwable $e) {
        return false;
    }
}

function eventos_notificacoes_buscar_contexto(PDO $pdo, int $meeting_id): ?array {
    $stmt = $pdo->prepare("
        SELECT r.id, r.me_event_id, r.me_event_snapshot
        FROM eventos_reunioes r
        WHERE r.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $meeting_id]);
    $reuniao = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$reuniao) {
        return null;
    }

    $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
    $snapshot = is_array($snapshot) ? $snapshot : [];
    $me_event_id = (int)($reuniao['me_event_id'] ?? 0);
    $space = '';

    try {
        if ($me_event_id > 0 && eventos_reuniao_has_table($pdo, 'logistica_eventos_espelho')) {
            $stmt_space = $pdo->prepare("
                SELECT TRIM(COALESCE(space_visivel, '')) AS space_visivel
                FROM logistica_eventos_espelho
                WHERE me_event_id = :me_event_id
                LIMIT 1
            ");
            $stmt_space->execute([':me_event_id' => $me_event_id]);
            $space = trim((string)($stmt_space->fetchColumn() ?: ''));
        }
    } catch (Throwable $e) {
        error_log('eventos_notificacoes_buscar_contexto space: ' . $e->getMessage());
    }

    if ($space === '') {
        $space = trim((string)($snapshot['space_visivel'] ?? $snapshot['unidade'] ?? ''));
    }

    $cliente = is_array($snapshot['cliente'] ?? null) ? $snapshot['cliente'] : [];
    $local = function_exists('eventos_me_snapshot_local')
        ? eventos_me_snapshot_local($snapshot, '')
        : trim((string)($snapshot['local'] ?? ''));
    $cliente_nome = function_exists('eventos_me_snapshot_cliente_nome')
        ? eventos_me_snapshot_cliente_nome($snapshot, '')
        : trim((string)($cliente['nome'] ?? ''));

    return [
        'meeting_id' => (int)$reuniao['id'],
        'me_event_id' => $me_event_id,
        'snapshot' => $snapshot,
        'nome_evento' => trim((string)($snapshot['nome'] ?? 'Evento')),
        'data_evento' => trim((string)($snapshot['data'] ?? '')),
        'local_evento' => trim($local),
        'cliente_nome' => trim($cliente_nome),
        'space_visivel' => $space,
    ];
}

function eventos_notificacoes_buscar_gerentes_evento(PDO $pdo, string $space_visivel): array {
    $space = trim($space_visivel);
    if ($space === '' || !eventos_reuniao_has_table($pdo, 'usuarios_spaces_visiveis')) {
        return [];
    }

    $ativo_where = eventos_reuniao_has_column($pdo, 'usuarios', 'ativo') ? "AND COALESCE(u.ativo, TRUE) = TRUE" : "";
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.nome, u.email
        FROM usuarios u
        JOIN usuarios_spaces_visiveis us ON us.usuario_id = u.id
        WHERE u.email IS NOT NULL
          AND TRIM(u.email) <> ''
          AND LOWER(TRIM(COALESCE(u.cargo, ''))) = LOWER('Gerente de eventos')
          AND LOWER(TRIM(us.space_visivel)) = LOWER(TRIM(:space))
          {$ativo_where}
        ORDER BY u.nome ASC
    ");
    $stmt->execute([':space' => $space]);

    $destinatarios = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $destinatarios[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'nome' => trim((string)($row['nome'] ?? '')),
            'email' => $email,
        ];
    }

    return array_values($destinatarios);
}

function eventos_notificacoes_label_secao(string $section): string {
    $labels = [
        'decoracao' => 'Decoração',
        'observacoes_gerais' => 'Observações gerais',
        'dj_protocolo' => 'DJ / Protocolo',
        'formulario' => 'Formulário',
    ];
    return $labels[$section] ?? $section;
}

function eventos_notificacoes_payload_values(string $html): array {
    if (!function_exists('eventos_reuniao_extrair_payload_formulario')) {
        return [];
    }
    $payload = eventos_reuniao_extrair_payload_formulario($html);
    $values = $payload['values'] ?? [];
    return is_array($values) ? $values : [];
}

function eventos_notificacoes_schema_labels(?string $schema_json): array {
    $labels = [];
    $decoded = json_decode((string)$schema_json, true);
    if (!is_array($decoded)) {
        return $labels;
    }
    foreach ($decoded as $field) {
        if (!is_array($field)) {
            continue;
        }
        $id = trim((string)($field['id'] ?? ''));
        $label = trim((string)($field['label'] ?? ''));
        if ($id !== '' && $label !== '') {
            $labels[$id] = $label;
        }
    }
    return $labels;
}

function eventos_notificacoes_resumir_alteracoes_reuniao(string $prev_html, string $new_html, ?string $prev_schema_json = null, ?string $new_schema_json = null): array {
    $labels = array_replace(
        eventos_notificacoes_schema_labels($prev_schema_json),
        eventos_notificacoes_schema_labels($new_schema_json)
    );

    $prev_values = eventos_notificacoes_payload_values($prev_html);
    $new_values = eventos_notificacoes_payload_values($new_html);
    $changes = [];

    foreach (array_unique(array_merge(array_keys($prev_values), array_keys($new_values))) as $key) {
        $antes = trim((string)($prev_values[$key] ?? ''));
        $depois = trim((string)($new_values[$key] ?? ''));
        if ($antes === $depois) {
            continue;
        }
        $changes[] = [
            'campo' => $labels[$key] ?? (string)$key,
            'antes' => $antes !== '' ? $antes : 'vazio',
            'depois' => $depois !== '' ? $depois : 'vazio',
        ];
    }

    if (!empty($changes)) {
        return array_slice($changes, 0, 12);
    }

    $prev_text = function_exists('eventos_reuniao_html_para_texto') ? eventos_reuniao_html_para_texto($prev_html) : trim(strip_tags($prev_html));
    $new_text = function_exists('eventos_reuniao_html_para_texto') ? eventos_reuniao_html_para_texto($new_html) : trim(strip_tags($new_html));
    if (trim($prev_text) !== trim($new_text)) {
        return [[
            'campo' => 'Conteúdo da seção',
            'antes' => $prev_text !== '' ? eventos_notificacoes_cortar_texto($prev_text, 240) : 'vazio',
            'depois' => $new_text !== '' ? eventos_notificacoes_cortar_texto($new_text, 240) : 'vazio',
        ]];
    }

    return [];
}

function eventos_notificacoes_montar_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $base_url = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));
    if ($base_url === '' && $host !== '') {
        $base_url = $scheme . '://' . $host;
    }
    return rtrim($base_url, '/');
}

function eventos_notificacoes_email_html(array $ctx, string $titulo, string $descricao, array $alteracoes, string $url_destino = '', string $extra_html = ''): string {
    $nome = eventos_notificacoes_e($ctx['nome_evento'] ?? 'Evento');
    $data = eventos_notificacoes_e(eventos_notificacoes_formatar_data((string)($ctx['data_evento'] ?? '')));
    $local = eventos_notificacoes_e(trim((string)($ctx['local_evento'] ?? '')) ?: '-');
    $cliente = eventos_notificacoes_e(trim((string)($ctx['cliente_nome'] ?? '')) ?: '-');
    $unidade = eventos_notificacoes_e(trim((string)($ctx['space_visivel'] ?? '')) ?: '-');
    $titulo_e = eventos_notificacoes_e($titulo);
    $descricao_e = eventos_notificacoes_e($descricao);

    $rows = '';
    foreach ($alteracoes as $alteracao) {
        $campo = eventos_notificacoes_e($alteracao['campo'] ?? 'Campo');
        $antes = eventos_notificacoes_e($alteracao['antes'] ?? 'vazio');
        $depois = eventos_notificacoes_e($alteracao['depois'] ?? 'vazio');
        $rows .= "<tr><td style='padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600;'>{$campo}</td><td style='padding:10px;border-bottom:1px solid #e5e7eb;'>{$antes}</td><td style='padding:10px;border-bottom:1px solid #e5e7eb;'>{$depois}</td></tr>";
    }
    if ($rows === '') {
        $rows = "<tr><td colspan='3' style='padding:10px;border-bottom:1px solid #e5e7eb;'>Atualização registrada no sistema.</td></tr>";
    }

    $button = '';
    if (trim($url_destino) !== '') {
        $url = eventos_notificacoes_e($url_destino);
        $button = "<p style='margin:24px 0 0;'><a href='{$url}' style='display:inline-block;background:#1e3a8a;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:6px;font-weight:700;'>Abrir evento no painel</a></p>";
    }

    return "
        <div style='font-family:Arial,sans-serif;color:#111827;line-height:1.5;max-width:680px;margin:0 auto;'>
            <p style='font-size:12px;color:#6b7280;margin:0 0 12px;'>Notificação automática do Painel Smile Eventos</p>
            <h1 style='font-size:22px;margin:0 0 12px;color:#111827;'>{$titulo_e}</h1>
            <p style='margin:0 0 18px;'>{$descricao_e}</p>
            <div style='background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin:0 0 18px;'>
                <p style='margin:0 0 6px;'><strong>Evento:</strong> {$nome}</p>
                <p style='margin:0 0 6px;'><strong>Data:</strong> {$data}</p>
                <p style='margin:0 0 6px;'><strong>Local:</strong> {$local}</p>
                <p style='margin:0 0 6px;'><strong>Cliente:</strong> {$cliente}</p>
                <p style='margin:0;'><strong>Unidade:</strong> {$unidade}</p>
            </div>
            <table cellpadding='0' cellspacing='0' style='border-collapse:collapse;width:100%;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;'>
                <thead><tr style='background:#eef2ff;'><th align='left' style='padding:10px;'>Campo</th><th align='left' style='padding:10px;'>Antes</th><th align='left' style='padding:10px;'>Depois</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
            {$extra_html}
            {$button}
            <p style='font-size:12px;color:#6b7280;margin-top:24px;'>Você recebeu este email porque seu cargo é gerente de evento e sua unidade no cadastro corresponde à unidade do evento.</p>
        </div>
    ";
}

function eventos_notificacoes_enviar_para_gerentes(PDO $pdo, int $meeting_id, string $assunto, string $titulo, string $descricao, array $alteracoes, array $anexos = [], string $extra_html = ''): array {
    $ctx = eventos_notificacoes_buscar_contexto($pdo, $meeting_id);
    if (!$ctx || !eventos_notificacoes_data_na_janela_5_dias((string)($ctx['data_evento'] ?? ''))) {
        return ['ok' => false, 'motivo' => 'fora_janela_5_dias', 'destinatarios' => []];
    }

    $destinatarios = eventos_notificacoes_buscar_gerentes_evento($pdo, (string)($ctx['space_visivel'] ?? ''));
    if (empty($destinatarios)) {
        return ['ok' => false, 'motivo' => 'sem_gerente_unidade', 'destinatarios' => []];
    }

    $base_url = eventos_notificacoes_montar_base_url();
    $url = $base_url !== '' ? $base_url . '/index.php?page=eventos_reuniao_final&id=' . $meeting_id : '';
    $html = eventos_notificacoes_email_html($ctx, $titulo, $descricao, $alteracoes, $url, $extra_html);

    $emailHelper = new EmailGlobalHelper();
    $enviados = 0;
    foreach ($destinatarios as $destinatario) {
        $ok = !empty($anexos)
            ? $emailHelper->enviarEmailComAnexos($destinatario['email'], $assunto, $html, true, $anexos)
            : $emailHelper->enviarEmail($destinatario['email'], $assunto, $html, true);
        if ($ok) {
            $enviados++;
        }
    }

    return ['ok' => $enviados > 0, 'enviados' => $enviados, 'destinatarios' => $destinatarios];
}

function eventos_notificar_gerente_evento_atualizacao_reuniao(PDO $pdo, int $meeting_id, string $section, string $prev_html, string $new_html, ?string $prev_schema_json = null, ?string $new_schema_json = null): void {
    try {
        $alteracoes = eventos_notificacoes_resumir_alteracoes_reuniao($prev_html, $new_html, $prev_schema_json, $new_schema_json);
        if (empty($alteracoes)) {
            return;
        }

        $ctx = eventos_notificacoes_buscar_contexto($pdo, $meeting_id);
        $nome = $ctx ? (string)($ctx['nome_evento'] ?? 'Evento') : 'Evento';
        $data = $ctx ? eventos_notificacoes_formatar_data((string)($ctx['data_evento'] ?? '')) : '-';
        $secao_label = eventos_notificacoes_label_secao($section);
        $assunto = "Atualizacao na reuniao final - {$nome} - {$data}";
        $titulo = "NOVA ATUALIZAÇÃO DO EVENTO {$nome}, DIA {$data}";
        $descricao = "Houve alteração na reunião final, seção {$secao_label}.";

        eventos_notificacoes_enviar_para_gerentes($pdo, $meeting_id, $assunto, $titulo, $descricao, $alteracoes);
    } catch (Throwable $e) {
        error_log('eventos_notificar_gerente_evento_atualizacao_reuniao: ' . $e->getMessage());
    }
}

function eventos_notificacoes_baixar_anexo_base64(string $url, int $max_bytes = 10485760): string {
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'follow_location' => 1,
            'user_agent' => 'PainelSmileEventos/1.0',
        ],
    ]);
    $data = @file_get_contents($url, false, $context, 0, $max_bytes + 1);
    if (!is_string($data) || $data === '' || strlen($data) > $max_bytes) {
        return '';
    }
    return base64_encode($data);
}

function eventos_notificar_gerente_evento_resumo_atualizado(PDO $pdo, int $meeting_id, array $arquivo): void {
    try {
        $ctx = eventos_notificacoes_buscar_contexto($pdo, $meeting_id);
        $nome = $ctx ? (string)($ctx['nome_evento'] ?? 'Evento') : 'Evento';
        $data = $ctx ? eventos_notificacoes_formatar_data((string)($ctx['data_evento'] ?? '')) : '-';
        $assunto = "Atualizacao no resumo do evento - {$nome} - {$data}";
        $titulo = "NOVA ATUALIZAÇÃO DO EVENTO {$nome}, DIA {$data}";
        $descricao = "O resumo do evento foi atualizado. O novo resumo segue em anexo quando o arquivo está disponível para download pelo sistema.";
        $nome_arquivo = trim((string)($arquivo['original_name'] ?? 'resumo-evento.pdf')) ?: 'resumo-evento.pdf';
        $public_url = trim((string)($arquivo['public_url'] ?? ''));
        $anexos = [];

        $content = eventos_notificacoes_baixar_anexo_base64($public_url);
        if ($content !== '') {
            $anexos[] = [
                'filename' => $nome_arquivo,
                'content' => $content,
            ];
        }

        $extra = '';
        if ($public_url !== '') {
            $url_e = eventos_notificacoes_e($public_url);
            $extra = "<p style='margin-top:16px;'>Link do novo resumo: <a href='{$url_e}'>{$url_e}</a></p>";
        }

        eventos_notificacoes_enviar_para_gerentes(
            $pdo,
            $meeting_id,
            $assunto,
            $titulo,
            $descricao,
            [[
                'campo' => 'Resumo do evento',
                'antes' => 'versão anterior',
                'depois' => $nome_arquivo,
            ]],
            $anexos,
            $extra
        );
    } catch (Throwable $e) {
        error_log('eventos_notificar_gerente_evento_resumo_atualizado: ' . $e->getMessage());
    }
}

/**
 * Notificar quando link de cliente DJ é criado
 */
function eventos_notificar_link_cliente_criado(PDO $pdo, int $meeting_id, string $link_url): void {
    try {
        // Buscar dados da reunião
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   (r.me_event_snapshot->'cliente'->>'email') as email_cliente,
                   (r.me_event_snapshot->'cliente'->>'nome') as nome_cliente,
                   u.nome as criador_nome
            FROM eventos_reunioes r
            LEFT JOIN usuarios u ON u.id = r.created_by
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $meeting_id]);
        $reuniao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reuniao) return;
        
        // Enviar e-mail para o cliente (se tiver email)
        if (!empty($reuniao['email_cliente'])) {
            $emailHelper = new EmailGlobalHelper();
            
            $corpo = "
                <h2>Olá, {$reuniao['nome_cliente']}!</h2>
                <p>Você recebeu um link para preencher as informações de DJ do seu evento:</p>
                <p><strong>Evento:</strong> {$reuniao['nome_evento']}</p>
                <br>
                <p><a href='{$link_url}' style='display: inline-block; padding: 12px 24px; background: #1e3a8a; color: white; text-decoration: none; border-radius: 8px;'>Acessar Formulário</a></p>
                <br>
                <p style='color: #64748b; font-size: 14px;'>Este link é pessoal. Após enviar, você não poderá mais alterar as informações.</p>
                <br>
                <p>Atenciosamente,<br>Grupo Smile</p>
            ";
            
            $emailHelper->enviarEmail(
                $reuniao['email_cliente'],
                "Preencha as músicas do seu evento - {$reuniao['nome_evento']}",
                $corpo
            );
        }
        
        // Notificar internamente (interna + push para quem criou)
        if (!empty($reuniao['created_by'])) {
            $dispatcher = eventos_dispatcher($pdo);
            if ($dispatcher) {
                $dispatcher->dispatch(
                    [['id' => (int)$reuniao['created_by']]],
                    [
                        'tipo' => 'eventos_link_cliente_criado',
                        'titulo' => 'Link DJ enviado',
                        'mensagem' => "Link criado para {$reuniao['nome_evento']}",
                        'url_destino' => "index.php?page=eventos_reuniao_final&id={$meeting_id}",
                    ],
                    ['internal' => true, 'push' => true]
                );
            }
        }
        
    } catch (Exception $e) {
        error_log("Erro ao notificar link cliente: " . $e->getMessage());
    }
}

/**
 * Notificar quando cliente enviou informações de DJ
 */
function eventos_notificar_cliente_enviou_dj(PDO $pdo, int $meeting_id): void {
    try {
        // Buscar dados do evento/reunião
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   (r.me_event_snapshot->>'data') as data_evento,
                   (r.me_event_snapshot->>'hora_inicio') as hora_inicio,
                   (r.me_event_snapshot->>'hora_fim') as hora_fim,
                   (r.me_event_snapshot->>'local') as local_evento,
                   (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome,
                   (r.me_event_snapshot->'cliente'->>'email') as cliente_email,
                   (r.me_event_snapshot->'cliente'->>'telefone') as cliente_telefone,
                   r.fornecedor_dj_id
            FROM eventos_reunioes r
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $meeting_id]);
        $reuniao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reuniao) {
            return;
        }

        $e = function($value): string {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };

        $nome_evento = (string)($reuniao['nome_evento'] ?? 'Evento');
        $data_evento = (string)($reuniao['data_evento'] ?? '');
        $data_ts = $data_evento !== '' ? strtotime($data_evento) : false;
        $data_fmt = $data_ts ? date('d/m/Y', $data_ts) : '-';
        $hora_inicio = trim((string)($reuniao['hora_inicio'] ?? ''));
        $hora_fim = trim((string)($reuniao['hora_fim'] ?? ''));
        $hora_fmt = $hora_inicio !== '' ? $hora_inicio : '-';
        if ($hora_inicio !== '' && $hora_fim !== '') {
            $hora_fmt = $hora_inicio . ' - ' . $hora_fim;
        }
        $local_evento = trim((string)($reuniao['local_evento'] ?? ''));
        $cliente_nome = trim((string)($reuniao['cliente_nome'] ?? ''));
        
        // Notificar quem criou a reunião
        if (!empty($reuniao['created_by'])) {
            $dispatcher = eventos_dispatcher($pdo);
            if ($dispatcher) {
                $dispatcher->dispatch(
                    [['id' => (int)$reuniao['created_by']]],
                    [
                        'tipo' => 'eventos_cliente_enviou_dj',
                        'titulo' => 'Cliente enviou músicas',
                        'mensagem' => "O cliente preencheu as informações de DJ para {$reuniao['nome_evento']}",
                        'url_destino' => "index.php?page=eventos_reuniao_final&id={$meeting_id}",
                    ],
                    ['internal' => true, 'push' => true]
                );
            }
        }
        
        // Notificar DJ por e-mail (usa fornecedor vinculado; se não houver, envia para todos os DJs ativos)
        $emails = [];

        if (!empty($reuniao['fornecedor_dj_id'])) {
            $stmt = $pdo->prepare("SELECT email FROM eventos_fornecedores WHERE id = :id AND email IS NOT NULL");
            $stmt->execute([':id' => $reuniao['fornecedor_dj_id']]);
            $dj_email = trim((string)($stmt->fetchColumn() ?: ''));
            if ($dj_email !== '') {
                $emails[] = $dj_email;
            }
        }

        if (empty($emails)) {
            $stmt = $pdo->query("
                SELECT email
                FROM eventos_fornecedores
                WHERE tipo = 'dj'
                  AND ativo = TRUE
                  AND email IS NOT NULL
                  AND email <> ''
            ");
            $emails = array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        $emails = array_values(array_unique(array_filter($emails, fn($x) => is_string($x) && trim($x) !== '')));
        if (!empty($emails)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

            $base_url = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));
            if ($base_url === '' && $host !== '') {
                $base_url = $scheme . '://' . $host;
            }
            $base_url = rtrim($base_url, '/');
            $portal_url = $base_url !== ''
                ? $base_url . "/index.php?page=portal_dj&evento=" . (int)$meeting_id
                : "index.php?page=portal_dj&evento=" . (int)$meeting_id;

            $assunto = "FORMULARIO PREENCHIDO - SMILE EVENTOS";
            $nome_evento_e = $e($nome_evento);
            $data_fmt_e = $e($data_fmt);
            $hora_fmt_e = $e($hora_fmt);
            $local_e = $e($local_evento !== '' ? $local_evento : '-');
            $cliente_e = $e($cliente_nome !== '' ? $cliente_nome : '-');
            $portal_url_e = $e($portal_url);
            $corpo = "
                <h2>Formulário preenchido</h2>
                <p>O cliente enviou/atualizou o formulário de DJ do evento abaixo:</p>
                <p><strong>Evento:</strong> {$nome_evento_e}</p>
                <p><strong>Data:</strong> {$data_fmt_e}</p>
                <p><strong>Horário:</strong> {$hora_fmt_e}</p>
                <p><strong>Local:</strong> {$local_e}</p>
                <p><strong>Cliente:</strong> {$cliente_e}</p>
                <br>
                <p><a href='{$portal_url_e}' style='display:inline-block;padding:12px 18px;background:#1e3a8a;color:#fff;text-decoration:none;border-radius:8px;'>Abrir no Portal DJ</a></p>
                <p style='color:#64748b;font-size:14px;margin-top:10px;'>Se o link solicitar login, entre com seu usuário do Portal DJ.</p>
                <br>
                <p>Atenciosamente,<br>Grupo Smile</p>
            ";

            $emailHelper = new EmailGlobalHelper();
            foreach ($emails as $to) {
                $emailHelper->enviarEmail($to, $assunto, $corpo);
            }
        }
        
    } catch (Exception $e) {
        error_log("Erro ao notificar envio DJ: " . $e->getMessage());
    }
}

/**
 * Notificar Decoração quando houver alteração relevante (ex.: seção de decoração editada)
 */
function eventos_notificar_decoracao_atualizada(PDO $pdo, int $meeting_id): void {
    try {
        $stmt = $pdo->prepare("
            SELECT r.id, r.me_event_id, r.me_event_snapshot, r.fornecedor_decoracao_id
            FROM eventos_reunioes r
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $meeting_id]);
        $reuniao = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$reuniao) {
            return;
        }

        $e = function($value): string {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };

        $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $nome_evento = trim((string)($snapshot['nome'] ?? 'Evento'));
        $data_evento = trim((string)($snapshot['data'] ?? ''));
        $data_ts = $data_evento !== '' ? strtotime($data_evento) : false;
        $data_fmt = $data_ts ? date('d/m/Y', $data_ts) : '-';
        $hora_inicio = trim((string)($snapshot['hora_inicio'] ?? ''));
        $hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
        $hora_fmt = $hora_inicio !== '' ? $hora_inicio : '-';
        if ($hora_inicio !== '' && $hora_fim !== '') {
            $hora_fmt = $hora_inicio . ' - ' . $hora_fim;
        }
        $local_evento = trim((string)($snapshot['local'] ?? ''));
        $cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? ''));

        // Destinatários: fornecedor vinculado (se existir), senão todos fornecedores de decoração ativos.
        $emails = [];
        $fornecedor_id = (int)($reuniao['fornecedor_decoracao_id'] ?? 0);
        if ($fornecedor_id > 0) {
            $stmt = $pdo->prepare("SELECT email FROM eventos_fornecedores WHERE id = :id AND email IS NOT NULL");
            $stmt->execute([':id' => $fornecedor_id]);
            $email = trim((string)($stmt->fetchColumn() ?: ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        if (empty($emails)) {
            $stmt = $pdo->query("
                SELECT email
                FROM eventos_fornecedores
                WHERE tipo = 'decoracao'
                  AND ativo = TRUE
                  AND email IS NOT NULL
                  AND email <> ''
            ");
            $emails = array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        $emails = array_values(array_unique(array_filter($emails, fn($x) => is_string($x) && trim($x) !== '')));
        if (empty($emails)) {
            return;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $base_url = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));
        if ($base_url === '' && $host !== '') {
            $base_url = $scheme . '://' . $host;
        }
        $base_url = rtrim($base_url, '/');

        $me_event_id = (int)($reuniao['me_event_id'] ?? 0);
        $portal_url = $base_url !== '' && $me_event_id > 0
            ? $base_url . "/index.php?page=portal_decoracao&me_event_id=" . $me_event_id
            : "index.php?page=portal_decoracao";

        $assunto = "ATUALIZACAO DECORACAO - SMILE EVENTOS";
        $nome_evento_e = $e($nome_evento);
        $data_fmt_e = $e($data_fmt);
        $hora_fmt_e = $e($hora_fmt);
        $local_e = $e($local_evento !== '' ? $local_evento : '-');
        $cliente_e = $e($cliente_nome !== '' ? $cliente_nome : '-');
        $portal_url_e = $e($portal_url);
        $corpo = "
            <h2>Atualização de Decoração</h2>
            <p>Houve uma atualização nas informações de decoração do evento abaixo:</p>
            <p><strong>Evento:</strong> {$nome_evento_e}</p>
            <p><strong>Data:</strong> {$data_fmt_e}</p>
            <p><strong>Horário:</strong> {$hora_fmt_e}</p>
            <p><strong>Local:</strong> {$local_e}</p>
            <p><strong>Cliente:</strong> {$cliente_e}</p>
            <br>
            <p><a href='{$portal_url_e}' style='display:inline-block;padding:12px 18px;background:#059669;color:#fff;text-decoration:none;border-radius:8px;'>Abrir no Portal Decoração</a></p>
            <p style='color:#64748b;font-size:14px;margin-top:10px;'>Se o link solicitar login, entre com seu usuário do Portal Decoração.</p>
            <br>
            <p>Atenciosamente,<br>Grupo Smile</p>
        ";

        $emailHelper = new EmailGlobalHelper();
        foreach ($emails as $to) {
            $emailHelper->enviarEmail($to, $assunto, $corpo);
        }
    } catch (Throwable $e) {
        error_log("Erro ao notificar decoração atualizada: " . $e->getMessage());
    }
}

/**
 * Notificar Decoração quando a reunião for marcada como concluída.
 */
function eventos_notificar_decoracao_reuniao_concluida(PDO $pdo, int $meeting_id): void {
    try {
        $stmt = $pdo->prepare("
            SELECT r.id, r.me_event_id, r.me_event_snapshot, r.fornecedor_decoracao_id
            FROM eventos_reunioes r
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $meeting_id]);
        $reuniao = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$reuniao) {
            return;
        }

        // Reaproveita a mesma regra de destinatários e layout, mudando o assunto/título.
        // Para evitar duplicação pesada aqui, chamamos a função de atualização com assunto customizado via envio manual.
        // (Implementação intencionalmente simples: mesma composição base, com assunto diferente.)
        $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $e = function($value): string {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };

        $nome_evento = trim((string)($snapshot['nome'] ?? 'Evento'));
        $data_evento = trim((string)($snapshot['data'] ?? ''));
        $data_ts = $data_evento !== '' ? strtotime($data_evento) : false;
        $data_fmt = $data_ts ? date('d/m/Y', $data_ts) : '-';
        $hora_inicio = trim((string)($snapshot['hora_inicio'] ?? ''));
        $hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
        $hora_fmt = $hora_inicio !== '' ? $hora_inicio : '-';
        if ($hora_inicio !== '' && $hora_fim !== '') {
            $hora_fmt = $hora_inicio . ' - ' . $hora_fim;
        }
        $local_evento = trim((string)($snapshot['local'] ?? ''));
        $cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? ''));

        $emails = [];
        $fornecedor_id = (int)($reuniao['fornecedor_decoracao_id'] ?? 0);
        if ($fornecedor_id > 0) {
            $stmt = $pdo->prepare("SELECT email FROM eventos_fornecedores WHERE id = :id AND email IS NOT NULL");
            $stmt->execute([':id' => $fornecedor_id]);
            $email = trim((string)($stmt->fetchColumn() ?: ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        if (empty($emails)) {
            $stmt = $pdo->query("
                SELECT email
                FROM eventos_fornecedores
                WHERE tipo = 'decoracao'
                  AND ativo = TRUE
                  AND email IS NOT NULL
                  AND email <> ''
            ");
            $emails = array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        $emails = array_values(array_unique(array_filter($emails, fn($x) => is_string($x) && trim($x) !== '')));
        if (empty($emails)) {
            return;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $base_url = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));
        if ($base_url === '' && $host !== '') {
            $base_url = $scheme . '://' . $host;
        }
        $base_url = rtrim($base_url, '/');

        $me_event_id = (int)($reuniao['me_event_id'] ?? 0);
        $portal_url = $base_url !== '' && $me_event_id > 0
            ? $base_url . "/index.php?page=portal_decoracao&me_event_id=" . $me_event_id
            : "index.php?page=portal_decoracao";

        $assunto = "REUNIAO CONCLUIDA - SMILE EVENTOS";
        $nome_evento_e = $e($nome_evento);
        $data_fmt_e = $e($data_fmt);
        $hora_fmt_e = $e($hora_fmt);
        $local_e = $e($local_evento !== '' ? $local_evento : '-');
        $cliente_e = $e($cliente_nome !== '' ? $cliente_nome : '-');
        $portal_url_e = $e($portal_url);
        $corpo = "
            <h2>Reunião concluída</h2>
            <p>A reunião final do evento abaixo foi marcada como concluída e está pronta para conferência:</p>
            <p><strong>Evento:</strong> {$nome_evento_e}</p>
            <p><strong>Data:</strong> {$data_fmt_e}</p>
            <p><strong>Horário:</strong> {$hora_fmt_e}</p>
            <p><strong>Local:</strong> {$local_e}</p>
            <p><strong>Cliente:</strong> {$cliente_e}</p>
            <br>
            <p><a href='{$portal_url_e}' style='display:inline-block;padding:12px 18px;background:#059669;color:#fff;text-decoration:none;border-radius:8px;'>Abrir no Portal Decoração</a></p>
            <p style='color:#64748b;font-size:14px;margin-top:10px;'>Se o link solicitar login, entre com seu usuário do Portal Decoração.</p>
            <br>
            <p>Atenciosamente,<br>Grupo Smile</p>
        ";

        $emailHelper = new EmailGlobalHelper();
        foreach ($emails as $to) {
            $emailHelper->enviarEmail($to, $assunto, $corpo);
        }
    } catch (Throwable $e) {
        error_log("Erro ao notificar reunião concluída (decoração): " . $e->getMessage());
    }
}

/**
 * Notificar quando conteúdo é atualizado (para fornecedores vinculados)
 */
function eventos_notificar_conteudo_atualizado(PDO $pdo, int $meeting_id, string $section): void {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   r.fornecedor_dj_id,
                   r.fornecedor_decoracao_id
            FROM eventos_reunioes r
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $meeting_id]);
        $reuniao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reuniao) return;
        
        $fornecedor_id = null;
        $tipo = '';
        
        if ($section === 'dj_protocolo' && !empty($reuniao['fornecedor_dj_id'])) {
            $fornecedor_id = $reuniao['fornecedor_dj_id'];
            $tipo = 'DJ';
        } elseif ($section === 'decoracao' && !empty($reuniao['fornecedor_decoracao_id'])) {
            $fornecedor_id = $reuniao['fornecedor_decoracao_id'];
            $tipo = 'Decoração';
        }
        
        if ($fornecedor_id) {
            $stmt = $pdo->prepare("SELECT email FROM eventos_fornecedores WHERE id = :id AND email IS NOT NULL");
            $stmt->execute([':id' => $fornecedor_id]);
            $email = $stmt->fetchColumn();
            
            if ($email) {
                $emailHelper = new EmailGlobalHelper();
                $emailHelper->enviarEmail(
                    $email,
                    "Atualização de {$tipo} - {$reuniao['nome_evento']}",
                    "
                        <h2>Conteúdo atualizado!</h2>
                        <p>Houve uma atualização na seção de {$tipo} do evento:</p>
                        <p><strong>{$reuniao['nome_evento']}</strong></p>
                        <p>Acesse o Portal para visualizar os detalhes.</p>
                    "
                );
            }
        }
        
    } catch (Exception $e) {
        error_log("Erro ao notificar atualização: " . $e->getMessage());
    }
}

/**
 * Notificar quando versão é restaurada (log interno)
 */
function eventos_notificar_versao_restaurada(PDO $pdo, int $meeting_id, string $section, int $version_number, int $user_id): void {
    try {
        // Apenas log - não envia notificação
        error_log("[EVENTOS] Versão #{$version_number} restaurada na seção {$section} da reunião {$meeting_id} por user {$user_id}");
        
    } catch (Exception $e) {
        error_log("Erro ao logar restauração: " . $e->getMessage());
    }
}
