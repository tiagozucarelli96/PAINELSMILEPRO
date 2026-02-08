<?php
/**
 * eventos_pdf.php
 * Geração de PDF / impressão por seção da Reunião Final.
 *
 * Uso:
 * - index.php?page=eventos_pdf&id=MEETING_ID&section=decoracao&mode=print
 * - index.php?page=eventos_pdf&id=MEETING_ID&section=decoracao&mode=pdf
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

// Permissão
if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    http_response_code(403);
    echo 'Sem permissão.';
    exit;
}

$meeting_id = (int)($_GET['id'] ?? 0);
$section = trim((string)($_GET['section'] ?? 'decoracao'));
$mode = trim((string)($_GET['mode'] ?? 'print')); // print|pdf

$allowed_sections = ['decoracao', 'observacoes_gerais', 'dj_protocolo'];
if (!in_array($section, $allowed_sections, true)) {
    http_response_code(400);
    echo 'Seção inválida.';
    exit;
}

if (!in_array($mode, ['print', 'pdf'], true)) {
    http_response_code(400);
    echo 'Modo inválido.';
    exit;
}

if ($meeting_id <= 0) {
    http_response_code(400);
    echo 'Reunião inválida.';
    exit;
}

$reuniao = eventos_reuniao_get($pdo, $meeting_id);
if (!$reuniao) {
    http_response_code(404);
    echo 'Reunião não encontrada.';
    exit;
}

$secao = eventos_reuniao_get_secao($pdo, $meeting_id, $section);
$content_html = (string)($secao['content_html'] ?? '');

$snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
$snapshot = is_array($snapshot) ? $snapshot : [];

$nome_evento = trim((string)($snapshot['nome'] ?? 'Evento'));
$convidados_evento = (int)($snapshot['convidados'] ?? $snapshot['nconvidados'] ?? 0);

$data_evento = trim((string)($snapshot['data'] ?? ''));
$data_ts = $data_evento !== '' ? strtotime($data_evento) : false;
$data_fmt = $data_ts ? date('d/m/Y', $data_ts) : '-';

$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['horainicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? $snapshot['horafim'] ?? $snapshot['horatermino'] ?? ''));
$horario_fmt = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_fmt = $hora_inicio . ' - ' . $hora_fim;
}

$emitido_por = trim((string)($_SESSION['nome'] ?? 'Usuário'));

$section_titles = [
    'decoracao' => 'Decoração',
    'observacoes_gerais' => 'Observações Gerais',
    'dj_protocolo' => 'DJ / Protocolos',
];
$section_title = $section_titles[$section] ?? $section;

// Logo (data URI) para funcionar em PDF e impressão.
$logo_data_uri = '';
$logo_file = '';
foreach (['logo-smile.png', 'logo.png'] as $candidate) {
    $path = __DIR__ . '/' . $candidate;
    if (is_file($path)) {
        $logo_file = $path;
        break;
    }
}
if ($logo_file !== '') {
    $raw = @file_get_contents($logo_file);
    if (is_string($raw) && $raw !== '') {
        $ext = strtolower(pathinfo($logo_file, PATHINFO_EXTENSION));
        $mime = 'image/png';
        if ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
        if ($ext === 'gif') $mime = 'image/gif';
        if ($ext === 'webp') $mime = 'image/webp';
        $logo_data_uri = 'data:' . $mime . ';base64,' . base64_encode($raw);
    }
}

function e_pdf(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$qr_data_uri = '';
$qr_target_url = '';
try {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $scheme = 'http';
        $xf_proto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($xf_proto !== '') {
            $scheme = explode(',', $xf_proto)[0];
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        }

        $qr_target_url = $scheme . '://' . $host . '/index.php?page=eventos_reuniao_final&id=' . $meeting_id;

        // Evita dependência de lib externa: gera via serviço público e embute como data URI (melhor para PDF).
        $qr_remote_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=0&data=' . rawurlencode($qr_target_url);
        $ctx = stream_context_create([
            'http' => ['timeout' => 2],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $qr_raw = @file_get_contents($qr_remote_url, false, $ctx);
        if (is_string($qr_raw) && $qr_raw !== '') {
            $qr_data_uri = 'data:image/png;base64,' . base64_encode($qr_raw);
        }
    }
} catch (Throwable $e) {
    // Se falhar, seguimos sem QR Code.
    $qr_data_uri = '';
    $qr_target_url = '';
}

$doc_title = $section_title . ' - ' . $nome_evento;
$content_html_or_placeholder = trim($content_html) !== ''
    ? $content_html
    : '<p style="color:#64748b;font-style:italic;">Nenhuma informação cadastrada nesta aba.</p>';

$logo_html = '';
if ($logo_data_uri !== '') {
    $logo_html = '<img class="doc-logo" src="' . e_pdf($logo_data_uri) . '" alt="Grupo Smile Eventos">';
}

$qr_html = '';
if ($qr_data_uri !== '') {
    $qr_html = '<img class="doc-qr" src="' . e_pdf($qr_data_uri) . '" alt="QR Code">';
}

$data_evento_fmt = $data_fmt;
if ($hora_inicio !== '' && $hora_fim !== '') {
    $data_evento_fmt = $data_fmt . ' às ' . $hora_inicio . ' às ' . $hora_fim;
} elseif ($hora_inicio !== '') {
    $data_evento_fmt = $data_fmt . ' às ' . $hora_inicio;
}

$html = '<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . e_pdf($doc_title) . '</title>
  <style>
    :root { --ink:#0f172a; --muted:#475569; --line:#e5e7eb; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: DejaVu Sans, Arial, sans-serif; color: var(--ink); }
    .doc { padding: 24px; }
    .doc-header { padding-bottom: 10px; border-bottom: 2px solid #0f172a; }
    .doc-header-top { width: 100%; border-collapse: collapse; }
    .doc-header-top td { vertical-align: top; }
    .doc-logo { width: 300px; height: auto; object-fit: contain; }
    .doc-issuer { text-align: center; padding-top: 8px; }
    .doc-issuer-name { font-size: 18px; font-weight: 800; line-height: 1.1; }
    .doc-issuer-sub { margin-top: 2px; font-size: 12px; color: var(--muted); font-weight: 700; letter-spacing: 0.02em; }
    .doc-qr-wrap { text-align: right; width: 180px; }
    .doc-qr { width: 160px; height: 160px; }

    .doc-meta-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .doc-meta-table td { width: 50%; vertical-align: top; font-size: 13.5px; padding-top: 4px; }
    .doc-meta-row { margin: 0 0 6px 0; }
    .doc-meta-label { font-weight: 900; }
    .doc-meta-value { font-weight: 500; }

    .doc-section-title { margin: 18px 0 12px 0; font-size: 16px; font-weight: 900; }
    .doc-body { padding-top: 16px; }
    .doc-body img { max-width: 100%; height: auto; }
    .doc-body table { width: 100%; border-collapse: collapse; }
    .doc-body table th, .doc-body table td { border: 1px solid var(--line); padding: 6px 8px; vertical-align: top; }
    .doc-body h1, .doc-body h2, .doc-body h3 { margin: 0 0 10px 0; }
    .doc-body p { margin: 0 0 10px 0; }

    @page { margin: 12mm; }
    @media print {
      .doc { padding: 0; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
  <div class="doc">
    <div class="doc-header">
      <table class="doc-header-top">
        <tr>
          <td>' . $logo_html . '</td>
          <td class="doc-issuer">
            <div class="doc-issuer-name">' . e_pdf($emitido_por) . '</div>
            <div class="doc-issuer-sub">Emitido por</div>
          </td>
          <td class="doc-qr-wrap">' . $qr_html . '</td>
        </tr>
      </table>
      <table class="doc-meta-table">
        <tr>
          <td>
            <p class="doc-meta-row"><span class="doc-meta-label">Evento:</span> <span class="doc-meta-value">' . e_pdf($nome_evento) . '</span></p>
            <p class="doc-meta-row"><span class="doc-meta-label">Data do Evento:</span> <span class="doc-meta-value">' . e_pdf($data_evento_fmt) . '</span></p>
          </td>
          <td>
            <p class="doc-meta-row"><span class="doc-meta-label">Nº de Participantes:</span> <span class="doc-meta-value">' . (int)$convidados_evento . '</span></p>
            <p class="doc-meta-row"><span class="doc-meta-label">Emitido por:</span> <span class="doc-meta-value">' . e_pdf($emitido_por) . '</span></p>
          </td>
        </tr>
      </table>
    </div>
    <div class="doc-body">
      <div class="doc-section-title">' . e_pdf($section_title) . '</div>
      ' . $content_html_or_placeholder . '
    </div>
  </div>';

if ($mode === 'print') {
    $html .= '
  <script>
    window.addEventListener("load", function() {
      setTimeout(function() {
        try { window.print(); } catch (e) {}
      }, 250);
    });
  </script>';
}

$html .= '
</body>
</html>';

if ($mode === 'pdf') {
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $safe_section = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $section);
        $safe_event = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $nome_evento);
        $filename = 'reuniao_' . $meeting_id . '_' . $safe_section . '_' . $safe_event . '.pdf';
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Erro ao gerar PDF: ' . e_pdf($e->getMessage());
        exit;
    }
}

echo $html;
