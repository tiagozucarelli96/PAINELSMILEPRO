<?php
/**
 * Página pública de visualização do formulário DJ para fornecedores.
 * Usa o token do formulário DJ, mas não expõe a área editável do cliente.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

$token = trim((string)($_GET['token'] ?? ''));
$error = '';
$link = null;
$reuniao = null;
$schema = [];
$values = [];
$anexos = [];

function dj_fornecedor_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function dj_fornecedor_payload_values(string $html): array {
    $html = html_entity_decode($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (!preg_match('/data-smile-client-payload\s*=\s*(["\'])(.*?)\1/i', $html, $matches)) {
        return [];
    }
    $decoded = base64_decode(trim((string)($matches[2] ?? '')), true);
    if ($decoded === false || $decoded === '') {
        return [];
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload) || !isset($payload['values']) || !is_array($payload['values'])) {
        return [];
    }
    $values = [];
    foreach ($payload['values'] as $key => $value) {
        $values[(string)$key] = (string)$value;
    }
    return $values;
}

function dj_fornecedor_html_to_text(string $html): string {
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $text = preg_replace('/<\/p\s*>/i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

function dj_fornecedor_extrair_values_html_legado(string $html, array $schema): array {
    $html = trim($html);
    if ($html === '' || empty($schema)) {
        return [];
    }
    $values = [];
    foreach ($schema as $field) {
        if (!is_array($field)) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? '')));
        if (in_array($type, ['section', 'note', 'divider', 'file'], true)) {
            continue;
        }
        $field_id = trim((string)($field['id'] ?? ''));
        $label = trim((string)($field['label'] ?? ''));
        if ($field_id === '' || $label === '') {
            continue;
        }
        $pattern = '#<p\b[^>]*>\s*<strong>\s*' . preg_quote($label, '#') . '\s*</strong>\s*<br\s*/?>(.*?)</p>#isu';
        if (!preg_match($pattern, $html, $matches)) {
            continue;
        }
        $value = dj_fornecedor_html_to_text((string)($matches[1] ?? ''));
        $value = preg_replace('/^Ordem:\s*\d+\s*/i', '', $value) ?? $value;
        $value = preg_replace('/Tempo da música:\s*(Música inteira|\d{1,2}:\d{2}\s+até\s+\d{1,2}:\d{2})/iu', '', $value) ?? $value;
        $value = trim(str_replace('Arquivo anexado separadamente.', '', $value));
        if ($value !== '' && strcasecmp($value, 'Não informado') !== 0) {
            $values[$field_id] = $value;
        }
    }
    return $values;
}

function dj_fornecedor_anexos_por_campo(array $anexos, string $field_id): array {
    $field_id = trim($field_id);
    if ($field_id === '') {
        return [];
    }
    $filtered = [];
    foreach ($anexos as $anexo) {
        if (is_array($anexo) && trim((string)($anexo['form_field_id'] ?? '')) === $field_id) {
            $filtered[] = $anexo;
        }
    }
    return $filtered;
}

function dj_fornecedor_time_key(string $field_id, string $part): string {
    $part = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($part)) ?: 'part';
    return trim($field_id) . '__music_time_' . $part;
}

function dj_fornecedor_field_tem_tempo(array $field): bool {
    if (strtolower(trim((string)($field['type'] ?? ''))) !== 'textarea') {
        return false;
    }
    $label = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string)($field['label'] ?? '')), 'UTF-8')
        : strtolower(trim((string)($field['label'] ?? '')));
    foreach ([
        'música da entrada da debutante para o cerimonial',
        'vai ter sapato, anel e etc',
        'valsa. se for ter mais de uma',
        'irá ter mais algum momento especial no cerimonial',
    ] as $needle) {
        if (strpos($label, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function dj_fornecedor_render_anexos(array $anexos): string {
    if (empty($anexos)) {
        return '';
    }
    $html = '<div class="files"><div class="files-title">Arquivos do campo</div>';
    foreach ($anexos as $anexo) {
        if (!is_array($anexo)) {
            continue;
        }
        $nome = trim((string)($anexo['original_name'] ?? 'arquivo'));
        $url = trim((string)($anexo['public_url'] ?? ''));
        $mime = trim((string)($anexo['mime_type'] ?? ''));
        $note = trim((string)($anexo['note'] ?? $anexo['descricao'] ?? ''));
        $kind = strtolower(trim((string)($anexo['file_kind'] ?? '')));
        $icon = ($kind === 'audio' || stripos($mime, 'audio/') === 0) ? '🎵' : '📎';
        $html .= '<div class="file-row">';
        $html .= '<div class="file-main"><span>' . $icon . '</span><div><strong>' . dj_fornecedor_e($nome !== '' ? $nome : 'arquivo') . '</strong>';
        if ($mime !== '') {
            $html .= '<small>' . dj_fornecedor_e($mime) . '</small>';
        }
        if ($note !== '') {
            $html .= '<small>Obs: ' . dj_fornecedor_e($note) . '</small>';
        }
        $html .= '</div></div>';
        if ($url !== '') {
            $html .= '<div class="file-actions">';
            $html .= '<a class="btn secondary" href="' . dj_fornecedor_e($url) . '" target="_blank" rel="noopener noreferrer">Abrir</a>';
            $html .= '<a class="btn primary" href="' . dj_fornecedor_e($url) . '" target="_blank" rel="noopener noreferrer" download>Download</a>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function dj_fornecedor_render_schema(array $schema, array $values, array $anexos): string {
    if (empty($schema) || empty($values)) {
        return '<p class="empty">Nenhuma informação enviada neste formulário.</p>';
    }

    $html = '<div class="schema-view">';
    $section_title = 'Informações';
    $section_cards = '';
    $flush = static function () use (&$html, &$section_title, &$section_cards): void {
        if (trim($section_cards) === '') {
            return;
        }
        $html .= '<section class="section"><h2>' . dj_fornecedor_e($section_title !== '' ? $section_title : 'Informações') . '</h2>' . $section_cards . '</section>';
        $section_cards = '';
    };

    $count = count($schema);
    for ($i = 0; $i < $count; $i++) {
        $field = is_array($schema[$i] ?? null) ? (array)$schema[$i] : [];
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $field_id = trim((string)($field['id'] ?? ''));
        $label = trim((string)($field['label'] ?? 'Campo'));

        if ($type === 'section') {
            $flush();
            $section_title = $label !== '' ? $label : 'Seção';
            continue;
        }
        if (in_array($type, ['note', 'divider'], true)) {
            continue;
        }

        $upload_field = null;
        if ($type !== 'file' && isset($schema[$i + 1]) && is_array($schema[$i + 1]) && strtolower(trim((string)($schema[$i + 1]['type'] ?? ''))) === 'file') {
            $upload_field = (array)$schema[$i + 1];
            $i++;
        } elseif ($type === 'file') {
            $upload_field = $field;
        }

        $value = trim((string)($values[$field_id] ?? ''));
        if ($type === 'yesno') {
            $value = $value === 'sim' ? 'Sim' : ($value === 'nao' ? 'Não' : $value);
        }
        $field_anexos = is_array($upload_field)
            ? dj_fornecedor_anexos_por_campo($anexos, (string)($upload_field['id'] ?? ''))
            : dj_fornecedor_anexos_por_campo($anexos, $field_id);

        if ($value === '' && empty($field_anexos)) {
            continue;
        }

        $card = '<article class="card"><h3>' . dj_fornecedor_e($label !== '' ? $label : 'Campo') . '</h3>';
        if ($value !== '') {
            $card .= '<div class="answer">' . nl2br(dj_fornecedor_e($value)) . '</div>';
        }
        if (dj_fornecedor_field_tem_tempo($field)) {
            $start = trim((string)($values[dj_fornecedor_time_key($field_id, 'start')] ?? ''));
            $end = trim((string)($values[dj_fornecedor_time_key($field_id, 'end')] ?? ''));
            $full = trim((string)($values[dj_fornecedor_time_key($field_id, 'full')] ?? '')) === '1';
            if ($full || $start !== '' || $end !== '') {
                $label_time = $full ? 'Música inteira' : (($start !== '' ? $start : '00:00') . ' até ' . ($end !== '' ? $end : '00:00'));
                $card .= '<div class="time"><strong>Tempo da música:</strong> ' . dj_fornecedor_e($label_time) . '</div>';
            }
        }
        $card .= dj_fornecedor_render_anexos($field_anexos);
        $card .= '</article>';
        $section_cards .= $card;
    }
    $flush();
    $html .= '</div>';
    return $html;
}

if ($token === '') {
    $error = 'Link inválido.';
} else {
    $link = eventos_link_publico_get($pdo, $token);
    if (!$link || empty($link['is_active'])) {
        $error = 'Link inválido ou inativo.';
    } elseif (strtolower(trim((string)($link['link_type'] ?? ''))) !== 'cliente_dj') {
        $error = 'Este link não é de DJ / Protocolos.';
    } elseif (!empty($link['expires_at']) && strtotime((string)$link['expires_at']) < time()) {
        $error = 'Link expirado.';
    } else {
        $meeting_id = (int)($link['meeting_id'] ?? 0);
        $reuniao = $meeting_id > 0 ? eventos_reuniao_get($pdo, $meeting_id) : null;
        if (!$reuniao) {
            $error = 'Evento não encontrado.';
        } else {
            $secao_dj = eventos_reuniao_get_secao($pdo, $meeting_id, 'dj_protocolo');
            $content = is_array($secao_dj) ? trim((string)($secao_dj['content_html'] ?? '')) : '';
            if ($content === '') {
                $content = trim((string)($link['content_html_snapshot'] ?? ''));
            }
            if ($content === '') {
                $content = trim((string)($link['draft_content_html_snapshot'] ?? ''));
            }

            $schema = [];
            if (is_array($secao_dj) && !empty($secao_dj['form_schema_json'])) {
                $decoded_schema = json_decode((string)$secao_dj['form_schema_json'], true);
                if (is_array($decoded_schema)) {
                    $schema = eventos_form_template_normalizar_schema($decoded_schema);
                }
            }
            if (empty($schema)) {
                $schema = eventos_form_template_normalizar_schema(is_array($link['form_schema'] ?? null) ? $link['form_schema'] : []);
            }
            $values = dj_fornecedor_payload_values($content);
            if (empty($values)) {
                $values = dj_fornecedor_extrair_values_html_legado($content, $schema);
            }
            if ($content === '' && empty($link['submitted_at'])) {
                $error = 'O formulário ainda não foi enviado.';
            }
            $anexos = eventos_reuniao_get_anexos($pdo, $meeting_id, 'dj_protocolo');
            if (empty($anexos)) {
                $anexos = eventos_reuniao_get_anexos_link_finais($pdo, $meeting_id, 'dj_protocolo', (int)($link['id'] ?? 0));
            }
        }
    }
}

$snapshot = $reuniao ? json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true) : [];
$snapshot = is_array($snapshot) ? $snapshot : [];
$nome_evento = trim((string)($snapshot['nome'] ?? 'Evento'));
$data_evento = trim((string)($snapshot['data'] ?? ''));
$data_fmt = $data_evento !== '' && strtotime($data_evento) ? date('d/m/Y', strtotime($data_evento)) : '-';
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['horainicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? $snapshot['horafim'] ?? $snapshot['horatermino'] ?? ''));
$horario = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario = $hora_inicio . ' - ' . $hora_fim;
}
$local = trim((string)($snapshot['local'] ?? '-'));
$cliente_info = is_array($snapshot['cliente'] ?? null) ? (array)$snapshot['cliente'] : [];
$cliente = trim((string)($cliente_info['nome'] ?? $snapshot['cliente_nome'] ?? '-'));
$convidados = trim((string)($snapshot['convidados'] ?? $snapshot['nconvidados'] ?? '-'));
$unidade = trim((string)($snapshot['unidade'] ?? $snapshot['local_unidade'] ?? $local));
$slot = (int)($link['slot_index'] ?? 1);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DJ / Protocolos - <?= dj_fornecedor_e($nome_evento) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f4f7fb; color: #0f172a; }
        .page { max-width: 1040px; margin: 0 auto; padding: 24px; }
        .header { background: #fff; border: 1px solid #dbe3ef; border-radius: 14px; padding: 20px; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
        .brand { display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 2px solid #0f172a; padding-bottom: 14px; }
        .brand h1 { margin: 0; font-size: 1.35rem; }
        .brand p { margin: 4px 0 0 0; color: #475569; font-weight: 700; }
        .logo { max-width: 210px; height: auto; }
        .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; margin-top: 16px; }
        .meta-item { border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; background: #f8fafc; }
        .meta-item strong { display: block; color: #475569; font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 3px; }
        .meta-item span { font-weight: 800; color: #0f172a; }
        .schema-view { display: grid; gap: 18px; margin-top: 18px; }
        .section { display: grid; gap: 10px; }
        .section h2 { margin: 0; padding: 10px 12px; background: #eaf1ff; border: 1px solid #bfdbfe; border-radius: 10px; color: #1e3a8a; font-size: 1rem; }
        .card { border: 1px solid #dbe3ef; border-left: 6px solid #2563eb; background: #fff; border-radius: 12px; padding: 14px; box-shadow: 0 3px 10px rgba(15,23,42,.05); }
        .card h3 { margin: 0 0 8px 0; font-size: .98rem; color: #0f172a; }
        .answer { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; line-height: 1.5; overflow-wrap: anywhere; }
        .time { display: inline-flex; margin-top: 10px; padding: 5px 10px; border-radius: 999px; border: 1px solid #fde68a; background: #fffbeb; color: #92400e; font-size: .86rem; gap: 4px; }
        .files { margin-top: 12px; border-top: 1px solid #e5e7eb; padding-top: 10px; display: grid; gap: 7px; }
        .files-title { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; color: #334155; font-weight: 900; }
        .file-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; background: #f8fbff; border: 1px solid #dbeafe; border-radius: 10px; padding: 9px; }
        .file-main { display: flex; gap: 8px; min-width: 0; }
        .file-main strong { display: block; color: #1d4ed8; overflow-wrap: anywhere; }
        .file-main small { display: block; color: #64748b; margin-top: 2px; }
        .file-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; padding: 7px 11px; text-decoration: none; font-weight: 800; font-size: .84rem; border: 1px solid transparent; }
        .btn.primary { background: #1e3a8a; color: #fff; }
        .btn.secondary { background: #fff; color: #0f172a; border-color: #cbd5e1; }
        .empty, .error { margin-top: 18px; background: #fff; border: 1px solid #dbe3ef; border-radius: 12px; padding: 18px; color: #64748b; }
        .error { border-color: #fecaca; color: #991b1b; background: #fff7f7; }
        @media print {
            body { background: #fff; }
            .page { max-width: none; padding: 0; }
            .header, .card { box-shadow: none; }
            .btn { display: none; }
        }
    </style>
</head>
<body>
    <main class="page">
        <?php if ($error !== ''): ?>
            <div class="error"><?= dj_fornecedor_e($error) ?></div>
        <?php else: ?>
            <header class="header">
                <div class="brand">
                    <div>
                        <h1>DJ / Protocolos</h1>
                        <p><?= dj_fornecedor_e($nome_evento) ?> • Quadro <?= $slot ?></p>
                    </div>
                    <img class="logo" src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
                </div>
                <div class="meta">
                    <div class="meta-item"><strong>Data</strong><span><?= dj_fornecedor_e($data_fmt) ?></span></div>
                    <div class="meta-item"><strong>Horário</strong><span><?= dj_fornecedor_e($horario) ?></span></div>
                    <div class="meta-item"><strong>Local</strong><span><?= dj_fornecedor_e($local !== '' ? $local : '-') ?></span></div>
                    <div class="meta-item"><strong>Convidados</strong><span><?= dj_fornecedor_e($convidados !== '' ? $convidados : '-') ?></span></div>
                    <div class="meta-item"><strong>Cliente</strong><span><?= dj_fornecedor_e($cliente !== '' ? $cliente : '-') ?></span></div>
                    <div class="meta-item"><strong>Unidade</strong><span><?= dj_fornecedor_e($unidade !== '' ? $unidade : '-') ?></span></div>
                </div>
            </header>
            <?= dj_fornecedor_render_schema($schema, $values, is_array($anexos) ? $anexos : []) ?>
        <?php endif; ?>
    </main>
</body>
</html>
