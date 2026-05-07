<?php
/**
 * eventos_organizacao.php
 * Hub de Organização de Eventos (interno)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/pacotes_evento_helper.php';
require_once __DIR__ . '/logistica_cardapio_helper.php';
require_once __DIR__ . '/upload_magalu.php';

function eventos_organizacao_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eventos_organizacao_upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_OK:
            return '';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Arquivo excede o limite máximo permitido pelo servidor.';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload incompleto. Tente novamente.';
        case UPLOAD_ERR_NO_FILE:
            return 'Selecione um arquivo para enviar.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Servidor sem pasta temporária para upload.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Falha ao gravar o arquivo temporário.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload bloqueado por extensão do servidor.';
        default:
            return 'Erro desconhecido de upload.';
    }
}

function eventos_organizacao_flash_set(string $type, string $message): void
{
    $_SESSION['eventos_organizacao_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function eventos_organizacao_resumo_redirect(int $meeting_id): void
{
    $target = 'index.php?page=eventos_organizacao';
    if ($meeting_id > 0) {
        $target .= '&id=' . $meeting_id . '#resumo-evento-card';
    }
    header('Location: ' . $target);
    exit;
}

if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$meeting_id = (int)($_GET['id'] ?? $_POST['meeting_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
pacotes_evento_ensure_schema($pdo);
logistica_cardapio_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['upload_resumo_evento_card', 'excluir_resumo_evento_card'], true)) {
    if ($meeting_id <= 0) {
        eventos_organizacao_flash_set('error', 'Reunião inválida para atualizar o resumo do evento.');
        eventos_organizacao_resumo_redirect(0);
    }

    try {
        $reuniao_resumo = eventos_reuniao_get($pdo, $meeting_id);
        if (!$reuniao_resumo) {
            eventos_organizacao_flash_set('error', 'Reunião não encontrada.');
            eventos_organizacao_resumo_redirect($meeting_id);
        }

        eventos_arquivos_seed_campos_sistema($pdo, $meeting_id, $user_id);
        $campo_resumo = eventos_arquivos_buscar_campo_por_chave($pdo, $meeting_id, 'resumo_evento', true);
        if (!$campo_resumo || (int)($campo_resumo['id'] ?? 0) <= 0) {
            eventos_organizacao_flash_set('error', 'Campo "Resumo do evento" não encontrado.');
            eventos_organizacao_resumo_redirect($meeting_id);
        }

        if ($action === 'upload_resumo_evento_card') {
            $file = $_FILES['arquivo_resumo_evento'] ?? null;
            $descricao = trim((string)($_POST['descricao_resumo_evento'] ?? ''));

            if (!$file || !is_array($file)) {
                eventos_organizacao_flash_set('error', 'Selecione um PDF para o Resumo do evento.');
                eventos_organizacao_resumo_redirect($meeting_id);
            }

            $upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($upload_error !== UPLOAD_ERR_OK) {
                eventos_organizacao_flash_set('error', eventos_organizacao_upload_error_message($upload_error));
                eventos_organizacao_resumo_redirect($meeting_id);
            }

            if ((int)($file['size'] ?? 0) > 500 * 1024 * 1024) {
                eventos_organizacao_flash_set('error', 'Arquivo muito grande. Limite máximo: 500MB.');
                eventos_organizacao_resumo_redirect($meeting_id);
            }

            $uploader = new MagaluUpload(500);
            $upload_result = $uploader->upload($file, 'eventos/reunioes/' . $meeting_id . '/arquivos/resumo_evento');
            $saved = eventos_arquivos_salvar_item(
                $pdo,
                $meeting_id,
                $upload_result,
                (int)$campo_resumo['id'],
                $descricao,
                false,
                'interno',
                $user_id > 0 ? $user_id : null
            );

            if (!empty($saved['ok'])) {
                $replaced_count = (int)($saved['replaced_count'] ?? 0);
                eventos_organizacao_flash_set(
                    'success',
                    $replaced_count > 0
                        ? 'Resumo do evento atualizado com sucesso.'
                        : 'Resumo do evento anexado com sucesso.'
                );
            } else {
                eventos_organizacao_flash_set('error', (string)($saved['error'] ?? 'Falha ao salvar o Resumo do evento.'));
            }
        }

        if ($action === 'excluir_resumo_evento_card') {
            $arquivo_id = (int)($_POST['arquivo_id'] ?? 0);
            $deleted = eventos_arquivos_excluir_item($pdo, $meeting_id, $arquivo_id, $user_id);
            if (!empty($deleted['ok'])) {
                eventos_organizacao_flash_set('success', 'Resumo do evento removido com sucesso.');
            } else {
                eventos_organizacao_flash_set('error', (string)($deleted['error'] ?? 'Falha ao remover o Resumo do evento.'));
            }
        }
    } catch (Throwable $e) {
        error_log('eventos_organizacao resumo POST: ' . $e->getMessage());
        eventos_organizacao_flash_set('error', 'Erro interno ao processar o resumo do evento.');
    }

    eventos_organizacao_resumo_redirect($meeting_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($action) {
            case 'organizar_evento':
                $me_event_id = (int)($_POST['me_event_id'] ?? 0);
                $tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($_POST['tipo_evento_real'] ?? ''));
                if ($me_event_id <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'Selecione um evento válido.']);
                    exit;
                }
                if ($tipo_evento_real === '') {
                    echo json_encode(['ok' => false, 'error' => 'Selecione o tipo real do evento para continuar.']);
                    exit;
                }

                $stmt = $pdo->prepare("SELECT id FROM eventos_reunioes WHERE me_event_id = :me_event_id LIMIT 1");
                $stmt->execute([':me_event_id' => $me_event_id]);
                $existing_meeting_id = (int)($stmt->fetchColumn() ?: 0);
                if ($existing_meeting_id > 0) {
                    echo json_encode([
                        'ok' => false,
                        'already_exists' => true,
                        'error' => 'Este evento já está organizado.',
                        'reuniao' => ['id' => $existing_meeting_id],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }

                $meeting_result = eventos_reuniao_get_or_create($pdo, $me_event_id, $user_id, $tipo_evento_real);
                if (empty($meeting_result['ok']) || empty($meeting_result['reuniao']['id'])) {
                    echo json_encode(['ok' => false, 'error' => $meeting_result['error'] ?? 'Não foi possível organizar este evento.']);
                    exit;
                }

                $meeting_id = (int)$meeting_result['reuniao']['id'];
                $portal_result = eventos_cliente_portal_get_or_create($pdo, $meeting_id, $user_id);
                if (empty($portal_result['ok']) || empty($portal_result['portal'])) {
                    echo json_encode(['ok' => false, 'error' => $portal_result['error'] ?? 'Falha ao gerar link do portal do cliente.']);
                    exit;
                }

                echo json_encode([
                    'ok' => true,
                    'reuniao' => ['id' => $meeting_id],
                    'portal' => $portal_result['portal'],
                    'tipo_evento_real' => $tipo_evento_real,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;

            case 'atualizar_tipo_evento_real':
                if ($meeting_id <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'Reunião inválida.']);
                    exit;
                }
                $tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($_POST['tipo_evento_real'] ?? ''));
                if ($tipo_evento_real === '') {
                    echo json_encode(['ok' => false, 'error' => 'Tipo de evento inválido.']);
                    exit;
                }
                $updated = eventos_reuniao_atualizar_tipo_evento_real($pdo, $meeting_id, $tipo_evento_real, $user_id);
                echo json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;

            case 'atualizar_pacote_evento':
                if ($meeting_id <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'Reunião inválida.']);
                    exit;
                }

                $reuniao_atual = eventos_reuniao_get($pdo, $meeting_id);
                $pacote_evento_anterior = (int)($reuniao_atual['pacote_evento_id'] ?? 0);

                $pacote_evento_raw = trim((string)($_POST['pacote_evento_id'] ?? ''));
                $pacote_evento_id = null;
                if ($pacote_evento_raw !== '') {
                    if (!ctype_digit($pacote_evento_raw)) {
                        echo json_encode(['ok' => false, 'error' => 'Pacote do evento inválido.']);
                        exit;
                    }
                    $pacote_evento_id = (int)$pacote_evento_raw;
                    if ($pacote_evento_id <= 0) {
                        echo json_encode(['ok' => false, 'error' => 'Pacote do evento inválido.']);
                        exit;
                    }
                }

                $updated = eventos_reuniao_atualizar_pacote_evento($pdo, $meeting_id, $pacote_evento_id);
                if (!empty($updated['ok'])) {
                    $pacote_evento_novo = (int)($updated['reuniao']['pacote_evento_id'] ?? 0);
                    if ($pacote_evento_anterior !== $pacote_evento_novo) {
                        logistica_cardapio_evento_resetar($pdo, $meeting_id);
                    }
                }
                echo json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;

            case 'salvar_portal_config':
                if ($meeting_id <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'Reunião inválida.']);
                    exit;
                }

                $config_payload = [];
                $bool_fields = [
                    'visivel_reuniao',
                    'editavel_reuniao',
                    'visivel_dj',
                    'editavel_dj',
                    'visivel_convidados',
                    'editavel_convidados',
                    'visivel_arquivos',
                    'editavel_arquivos',
                    'visivel_cardapio',
                    'editavel_cardapio',
                ];
                foreach ($bool_fields as $field) {
                    if (!array_key_exists($field, $_POST)) {
                        continue;
                    }
                    $config_payload[$field] = ((string)($_POST[$field] ?? '0') === '1');
                }
                $config_payload['visivel_cardapio'] = false;
                $config_payload['editavel_cardapio'] = false;

                $result = eventos_cliente_portal_atualizar_config(
                    $pdo,
                    $meeting_id,
                    $config_payload,
                    $user_id
                );

                echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
        }
    } catch (Throwable $e) {
        error_log('eventos_organizacao POST: ' . $e->getMessage());
        echo json_encode([
            'ok' => false,
            'error' => 'Erro interno ao processar a solicitação.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$reuniao = null;
$snapshot = [];
$portal = null;
$links_cliente_dj = [];
$links_cliente_observacoes = [];

if ($meeting_id > 0) {
    $reuniao = eventos_reuniao_get($pdo, $meeting_id);
    if ($reuniao) {
        $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }

        $portal_result = eventos_cliente_portal_get_or_create($pdo, $meeting_id, $user_id);
        if (!empty($portal_result['ok']) && !empty($portal_result['portal'])) {
            $portal = $portal_result['portal'];
            $portal_secoes_cfg = eventos_cliente_portal_obter_config_secoes($portal);
            $sync_reuniao_visivel = !empty($portal['visivel_reuniao'])
                && (
                    !empty($portal_secoes_cfg['decoracao']['visivel'])
                    || !empty($portal_secoes_cfg['observacoes_gerais']['visivel'])
                );
            $sync_reuniao_editavel = !empty($portal_secoes_cfg['decoracao']['editavel'])
                || !empty($portal_secoes_cfg['observacoes_gerais']['editavel']);
            if ($sync_reuniao_editavel) {
                $sync_reuniao_visivel = true;
            }
            if ($sync_reuniao_visivel || $sync_reuniao_editavel) {
                eventos_cliente_portal_sincronizar_link_reuniao(
                    $pdo,
                    $meeting_id,
                    $sync_reuniao_visivel,
                    $sync_reuniao_editavel,
                    (int)$user_id
                );
            }
            $sync_dj_visivel = !empty($portal_secoes_cfg['dj_protocolo']['visivel']);
            $sync_dj_editavel = !empty($portal_secoes_cfg['dj_protocolo']['editavel']);
            if ($sync_dj_visivel || $sync_dj_editavel) {
                eventos_cliente_portal_sincronizar_link_dj(
                    $pdo,
                    $meeting_id,
                    $sync_dj_visivel,
                    $sync_dj_editavel,
                    (int)$user_id
                );
            }
        }

        $links_cliente_dj = eventos_reuniao_listar_links_cliente($pdo, $meeting_id, 'cliente_dj');
        $links_cliente_observacoes = eventos_reuniao_listar_links_cliente($pdo, $meeting_id, 'cliente_observacoes');
    }
}

$nome_evento = trim((string)($snapshot['nome'] ?? 'Evento'));
$data_evento = trim((string)($snapshot['data'] ?? ''));
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
$local_evento = trim((string)($snapshot['local'] ?? 'Local não definido'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente não informado'));
$data_fmt = $data_evento !== '' ? date('d/m/Y', strtotime($data_evento)) : '-';
$horario_fmt = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_fmt .= ' - ' . $hora_fim;
}

$portal_url = (string)($portal['url'] ?? '');
$visivel_reuniao = !empty($portal['visivel_reuniao']);
$editavel_reuniao = !empty($portal['editavel_reuniao']);
$visivel_dj = !empty($portal['visivel_dj']);
$editavel_dj = !empty($portal['editavel_dj']);
$visivel_convidados = !empty($portal['visivel_convidados']);
$editavel_convidados = !empty($portal['editavel_convidados']);
$visivel_arquivos = !empty($portal['visivel_arquivos']);
$editavel_arquivos = !empty($portal['editavel_arquivos']);
$visivel_cardapio = false;
$editavel_cardapio = false;
$convidados_resumo = $meeting_id > 0 ? eventos_convidados_resumo($pdo, $meeting_id) : ['total' => 0, 'checkin' => 0, 'pendentes' => 0];
$arquivos_resumo = $meeting_id > 0 ? eventos_arquivos_resumo($pdo, $meeting_id) : [
    'campos_total' => 0,
    'campos_obrigatorios' => 0,
    'campos_pendentes' => 0,
    'arquivos_total' => 0,
    'arquivos_visiveis_cliente' => 0,
    'arquivos_cliente' => 0,
];
$cardapio_context = $meeting_id > 0 ? logistica_cardapio_evento_contexto($pdo, $meeting_id) : ['ok' => false];
$cardapio_summary = !empty($cardapio_context['ok']) ? ($cardapio_context['summary'] ?? []) : [];
$cardapio_submitted_at = trim((string)($cardapio_summary['submitted_at'] ?? ''));
$cardapio_submitted_fmt = $cardapio_submitted_at !== '' ? date('d/m/Y H:i', strtotime($cardapio_submitted_at)) : '';
$cardapio_helper_note = 'Selecione um pacote do evento para montar o cardápio.';
if (!empty($cardapio_summary['has_pacote'])) {
    if ((int)($cardapio_summary['secoes_total'] ?? 0) <= 0) {
        $cardapio_helper_note = 'Pacote selecionado, mas sem seções configuradas para o cliente.';
    } elseif ($cardapio_submitted_at !== '') {
        $cardapio_helper_note = (int)($cardapio_summary['selecionados_total'] ?? 0)
            . ' item(ns) escolhidos • enviado em ' . $cardapio_submitted_fmt;
    } else {
        $cardapio_helper_note = (int)($cardapio_summary['secoes_total'] ?? 0)
            . ' seção(ões) configurada(s) • ' . (int)($cardapio_summary['itens_total'] ?? 0) . ' opção(ões) disponíveis';
    }
}
$tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($reuniao['tipo_evento_real'] ?? ($snapshot['tipo_evento_real'] ?? '')));
$tipo_evento_real_label = eventos_reuniao_tipo_evento_real_label($tipo_evento_real, $pdo);
$tipos_evento_real_options = eventos_reuniao_tipos_evento_real_options($pdo, false);
if ($tipo_evento_real !== '' && !isset($tipos_evento_real_options[$tipo_evento_real])) {
    $tipos_evento_real_options[$tipo_evento_real] = $tipo_evento_real_label;
}
$pacote_evento_id = (int)($reuniao['pacote_evento_id'] ?? 0);
$pacotes_evento_raw = pacotes_evento_listar($pdo, true);
$pacotes_evento_options = [];
foreach ($pacotes_evento_raw as $pacote_item) {
    $pacote_item_id = (int)($pacote_item['id'] ?? 0);
    if ($pacote_item_id <= 0) {
        continue;
    }
    $is_oculto = !empty($pacote_item['oculto']);
    if ($is_oculto && $pacote_item_id !== $pacote_evento_id) {
        continue;
    }
    $pacotes_evento_options[] = $pacote_item;
}

$has_dj_link = !empty($links_cliente_dj);
$has_obs_link = !empty($links_cliente_observacoes);
$flash = $_SESSION['eventos_organizacao_flash'] ?? null;
unset($_SESSION['eventos_organizacao_flash']);
$flash_type = is_array($flash) ? trim((string)($flash['type'] ?? '')) : '';
$flash_message = is_array($flash) ? trim((string)($flash['message'] ?? '')) : '';
$resumo_evento_arquivo_atual = null;

if ($meeting_id > 0 && $reuniao) {
    eventos_arquivos_seed_campos_sistema($pdo, $meeting_id, $user_id);
    $campo_resumo_evento = eventos_arquivos_buscar_campo_por_chave($pdo, $meeting_id, 'resumo_evento', true);
    $campo_resumo_evento_id = (int)($campo_resumo_evento['id'] ?? 0);
    if ($campo_resumo_evento_id > 0) {
        $resumo_evento_arquivos = eventos_arquivos_listar($pdo, $meeting_id, false, $campo_resumo_evento_id, true);
        $resumo_evento_arquivo_atual = $resumo_evento_arquivos[0] ?? null;
    }
}

includeSidebar('Organização eventos');
?>

<style>
    .organizacao-container {
        padding: 2rem;
        max-width: 1280px;
        margin: 0 auto;
        background: #f8fafc;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }

    .page-title {
        font-size: 1.6rem;
        font-weight: 700;
        color: #1e3a8a;
        margin: 0;
    }

    .page-subtitle {
        color: #64748b;
        margin-top: 0.35rem;
        font-size: 0.92rem;
    }

    .btn {
        padding: 0.62rem 1rem;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        font-size: 0.86rem;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .btn-primary {
        background: #1e3a8a;
        color: #fff;
    }

    .btn-primary:hover {
        background: #254ac9;
    }

    .btn-secondary {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #dbe3ef;
    }

    .btn-secondary:hover {
        background: #e2e8f0;
    }

    .btn-success {
        background: #059669;
        color: #fff;
    }

    .btn-success:hover {
        background: #047857;
    }

    .btn-danger {
        background: #dc2626;
        color: #fff;
    }

    .btn-danger:hover {
        background: #b91c1c;
    }

    .alert {
        margin-bottom: 1rem;
        padding: 0.85rem 1rem;
        border-radius: 10px;
        border: 1px solid transparent;
        font-size: 0.88rem;
    }

    .alert-success {
        background: #ecfdf5;
        color: #166534;
        border-color: #a7f3d0;
    }

    .alert-error {
        background: #fef2f2;
        color: #991b1b;
        border-color: #fecaca;
    }

    .event-selector {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 1.2rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    }

    .event-selector h3 {
        margin: 0 0 0.85rem 0;
        color: #1f2937;
    }

    .search-wrapper {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .search-input {
        flex: 1;
        border: 1px solid #d1d5db;
        border-radius: 10px;
        padding: 0.72rem 0.9rem;
        font-size: 0.9rem;
    }

    .search-input:focus {
        outline: none;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.12);
    }

    .search-hint {
        color: #64748b;
        font-size: 0.8rem;
        margin-bottom: 0.75rem;
    }

    .events-list {
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        max-height: 360px;
        overflow-y: auto;
        background: #fff;
    }

    .event-item {
        padding: 0.9rem 1rem;
        border-bottom: 1px solid #eef2f7;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        transition: background 0.15s ease;
    }

    .event-item:last-child {
        border-bottom: none;
    }

    .event-item:hover {
        background: #f8fafc;
    }

    .event-item.selected {
        background: #e7efff;
        border-left: 3px solid #1e3a8a;
        padding-left: calc(1rem - 3px);
    }

    .event-info h4 {
        margin: 0;
        font-size: 1.05rem;
        color: #1f2937;
    }

    .event-title-row {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .event-organizado-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #065f46;
        background: #d1fae5;
        border: 1px solid #a7f3d0;
    }

    .event-item.organized {
        background: #f8fffb;
    }

    .event-info p {
        margin: 0.2rem 0;
        color: #64748b;
        font-size: 0.92rem;
    }

    .event-item-label {
        color: #1d4ed8;
        font-size: 0.9rem;
        font-weight: 600;
    }

    .event-date {
        color: #1e3a8a;
        font-weight: 700;
        white-space: nowrap;
    }

    .selected-event-summary {
        display: none;
        margin-top: 0.9rem;
        padding: 0.7rem 0.85rem;
        border: 1px solid #dbe3ef;
        border-radius: 9px;
        background: #f8fafc;
        color: #334155;
        font-size: 0.88rem;
    }

    .selected-event {
        display: none;
        margin-top: 0.85rem;
    }

    .event-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: #fff;
        border-radius: 14px;
        padding: 1.3rem 1.5rem;
        margin-bottom: 1rem;
    }

    .event-header h2 {
        margin: 0 0 0.55rem 0;
        font-size: 1.45rem;
    }

    .event-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.85rem 1.35rem;
        font-size: 0.92rem;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.3rem 0.58rem;
        border-radius: 999px;
        font-weight: 700;
        font-size: 0.78rem;
        background: rgba(255, 255, 255, 0.2);
    }

    .portal-link-card {
        position: sticky;
        top: 0.75rem;
        z-index: 8;
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 0.85rem;
        margin-bottom: 1rem;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
    }

    .portal-link-title {
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 0.5rem;
        font-size: 0.92rem;
    }

    .link-row {
        display: flex;
        gap: 0.6rem;
    }

    .link-input {
        flex: 1;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.62rem 0.78rem;
        font-size: 0.84rem;
        color: #1f2937;
        background: #f8fafc;
    }

    .cards-grid {
        display: grid;
        gap: 0.95rem;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    }

    .module-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
    }

    .module-card h3 {
        margin: 0;
        color: #1f2937;
        font-size: 1.05rem;
    }

    .module-card p {
        margin: 0.45rem 0 0 0;
        color: #64748b;
        font-size: 0.86rem;
        line-height: 1.45;
    }

    .module-options {
        margin-top: 0.85rem;
        display: grid;
        gap: 0.5rem;
    }

    .check-row {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        color: #334155;
        font-size: 0.88rem;
    }

    .card-actions {
        margin-top: 0.85rem;
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
    }

    .card-actions form {
        display: inline-flex;
    }

    .helper-note {
        margin-top: 0.5rem;
        color: #64748b;
        font-size: 0.8rem;
    }

    .helper-note-stack {
        display: grid;
        gap: 0.2rem;
    }

    .resumo-upload-form {
        margin-top: 0.9rem;
        display: grid;
        gap: 0.7rem;
    }

    .resumo-upload-field {
        display: grid;
        gap: 0.35rem;
    }

    .resumo-upload-field label {
        font-size: 0.84rem;
        font-weight: 700;
        color: #334155;
    }

    .resumo-upload-field input[type="file"] {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.55rem 0.65rem;
        background: #fff;
        color: #1f2937;
        font-size: 0.84rem;
    }

    .resumo-upload-field textarea {
        min-height: 72px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.65rem 0.75rem;
        resize: vertical;
        font: inherit;
        color: #1f2937;
    }

    .resumo-file-box {
        margin-top: 0.9rem;
        padding: 0.8rem;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #f8fafc;
    }

    .resumo-file-title {
        margin: 0;
        font-size: 0.92rem;
        font-weight: 700;
        color: #1f2937;
    }

    .resumo-file-meta {
        margin-top: 0.35rem;
        color: #64748b;
        font-size: 0.82rem;
        line-height: 1.45;
    }

    .status-note {
        margin-top: 0.75rem;
        font-size: 0.82rem;
        color: #0f766e;
        display: none;
    }

    .status-note.error {
        color: #b91c1c;
    }

    .tipo-real-config {
        margin-top: 0.85rem;
        border-top: 1px solid #e2e8f0;
        padding-top: 0.8rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 0.75rem;
        align-items: end;
    }

    .tipo-real-field {
        display: flex;
        flex-direction: column;
        gap: 0.38rem;
    }

    .tipo-real-field label {
        font-size: 0.84rem;
        font-weight: 700;
        color: #334155;
    }

    .tipo-real-select {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.5rem 0.65rem;
        font-size: 0.85rem;
        background: #fff;
        color: #1f2937;
    }

    .tipo-evento-modal {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 60;
        padding: 1rem;
    }

    .tipo-evento-modal.open {
        display: flex;
    }

    .tipo-evento-modal-card {
        width: min(460px, 100%);
        background: #fff;
        border-radius: 12px;
        border: 1px solid #dbe3ef;
        box-shadow: 0 18px 38px rgba(15, 23, 42, 0.22);
        padding: 1rem;
    }

    .tipo-evento-modal-card h3 {
        margin: 0;
        color: #0f172a;
    }

    .tipo-evento-modal-card p {
        margin: 0.45rem 0 0.8rem 0;
        color: #64748b;
        font-size: 0.86rem;
    }

    .tipo-evento-modal-card select {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.58rem 0.7rem;
        font-size: 0.9rem;
        margin-bottom: 0.9rem;
    }

    .tipo-evento-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.55rem;
        flex-wrap: wrap;
    }

    @media (max-width: 768px) {
        .organizacao-container {
            padding: 1rem;
        }

        .search-wrapper,
        .link-row {
            flex-direction: column;
        }
    }
</style>

<div class="organizacao-container">
    <?php if ($flash_message !== ''): ?>
    <div class="alert <?= $flash_type === 'success' ? 'alert-success' : 'alert-error' ?>"><?= eventos_organizacao_e($flash_message) ?></div>
    <?php endif; ?>

    <div class="page-header">
        <div>
            <h1 class="page-title">🧩 Organização de Eventos</h1>
            <div class="page-subtitle">Busque o evento, organize e configure o que o cliente verá no portal.</div>
        </div>
        <?php if ($reuniao): ?>
        <a href="index.php?page=eventos_organizacao" class="btn btn-secondary">+ Organizar outro evento</a>
        <?php else: ?>
        <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
        <?php endif; ?>
    </div>

    <?php if (!$reuniao): ?>
    <div class="event-selector">
        <h3>🔍 Buscar Evento</h3>
        <div class="search-wrapper">
            <input type="text" id="eventSearch" class="search-input" placeholder="Digite nome, cliente, local ou data...">
            <button type="button" class="btn btn-primary" onclick="searchEvents(null, true)">Buscar</button>
        </div>
        <div class="search-hint">Busca inteligente: digitou, filtrou. A lista também usa cache para reduzir atraso.</div>
        <div id="eventsList" class="events-list" style="display:none;"></div>
        <div id="loadingEvents" style="display:none; padding:1.3rem; text-align:center; color:#64748b;">Carregando eventos...</div>
        <div id="selectedEventSummary" class="selected-event-summary"></div>
        <div id="selectedEvent" class="selected-event">
            <button type="button" id="btnOrganizarEvento" class="btn btn-success" onclick="organizarEvento()">Organizar este Evento</button>
        </div>
    </div>

    <div id="tipoEventoModal" class="tipo-evento-modal" onclick="onTipoEventoModalBackdrop(event)">
        <div class="tipo-evento-modal-card" role="dialog" aria-modal="true" aria-labelledby="tipoEventoModalTitle">
            <h3 id="tipoEventoModalTitle">Defina o tipo real do evento</h3>
            <p>Selecione o tipo para continuar. Essa informação será usada no Portal DJ e Lista de Convidados.</p>
            <select id="tipoEventoModalSelect">
                <option value="">Selecione o tipo...</option>
                <?php foreach ($tipos_evento_real_options as $tipo_key => $tipo_label): ?>
                <option value="<?= htmlspecialchars($tipo_key) ?>"><?= htmlspecialchars($tipo_label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="tipo-evento-modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeTipoEventoModal()">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btnConfirmOrganizarEvento" onclick="confirmarOrganizarEvento()">Organizar evento</button>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="event-header">
        <h2><?= htmlspecialchars($nome_evento) ?></h2>
        <div class="event-meta">
            <div>📅 <?= htmlspecialchars($data_fmt) ?> • <?= htmlspecialchars($horario_fmt) ?></div>
            <div>📍 <?= htmlspecialchars($local_evento) ?></div>
            <div>👤 <?= htmlspecialchars($cliente_nome) ?></div>
            <div>🏷️ <?= htmlspecialchars($tipo_evento_real_label) ?></div>
            <div class="status-badge">
                <?= !empty($reuniao['status']) && $reuniao['status'] === 'concluida' ? 'Concluída' : 'Rascunho' ?>
            </div>
        </div>
    </div>

    <div class="portal-link-card">
        <div class="portal-link-title">🔗 Link do portal do cliente (fixo)</div>
        <div class="link-row">
            <input type="text" id="portalLinkInput" class="link-input" readonly value="<?= htmlspecialchars($portal_url) ?>">
            <button type="button" class="btn btn-secondary" onclick="copiarPortalLink()">📋 Copiar</button>
            <a href="<?= htmlspecialchars($portal_url) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">Abrir</a>
        </div>
        <div class="tipo-real-config">
            <div class="tipo-real-field">
                <label for="tipoEventoRealSelect">Tipo de evento</label>
                <select id="tipoEventoRealSelect" class="tipo-real-select">
                    <option value="">Selecione...</option>
                    <?php foreach ($tipos_evento_real_options as $tipo_key => $tipo_label): ?>
                    <option value="<?= htmlspecialchars($tipo_key) ?>" <?= $tipo_evento_real === $tipo_key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tipo_label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tipo-real-field">
                <label for="pacoteEventoSelect">Pacote do evento</label>
                <select id="pacoteEventoSelect" class="tipo-real-select">
                    <option value="">Sem pacote selecionado</option>
                    <?php foreach ($pacotes_evento_options as $pacote_item): ?>
                    <?php
                    $pacote_item_id = (int)($pacote_item['id'] ?? 0);
                    $pacote_label = trim((string)($pacote_item['nome'] ?? 'Pacote'));
                    if (!empty($pacote_item['oculto'])) {
                        $pacote_label .= ' (oculto)';
                    }
                    ?>
                    <option value="<?= $pacote_item_id ?>" <?= $pacote_evento_id === $pacote_item_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pacote_label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="cards-grid">
        <div class="module-card">
            <h3>📝 Reunião Final</h3>
            <p>Área central da reunião final com as abas Decoração, Observações Gerais, DJ / Protocolos e Formulário.</p>
            <div class="module-options">
                <label class="check-row">
                    <input type="checkbox" id="cfgVisivelReuniao" <?= $visivel_reuniao ? 'checked' : '' ?>>
                    <span>Visível para o cliente</span>
                </label>
            </div>
            <div class="card-actions">
                <a href="index.php?page=eventos_reuniao_final&id=<?= (int)$meeting_id ?>&origin=organizacao" class="btn btn-primary">Abrir Reunião Final</a>
            </div>
            <div class="helper-note helper-note-stack">
                <div><?= $has_obs_link ? 'Há link público ativo para Reunião Final.' : 'Sem link público ativo de Reunião Final.' ?></div>
                <div><?= $has_dj_link ? 'Há link público ativo para DJ.' : 'Sem link público ativo de DJ.' ?></div>
            </div>
        </div>

        <div class="module-card">
            <h3>📋 Lista de Convidados</h3>
            <p>Cliente preenche lista por nome/faixa etária e, quando aplicável, número da mesa. Uso interno com check-in por nome.</p>
            <div class="module-options">
                <label class="check-row">
                    <input type="checkbox" id="cfgVisivelConvidados" <?= $visivel_convidados ? 'checked' : '' ?>>
                    <span>Visível para o cliente</span>
                </label>
                <label class="check-row">
                    <input type="checkbox" id="cfgEditavelConvidados" <?= $editavel_convidados ? 'checked' : '' ?>>
                    <span>Editável pelo cliente</span>
                </label>
            </div>
            <div class="card-actions">
                <a href="index.php?page=eventos_lista_convidados&id=<?= (int)$meeting_id ?>" class="btn btn-primary">Abrir Lista / Check-in</a>
            </div>
            <div class="helper-note">
                <?= (int)$convidados_resumo['total'] ?> convidados cadastrados • <?= (int)$convidados_resumo['checkin'] ?> check-ins
            </div>
        </div>

        <div class="module-card">
            <h3>📁 Arquivos</h3>
            <p>Central de envio de arquivos do evento. Defina campos solicitados, receba anexos do cliente e controle visibilidade.</p>
            <div class="module-options">
                <label class="check-row">
                    <input type="checkbox" id="cfgVisivelArquivos" <?= $visivel_arquivos ? 'checked' : '' ?>>
                    <span>Visível para o cliente</span>
                </label>
                <label class="check-row">
                    <input type="checkbox" id="cfgEditavelArquivos" <?= $editavel_arquivos ? 'checked' : '' ?>>
                    <span>Editável pelo cliente</span>
                </label>
            </div>
            <div class="card-actions">
                <a href="index.php?page=eventos_arquivos&id=<?= (int)$meeting_id ?>" class="btn btn-primary">Abrir Arquivos</a>
            </div>
            <div class="helper-note">
                <?= (int)$arquivos_resumo['arquivos_total'] ?> arquivos • <?= (int)$arquivos_resumo['campos_total'] ?> campos solicitados • <?= (int)$arquivos_resumo['campos_pendentes'] ?> pendências obrigatórias
            </div>
        </div>

        <div class="module-card" id="resumo-evento-card">
            <h3>📄 Resumo do Evento</h3>
            <p>Contrato do cliente em PDF com tudo que foi contratado. Uso interno, com histórico de substituições no log de anexos.</p>

            <?php
                $resumo_nome = trim((string)($resumo_evento_arquivo_atual['original_name'] ?? ''));
                $resumo_url = trim((string)($resumo_evento_arquivo_atual['public_url'] ?? ''));
                $resumo_desc = trim((string)($resumo_evento_arquivo_atual['descricao'] ?? ''));
                $resumo_uploaded_at = trim((string)($resumo_evento_arquivo_atual['uploaded_at'] ?? ''));
                $resumo_uploaded_at_fmt = $resumo_uploaded_at !== '' ? date('d/m/Y H:i', strtotime($resumo_uploaded_at)) : '-';
            ?>

            <?php if (!$resumo_evento_arquivo_atual): ?>
            <div class="helper-note">Nenhum PDF anexado ainda.</div>
            <?php else: ?>
            <div class="resumo-file-box">
                <div class="resumo-file-title"><?= eventos_organizacao_e($resumo_nome !== '' ? $resumo_nome : 'arquivo.pdf') ?></div>
                <div class="resumo-file-meta">
                    Última atualização: <?= eventos_organizacao_e($resumo_uploaded_at_fmt) ?>
                    <?php if ($resumo_desc !== ''): ?><br><?= eventos_organizacao_e($resumo_desc) ?><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="resumo-upload-form">
                <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
                <input type="hidden" name="action" value="upload_resumo_evento_card">

                <div class="resumo-upload-field">
                    <label for="arquivoResumoEventoCard">Arquivo PDF</label>
                    <input type="file" id="arquivoResumoEventoCard" name="arquivo_resumo_evento" accept=".pdf,application/pdf" required>
                </div>

                <div class="resumo-upload-field">
                    <label for="descricaoResumoEventoCard">Descrição (opcional)</label>
                    <textarea id="descricaoResumoEventoCard" name="descricao_resumo_evento" placeholder="Ex.: Contrato assinado atualizado."></textarea>
                </div>

                <div class="card-actions">
                    <button type="submit" class="btn btn-primary"><?= $resumo_evento_arquivo_atual ? 'Substituir PDF' : 'Anexar PDF' ?></button>
                    <?php if ($resumo_url !== ''): ?>
                    <a href="<?= eventos_organizacao_e($resumo_url) ?>" class="btn btn-secondary" target="_blank" rel="noopener noreferrer">Visualizar PDF</a>
                    <?php endif; ?>
                    <a href="index.php?page=eventos_arquivos&id=<?= (int)$meeting_id ?>#resumo-evento" class="btn btn-secondary">Abrir histórico</a>
                </div>
            </form>

            <?php if ($resumo_evento_arquivo_atual): ?>
            <div class="card-actions">
                <form method="POST" onsubmit="return confirm('Deseja excluir o PDF atual do resumo do evento?');">
                    <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
                    <input type="hidden" name="action" value="excluir_resumo_evento_card">
                    <input type="hidden" name="arquivo_id" value="<?= (int)($resumo_evento_arquivo_atual['id'] ?? 0) ?>">
                    <button type="submit" class="btn btn-danger">Excluir PDF</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="helper-note">
                Sem opção de exibição no portal do cliente. Ao anexar novo PDF, o anterior é substituído automaticamente.
            </div>
        </div>

        <div class="module-card">
            <h3>🍽️ Cardápio</h3>
            <p>Monte as opções do cliente com base no pacote do evento, seções configuradas e escolhas bloqueadas após o envio.</p>
            <div class="module-options">
                <label class="check-row">
                    <input type="checkbox" id="cfgVisivelCardapio" disabled>
                    <span>Visível para o cliente</span>
                </label>
                <label class="check-row">
                    <input type="checkbox" id="cfgEditavelCardapio" disabled>
                    <span>Editável pelo cliente</span>
                </label>
            </div>
            <div class="card-actions">
                <a href="index.php?page=eventos_cardapio&id=<?= (int)$meeting_id ?>" class="btn btn-primary">Abrir Cardápio</a>
            </div>
            <div class="helper-note">
                <?= htmlspecialchars($cardapio_helper_note) ?>
            </div>
        </div>
    </div>

    <div id="cfgStatus" class="status-note"></div>
    <?php endif; ?>
</div>

<script>
const meetingId = <?= $meeting_id > 0 ? (int)$meeting_id : 'null' ?>;
let selectedEventId = null;
let selectedEventData = null;
let searchDebounceTimer = null;
let searchAbortController = null;
let eventsCacheLoaded = false;
let eventsMasterCache = [];
const eventsQueryCache = new Map();
let portalConfigSaveInFlight = false;
let portalConfigSaveQueued = false;
let tipoEventoSaveInFlight = false;
let tipoEventoSaveQueuedValue = null;
let pacoteEventoSaveInFlight = false;
let pacoteEventoSaveQueuedValue = null;
let pendingOrganizarEventId = null;
let organizarEventoInFlight = false;

async function parseJsonResponse(response) {
    const raw = await response.text();
    if (raw === '') {
        throw new Error('Resposta vazia do servidor.');
    }
    try {
        return JSON.parse(raw);
    } catch (err) {
        throw new Error('Resposta inválida do servidor.');
    }
}

function normalizeText(value) {
    return (value || '')
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

function localFilterEvents(query) {
    const q = normalizeText(query);
    if (!q) return eventsMasterCache.slice(0, 50);
    return eventsMasterCache.filter((ev) => {
        const hay = normalizeText([
            ev.nome,
            ev.cliente,
            ev.local,
            ev.data_formatada,
            ev.tipo,
            ev.organizado ? 'organizado' : '',
            ev.tipo_evento_real,
        ].join(' '));
        return hay.includes(q);
    }).slice(0, 80);
}

function getMeetingIdFromEvent(ev) {
    return Number(ev && ev.meeting_id ? ev.meeting_id : 0);
}

function isEventOrganizado(ev) {
    return getMeetingIdFromEvent(ev) > 0 || !!(ev && ev.organizado);
}

function renderEventsList(events) {
    const list = document.getElementById('eventsList');
    if (!list) return;

    if (!Array.isArray(events) || events.length === 0) {
        list.innerHTML = '<div style="padding:0.9rem; color:#64748b;">Nenhum evento encontrado</div>';
        list.style.display = 'block';
        return;
    }

    const selectedId = Number(selectedEventId || 0);
    list.innerHTML = events.map((ev) => {
        const isSelected = selectedId > 0 && Number(ev.id) === selectedId;
        const isOrganizado = isEventOrganizado(ev);
        const label = ev.label || `${ev.nome || 'Evento'} - ${ev.data_formatada || ''}`;
        return `
            <div class="event-item ${isSelected ? 'selected' : ''} ${isOrganizado ? 'organized' : ''}" data-id="${ev.id}" onclick="selectEvent(this, ${ev.id})">
                <div class="event-info">
                    <div class="event-title-row">
                        <h4>${ev.nome || 'Evento'}</h4>
                        ${isOrganizado ? '<span class="event-organizado-badge">Já organizado</span>' : ''}
                    </div>
                    <p>${ev.cliente || 'Cliente'} • ${ev.local || 'Local'} • ${ev.convidados || 0} convidados</p>
                    <div class="event-item-label">${label}</div>
                </div>
                <div class="event-date">${ev.data_formatada || '-'}</div>
            </div>
        `;
    }).join('');
    list.style.display = 'block';
}

function renderSelectedEventSummary(ev) {
    const summary = document.getElementById('selectedEventSummary');
    if (!summary) return;
    if (!ev) {
        summary.innerHTML = '';
        summary.style.display = 'none';
        return;
    }
    const meetingId = getMeetingIdFromEvent(ev);
    const statusLine = meetingId > 0
        ? `<br><span style="color:#065f46; font-weight:700;">✅ Já organizado (ID ${meetingId})</span>`
        : '';
    summary.innerHTML = `<strong>Selecionado:</strong> ${ev.nome || 'Evento'}<br><span>${ev.data_formatada || '-'} • ${ev.hora || '-'} • ${ev.local || 'Local não informado'} • ${ev.cliente || 'Cliente'}</span>${statusLine}`;
    summary.style.display = 'block';
}

function updateSelectedAction(ev) {
    const selected = document.getElementById('selectedEvent');
    const button = document.getElementById('btnOrganizarEvento');
    if (!selected || !button) return;

    if (!ev) {
        selected.style.display = 'none';
        return;
    }

    selected.style.display = 'block';
    if (isEventOrganizado(ev)) {
        button.textContent = 'Abrir organização existente';
        button.classList.remove('btn-success');
        button.classList.add('btn-primary');
    } else {
        button.textContent = 'Organizar este Evento';
        button.classList.remove('btn-primary');
        button.classList.add('btn-success');
    }
}

async function fetchRemoteEvents(query = '', forceRefresh = false) {
    const key = `${query}::${forceRefresh ? '1' : '0'}`;
    if (!forceRefresh && eventsQueryCache.has(key)) {
        return eventsQueryCache.get(key);
    }

    if (searchAbortController) {
        searchAbortController.abort();
    }
    searchAbortController = new AbortController();

    const url = `index.php?page=eventos_me_proxy&action=list&search=${encodeURIComponent(query)}&days=120${forceRefresh ? '&refresh=1' : ''}`;
    const resp = await fetch(url, { signal: searchAbortController.signal });
    const data = await parseJsonResponse(resp);
    if (!data.ok) {
        throw new Error(data.error || 'Erro ao buscar eventos');
    }
    const events = Array.isArray(data.events) ? data.events : [];
    eventsQueryCache.set(key, events);

    if (!query) {
        eventsMasterCache = events;
        eventsCacheLoaded = true;
    } else if (eventsMasterCache.length > 0) {
        const ids = new Set(eventsMasterCache.map((e) => Number(e.id)));
        events.forEach((ev) => {
            if (!ids.has(Number(ev.id))) {
                eventsMasterCache.push(ev);
            }
        });
    }
    return events;
}

async function searchEvents(queryOverride = null, forceRemote = false) {
    const input = document.getElementById('eventSearch');
    const list = document.getElementById('eventsList');
    const loading = document.getElementById('loadingEvents');
    if (!input || !list || !loading) return;

    const query = (queryOverride !== null ? queryOverride : input.value || '').trim();
    loading.style.display = 'block';
    list.style.display = 'none';

    try {
        if (!eventsCacheLoaded) {
            const initial = await fetchRemoteEvents('', false);
            renderEventsList(initial);
        }

        const localResults = localFilterEvents(query);
        renderEventsList(localResults);
        loading.style.display = 'none';

        if ((query.length >= 2 && forceRemote) || (query.length >= 3 && localResults.length < 8) || (forceRemote && query.length === 0)) {
            const remote = await fetchRemoteEvents(query, forceRemote);
            renderEventsList(remote);
        }
    } catch (err) {
        if (err && err.name === 'AbortError') return;
        loading.style.display = 'none';
        list.innerHTML = `<div style="padding:0.9rem; color:#b91c1c;">Erro: ${err.message}</div>`;
        list.style.display = 'block';
    }
}

function selectEvent(el, id) {
    selectedEventId = Number(id);
    pendingOrganizarEventId = selectedEventId;
    selectedEventData =
        (eventsMasterCache || []).find((ev) => Number(ev.id) === selectedEventId)
        || Array.from(eventsQueryCache.values()).flat().find((ev) => Number(ev.id) === selectedEventId)
        || null;

    document.querySelectorAll('.event-item').forEach((item) => item.classList.remove('selected'));
    if (el) el.classList.add('selected');
    renderSelectedEventSummary(selectedEventData);
    updateSelectedAction(selectedEventData);
}

function sugerirTipoEventoReal(ev) {
    if (!ev) return '';
    const hay = normalizeText([
        ev.nome,
        ev.tipo,
        ev.label,
        ev.local,
    ].join(' '));
    if (!hay) return '';
    if (hay.includes('15') && (hay.includes('anos') || hay.includes('ano'))) return '15anos';
    if (hay.includes('casamento') || hay.includes('wedding')) return 'casamento';
    if (hay.includes('infantil') || hay.includes('kids') || hay.includes('diverkids')) return 'infantil';
    return '';
}

function openTipoEventoModal() {
    const modal = document.getElementById('tipoEventoModal');
    if (!modal) return;

    const select = document.getElementById('tipoEventoModalSelect');
    if (select) {
        const sugerido = sugerirTipoEventoReal(selectedEventData);
        select.value = sugerido || '';
        setTimeout(() => select.focus(), 20);
    }

    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeTipoEventoModal() {
    if (organizarEventoInFlight) return;
    const modal = document.getElementById('tipoEventoModal');
    if (!modal) return;
    modal.classList.remove('open');
    document.body.style.overflow = '';
}

function onTipoEventoModalBackdrop(event) {
    const modal = document.getElementById('tipoEventoModal');
    if (!modal) return;
    if (event.target === modal) {
        closeTipoEventoModal();
    }
}

async function confirmarOrganizarEvento() {
    if (organizarEventoInFlight) return;

    const meEventId = Number(pendingOrganizarEventId || selectedEventId || 0);
    if (meEventId <= 0) {
        alert('Selecione um evento primeiro.');
        return;
    }

    const tipoSelect = document.getElementById('tipoEventoModalSelect');
    const tipoEventoReal = tipoSelect ? String(tipoSelect.value || '').trim() : '';
    if (tipoEventoReal === '') {
        alert('Selecione o tipo real do evento para continuar.');
        if (tipoSelect) tipoSelect.focus();
        return;
    }

    const confirmBtn = document.getElementById('btnConfirmOrganizarEvento');
    const previousLabel = confirmBtn ? confirmBtn.textContent : '';
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Organizando...';
    }
    organizarEventoInFlight = true;

    const formData = new FormData();
    formData.append('action', 'organizar_evento');
    formData.append('me_event_id', String(meEventId));
    formData.append('tipo_evento_real', tipoEventoReal);

    try {
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp);
        if (!data.ok) {
            if (data.already_exists && data.reuniao && data.reuniao.id) {
                alert(data.error || 'Este evento já está organizado. Abrindo a organização existente.');
                window.location.href = `index.php?page=eventos_organizacao&id=${data.reuniao.id}`;
                return;
            }
            alert(data.error || 'Não foi possível organizar o evento.');
            return;
        }
        if (!data.reuniao || !data.reuniao.id) {
            alert('Não foi possível organizar o evento.');
            return;
        }
        window.location.href = `index.php?page=eventos_organizacao&id=${data.reuniao.id}`;
    } catch (err) {
        alert('Erro: ' + err.message);
    } finally {
        organizarEventoInFlight = false;
        if (confirmBtn) {
            confirmBtn.disabled = false;
            confirmBtn.textContent = previousLabel || 'Organizar evento';
        }
    }
}

async function organizarEvento() {
    if (!selectedEventId) {
        alert('Selecione um evento primeiro.');
        return;
    }

    const existingMeetingId = getMeetingIdFromEvent(selectedEventData);
    if (existingMeetingId > 0) {
        window.location.href = `index.php?page=eventos_organizacao&id=${existingMeetingId}`;
        return;
    }

    pendingOrganizarEventId = Number(selectedEventId);
    openTipoEventoModal();
}

function copiarPortalLink() {
    const input = document.getElementById('portalLinkInput');
    if (!input || !input.value) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        mostrarStatusConfig('Link copiado.');
    }).catch(() => {
        document.execCommand('copy');
        mostrarStatusConfig('Link copiado.');
    });
}

function mostrarStatusConfig(texto, isError = false) {
    const el = document.getElementById('cfgStatus');
    if (!el) return;
    el.textContent = texto;
    el.classList.toggle('error', !!isError);
    el.style.display = 'block';
}

async function salvarTipoEventoReal(tipoEventoReal) {
    if (!meetingId) return;
    const select = document.getElementById('tipoEventoRealSelect');
    if (!select) return;

    const nextValue = String(tipoEventoReal || '').trim();
    const lastValue = String(select.dataset.lastValue || '').trim();
    if (nextValue === lastValue) return;

    if (tipoEventoSaveInFlight) {
        tipoEventoSaveQueuedValue = nextValue;
        return;
    }

    if (nextValue === '') {
        mostrarStatusConfig('Selecione um tipo de evento válido.', true);
        select.value = lastValue;
        return;
    }

    const formData = new FormData();
    formData.append('action', 'atualizar_tipo_evento_real');
    formData.append('meeting_id', String(meetingId));
    formData.append('tipo_evento_real', nextValue);

    tipoEventoSaveInFlight = true;
    select.disabled = true;
    mostrarStatusConfig('Salvando tipo do evento...');

    try {
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp);
        if (!data.ok) {
            select.value = lastValue;
            mostrarStatusConfig(data.error || 'Erro ao salvar tipo do evento.', true);
            return;
        }
        select.dataset.lastValue = nextValue;
        mostrarStatusConfig('Tipo do evento salvo automaticamente.');
    } catch (err) {
        select.value = lastValue;
        mostrarStatusConfig('Erro: ' + err.message, true);
    } finally {
        tipoEventoSaveInFlight = false;
        select.disabled = false;
        if (tipoEventoSaveQueuedValue !== null) {
            const queuedValue = String(tipoEventoSaveQueuedValue || '').trim();
            tipoEventoSaveQueuedValue = null;
            if (queuedValue !== String(select.dataset.lastValue || '').trim()) {
                select.value = queuedValue;
                salvarTipoEventoReal(queuedValue);
            }
        }
    }
}

async function salvarPacoteEvento(pacoteEventoIdRaw) {
    if (!meetingId) return;
    const select = document.getElementById('pacoteEventoSelect');
    if (!select) return;

    const nextValue = String(pacoteEventoIdRaw || '').trim();
    const lastValue = String(select.dataset.lastValue || '').trim();
    if (nextValue === lastValue) return;

    if (pacoteEventoSaveInFlight) {
        pacoteEventoSaveQueuedValue = nextValue;
        return;
    }

    if (nextValue !== '' && !/^\d+$/.test(nextValue)) {
        select.value = lastValue;
        mostrarStatusConfig('Selecione um pacote válido.', true);
        return;
    }

    const formData = new FormData();
    formData.append('action', 'atualizar_pacote_evento');
    formData.append('meeting_id', String(meetingId));
    formData.append('pacote_evento_id', nextValue);

    pacoteEventoSaveInFlight = true;
    select.disabled = true;
    mostrarStatusConfig('Salvando pacote do evento...');

    try {
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp);
        if (!data.ok) {
            select.value = lastValue;
            mostrarStatusConfig(data.error || 'Erro ao salvar pacote do evento.', true);
            return;
        }
        select.dataset.lastValue = nextValue;
        mostrarStatusConfig('Pacote do evento salvo automaticamente. Atualizando cardápio...');
        window.setTimeout(() => {
            window.location.reload();
        }, 250);
    } catch (err) {
        select.value = lastValue;
        mostrarStatusConfig('Erro: ' + err.message, true);
    } finally {
        pacoteEventoSaveInFlight = false;
        select.disabled = false;
        if (pacoteEventoSaveQueuedValue !== null) {
            const queuedValue = String(pacoteEventoSaveQueuedValue || '').trim();
            pacoteEventoSaveQueuedValue = null;
            if (queuedValue !== String(select.dataset.lastValue || '').trim()) {
                select.value = queuedValue;
                salvarPacoteEvento(queuedValue);
            }
        }
    }
}

async function salvarConfigPortal() {
    if (!meetingId) return;

    if (portalConfigSaveInFlight) {
        portalConfigSaveQueued = true;
        return;
    }

    const visivelReuniao = document.getElementById('cfgVisivelReuniao');
    const visivelConvidados = document.getElementById('cfgVisivelConvidados');
    const editavelConvidados = document.getElementById('cfgEditavelConvidados');
    const visivelArquivos = document.getElementById('cfgVisivelArquivos');
    const editavelArquivos = document.getElementById('cfgEditavelArquivos');
    const visivelCardapio = document.getElementById('cfgVisivelCardapio');
    const editavelCardapio = document.getElementById('cfgEditavelCardapio');

    if (editavelConvidados && editavelConvidados.checked && visivelConvidados) {
        visivelConvidados.checked = true;
    }
    if (editavelArquivos && editavelArquivos.checked && visivelArquivos) {
        visivelArquivos.checked = true;
    }
    if (visivelCardapio) {
        visivelCardapio.checked = false;
    }
    if (editavelCardapio) {
        editavelCardapio.checked = false;
    }

    const formData = new FormData();
    formData.append('action', 'salvar_portal_config');
    formData.append('meeting_id', String(meetingId));
    const appendCheckbox = (key, input) => {
        if (!input) return;
        formData.append(key, input.checked ? '1' : '0');
    };
    appendCheckbox('visivel_reuniao', visivelReuniao);
    appendCheckbox('visivel_convidados', visivelConvidados);
    appendCheckbox('editavel_convidados', editavelConvidados);
    appendCheckbox('visivel_arquivos', visivelArquivos);
    appendCheckbox('editavel_arquivos', editavelArquivos);
    appendCheckbox('visivel_cardapio', visivelCardapio);
    appendCheckbox('editavel_cardapio', editavelCardapio);

    portalConfigSaveInFlight = true;
    mostrarStatusConfig('Salvando configurações...');
    try {
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp);
        if (!data.ok) {
            mostrarStatusConfig(data.error || 'Erro ao salvar configurações.', true);
            return;
        }
        mostrarStatusConfig('Configurações salvas automaticamente.');
    } catch (err) {
        mostrarStatusConfig('Erro: ' + err.message, true);
    } finally {
        portalConfigSaveInFlight = false;
        if (portalConfigSaveQueued) {
            portalConfigSaveQueued = false;
            salvarConfigPortal();
        }
    }
}

function bindPortalConfigAutoSave() {
    const ids = [
        'cfgVisivelReuniao',
        'cfgVisivelConvidados',
        'cfgEditavelConvidados',
        'cfgVisivelArquivos',
        'cfgEditavelArquivos',
        'cfgVisivelCardapio',
        'cfgEditavelCardapio',
    ];
    ids.forEach((id) => {
        const input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('change', () => {
            salvarConfigPortal();
        });
    });
}

function bindTipoEventoRealAutoSave() {
    const select = document.getElementById('tipoEventoRealSelect');
    if (!select) return;
    select.dataset.lastValue = String(select.value || '').trim();
    select.addEventListener('change', () => {
        salvarTipoEventoReal(select.value);
    });
}

function bindPacoteEventoAutoSave() {
    const select = document.getElementById('pacoteEventoSelect');
    if (!select) return;
    select.dataset.lastValue = String(select.value || '').trim();
    select.addEventListener('change', () => {
        salvarPacoteEvento(select.value);
    });
}

function bindTipoEventoModal() {
    document.addEventListener('keydown', (event) => {
        const modal = document.getElementById('tipoEventoModal');
        if (!modal || !modal.classList.contains('open')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeTipoEventoModal();
            return;
        }
        if (event.key === 'Enter') {
            const target = event.target;
            if (target && target.id === 'tipoEventoModalSelect') {
                event.preventDefault();
                confirmarOrganizarEvento();
            }
        }
    });
}

function bindSearchEvents() {
    const searchInput = document.getElementById('eventSearch');
    if (!searchInput) return;

    searchInput.addEventListener('input', function() {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => searchEvents(searchInput.value, false), 260);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchEvents(searchInput.value, true);
        }
    });

    searchEvents('', false);
}

document.addEventListener('DOMContentLoaded', () => {
    bindSearchEvents();
    bindPortalConfigAutoSave();
    bindTipoEventoRealAutoSave();
    bindPacoteEventoAutoSave();
    bindTipoEventoModal();
});
</script>

<?php endSidebar(); ?>
