<?php
/**
 * eventos_cliente_convidados.php
 * Area publica do cliente para lista de convidados.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

function eventos_cliente_convidados_redirect(string $token, bool $ok, string $message = ''): void {
    $params = [
        'page' => 'eventos_cliente_convidados',
        'token' => $token,
        'status' => $ok ? 'ok' : 'error',
    ];
    if (trim($message) !== '') {
        $params['msg'] = $message;
    }
    header('Location: index.php?' . http_build_query($params));
    exit;
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$status_flash = trim((string)($_GET['status'] ?? ''));
$message_flash = trim((string)($_GET['msg'] ?? ''));
$error = '';

$portal = null;
$reuniao = null;
$snapshot = [];
$meeting_id = 0;
$visivel_convidados = false;
$editavel_convidados = false;

if ($token === '') {
    $error = 'Link invalido.';
} else {
    $portal = eventos_cliente_portal_get_by_token($pdo, $token);
    if (!$portal || empty($portal['is_active'])) {
        $error = 'Portal nao encontrado ou desativado.';
    } else {
        $meeting_id = (int)($portal['meeting_id'] ?? 0);
        $reuniao = eventos_reuniao_get($pdo, $meeting_id);
        if (!$reuniao) {
            $error = 'Evento nao encontrado.';
        } else {
            $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
            if (!is_array($snapshot)) {
                $snapshot = [];
            }

            $visivel_convidados = !empty($portal['visivel_convidados']);
            $editavel_convidados = !empty($portal['editavel_convidados']);
            if (!$visivel_convidados) {
                $error = 'A lista de convidados ainda nao esta habilitada para este evento.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $action = trim((string)($_POST['action'] ?? ''));
    if (!$editavel_convidados) {
        eventos_cliente_convidados_redirect($token, false, 'Este card esta em modo somente visualizacao.');
    }

    switch ($action) {
        case 'salvar_tipo_evento':
            eventos_cliente_convidados_redirect($token, false, 'O tipo do evento e definido pela equipe de organizacao.');
            break;

        case 'adicionar_convidado':
            $result = eventos_convidados_adicionar(
                $pdo,
                $meeting_id,
                (string)($_POST['nome'] ?? ''),
                (string)($_POST['faixa_etaria'] ?? ''),
                (string)($_POST['numero_mesa'] ?? ''),
                'cliente',
                0
            );
            if (empty($result['ok'])) {
                eventos_cliente_convidados_redirect($token, false, (string)($result['error'] ?? 'Nao foi possivel adicionar o convidado.'));
            }
            eventos_cliente_convidados_redirect($token, true, 'Convidado adicionado.');
            break;

        case 'atualizar_convidado':
            $guest_id = (int)($_POST['guest_id'] ?? 0);
            $result = eventos_convidados_atualizar(
                $pdo,
                $meeting_id,
                $guest_id,
                (string)($_POST['nome'] ?? ''),
                (string)($_POST['faixa_etaria'] ?? ''),
                (string)($_POST['numero_mesa'] ?? ''),
                0
            );
            if (empty($result['ok'])) {
                eventos_cliente_convidados_redirect($token, false, (string)($result['error'] ?? 'Nao foi possivel atualizar o convidado.'));
            }
            eventos_cliente_convidados_redirect($token, true, 'Convidado atualizado.');
            break;

        case 'excluir_convidado':
            $guest_id = (int)($_POST['guest_id'] ?? 0);
            $result = eventos_convidados_excluir($pdo, $meeting_id, $guest_id, 0);
            if (empty($result['ok'])) {
                eventos_cliente_convidados_redirect($token, false, (string)($result['error'] ?? 'Nao foi possivel excluir o convidado.'));
            }
            eventos_cliente_convidados_redirect($token, true, 'Convidado excluido.');
            break;

        case 'importar_texto_cru':
            $texto_cru = trim((string)($_POST['texto_cru'] ?? ''));
            $result = eventos_convidados_importar_texto_cru($pdo, $meeting_id, $texto_cru, 'cliente', 0);
            if (empty($result['ok'])) {
                eventos_cliente_convidados_redirect($token, false, (string)($result['error'] ?? 'Nao foi possivel importar os convidados.'));
            }
            $msg = 'Importacao concluida: '
                . (int)($result['inserted'] ?? 0) . ' adicionados'
                . ' e ' . (int)($result['skipped'] ?? 0) . ' ignorados.';
            eventos_cliente_convidados_redirect($token, true, $msg);
            break;

        default:
            eventos_cliente_convidados_redirect($token, false, 'Acao invalida.');
            break;
    }
}

$config_convidados = ($meeting_id > 0 && $error === '') ? eventos_convidados_get_config($pdo, $meeting_id) : [
    'tipo_evento' => 'infantil',
    'usa_mesa' => false,
    'opcoes_faixa' => eventos_convidados_opcoes_faixa_etaria('infantil'),
];
$tipo_evento = (string)($config_convidados['tipo_evento'] ?? 'infantil');
$usa_mesa = !empty($config_convidados['usa_mesa']);
$opcoes_faixa = is_array($config_convidados['opcoes_faixa'] ?? null) ? $config_convidados['opcoes_faixa'] : [];
$convidados = ($meeting_id > 0 && $error === '') ? eventos_convidados_listar($pdo, $meeting_id) : [];
$resumo = ($meeting_id > 0 && $error === '') ? eventos_convidados_resumo($pdo, $meeting_id) : ['total' => 0, 'checkin' => 0, 'pendentes' => 0];
$tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($reuniao['tipo_evento_real'] ?? ($snapshot['tipo_evento_real'] ?? '')));
$tipo_evento_label = $tipo_evento_real !== ''
    ? eventos_reuniao_tipo_evento_real_label($tipo_evento_real)
    : ($tipo_evento === 'mesa' ? '15 anos / Casamento' : 'Infantil');

$evento_nome = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
$horario_evento = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_evento .= ' - ' . $hora_fim;
}
$local_evento = trim((string)($snapshot['local'] ?? 'Local nao informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Convidados - Portal do Cliente</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.5;
        }

        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: #fff;
            padding: 2rem 1rem;
            text-align: center;
        }

        .header img {
            max-width: 170px;
            margin-bottom: 0.8rem;
        }

        .header h1 {
            font-size: 1.55rem;
            margin-bottom: 0.3rem;
        }

        .container {
            max-width: 1080px;
            margin: 0 auto;
            padding: 1.2rem;
        }

        .event-box {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .event-box h2 {
            color: #1e3a8a;
            margin-bottom: 0.6rem;
        }

        .event-meta {
            display: grid;
            gap: 0.55rem;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            font-size: 0.92rem;
            color: #334155;
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

        .alert-success {
            background: #dcfce7;
            border-color: #86efac;
            color: #166534;
        }

        .alert-info {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1e3a8a;
        }

        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .card h3 {
            color: #0f172a;
            font-size: 1.06rem;
            margin-bottom: 0.45rem;
        }

        .card-subtitle {
            color: #64748b;
            font-size: 0.86rem;
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

        .btn-primary {
            background: #1e3a8a;
            color: #fff;
        }

        .btn-primary:hover {
            background: #254ac9;
        }

        .btn-secondary {
            background: #f1f5f9;
            border-color: #dbe3ef;
            color: #334155;
        }

        .btn-danger {
            background: #fee2e2;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .actions-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-top: 0.75rem;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.7rem;
            margin-top: 0.85rem;
        }

        .stat-item {
            border: 1px solid #dbe3ef;
            border-radius: 9px;
            background: #f8fafc;
            padding: 0.7rem;
        }

        .stat-label {
            font-size: 0.78rem;
            color: #64748b;
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
        }

        .tipo-wrap {
            display: flex;
            gap: 0.9rem;
            flex-wrap: wrap;
            margin-top: 0.65rem;
        }

        .tipo-item {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.9rem;
            color: #334155;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            padding: 0.45rem 0.65rem;
            background: #f8fafc;
        }

        .guest-table {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .guest-head,
        .guest-row {
            display: grid;
            align-items: center;
            gap: 0.55rem;
            padding: 0.55rem 0.7rem;
        }

        .guest-table.with-mesa .guest-head,
        .guest-table.with-mesa .guest-row {
            grid-template-columns: minmax(180px, 2fr) minmax(150px, 1.25fr) minmax(120px, 0.8fr) minmax(170px, 1fr);
        }

        .guest-table.without-mesa .guest-head,
        .guest-table.without-mesa .guest-row {
            grid-template-columns: minmax(190px, 2.1fr) minmax(170px, 1.3fr) minmax(170px, 1fr);
        }

        .guest-head {
            background: #f1f5f9;
            font-size: 0.8rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .guest-row {
            border-top: 1px solid #eef2f7;
            background: #fff;
        }

        .guest-row input[type="text"],
        .guest-row select,
        .add-form-grid input[type="text"],
        .add-form-grid select,
        textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 0.5rem 0.62rem;
            font-size: 0.86rem;
            color: #1f2937;
        }

        .row-actions {
            display: flex;
            gap: 0.45rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .toggle-panel {
            display: none;
            margin-top: 0.8rem;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            padding: 0.75rem;
        }

        .toggle-panel.open {
            display: block;
        }

        .add-form-grid {
            display: grid;
            gap: 0.55rem;
        }

        .add-form-grid.with-mesa {
            grid-template-columns: minmax(180px, 2fr) minmax(150px, 1.2fr) minmax(110px, 0.8fr) minmax(150px, 0.9fr);
            align-items: end;
        }

        .add-form-grid.without-mesa {
            grid-template-columns: minmax(200px, 2fr) minmax(170px, 1.2fr) minmax(150px, 0.9fr);
            align-items: end;
        }

        .empty-text {
            color: #64748b;
            font-size: 0.9rem;
            font-style: italic;
            padding: 0.85rem 0.2rem 0.2rem;
        }

        @media (max-width: 840px) {
            .guest-table.with-mesa .guest-head,
            .guest-table.with-mesa .guest-row,
            .guest-table.without-mesa .guest-head,
            .guest-table.without-mesa .guest-row,
            .add-form-grid.with-mesa,
            .add-form-grid.without-mesa {
                grid-template-columns: 1fr;
            }

            .row-actions {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
        <h1>📋 Lista de Convidados</h1>
        <p>Preencha e mantenha sua lista atualizada</p>
    </div>

    <div class="container">
        <?php if ($error !== ''): ?>
        <div class="alert alert-error"><strong>Erro:</strong> <?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <?php if ($status_flash === 'ok' && $message_flash !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message_flash) ?></div>
            <?php elseif ($status_flash === 'error' && $message_flash !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($message_flash) ?></div>
            <?php endif; ?>

            <div class="event-box">
                <h2><?= htmlspecialchars($evento_nome) ?></h2>
                <div class="event-meta">
                    <div><strong>📅 Data:</strong> <?= htmlspecialchars($data_evento_fmt) ?></div>
                    <div><strong>⏰ Horario:</strong> <?= htmlspecialchars($horario_evento) ?></div>
                    <div><strong>📍 Local:</strong> <?= htmlspecialchars($local_evento) ?></div>
                    <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($cliente_nome) ?></div>
                </div>
                <div class="actions-row">
                    <a class="btn btn-secondary" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>">← Voltar ao portal</a>
                </div>
            </div>

            <div class="alert alert-info">
                <strong>Informacao importante:</strong> Para o mapeamento de mesa funcionar o cliente devera trazer a plaquinha com numero de mesa.
                Nao levamos seus convidados ate a mesa, o numero da mesa e informado no momento da entrada.
            </div>

            <section class="card">
                <h3>Tipo do evento</h3>
                <div class="card-subtitle">Definido pela equipe na organizacao do evento. Esse tipo controla faixa etaria e uso de mesa.</div>
                <div class="tipo-wrap">
                    <div class="tipo-item"><strong>Tipo selecionado:</strong> <?= htmlspecialchars($tipo_evento_label) ?></div>
                </div>
            </section>

            <section class="card">
                <h3>Resumo da lista</h3>
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-label">Total de convidados</div>
                        <div class="stat-value"><?= (int)$resumo['total'] ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Check-ins realizados</div>
                        <div class="stat-value"><?= (int)$resumo['checkin'] ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Pendentes</div>
                        <div class="stat-value"><?= (int)$resumo['pendentes'] ?></div>
                    </div>
                </div>
            </section>

            <?php if ($editavel_convidados): ?>
            <section class="card">
                <h3>Adicionar convidado</h3>
                <div class="card-subtitle">Clique para abrir uma linha no estilo tabela e preencher os dados.</div>
                <button type="button" class="btn btn-primary" onclick="togglePanel('addGuestPanel')">+ Adicionar convidado</button>

                <div id="addGuestPanel" class="toggle-panel">
                    <form method="post" class="add-form-grid <?= $usa_mesa ? 'with-mesa' : 'without-mesa' ?>">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="action" value="adicionar_convidado">

                        <div>
                            <label>Nome</label>
                            <input type="text" name="nome" maxlength="180" required>
                        </div>

                        <div>
                            <label>Faixa etaria</label>
                            <select name="faixa_etaria">
                                <option value="">Selecione</option>
                                <?php foreach ($opcoes_faixa as $opt): ?>
                                <option value="<?= htmlspecialchars((string)$opt) ?>"><?= htmlspecialchars((string)$opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($usa_mesa): ?>
                        <div>
                            <label>Numero da mesa</label>
                            <input type="text" name="numero_mesa" maxlength="20" placeholder="Ex.: 12">
                        </div>
                        <?php endif; ?>

                        <div>
                            <button type="submit" class="btn btn-primary">Salvar convidado</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card">
                <h3>Importar texto cru</h3>
                <div class="card-subtitle">Se o cliente enviou no WhatsApp, cole aqui. O sistema preenche os nomes sem faixa e sem mesa.</div>
                <button type="button" class="btn btn-secondary" onclick="togglePanel('importPanel')">Colar lista do WhatsApp</button>

                <div id="importPanel" class="toggle-panel">
                    <form method="post">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="action" value="importar_texto_cru">
                        <textarea name="texto_cru" rows="7" placeholder="Cole o texto da lista aqui..." required></textarea>
                        <div class="actions-row">
                            <button type="submit" class="btn btn-primary">Importar nomes</button>
                        </div>
                    </form>
                </div>
            </section>
            <?php endif; ?>

            <section class="card">
                <h3>Lista atual de convidados</h3>
                <div class="card-subtitle">Ordenada por mesa e em ordem alfabetica.</div>

                <?php if (empty($convidados)): ?>
                <div class="empty-text">Nenhum convidado cadastrado ainda.</div>
                <?php else: ?>
                <div class="guest-table <?= $usa_mesa ? 'with-mesa' : 'without-mesa' ?>">
                    <div class="guest-head">
                        <div>Nome</div>
                        <div>Faixa etaria</div>
                        <?php if ($usa_mesa): ?><div>Mesa</div><?php endif; ?>
                        <div><?= $editavel_convidados ? 'Acoes' : 'Status' ?></div>
                    </div>

                    <?php foreach ($convidados as $convidado): ?>
                        <?php
                            $current_faixa = trim((string)($convidado['faixa_etaria'] ?? ''));
                            $faixas_row = $opcoes_faixa;
                            if ($current_faixa !== '' && !in_array($current_faixa, $faixas_row, true)) {
                                array_unshift($faixas_row, $current_faixa);
                            }
                        ?>
                        <?php if ($editavel_convidados): ?>
                        <form method="post" class="guest-row">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            <input type="hidden" name="action" value="atualizar_convidado">
                            <input type="hidden" name="guest_id" value="<?= (int)$convidado['id'] ?>">

                            <input type="text" name="nome" value="<?= htmlspecialchars((string)$convidado['nome']) ?>" maxlength="180" required>

                            <select name="faixa_etaria">
                                <option value="">Selecione</option>
                                <?php foreach ($faixas_row as $opt): ?>
                                <option value="<?= htmlspecialchars((string)$opt) ?>" <?= $current_faixa === (string)$opt ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$opt) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if ($usa_mesa): ?>
                            <input type="text" name="numero_mesa" value="<?= htmlspecialchars((string)($convidado['numero_mesa'] ?? '')) ?>" maxlength="20">
                            <?php endif; ?>

                            <div class="row-actions">
                                <button type="submit" class="btn btn-secondary">Salvar</button>
                                <button type="submit" class="btn btn-danger" onclick="return prepararExclusao(this)">Excluir</button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="guest-row">
                            <div><?= htmlspecialchars((string)$convidado['nome']) ?></div>
                            <div><?= htmlspecialchars($current_faixa !== '' ? $current_faixa : '-') ?></div>
                            <?php if ($usa_mesa): ?>
                            <div><?= htmlspecialchars((string)($convidado['numero_mesa'] ?? '-')) ?></div>
                            <?php endif; ?>
                            <div><?= !empty($convidado['is_checked_in']) ? 'Presente' : 'Pendente' ?></div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <script>
        function togglePanel(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.toggle('open');
        }

        function prepararExclusao(button) {
            if (!confirm('Deseja realmente excluir este convidado?')) {
                return false;
            }
            const form = button.closest('form');
            if (!form) return false;
            const actionInput = form.querySelector('input[name="action"]');
            if (actionInput) {
                actionInput.value = 'excluir_convidado';
            }
            return true;
        }

    </script>
</body>
</html>
