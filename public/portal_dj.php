<?php
/**
 * portal_dj.php
 * Painel do Portal DJ (acesso externo)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

// Verificar login
if (empty($_SESSION['portal_dj_logado']) || $_SESSION['portal_dj_logado'] !== true) {
    $redirect = (string)($_SERVER['REQUEST_URI'] ?? '');
    $redirect_param = '';
    $starts_ok = (substr($redirect, 0, 9) === '/index.php' || substr($redirect, 0, 8) === 'index.php');
    if ($redirect !== '' && $starts_ok && strpos($redirect, 'page=portal_dj') !== false) {
        $redirect_param = '&redirect=' . urlencode($redirect);
    }
    header('Location: index.php?page=portal_dj_login' . $redirect_param);
    exit;
}

$fornecedor_id = $_SESSION['portal_dj_fornecedor_id'];
$nome = $_SESSION['portal_dj_nome'];

// Logout
if (isset($_GET['logout'])) {
    // Invalidar sessão no banco
    if (!empty($_SESSION['portal_dj_token'])) {
        $stmt = $pdo->prepare("UPDATE eventos_fornecedores_sessoes SET ativo = FALSE WHERE token = :token");
        $stmt->execute([':token' => $_SESSION['portal_dj_token']]);
    }
    
    unset($_SESSION['portal_dj_logado']);
    unset($_SESSION['portal_dj_fornecedor_id']);
    unset($_SESSION['portal_dj_nome']);
    unset($_SESSION['portal_dj_token']);
    
    header('Location: index.php?page=portal_dj_login');
    exit;
}

// Buscar próximos 30 eventos (somente tipo real casamento/15 anos)
$eventos = [];
$eventos_por_data = [];
$erro_eventos = '';
$aviso_evento_cancelado = '';
$start_date = date('Y-m-d');
try {
    $start = new DateTime('today');
    $start_date = $start->format('Y-m-d');
    $tipo_real_expr = "COALESCE(NULLIF(LOWER(TRIM(r.tipo_evento_real)), ''), LOWER(TRIM(COALESCE(r.me_event_snapshot->>'tipo_evento_real', ''))))";

    $stmt = $pdo->prepare("
        SELECT r.id,
               r.me_event_id,
               r.status,
               r.me_event_snapshot,
               (r.me_event_snapshot->>'data')::date as data_evento,
               (r.me_event_snapshot->>'nome') as nome_evento,
               (r.me_event_snapshot->>'local') as local_evento,
               (r.me_event_snapshot->>'hora_inicio') as hora_inicio_evento,
               (r.me_event_snapshot->>'hora_fim') as hora_fim_evento,
               (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome
        FROM eventos_reunioes r
        WHERE (r.me_event_snapshot->>'data')::date >= :start
          AND {$tipo_real_expr} IN ('casamento', '15anos')
        ORDER BY (r.me_event_snapshot->>'data')::date ASC, (r.me_event_snapshot->>'hora_inicio') ASC, r.id ASC
        LIMIT 30
    ");
    $stmt->execute([':start' => $start_date]);
    $eventos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($eventos_raw as $ev) {
        $snapshot = json_decode((string)($ev['me_event_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $me_event_id = (int)($ev['me_event_id'] ?? ($snapshot['id'] ?? 0));
        $cancelado = (!empty($snapshot) && eventos_me_evento_cancelado($snapshot))
            || ($me_event_id > 0 && eventos_me_evento_cancelado_por_webhook($pdo, $me_event_id));

        if ($cancelado) {
            continue;
        }

        unset($ev['me_event_snapshot']);
        $ev['hora_evento'] = portal_dj_formatar_intervalo_evento($ev);
        $eventos[] = $ev;
    }

    foreach ($eventos as $ev) {
        $data_evento = trim((string)($ev['data_evento'] ?? ''));
        if ($data_evento === '') {
            continue;
        }
        $data_norm = date('Y-m-d', strtotime($data_evento));
        if (!isset($eventos_por_data[$data_norm])) {
            $eventos_por_data[$data_norm] = [];
        }
        $eventos_por_data[$data_norm][] = $ev;
    }
} catch (Exception $e) {
    error_log("Erro portal DJ (lista calendário): " . $e->getMessage());
    $erro_eventos = 'Erro interno ao buscar eventos.';
}

// Ver detalhes de um evento
$evento_selecionado = null;
$secao_dj = null;
$secao_observacoes = null;
$secao_formulario = null;
$anexos_dj = [];
$anexos_observacoes = [];
$anexos_formulario = [];
$observacoes_blocos = [];
$quadros_observacoes = [];
$arquivos_evento = [];

function portal_dj_formatar_horario_curto(?string $valor, string $fallback = '-'): string
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return $fallback;
    }

    if (preg_match('/\b(\d{1,2}):(\d{2})\b/', $valor, $matches)) {
        $hora = max(0, min(23, (int)$matches[1]));
        $minuto = max(0, min(59, (int)$matches[2]));
        return sprintf('%02d:%02d', $hora, $minuto);
    }

    $timestamp = strtotime($valor);
    if ($timestamp === false) {
        return $fallback;
    }

    return date('H:i', $timestamp);
}

function portal_dj_formatar_intervalo_evento(array $evento): string
{
    $inicio = portal_dj_formatar_horario_curto((string)($evento['hora_inicio_evento'] ?? $evento['hora_evento'] ?? ''), '');
    $fim = portal_dj_formatar_horario_curto((string)($evento['hora_fim_evento'] ?? ''), '');

    if ($inicio !== '' && $fim !== '') {
        return $inicio . ' - ' . $fim;
    }
    if ($inicio !== '') {
        return $inicio;
    }
    if ($fim !== '') {
        return $fim;
    }
    return '-';
}

function portal_dj_renderizar_lista_anexos(array $anexos, string $titulo, string $mensagem_vazia = 'Nenhum arquivo disponível.'): void
{
    ?>
    <div class="anexos-list">
        <h3 style="font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem;"><?= htmlspecialchars($titulo) ?></h3>
        <?php if (empty($anexos)): ?>
        <p style="color: #64748b; font-style: italic;"><?= htmlspecialchars($mensagem_vazia) ?></p>
        <?php else: ?>
        <?php foreach ($anexos as $a): ?>
        <?php
            $anexo_url = trim((string)($a['public_url'] ?? ''));
            $anexo_nome = trim((string)($a['original_name'] ?? 'arquivo'));
            $anexo_mime = strtolower(trim((string)($a['mime_type'] ?? 'application/octet-stream')));
            $anexo_kind = strtolower(trim((string)($a['file_kind'] ?? 'outros')));
            $anexo_size = (int)($a['size_bytes'] ?? 0);
            $anexo_note = trim((string)($a['note'] ?? $a['descricao'] ?? ''));
            $anexo_icon = '📎';
            if ($anexo_kind === 'imagem') {
                $anexo_icon = '🖼️';
            } elseif ($anexo_kind === 'video') {
                $anexo_icon = '🎬';
            } elseif ($anexo_kind === 'audio') {
                $anexo_icon = '🎵';
            } elseif ($anexo_kind === 'pdf') {
                $anexo_icon = '📄';
            }
        ?>
        <div class="anexo-item">
            <div class="anexo-main">
                <span class="anexo-icon"><?= $anexo_icon ?></span>
                <div class="anexo-info">
                    <div class="anexo-name"><?= htmlspecialchars($anexo_nome !== '' ? $anexo_nome : 'arquivo') ?></div>
                    <div class="anexo-meta">
                        <?= htmlspecialchars($anexo_mime !== '' ? $anexo_mime : 'application/octet-stream') ?>
                        • <?= $anexo_size > 0 ? htmlspecialchars(number_format($anexo_size / 1024, 1, ',', '.')) . ' KB' : '-' ?>
                    </div>
                    <?php if ($anexo_note !== ''): ?>
                    <div class="anexo-note"><strong>Obs:</strong> <?= htmlspecialchars($anexo_note) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="anexo-actions">
                <?php if ($anexo_url !== ''): ?>
                <button type="button"
                        class="btn btn-secondary btn-small"
                        data-open-anexo-modal="1"
                        data-url="<?= htmlspecialchars($anexo_url, ENT_QUOTES, 'UTF-8') ?>"
                        data-name="<?= htmlspecialchars($anexo_nome, ENT_QUOTES, 'UTF-8') ?>"
                        data-mime="<?= htmlspecialchars($anexo_mime, ENT_QUOTES, 'UTF-8') ?>"
                        data-kind="<?= htmlspecialchars($anexo_kind, ENT_QUOTES, 'UTF-8') ?>">
                    Visualizar
                </button>
                <a href="<?= htmlspecialchars($anexo_url) ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   download
                   class="btn btn-primary btn-small">Download</a>
                <?php else: ?>
                <span class="anexo-meta">Arquivo sem URL pública.</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

function portal_dj_secao_tem_conteudo(?array $secao): bool
{
    if (!$secao) {
        return false;
    }

    $schema_raw = $secao['form_schema_json'] ?? null;
    if (is_string($schema_raw) && trim($schema_raw) !== '') {
        $decoded = json_decode($schema_raw, true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $field_type = strtolower(trim((string)($field['type'] ?? '')));
                if (in_array($field_type, ['text', 'textarea', 'yesno', 'select', 'file', 'note', 'title'], true)) {
                    return true;
                }
            }
        }
    }

    $html = trim((string)($secao['content_html'] ?? ''));
    if ($html === '') {
        return false;
    }
    return trim(strip_tags($html)) !== '';
}

/**
 * Retorna IDs de campos do tipo "file" respeitando a ordem do schema.
 */
function portal_dj_schema_file_field_ids(?array $secao): array
{
    if (!$secao) {
        return [];
    }

    $schema_raw = $secao['form_schema_json'] ?? null;
    if (!is_string($schema_raw) || trim($schema_raw) === '') {
        return [];
    }

    $decoded = json_decode($schema_raw, true);
    if (!is_array($decoded) || empty($decoded)) {
        return [];
    }

    $ids = [];
    foreach ($decoded as $field) {
        if (!is_array($field)) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? '')));
        $field_id = trim((string)($field['id'] ?? ''));
        if ($type !== 'file' || $field_id === '') {
            continue;
        }
        $ids[] = $field_id;
    }

    return $ids;
}

function portal_dj_render_inline_anexo_link(array $anexo): string
{
    $name = trim((string)($anexo['original_name'] ?? 'arquivo'));
    if ($name === '') {
        $name = 'arquivo';
    }
    $name_esc = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $url = trim((string)($anexo['public_url'] ?? ''));
    if ($url === '') {
        return $name_esc;
    }

    return '<a class="inline-anexo-link" href="'
        . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '" target="_blank" rel="noopener noreferrer">'
        . $name_esc
        . '</a>';
}

/**
 * Injeta os anexos na mesma linha da frase "Arquivo anexado separadamente.",
 * replicando o comportamento do "Ver enviado" da reunião final.
 */
function portal_dj_renderizar_html_com_anexos_inline(string $content_html, array $anexos, ?array $secao): array
{
    $result = [
        'html' => $content_html,
        'used_inline' => false,
    ];

    $content_html = trim($content_html);
    if ($content_html === '' || empty($anexos)) {
        return $result;
    }

    $entries = [];
    foreach (array_values($anexos) as $idx => $anexo) {
        if (!is_array($anexo)) {
            continue;
        }
        $id = (int)($anexo['id'] ?? 0);
        $entries[] = [
            'anexo' => $anexo,
            'key' => $id > 0 ? ('id:' . $id) : ('idx:' . (int)$idx),
        ];
    }
    if (empty($entries)) {
        return $result;
    }

    $attachments_by_field = [];
    $unlinked = [];
    foreach ($entries as $entry) {
        $field_id = trim((string)($entry['anexo']['form_field_id'] ?? ''));
        if ($field_id === '') {
            $unlinked[] = $entry;
            continue;
        }
        if (!isset($attachments_by_field[$field_id]) || !is_array($attachments_by_field[$field_id])) {
            $attachments_by_field[$field_id] = [];
        }
        $attachments_by_field[$field_id][] = $entry;
    }

    $file_field_ids = portal_dj_schema_file_field_ids($secao);
    $has_field_mapping = !empty($attachments_by_field);
    $rendered_keys = [];
    $file_field_cursor = 0;
    $unlinked_fallback_used = false;
    $placeholder_hits = 0;

    $rendered_html = preg_replace_callback(
        '/Arquivo anexado separadamente\.?/iu',
        static function (array $matches) use (
            &$file_field_cursor,
            &$unlinked_fallback_used,
            &$placeholder_hits,
            &$rendered_keys,
            $file_field_ids,
            $attachments_by_field,
            $has_field_mapping,
            $unlinked
        ): string {
            $line_attachments = [];

            $field_id = $file_field_ids[$file_field_cursor] ?? '';
            if ($file_field_cursor < count($file_field_ids)) {
                $file_field_cursor++;
            }
            if ($field_id !== '' && !empty($attachments_by_field[$field_id])) {
                $line_attachments = $attachments_by_field[$field_id];
            } elseif (!$has_field_mapping && !$unlinked_fallback_used && !empty($unlinked)) {
                $line_attachments = $unlinked;
                $unlinked_fallback_used = true;
            }

            if (empty($line_attachments)) {
                return (string)($matches[0] ?? 'Arquivo anexado separadamente.');
            }

            $placeholder_hits++;
            $links = [];
            foreach ($line_attachments as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $key = (string)($entry['key'] ?? '');
                if ($key !== '') {
                    $rendered_keys[$key] = true;
                }
                $links[] = portal_dj_render_inline_anexo_link((array)($entry['anexo'] ?? []));
            }

            $prefix = (string)($matches[0] ?? 'Arquivo anexado separadamente.');
            if (empty($links)) {
                return $prefix;
            }
            return $prefix . ' ' . implode(' • ', $links);
        },
        $content_html
    );

    if (!is_string($rendered_html)) {
        $rendered_html = $content_html;
    }

    $remaining_links = [];
    foreach ($entries as $entry) {
        $key = (string)($entry['key'] ?? '');
        if ($key !== '' && isset($rendered_keys[$key])) {
            continue;
        }
        $remaining_links[] = portal_dj_render_inline_anexo_link((array)($entry['anexo'] ?? []));
    }

    if (!empty($remaining_links)) {
        $rendered_html .= '<p><em>Arquivo anexado separadamente.</em> ' . implode(' • ', $remaining_links) . '</p>';
        $placeholder_hits++;
    }

    return [
        'html' => $rendered_html,
        'used_inline' => $placeholder_hits > 0,
    ];
}

/**
 * Resolve conteúdo/anexos da seção DJ com base nos links ativos.
 * Evita exibir dados órfãos quando os quadros forem excluídos na reunião final.
 */
function portal_dj_resolver_dados_dj_por_links_ativos(PDO $pdo, int $meeting_id, ?array $secao_fallback = null): array
{
    if ($meeting_id <= 0) {
        return ['resolved' => false];
    }

    $links_ativos = eventos_reuniao_listar_links_cliente($pdo, $meeting_id, 'cliente_dj');
    if (empty($links_ativos)) {
        return ['resolved' => false];
    }

    $links_filtrados = array_values(array_filter($links_ativos, static function ($link): bool {
        if (!is_array($link)) {
            return false;
        }
        if (strtolower(trim((string)($link['link_type'] ?? 'cliente_dj'))) !== 'cliente_dj') {
            return false;
        }

        // Compatibilidade: se allowed_sections estiver ausente, considera DJ por padrão.
        $raw_allowed = $link['allowed_sections'] ?? null;
        if ($raw_allowed === null || $raw_allowed === '') {
            return true;
        }

        $sections = [];
        if (is_array($raw_allowed)) {
            $sections = $raw_allowed;
        } elseif (is_string($raw_allowed) && trim($raw_allowed) !== '') {
            $decoded = json_decode($raw_allowed, true);
            if (is_array($decoded)) {
                $sections = $decoded;
            }
        }

        if (empty($sections)) {
            return true;
        }

        foreach ($sections as $section) {
            if (strtolower(trim((string)$section)) === 'dj_protocolo') {
                return true;
            }
        }
        return false;
    }));
    if (empty($links_filtrados)) {
        return ['resolved' => false];
    }

    usort($links_filtrados, static function (array $a, array $b): int {
        $a_submitted = trim((string)($a['submitted_at'] ?? ''));
        $b_submitted = trim((string)($b['submitted_at'] ?? ''));
        $a_has_submitted = $a_submitted !== '';
        $b_has_submitted = $b_submitted !== '';

        if ($a_has_submitted && $b_has_submitted && $a_submitted !== $b_submitted) {
            return strcmp($b_submitted, $a_submitted);
        }
        if ($a_has_submitted !== $b_has_submitted) {
            return $a_has_submitted ? -1 : 1;
        }
        return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
    });

    $principal = $links_filtrados[0];
    $principal_id = (int)($principal['id'] ?? 0);
    $snapshot_html = (string)($principal['content_html_snapshot'] ?? '');

    $secao_resolvida = is_array($secao_fallback) ? $secao_fallback : [];
    $secao_resolvida['section'] = 'dj_protocolo';
    $secao_resolvida['content_html'] = $snapshot_html;
    $secao_resolvida['content_text'] = trim(strip_tags($snapshot_html));
    if (array_key_exists('form_schema', $principal) && is_array($principal['form_schema'])) {
        $secao_resolvida['form_schema_json'] = json_encode(
            $principal['form_schema'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '[]';
    }

    $anexos = [];
    if ($principal_id > 0) {
        $anexos = eventos_reuniao_get_anexos_link_finais($pdo, $meeting_id, 'dj_protocolo', $principal_id);
    }

    return [
        'resolved' => true,
        'secao' => $secao_resolvida,
        'anexos' => is_array($anexos) ? $anexos : [],
    ];
}

/**
 * Verifica se o link contempla uma seção específica no allowed_sections.
 * Para observações_gerais, evita incluir links legados de decoração.
 */
function portal_dj_link_contem_secao(?array $link, string $section): bool
{
    if (!is_array($link)) {
        return false;
    }

    $section = strtolower(trim((string)$section));
    if ($section === '') {
        return true;
    }

    $raw_allowed = $link['allowed_sections'] ?? null;
    $sections = [];
    if (is_array($raw_allowed)) {
        $sections = $raw_allowed;
    } elseif (is_string($raw_allowed)) {
        $decoded = json_decode($raw_allowed, true);
        if (is_array($decoded)) {
            $sections = $decoded;
        }
    }

    if (!empty($sections)) {
        foreach ($sections as $allowed) {
            if (strtolower(trim((string)$allowed)) === $section) {
                return true;
            }
        }
        return false;
    }

    // Fallback legado: sem allowed_sections.
    // Para observações, não assumir "permitido" para não misturar com decoração.
    if ($section !== 'observacoes_gerais') {
        return true;
    }

    $title = strtolower(trim((string)($link['form_title'] ?? '')));
    if ($title !== '') {
        if (str_contains($title, 'decora')) {
            return false;
        }
        if (str_contains($title, 'observa')) {
            return true;
        }
    }

    $snapshot = trim((string)($link['content_html_snapshot'] ?? ''));
    $draft = trim((string)($link['draft_content_html_snapshot'] ?? ''));
    $haystack = strtolower($snapshot . "\n" . $draft);
    if ($haystack !== '') {
        if (str_contains($haystack, 'data-smile-public-section="decoracao"')
            || str_contains($haystack, "data-smile-public-section='decoracao'")
        ) {
            return false;
        }
        if (str_contains($haystack, 'data-smile-public-section="observacoes_gerais"')
            || str_contains($haystack, "data-smile-public-section='observacoes_gerais'")
            || str_contains($haystack, 'data-smile-observacoes-block')
        ) {
            return true;
        }
    }

    // Sem evidência de que seja observações gerais.
    return false;
}

/**
 * Extrai do snapshot apenas o conteúdo da seção pedida quando o HTML vier agregado.
 */
function portal_dj_extrair_secao_publica_snapshot(string $content_html, string $section): string
{
    $content_html = trim($content_html);
    $section = strtolower(trim($section));
    if ($content_html === '' || $section === '') {
        return $content_html;
    }
    if (stripos($content_html, 'data-smile-public-section') === false) {
        return $content_html;
    }
    if (!class_exists('DOMDocument')) {
        return $content_html;
    }

    $dom = new DOMDocument();
    $prev_state = libxml_use_internal_errors(true);
    $flags = 0;
    if (defined('LIBXML_HTML_NODEFDTD')) {
        $flags |= LIBXML_HTML_NODEFDTD;
    }
    if (defined('LIBXML_HTML_NOIMPLIED')) {
        $flags |= LIBXML_HTML_NOIMPLIED;
    }

    $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $content_html . '</body></html>';
    $loaded = $dom->loadHTML($wrapped, $flags);
    libxml_clear_errors();
    libxml_use_internal_errors($prev_state);
    if (!$loaded) {
        return $content_html;
    }

    $sections = $dom->getElementsByTagName('section');
    foreach ($sections as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $current = strtolower(trim((string)$node->getAttribute('data-smile-public-section')));
        if ($current !== $section) {
            continue;
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= (string)$dom->saveHTML($child);
        }
        $inner = trim($inner);
        return $inner !== '' ? $inner : $content_html;
    }

    return $content_html;
}

function portal_dj_conteudo_tem_marcador_decoracao(string $content_html): bool
{
    $haystack = strtolower(trim($content_html));
    if ($haystack === '') {
        return false;
    }

    return str_contains($haystack, 'data-smile-public-section="decoracao"')
        || str_contains($haystack, "data-smile-public-section='decoracao'")
        || str_contains($haystack, '>decoração<')
        || str_contains($haystack, '>decoracao<');
}

/**
 * Definição fixa dos blocos de Observações Gerais da reunião final.
 */
function portal_dj_observacoes_blocos_padrao(): array
{
    return [
        [
            'key' => 'legacy_text',
            'label' => 'Texto livre (opcional)',
            'description' => 'Área aberta para observações complementares.',
            'internal' => false,
        ],
        [
            'key' => 'cronograma',
            'label' => 'Cronograma',
            'description' => 'Use este bloco para roteiro, horários e sequência do evento.',
            'internal' => false,
        ],
        [
            'key' => 'fornecedores_externos',
            'label' => 'Fornecedores externos',
            'description' => 'Registre contatos, entregas e combinados com fornecedores parceiros.',
            'internal' => false,
        ],
        [
            'key' => 'informacoes_importantes',
            'label' => 'Informações importantes',
            'description' => 'Pontos críticos que precisam ficar claros para a execução do evento.',
            'internal' => false,
        ],
        [
            'key' => 'informacoes_internas',
            'label' => 'Informações internas',
            'description' => 'Uso exclusivo da equipe interna. Nunca exibido ao cliente.',
            'internal' => true,
        ],
    ];
}

/**
 * Parseia o HTML salvo da seção de observações em blocos por chave.
 */
function portal_dj_parsear_observacoes_blocos(string $content_html): array
{
    $content_html = trim($content_html);
    if ($content_html === '') {
        return [];
    }

    if (stripos($content_html, 'data-smile-observacoes-block') === false || !class_exists('DOMDocument')) {
        return ['legacy_text' => $content_html];
    }

    $dom = new DOMDocument();
    $prev_state = libxml_use_internal_errors(true);
    $flags = 0;
    if (defined('LIBXML_HTML_NODEFDTD')) {
        $flags |= LIBXML_HTML_NODEFDTD;
    }
    if (defined('LIBXML_HTML_NOIMPLIED')) {
        $flags |= LIBXML_HTML_NOIMPLIED;
    }

    $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $content_html . '</body></html>';
    $loaded = $dom->loadHTML($wrapped, $flags);
    libxml_clear_errors();
    libxml_use_internal_errors($prev_state);
    if (!$loaded) {
        return ['legacy_text' => $content_html];
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//*[@data-smile-observacoes-block]');
    if (!$nodes || $nodes->length === 0) {
        return ['legacy_text' => $content_html];
    }

    $parsed = [];
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $key = strtolower(trim((string)$node->getAttribute('data-smile-observacoes-block')));
        if ($key === '') {
            continue;
        }

        $target = $node;
        $content_nodes = $xpath->query('.//*[@data-smile-observacoes-content]', $node);
        if ($content_nodes && $content_nodes->length > 0 && $content_nodes->item(0) instanceof DOMElement) {
            $target = $content_nodes->item(0);
        }

        $inner = '';
        foreach ($target->childNodes as $child) {
            $inner .= (string)$dom->saveHTML($child);
        }
        $parsed[$key] = trim($inner);
    }

    return $parsed;
}

/**
 * Monta a visão dos blocos de Observações Gerais com conteúdo e estado.
 */
function portal_dj_montar_observacoes_blocos(string $content_html): array
{
    $defs = portal_dj_observacoes_blocos_padrao();
    $parsed = portal_dj_parsear_observacoes_blocos($content_html);
    $view = [];

    foreach ($defs as $def) {
        $key = trim((string)($def['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $html = trim((string)($parsed[$key] ?? ''));
        $plain = trim((string)strip_tags($html));
        $view[] = [
            'key' => $key,
            'label' => (string)($def['label'] ?? $key),
            'description' => (string)($def['description'] ?? ''),
            'internal' => !empty($def['internal']),
            'content_html' => $html,
            'has_content' => $plain !== '',
        ];
    }

    return $view;
}

/**
 * Carrega os quadros (slots) de Observações Gerais da reunião final para visualização no Portal DJ.
 */
function portal_dj_resolver_quadros_observacoes(PDO $pdo, int $meeting_id): array
{
    if ($meeting_id <= 0) {
        return [];
    }

    $links = eventos_reuniao_listar_links_cliente($pdo, $meeting_id, 'cliente_observacoes');
    if (empty($links)) {
        return [];
    }

    $by_slot = [];
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        if (!portal_dj_link_contem_secao($link, 'observacoes_gerais')) {
            continue;
        }

        $slot_index = max(1, (int)($link['slot_index'] ?? 1));
        if (isset($by_slot[$slot_index])) {
            continue;
        }

        $submitted_snapshot = trim((string)($link['content_html_snapshot'] ?? ''));
        $draft_snapshot = trim((string)($link['draft_content_html_snapshot'] ?? ''));

        $content_html = $submitted_snapshot;
        $snapshot_status = 'submitted';
        if ($content_html === '' && $draft_snapshot !== '') {
            $content_html = $draft_snapshot;
            $snapshot_status = 'draft';
        } elseif ($content_html === '') {
            $snapshot_status = 'empty';
        }
        if ($content_html !== '') {
            $content_html = portal_dj_extrair_secao_publica_snapshot($content_html, 'observacoes_gerais');
        }

        $form_title = trim((string)($link['form_title'] ?? ''));
        if ($form_title === '') {
            $form_title = 'Observações Gerais - Quadro ' . $slot_index;
        }

        $link_id = (int)($link['id'] ?? 0);
        $anexos = $link_id > 0
            ? eventos_reuniao_get_anexos_link_finais($pdo, $meeting_id, 'observacoes_gerais', $link_id)
            : [];

        $by_slot[$slot_index] = [
            'slot_index' => $slot_index,
            'id' => $link_id,
            'form_title' => $form_title,
            'content_html' => $content_html,
            'snapshot_status' => $snapshot_status,
            'submitted_at' => isset($link['submitted_at']) ? (string)$link['submitted_at'] : '',
            'draft_saved_at' => isset($link['draft_saved_at']) ? (string)$link['draft_saved_at'] : '',
            'anexos' => is_array($anexos) ? $anexos : [],
        ];
    }

    if (empty($by_slot)) {
        return [];
    }

    ksort($by_slot, SORT_NUMERIC);
    return array_values($by_slot);
}

if (!empty($_GET['evento'])) {
    $evento_id = (int)$_GET['evento'];

    try {
        $tipo_real_expr = "COALESCE(NULLIF(LOWER(TRIM(r.tipo_evento_real)), ''), LOWER(TRIM(COALESCE(r.me_event_snapshot->>'tipo_evento_real', ''))))";
        $stmt = $pdo->prepare("
            SELECT r.id,
                   r.me_event_id,
                   r.status,
                   r.me_event_snapshot,
                   (r.me_event_snapshot->>'data')::date as data_evento,
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   (r.me_event_snapshot->>'local') as local_evento,
                   (r.me_event_snapshot->>'hora_inicio') as hora_inicio_evento,
                   (r.me_event_snapshot->>'hora_fim') as hora_fim_evento,
                   (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome
            FROM eventos_reunioes r
            WHERE r.id = :id
              AND (r.me_event_snapshot->>'data')::date >= :start
              AND {$tipo_real_expr} IN ('casamento', '15anos')
            LIMIT 1
        ");
        $stmt->execute([':id' => $evento_id, ':start' => $start_date]);
        $evento_selecionado = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($evento_selecionado) {
            $snapshot = json_decode((string)($evento_selecionado['me_event_snapshot'] ?? '{}'), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $me_event_id = (int)($evento_selecionado['me_event_id'] ?? ($snapshot['id'] ?? 0));
            $cancelado = (!empty($snapshot) && eventos_me_evento_cancelado($snapshot))
                || ($me_event_id > 0 && eventos_me_evento_cancelado_por_webhook($pdo, $me_event_id));

            if ($cancelado) {
                $aviso_evento_cancelado = 'Evento cancelado na ME. Ele foi ocultado do portal.';
                $evento_selecionado = null;
            } else {
                $evento_selecionado['hora_evento'] = portal_dj_formatar_intervalo_evento($evento_selecionado);
                $secao_dj = eventos_reuniao_get_secao($pdo, $evento_id, 'dj_protocolo');
                $secao_observacoes = eventos_reuniao_get_secao($pdo, $evento_id, 'observacoes_gerais');
                $secao_formulario = eventos_reuniao_get_secao($pdo, $evento_id, 'formulario');
                $anexos_dj = eventos_reuniao_get_anexos($pdo, $evento_id, 'dj_protocolo');
                $anexos_observacoes = eventos_reuniao_get_anexos($pdo, $evento_id, 'observacoes_gerais');
                $anexos_formulario = eventos_reuniao_get_anexos($pdo, $evento_id, 'formulario');
                $dj_resolvido = portal_dj_resolver_dados_dj_por_links_ativos($pdo, $evento_id, $secao_dj);
                if (!empty($dj_resolvido['resolved'])) {
                    $secao_dj = $dj_resolvido['secao'] ?? $secao_dj;
                    $anexos_dj = $dj_resolvido['anexos'] ?? [];
                }
                if (is_array($secao_observacoes)) {
                    $obs_html = trim((string)($secao_observacoes['content_html'] ?? ''));
                    if ($obs_html !== '') {
                        $obs_filtrado = portal_dj_extrair_secao_publica_snapshot($obs_html, 'observacoes_gerais');
                        if (!portal_dj_conteudo_tem_marcador_decoracao($obs_filtrado)) {
                            $secao_observacoes['content_html'] = $obs_filtrado;
                            $secao_observacoes['content_text'] = trim(strip_tags($obs_filtrado));
                        }
                    }
                }
                $observacoes_blocos = portal_dj_montar_observacoes_blocos((string)($secao_observacoes['content_html'] ?? ''));
                $quadros_observacoes = portal_dj_resolver_quadros_observacoes($pdo, $evento_id);
                $arquivos_evento = eventos_arquivos_listar($pdo, $evento_id, false);
            }
        }
    } catch (Exception $e) {
        error_log("Erro portal DJ (detalhe): " . $e->getMessage());
        $evento_selecionado = null;
    }
}

$aba_detalhe = trim((string)($_GET['aba'] ?? ''));
$exibir_aba_formulario = portal_dj_secao_tem_conteudo($secao_formulario) || !empty($anexos_formulario);
$abas_detalhe = [
    'observacoes_gerais' => '📝 Observações Gerais',
    'dj_protocolo' => '🎵 DJ / Protocolos',
    'arquivos' => '📎 Arquivos',
];
if ($exibir_aba_formulario) {
    $abas_detalhe['formulario'] = '📋 Formulário';
}
if ($aba_detalhe === '' || !array_key_exists($aba_detalhe, $abas_detalhe)) {
    $aba_detalhe = 'observacoes_gerais';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal DJ - Minha Agenda</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #7c3aed 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .header-user {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .header-user span {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        .btn-light {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .btn-light:hover {
            background: rgba(255,255,255,0.3);
        }
        .btn-primary {
            background: #1e3a8a;
            color: white;
        }
        .btn-primary:hover {
            background: #1e40af;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        .btn-small {
            padding: 0.4rem 0.7rem;
            font-size: 0.78rem;
            font-weight: 600;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        .section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 1rem;
        }
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        .event-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #1e3a8a;
            transition: all 0.2s;
        }
        .event-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .event-card.past {
            opacity: 0.6;
            border-left-color: #94a3b8;
        }
        .event-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        .event-meta {
            font-size: 0.875rem;
            color: #64748b;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .event-date {
            display: inline-block;
            background: #eff6ff;
            color: #1e40af;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 0.75rem;
        }
        .event-actions {
            margin-top: 1rem;
        }
        .detail-panel {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        .detail-header {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-header h2 {
            font-size: 1.25rem;
        }
        .event-meta-grid {
            display: grid;
            gap: 0.55rem;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            color: #64748b;
            font-size: 0.9rem;
        }
        .tabs-wrap {
            margin-bottom: 1.2rem;
        }
        .tabs-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.9rem;
        }
        .tab-link {
            text-decoration: none;
            padding: 0.5rem 0.8rem;
            border-radius: 999px;
            border: 1px solid #dbe3ef;
            background: #f8fafc;
            color: #334155;
            font-size: 0.83rem;
            font-weight: 700;
        }
        .tab-link:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        .tab-link.is-active {
            background: #1e3a8a;
            border-color: #1e3a8a;
            color: #fff;
        }
        .tab-panel-title {
            font-size: 1.02rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.2rem;
        }
        .tab-panel-subtitle {
            color: #64748b;
            font-size: 0.84rem;
            margin-bottom: 0.75rem;
        }
        .obs-fields-wrap {
            display: grid;
            gap: 0.7rem;
        }
        .obs-field-card {
            border: 1px solid #dbe3ef;
            background: #fff;
            border-radius: 10px;
            padding: 0.85rem;
        }
        .obs-field-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .obs-field-title {
            font-size: 0.92rem;
            color: #0f172a;
            font-weight: 800;
        }
        .obs-field-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid #dbe3ef;
            background: #f1f5f9;
            color: #334155;
            padding: 0.15rem 0.55rem;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .obs-field-badge.internal {
            background: #fee2e2;
            border-color: #fecaca;
            color: #991b1b;
        }
        .obs-field-desc {
            color: #64748b;
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }
        .obs-field-content {
            margin-top: 0.55rem;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.75rem;
        }
        .obs-quadros-wrap {
            margin-top: 1rem;
            display: grid;
            gap: 0.85rem;
        }
        .obs-quadro-card {
            border: 1px solid #dbe3ef;
            background: #fff;
            border-radius: 10px;
            padding: 0.95rem;
        }
        .obs-quadro-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
            margin-bottom: 0.55rem;
        }
        .obs-quadro-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: #0f172a;
        }
        .obs-quadro-status {
            display: inline-flex;
            align-items: center;
            font-size: 0.72rem;
            font-weight: 700;
            border-radius: 999px;
            padding: 0.2rem 0.55rem;
            border: 1px solid #dbe3ef;
            background: #f1f5f9;
            color: #334155;
        }
        .obs-quadro-status.is-draft {
            background: #fef3c7;
            border-color: #fde68a;
            color: #92400e;
        }
        .obs-quadro-status.is-empty {
            background: #f8fafc;
            border-color: #e2e8f0;
            color: #64748b;
        }
        .obs-quadro-meta {
            color: #64748b;
            font-size: 0.78rem;
            margin-bottom: 0.55rem;
        }
        .obs-quadro-content {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.85rem;
        }
        .content-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1.5rem;
            min-height: 200px;
        }
        .content-box h3 {
            font-size: 0.95rem;
            color: #374151;
            margin-bottom: 1rem;
        }
        .content-box .inline-anexo-link {
            color: #1d4ed8;
            text-decoration: underline;
            word-break: break-word;
            font-weight: 700;
        }
        .anexos-list {
            margin-top: 1.5rem;
        }
        .anexo-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.75rem;
            background: #f1f5f9;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            border: 1px solid #e2e8f0;
            flex-wrap: wrap;
        }
        .anexo-main {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            min-width: 0;
            flex: 1;
        }
        .anexo-icon {
            font-size: 1.1rem;
            line-height: 1;
            margin-top: 0.1rem;
        }
        .anexo-info {
            min-width: 0;
        }
        .anexo-name {
            font-size: 0.875rem;
            font-weight: 700;
            color: #0f172a;
            word-break: break-word;
        }
        .anexo-meta {
            margin-top: 0.2rem;
            font-size: 0.75rem;
            color: #64748b;
        }
        .anexo-note {
            margin-top: 0.2rem;
            font-size: 0.75rem;
            color: #475569;
        }
        .anexo-actions {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }

        /* Calendário (similar a eventos_calendario) */
        .month-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .month-nav h2 {
            font-size: 1.125rem;
            color: #1e293b;
            margin: 0;
            min-width: 200px;
            text-align: center;
            flex: 1;
        }
        .nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid #e2e8f0;
            background: white;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            transition: all 0.2s;
            color: #1e293b;
            text-decoration: none;
        }
        .nav-btn:hover {
            background: #f1f5f9;
            border-color: #1e3a8a;
        }
        .calendar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .calendar-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #1e3a8a;
            color: white;
        }
        .calendar-header div {
            padding: 0.75rem;
            text-align: center;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .calendar-body {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }
        .calendar-day {
            min-height: 110px;
            border: 1px solid #e5e7eb;
            padding: 0.5rem;
            position: relative;
        }
        .calendar-day.month-break {
            border-top: 3px solid #1e3a8a;
            box-shadow: inset 0 2px 0 rgba(30, 58, 138, 0.1);
        }
        .calendar-day.other-month {
            background: #f8fafc;
            color: #94a3b8;
        }
        .calendar-day.today {
            background: #eff6ff;
        }
        .month-marker {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #1e3a8a;
            background: #dbeafe;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            padding: 0.15rem 0.45rem;
            margin-bottom: 0.35rem;
        }
        .calendar-day.other-month .month-marker {
            color: #475569;
            background: #e2e8f0;
            border-color: #cbd5e1;
        }
        .day-number {
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .calendar-day.today .day-number {
            background: #1e3a8a;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .day-events {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .event-tag {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: all 0.2s;
            text-decoration: none;
            display: block;
        }
        .event-tag:hover { transform: translateX(2px); }
        .event-tag.rascunho {
            background: #fef3c7;
            color: #92400e;
            border-left: 3px solid #f59e0b;
        }
        .event-tag.concluida {
            background: #d1fae5;
            color: #065f46;
            border-left: 3px solid #10b981;
        }
        .event-tag.muted {
            background: #e2e8f0;
            color: #475569;
            cursor: default;
            transform: none !important;
        }
        .event-tag.more-btn {
            border: 0;
            width: 100%;
            text-align: left;
            font-family: inherit;
        }
        .event-tag.more-btn.muted {
            cursor: pointer;
        }
        .event-tag.more-btn:hover {
            transform: none;
        }
        .event-tag.more-btn.muted:hover {
            background: #cbd5e1;
        }

        /* Modal (lista de eventos do dia) */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: flex-end;
            justify-content: center;
            padding: 1rem;
            z-index: 9999;
        }
        .modal-overlay.open {
            display: flex;
        }
        .modal {
            background: #ffffff;
            width: min(560px, 100%);
            border-radius: 14px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }
        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }
        .modal-title {
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }
        .modal-subtitle {
            margin-top: 0.25rem;
            font-size: 0.875rem;
            color: #64748b;
        }
        .modal-close {
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #0f172a;
            border-radius: 10px;
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .modal-close:hover {
            background: #f1f5f9;
        }
        .modal-body {
            padding: 1rem 1.25rem;
            overflow: auto;
        }
        .modal-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .modal-item {
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            border-radius: 12px;
            padding: 0.75rem 0.85rem;
        }
        .modal-link {
            color: #1e3a8a;
            text-decoration: none;
            font-weight: 800;
        }
        .modal-link:hover {
            text-decoration: underline;
        }
        .modal-meta {
            margin-top: 0.25rem;
            font-size: 0.85rem;
            color: #64748b;
        }
        .anexo-preview-body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 240px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .anexo-preview-body img,
        .anexo-preview-body video {
            max-width: 100%;
            max-height: 65vh;
            display: block;
        }
        .anexo-preview-body audio {
            width: min(560px, 100%);
        }
        .anexo-preview-body iframe {
            width: 100%;
            height: min(70vh, 640px);
            border: 0;
            background: #fff;
        }
        .anexo-preview-empty {
            text-align: center;
            color: #64748b;
            font-size: 0.9rem;
            padding: 2rem 1rem;
        }
        .anexo-preview-footer {
            margin-top: 0.9rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .calendar-day { min-height: 80px; padding: 0.25rem; }
            .day-number { font-size: 0.75rem; }
            .event-tag { font-size: 0.65rem; padding: 0.125rem 0.25rem; }
            .calendar-header div { padding: 0.5rem; font-size: 0.75rem; }
            .anexo-actions {
                width: 100%;
            }
            .anexo-actions .btn {
                flex: 1;
                justify-content: center;
            }
            .tabs-nav {
                gap: 0.4rem;
            }
            .tab-link {
                font-size: 0.79rem;
                padding: 0.45rem 0.7rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎧 Portal DJ</h1>
        <div class="header-user">
            <span>Olá, <?= htmlspecialchars($nome) ?></span>
            <a href="?page=portal_dj&logout=1" class="btn btn-light">Sair</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($aviso_evento_cancelado !== ''): ?>
        <div style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid #facc15; background:#fef9c3; color:#854d0e; border-radius:8px;">
            <?= htmlspecialchars($aviso_evento_cancelado) ?>
        </div>
        <?php endif; ?>
        <?php if ($evento_selecionado): ?>
        <!-- Detalhes do Evento -->
        <a href="?page=portal_dj" class="btn btn-primary" style="margin-bottom: 1rem;">← Voltar à lista</a>
        
        <div class="detail-panel">
            <div class="detail-header">
                <div>
                    <h2><?= htmlspecialchars($evento_selecionado['nome_evento']) ?></h2>
                    <div class="event-meta-grid" style="margin-top: 0.4rem;">
                        <div><strong>📅 Data:</strong> <?= date('d/m/Y', strtotime($evento_selecionado['data_evento'])) ?></div>
                        <div><strong>⏰ Horário:</strong> <?= htmlspecialchars($evento_selecionado['hora_evento'] ?: '-') ?></div>
                        <div><strong>📍 Local:</strong> <?= htmlspecialchars($evento_selecionado['local_evento'] ?: '-') ?></div>
                        <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($evento_selecionado['cliente_nome'] ?: '-') ?></div>
                    </div>
                </div>
            </div>
            
            <div class="tabs-wrap">
                <div class="tabs-nav">
                    <?php foreach ($abas_detalhe as $aba_id => $aba_label): ?>
                    <a
                        class="tab-link <?= $aba_detalhe === $aba_id ? 'is-active' : '' ?>"
                        href="?page=portal_dj&evento=<?= (int)$evento_selecionado['id'] ?>&aba=<?= urlencode($aba_id) ?>"
                    >
                        <?= htmlspecialchars($aba_label) ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <?php
                    $aba_conteudos = [
                        'observacoes_gerais' => [
                            'titulo' => 'Observações Gerais',
                            'subtitulo' => 'Informações gerais e quadros da reunião final em modo somente leitura.',
                            'secao' => $secao_observacoes,
                            'mensagem_vazia' => 'Nenhuma observação geral cadastrada ainda.',
                            'anexos' => $anexos_observacoes,
                            'titulo_anexos' => '📎 Anexos (Observações Gerais)',
                        ],
                        'dj_protocolo' => [
                            'titulo' => 'DJ / Protocolos',
                            'subtitulo' => 'Músicas, protocolos e orientações do evento.',
                            'secao' => $secao_dj,
                            'mensagem_vazia' => 'Nenhuma informação cadastrada ainda.',
                            'anexos' => $anexos_dj,
                            'titulo_anexos' => '📎 Anexos (DJ / Protocolos)',
                        ],
                        'arquivos' => [
                            'titulo' => 'Arquivos',
                            'subtitulo' => 'Arquivos enviados na área do DJ e anexos gerais do evento.',
                            'secao' => null,
                            'mensagem_vazia' => 'Nenhum arquivo disponível para este evento.',
                            'anexos' => [],
                            'titulo_anexos' => '',
                        ],
                        'formulario' => [
                            'titulo' => 'Formulário',
                            'subtitulo' => 'Conteúdo consolidado dos formulários do evento.',
                            'secao' => $secao_formulario,
                            'mensagem_vazia' => 'Nenhum formulário disponível para este evento.',
                            'anexos' => $anexos_formulario,
                            'titulo_anexos' => '📎 Anexos (Formulário)',
                        ],
                    ];
                    $aba_atual = $aba_conteudos[$aba_detalhe] ?? $aba_conteudos['observacoes_gerais'];
                    if ($aba_detalhe === 'formulario' && !$exibir_aba_formulario) {
                        $aba_atual = $aba_conteudos['observacoes_gerais'];
                    }
                    $secao_ativa = $aba_atual['secao'] ?? null;
                    $html_secao_ativa = trim((string)($secao_ativa['content_html'] ?? ''));
                    $html_secao_ativa_render = $html_secao_ativa;
                    $anexos_aba_ativa = is_array($aba_atual['anexos'] ?? null) ? $aba_atual['anexos'] : [];
                    $mostrar_lista_anexos_aba = !empty($anexos_aba_ativa);

                    if ($aba_detalhe === 'formulario' && $html_secao_ativa !== '' && !empty($anexos_aba_ativa)) {
                        $inline = portal_dj_renderizar_html_com_anexos_inline($html_secao_ativa, $anexos_aba_ativa, $secao_ativa);
                        $html_secao_ativa_render = trim((string)($inline['html'] ?? $html_secao_ativa));
                        if (!empty($inline['used_inline'])) {
                            // Evita duplicar os mesmos anexos: já ficaram inline no conteúdo.
                            $mostrar_lista_anexos_aba = false;
                        }
                    }
                ?>
                <div class="tab-panel-title"><?= htmlspecialchars($aba_atual['titulo']) ?></div>
                <div class="tab-panel-subtitle"><?= htmlspecialchars($aba_atual['subtitulo']) ?></div>

                <?php if ($aba_detalhe === 'observacoes_gerais'): ?>
                <div class="obs-fields-wrap">
                    <?php foreach ($observacoes_blocos as $bloco): ?>
                    <?php
                        $bloco_label = trim((string)($bloco['label'] ?? 'Campo'));
                        $bloco_desc = trim((string)($bloco['description'] ?? ''));
                        $bloco_html = trim((string)($bloco['content_html'] ?? ''));
                        $bloco_has_content = !empty($bloco['has_content']);
                        $bloco_internal = !empty($bloco['internal']);
                    ?>
                    <article class="obs-field-card">
                        <div class="obs-field-head">
                            <div class="obs-field-title"><?= htmlspecialchars($bloco_label !== '' ? $bloco_label : 'Campo') ?></div>
                            <span class="obs-field-badge<?= $bloco_internal ? ' internal' : '' ?>">
                                <?= $bloco_internal ? 'Interno' : 'Portal' ?>
                            </span>
                        </div>
                        <?php if ($bloco_desc !== ''): ?>
                        <div class="obs-field-desc"><?= htmlspecialchars($bloco_desc) ?></div>
                        <?php endif; ?>
                        <div class="obs-field-content">
                            <?php if ($bloco_has_content): ?>
                            <div><?= $bloco_html ?></div>
                            <?php else: ?>
                            <p style="color: #64748b; font-style: italic;">Sem informação registrada neste campo.</p>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="content-box">
                    <?php if ($aba_detalhe === 'arquivos'): ?>
                    <?php
                        portal_dj_renderizar_lista_anexos(
                            $anexos_dj,
                            'Arquivos DJ',
                            'Nenhum arquivo enviado na área de DJ.'
                        );
                        portal_dj_renderizar_lista_anexos(
                            $arquivos_evento,
                            'Arquivos gerais',
                            'Nenhum anexo geral cadastrado para este evento.'
                        );
                    ?>
                    <?php elseif ($html_secao_ativa_render !== ''): ?>
                    <div><?= $html_secao_ativa_render ?></div>
                    <?php else: ?>
                    <p style="color: #64748b; font-style: italic;"><?= htmlspecialchars($aba_atual['mensagem_vazia']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($aba_detalhe === 'observacoes_gerais' && !empty($quadros_observacoes)): ?>
                <div class="obs-quadros-wrap">
                    <h3 style="font-size: 0.95rem; color: #374151;">🧩 Demais quadros da reunião final</h3>
                    <?php foreach ($quadros_observacoes as $quadro): ?>
                    <?php
                        $quadro_slot = max(1, (int)($quadro['slot_index'] ?? 1));
                        $quadro_titulo = trim((string)($quadro['form_title'] ?? ''));
                        if ($quadro_titulo === '') {
                            $quadro_titulo = 'Observações Gerais - Quadro ' . $quadro_slot;
                        }
                        $quadro_html = trim((string)($quadro['content_html'] ?? ''));
                        $quadro_status = trim((string)($quadro['snapshot_status'] ?? 'submitted'));
                        $quadro_submitted_raw = trim((string)($quadro['submitted_at'] ?? ''));
                        $quadro_submitted_fmt = $quadro_submitted_raw !== '' ? date('d/m/Y H:i', strtotime($quadro_submitted_raw)) : '';
                        $quadro_draft_raw = trim((string)($quadro['draft_saved_at'] ?? ''));
                        $quadro_draft_fmt = $quadro_draft_raw !== '' ? date('d/m/Y H:i', strtotime($quadro_draft_raw)) : '';
                        $quadro_status_text = 'Enviado';
                        $quadro_status_class = '';
                        $quadro_meta = $quadro_submitted_fmt !== '' ? 'Enviado em ' . $quadro_submitted_fmt : '';
                        if ($quadro_status === 'draft') {
                            $quadro_status_text = 'Rascunho';
                            $quadro_status_class = ' is-draft';
                            $quadro_meta = $quadro_draft_fmt !== '' ? 'Rascunho salvo em ' . $quadro_draft_fmt : 'Quadro em rascunho (sem envio final).';
                        } elseif ($quadro_status === 'empty') {
                            $quadro_status_text = 'Sem conteúdo';
                            $quadro_status_class = ' is-empty';
                            $quadro_meta = 'Quadro sem conteúdo enviado até o momento.';
                        }
                        $anexos_quadro = is_array($quadro['anexos'] ?? null) ? $quadro['anexos'] : [];
                    ?>
                    <div class="obs-quadro-card">
                        <div class="obs-quadro-head">
                            <div class="obs-quadro-title">Quadro <?= (int)$quadro_slot ?> • <?= htmlspecialchars($quadro_titulo) ?></div>
                            <span class="obs-quadro-status<?= htmlspecialchars($quadro_status_class) ?>"><?= htmlspecialchars($quadro_status_text) ?></span>
                        </div>
                        <?php if ($quadro_meta !== ''): ?>
                        <div class="obs-quadro-meta"><?= htmlspecialchars($quadro_meta) ?></div>
                        <?php endif; ?>
                        <div class="obs-quadro-content">
                            <?php if ($quadro_html !== ''): ?>
                            <div><?= $quadro_html ?></div>
                            <?php else: ?>
                            <p style="color: #64748b; font-style: italic;">Quadro sem conteúdo disponível.</p>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($anexos_quadro)): ?>
                        <div class="anexos-list">
                            <h3 style="font-size: 0.88rem; color: #374151; margin-bottom: 0.75rem;">📎 Anexos do quadro</h3>
                            <?php foreach ($anexos_quadro as $a): ?>
                            <?php
                                $anexo_url = trim((string)($a['public_url'] ?? ''));
                                $anexo_nome = trim((string)($a['original_name'] ?? 'arquivo'));
                                $anexo_mime = strtolower(trim((string)($a['mime_type'] ?? 'application/octet-stream')));
                                $anexo_kind = strtolower(trim((string)($a['file_kind'] ?? 'outros')));
                                $anexo_size = (int)($a['size_bytes'] ?? 0);
                                $anexo_note = trim((string)($a['note'] ?? ''));
                                $anexo_icon = '📎';
                                if ($anexo_kind === 'imagem') {
                                    $anexo_icon = '🖼️';
                                } elseif ($anexo_kind === 'video') {
                                    $anexo_icon = '🎬';
                                } elseif ($anexo_kind === 'audio') {
                                    $anexo_icon = '🎵';
                                } elseif ($anexo_kind === 'pdf') {
                                    $anexo_icon = '📄';
                                }
                            ?>
                            <div class="anexo-item">
                                <div class="anexo-main">
                                    <span class="anexo-icon"><?= $anexo_icon ?></span>
                                    <div class="anexo-info">
                                        <div class="anexo-name"><?= htmlspecialchars($anexo_nome !== '' ? $anexo_nome : 'arquivo') ?></div>
                                        <div class="anexo-meta">
                                            <?= htmlspecialchars($anexo_mime !== '' ? $anexo_mime : 'application/octet-stream') ?>
                                            • <?= $anexo_size > 0 ? htmlspecialchars(number_format($anexo_size / 1024, 1, ',', '.')) . ' KB' : '-' ?>
                                        </div>
                                        <?php if ($anexo_note !== ''): ?>
                                        <div class="anexo-note"><strong>Obs:</strong> <?= htmlspecialchars($anexo_note) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="anexo-actions">
                                    <?php if ($anexo_url !== ''): ?>
                                    <button type="button"
                                            class="btn btn-secondary btn-small"
                                            data-open-anexo-modal="1"
                                            data-url="<?= htmlspecialchars($anexo_url, ENT_QUOTES, 'UTF-8') ?>"
                                            data-name="<?= htmlspecialchars($anexo_nome, ENT_QUOTES, 'UTF-8') ?>"
                                            data-mime="<?= htmlspecialchars($anexo_mime, ENT_QUOTES, 'UTF-8') ?>"
                                            data-kind="<?= htmlspecialchars($anexo_kind, ENT_QUOTES, 'UTF-8') ?>">
                                        Visualizar
                                    </button>
                                    <a href="<?= htmlspecialchars($anexo_url) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       download
                                       class="btn btn-primary btn-small">Download</a>
                                    <?php else: ?>
                                    <span class="anexo-meta">Arquivo sem URL pública.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($mostrar_lista_anexos_aba): ?>
                <?php portal_dj_renderizar_lista_anexos($anexos_aba_ativa, (string)$aba_atual['titulo_anexos']); ?>
                <?php endif; ?>
            </div>

        </div>
        
        <?php else: ?>
        <!-- Calendário -->
        <h2 class="section-title">📅 Próximos 30 eventos</h2>
        <p style="color: #64748b; margin-top: -0.25rem; margin-bottom: 1rem;">Somente eventos com tipo real casamento ou 15 anos.</p>
        <div class="month-nav">
            <h2>Agenda por ordem de data/hora</h2>
            <a href="?page=portal_dj" class="btn btn-primary" style="margin-left: auto;">⟳ Atualizar</a>
        </div>

        <?php if ($erro_eventos): ?>
        <div class="empty-state">
            <p style="font-size: 2rem;">⚠️</p>
            <p><strong>Não foi possível carregar os eventos.</strong></p>
            <p><?= htmlspecialchars($erro_eventos) ?></p>
        </div>
        <?php elseif (empty($eventos)): ?>
        <div class="empty-state">
            <p style="font-size: 2rem;">🎧</p>
            <p><strong>Nenhum evento disponível.</strong></p>
            <p>Este calendário mostra os próximos 30 eventos com tipo real casamento ou 15 anos.</p>
        </div>
        <?php else: ?>
        <?php
            $today = new DateTime('today');
            $end = clone $today;
            $meses_abreviados = [
                1 => 'Jan',
                2 => 'Fev',
                3 => 'Mar',
                4 => 'Abr',
                5 => 'Mai',
                6 => 'Jun',
                7 => 'Jul',
                8 => 'Ago',
                9 => 'Set',
                10 => 'Out',
                11 => 'Nov',
                12 => 'Dez',
            ];
            if (!empty($eventos)) {
                $ultimo_evento = end($eventos);
                $ultima_data = trim((string)($ultimo_evento['data_evento'] ?? ''));
                if ($ultima_data !== '') {
                    $ts_ultima_data = strtotime($ultima_data);
                    if ($ts_ultima_data) {
                        $end = new DateTime(date('Y-m-d', $ts_ultima_data));
                    }
                }
                reset($eventos);
            }
            if ($end < $today) {
                $end = clone $today;
            }

            $start_grid = new DateTime('today');
            $weekday = (int)$start_grid->format('w');
            if ($weekday > 0) {
                $start_grid->modify('-' . $weekday . ' days');
            }

            $end_grid = clone $end;
            $weekday_end = (int)$end_grid->format('w');
            if ($weekday_end < 6) {
                $end_grid->modify('+' . (6 - $weekday_end) . ' days');
            }

            $cursor = clone $start_grid;
            $primeiro_dia_visivel = $start_grid->format('Y-m-d');
        ?>

        <div class="calendar">
            <div class="calendar-header">
                <div>Dom</div>
                <div>Seg</div>
                <div>Ter</div>
                <div>Qua</div>
                <div>Qui</div>
                <div>Sex</div>
                <div>Sáb</div>
            </div>
            <div class="calendar-body">
                <?php while ($cursor <= $end_grid):
                    $date_key = $cursor->format('Y-m-d');
                    $is_today = $date_key === $today->format('Y-m-d');
                    $is_outside = ($cursor < $today) || ($cursor > $end);
                    $day_events = $eventos_por_data[$date_key] ?? [];
                    $is_month_break = ((int)$cursor->format('j') === 1) || ($date_key === $primeiro_dia_visivel);
                    $month_label = $meses_abreviados[(int)$cursor->format('n')] . '/' . $cursor->format('Y');
                ?>
                <div class="calendar-day <?= $is_outside ? 'other-month' : '' ?> <?= $is_today ? 'today' : '' ?> <?= $is_month_break ? 'month-break' : '' ?>">
                    <?php if ($is_month_break): ?>
                    <div class="month-marker"><?= htmlspecialchars($month_label) ?></div>
                    <?php endif; ?>
                    <div class="day-number"><?= (int)$cursor->format('j') ?></div>
                    <div class="day-events">
                        <?php foreach (array_slice($day_events, 0, 3) as $ev): 
                            $title_parts = [];
                            $title_parts[] = trim((string)($ev['nome_evento'] ?? ''));
                            if (!empty($ev['hora_evento'])) $title_parts[] = 'Hora: ' . $ev['hora_evento'];
                            if (!empty($ev['local_evento'])) $title_parts[] = 'Local: ' . $ev['local_evento'];
                            $title = trim(implode(' | ', array_filter($title_parts)));
                            $ev_name = (string)($ev['nome_evento'] ?? '');
                            $short = function_exists('mb_substr') ? mb_substr($ev_name, 0, 15) : substr($ev_name, 0, 15);
                            $len = function_exists('mb_strlen') ? mb_strlen($ev_name) : strlen($ev_name);
                        ?>
                        <a href="?page=portal_dj&evento=<?= (int)$ev['id'] ?>"
                           class="event-tag concluida"
                           title="<?= htmlspecialchars($title ?: $ev_name) ?>">
                            <?= htmlspecialchars($short) ?><?= ($len > 15 ? '...' : '') ?>
                        </a>
                        <?php endforeach; ?>
                        <?php if (count($day_events) > 3): ?>
                            <?php
                                $events_payload = [];
                                foreach ($day_events as $ev_full) {
                                    $events_payload[] = [
                                        'name' => (string)($ev_full['nome_evento'] ?? ''),
                                        'url' => '?page=portal_dj&evento=' . (int)($ev_full['id'] ?? 0),
                                        'time' => (string)($ev_full['hora_evento'] ?? ''),
                                        'local' => (string)($ev_full['local_evento'] ?? ''),
                                        'cliente' => (string)($ev_full['cliente_nome'] ?? ''),
                                    ];
                                }
                                $events_json = htmlspecialchars(
                                    json_encode($events_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            ?>
                        <button
                                type="button"
                                class="event-tag muted more-btn"
                                data-open-day-modal="1"
                                data-date-display="<?= htmlspecialchars($cursor->format('d/m/Y')) ?>"
                                data-events="<?= $events_json ?>"
                            >+<?= (int)(count($day_events) - 3) ?> mais</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $cursor->modify('+1 day'); endwhile; ?>
            </div>
        </div>

        <h3 class="section-title" style="margin-top: 0;">📋 Próximos 30 eventos (<?= (int)count($eventos) ?>)</h3>
        <div class="events-grid">
            <?php foreach ($eventos as $ev):
                $is_past = strtotime($ev['data_evento']) < strtotime('today');
            ?>
            <div class="event-card <?= $is_past ? 'past' : '' ?>">
                <div class="event-name"><?= htmlspecialchars($ev['nome_evento']) ?></div>
                <div class="event-meta">
                    <span>📍 <?= htmlspecialchars($ev['local_evento'] ?: 'Local não definido') ?></span>
                    <span>👤 <?= htmlspecialchars($ev['cliente_nome'] ?: 'Cliente') ?></span>
                </div>
                <div class="event-date">
                    📅 <?= date('d/m/Y', strtotime($ev['data_evento'])) ?> às <?= htmlspecialchars($ev['hora_evento'] ?: '-') ?>
                </div>
                <div class="event-actions">
                    <a href="?page=portal_dj&evento=<?= (int)$ev['id'] ?>" class="btn btn-primary">Ver Detalhes</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
	        <?php endif; ?>
	        <?php endif; ?>
	    </div>
        <div id="dayModalOverlay" class="modal-overlay" aria-hidden="true">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dayModalTitle">
                <div class="modal-header">
                    <div>
                        <div class="modal-title" id="dayModalTitle">Eventos</div>
                        <div class="modal-subtitle" id="dayModalSubtitle"></div>
                    </div>
                    <button type="button" class="modal-close" data-day-modal-close="1">Fechar</button>
                </div>
                <div class="modal-body">
                    <div class="modal-list" id="dayModalList"></div>
                </div>
            </div>
        </div>
        <div id="anexoModalOverlay" class="modal-overlay" aria-hidden="true">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="anexoModalTitle">
                <div class="modal-header">
                    <div>
                        <div class="modal-title" id="anexoModalTitle">Pré-visualização</div>
                        <div class="modal-subtitle" id="anexoModalSubtitle"></div>
                    </div>
                    <button type="button" class="modal-close" data-close-anexo-modal="1">Fechar</button>
                </div>
                <div class="modal-body">
                    <div class="anexo-preview-body" id="anexoModalBody"></div>
                    <div class="anexo-preview-footer">
                        <a href="#"
                           id="anexoModalDownload"
                           target="_blank"
                           rel="noopener noreferrer"
                           download
                           class="btn btn-primary btn-small">Download</a>
                        <button type="button" class="btn btn-secondary btn-small" data-close-anexo-modal="1">Fechar</button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function() {
                const overlay = document.getElementById('dayModalOverlay');
                const list = document.getElementById('dayModalList');
                const title = document.getElementById('dayModalTitle');
                const subtitle = document.getElementById('dayModalSubtitle');

                if (!overlay || !list || !title || !subtitle) return;

                function closeModal() {
                    overlay.classList.remove('open');
                    overlay.setAttribute('aria-hidden', 'true');
                    document.body.style.overflow = '';
                }

                function openModal(btn) {
                    const dateDisplay = btn.getAttribute('data-date-display') || '';
                    let events = [];
                    try {
                        events = JSON.parse(btn.getAttribute('data-events') || '[]') || [];
                    } catch (e) {
                        events = [];
                    }

                    title.textContent = 'Eventos do dia';
                    subtitle.textContent = (dateDisplay ? dateDisplay : '') + (events.length ? ' • ' + events.length : '');
                    list.innerHTML = '';

                    if (!events.length) {
                        const empty = document.createElement('div');
                        empty.style.color = '#64748b';
                        empty.textContent = 'Nenhum evento.';
                        list.appendChild(empty);
                    } else {
                        events.forEach((ev) => {
                            const item = document.createElement('div');
                            item.className = 'modal-item';

                            const a = document.createElement('a');
                            a.className = 'modal-link';
                            a.href = ev.url || '#';
                            a.textContent = ev.name || 'Evento';

                            const meta = document.createElement('div');
                            meta.className = 'modal-meta';
                            const parts = [];
                            if (ev.time) parts.push('Hora: ' + ev.time);
                            if (ev.local) parts.push('Local: ' + ev.local);
                            if (ev.cliente) parts.push('Cliente: ' + ev.cliente);
                            meta.textContent = parts.join(' • ');

                            item.appendChild(a);
                            if (parts.length) item.appendChild(meta);
                            list.appendChild(item);
                        });
                    }

                    overlay.classList.add('open');
                    overlay.setAttribute('aria-hidden', 'false');
                    document.body.style.overflow = 'hidden';
                }

                document.addEventListener('click', (e) => {
                    const openBtn = e.target.closest('[data-open-day-modal="1"]');
                    if (openBtn) {
                        openModal(openBtn);
                        return;
                    }
                    if (e.target.closest('[data-day-modal-close="1"]')) {
                        closeModal();
                        return;
                    }
                    if (e.target === overlay) {
                        closeModal();
                    }
                });

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && overlay.classList.contains('open')) {
                        closeModal();
                    }
                });
            })();

            (function() {
                const overlay = document.getElementById('anexoModalOverlay');
                const body = document.getElementById('anexoModalBody');
                const title = document.getElementById('anexoModalTitle');
                const subtitle = document.getElementById('anexoModalSubtitle');
                const downloadLink = document.getElementById('anexoModalDownload');

                if (!overlay || !body || !title || !subtitle || !downloadLink) return;

                function closeAttachmentModal() {
                    overlay.classList.remove('open');
                    overlay.setAttribute('aria-hidden', 'true');
                    document.body.style.overflow = '';
                    body.innerHTML = '';
                    subtitle.textContent = '';
                    downloadLink.setAttribute('href', '#');
                    downloadLink.removeAttribute('download');
                }

                function getFileExtension(fileName) {
                    const name = String(fileName || '').trim();
                    const idx = name.lastIndexOf('.');
                    if (idx < 0) return '';
                    return name.slice(idx + 1).toLowerCase();
                }

                function openAttachmentModal(btn) {
                    const url = String(btn.getAttribute('data-url') || '').trim();
                    const name = String(btn.getAttribute('data-name') || 'arquivo').trim() || 'arquivo';
                    const mime = String(btn.getAttribute('data-mime') || '').toLowerCase();
                    const kind = String(btn.getAttribute('data-kind') || '').toLowerCase();
                    const ext = getFileExtension(name);

                    title.textContent = name;
                    subtitle.textContent = mime || kind || 'Arquivo';
                    body.innerHTML = '';

                    if (url === '') {
                        const msg = document.createElement('div');
                        msg.className = 'anexo-preview-empty';
                        msg.textContent = 'Arquivo sem URL pública para visualização.';
                        body.appendChild(msg);
                        downloadLink.setAttribute('href', '#');
                        downloadLink.removeAttribute('download');
                        downloadLink.style.display = 'none';
                    } else {
                        downloadLink.style.display = 'inline-flex';
                        downloadLink.setAttribute('href', url);
                        downloadLink.setAttribute('download', name);

                        const isImage = kind === 'imagem'
                            || mime.startsWith('image/')
                            || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'].includes(ext);
                        const isVideo = kind === 'video'
                            || mime.startsWith('video/')
                            || ['mp4', 'mov', 'webm', 'ogg', 'avi'].includes(ext);
                        const isAudio = kind === 'audio'
                            || mime.startsWith('audio/')
                            || ['mp3', 'wav', 'ogg', 'aac', 'm4a'].includes(ext);
                        const isPdf = kind === 'pdf' || mime === 'application/pdf' || ext === 'pdf';

                        if (isImage) {
                            const img = document.createElement('img');
                            img.src = url;
                            img.alt = name;
                            body.appendChild(img);
                        } else if (isVideo) {
                            const video = document.createElement('video');
                            video.src = url;
                            video.controls = true;
                            video.preload = 'metadata';
                            body.appendChild(video);
                        } else if (isAudio) {
                            const audio = document.createElement('audio');
                            audio.src = url;
                            audio.controls = true;
                            audio.preload = 'metadata';
                            body.appendChild(audio);
                        } else if (isPdf) {
                            const frame = document.createElement('iframe');
                            frame.src = url;
                            frame.title = name;
                            body.appendChild(frame);
                        } else {
                            const msg = document.createElement('div');
                            msg.className = 'anexo-preview-empty';
                            msg.innerHTML = 'Pré-visualização não disponível para este formato.<br>Use o botão Download.';
                            body.appendChild(msg);
                        }
                    }

                    overlay.classList.add('open');
                    overlay.setAttribute('aria-hidden', 'false');
                    document.body.style.overflow = 'hidden';
                }

                document.addEventListener('click', (e) => {
                    const openBtn = e.target.closest('[data-open-anexo-modal="1"]');
                    if (openBtn) {
                        openAttachmentModal(openBtn);
                        return;
                    }
                    if (e.target.closest('[data-close-anexo-modal="1"]')) {
                        closeAttachmentModal();
                        return;
                    }
                    if (e.target === overlay) {
                        closeAttachmentModal();
                    }
                });

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && overlay.classList.contains('open')) {
                        closeAttachmentModal();
                    }
                });
            })();
        </script>
</body>
</html>
