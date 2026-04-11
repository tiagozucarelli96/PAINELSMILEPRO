<?php
/**
 * eventos_cliente_formulario_portal.php
 * Página pública dedicada aos formulários extras do evento.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

$token = trim((string)($_GET['token'] ?? ''));
$error = '';

$portal = null;
$reuniao = null;
$snapshot = [];
$links_formulario_portal = [];

function eventos_cliente_formulario_portal_tem_campos($schema): bool
{
    if (!is_array($schema)) {
        return false;
    }
    foreach ($schema as $field) {
        if (!is_array($field)) {
            continue;
        }
        $field_id = trim((string)($field['id'] ?? ''));
        if (strpos($field_id, 'legacy_portal_text_') === 0) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        if (in_array($type, ['text', 'textarea', 'yesno', 'select', 'file'], true)) {
            return true;
        }
    }
    return false;
}

if ($token === '') {
    $error = 'Link inválido.';
} else {
    $portal = eventos_cliente_portal_get_by_token($pdo, $token);
    if (!$portal || empty($portal['is_active'])) {
        $error = 'Portal não encontrado ou desativado.';
    } else {
        $reuniao = eventos_reuniao_get($pdo, (int)($portal['meeting_id'] ?? 0));
        if (!$reuniao) {
            $error = 'Reunião não encontrada.';
        } else {
            $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
            if (!is_array($snapshot)) {
                $snapshot = [];
            }

            $links_formulario = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_formulario');
            foreach ($links_formulario as $form_link_item) {
                if (empty($form_link_item['is_active']) || empty($form_link_item['portal_visible'])) {
                    continue;
                }
                if (!eventos_cliente_formulario_portal_tem_campos($form_link_item['form_schema'] ?? null)) {
                    continue;
                }
                $links_formulario_portal[] = $form_link_item;
            }

            if (empty($links_formulario_portal)) {
                $error = 'Nenhum formulário está liberado para este evento no momento.';
            }
        }
    }
}

$evento_nome = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
$horario_evento = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_evento .= ' - ' . $hora_fim;
}
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));
$formularios_editaveis_abertos = 0;
foreach ($links_formulario_portal as $form_link_item) {
    if (!empty($form_link_item['portal_editable']) && empty($form_link_item['submitted_at'])) {
        $formularios_editaveis_abertos++;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulários - Portal do Cliente</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            color: #fff;
            padding: 2rem 1rem;
            text-align: center;
        }

        .header img { max-width: 170px; margin-bottom: 0.8rem; }
        .header h1 { font-size: 1.55rem; margin-bottom: 0.3rem; }

        .container {
            max-width: 980px;
            margin: 0 auto;
            padding: 1.2rem;
        }

        .alert {
            border-radius: 10px;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }

        .event-box,
        .card,
        .form-item {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .event-box h2 {
            color: #0f172a;
            margin-bottom: 0.6rem;
        }

        .event-meta {
            display: grid;
            gap: 0.55rem;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            font-size: 0.92rem;
            color: #334155;
        }

        .actions-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-top: 0.75rem;
        }

        .card h3 {
            color: #0f172a;
            font-size: 1.06rem;
            margin-bottom: 0.45rem;
        }

        .card-subtitle {
            color: #64748b;
            font-size: 0.88rem;
            margin-bottom: 0.8rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.86rem;
            font-weight: 700;
            padding: 0.56rem 0.9rem;
            gap: 0.45rem;
        }

        .btn-primary { background: #6d28d9; color: #fff; }
        .btn-primary:hover { background: #5b21b6; }
        .btn-secondary { background: #f1f5f9; border-color: #dbe3ef; color: #334155; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.2rem 0.55rem;
            font-size: 0.72rem;
            font-weight: 700;
            border: 1px solid transparent;
            margin-left: 0.4rem;
        }

        .status-editavel {
            background: #ede9fe;
            border-color: #c4b5fd;
            color: #5b21b6;
        }

        .status-visualizacao {
            background: #e2e8f0;
            border-color: #cbd5e1;
            color: #334155;
        }

        .form-grid {
            display: grid;
            gap: 0.7rem;
        }

        .form-item {
            margin-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .form-item-title {
            font-weight: 700;
            color: #0f172a;
        }

        .form-item-subtitle {
            font-size: 0.82rem;
            color: #64748b;
        }

        @media (max-width: 780px) {
            .container {
                padding: 1rem 0.8rem 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
        <h1>📋 Formulários</h1>
        <p>Área de formulários complementares do evento</p>
    </div>

    <div class="container">
        <?php if ($error !== ''): ?>
        <div class="alert alert-error"><strong>Erro:</strong> <?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <div class="event-box">
                <h2><?= htmlspecialchars($evento_nome) ?></h2>
                <div class="event-meta">
                    <div><strong>📅 Data:</strong> <?= htmlspecialchars($data_evento_fmt) ?></div>
                    <div><strong>⏰ Horário:</strong> <?= htmlspecialchars($horario_evento) ?></div>
                    <div><strong>📍 Local:</strong> <?= htmlspecialchars($local_evento) ?></div>
                    <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($cliente_nome) ?></div>
                </div>
                <div class="actions-row">
                    <a class="btn btn-secondary" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>">← Voltar ao portal</a>
                </div>
            </div>

            <section class="card">
                <h3>
                    Formulários disponíveis
                    <span class="status-badge <?= $formularios_editaveis_abertos > 0 ? 'status-editavel' : 'status-visualizacao' ?>">
                        <?= $formularios_editaveis_abertos > 0 ? 'Preenchimento liberado' : 'Somente visualização' ?>
                    </span>
                </h3>
                <div class="card-subtitle">Selecione abaixo o formulário que deseja preencher ou consultar.</div>

                <div class="form-grid">
                    <?php foreach ($links_formulario_portal as $form_link_item): ?>
                    <?php
                        $slot = max(1, (int)($form_link_item['slot_index'] ?? 1));
                        $title = trim((string)($form_link_item['form_title'] ?? ''));
                        if ($title === '') {
                            $title = 'Formulário do Evento';
                        }
                        $item_is_editable = !empty($form_link_item['portal_editable']) && empty($form_link_item['submitted_at']);
                    ?>
                    <div class="form-item">
                        <div>
                            <div class="form-item-title"><?= htmlspecialchars($title) ?></div>
                            <div class="form-item-subtitle">
                                Quadro <?= $slot ?>
                                <?php if (!empty($form_link_item['submitted_at'])): ?>
                                    • enviado
                                <?php elseif ($item_is_editable): ?>
                                    • aguardando preenchimento
                                <?php else: ?>
                                    • somente visualização
                                <?php endif; ?>
                            </div>
                        </div>
                        <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$form_link_item['token']) ?>">
                            <?= $item_is_editable ? 'Abrir formulário' : 'Visualizar formulário' ?>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>
