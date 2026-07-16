<?php
// agenda_config.php — Configurações da agenda
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/agenda_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

if (!function_exists('agenda_config_session_bool')) {
    function agenda_config_session_bool($value): bool {
        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'on', 'yes', 'y'], true);
    }
}

if (!function_exists('agenda_config_normalize_color')) {
    function agenda_config_normalize_color($value): ?string {
        $color = strtolower(trim((string)$value));
        return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null;
    }
}

if (empty($_SESSION['logado'])) {
    header('Location: index.php?page=login');
    exit;
}

$is_superadmin = agenda_config_session_bool($_SESSION['perm_superadmin'] ?? false);
$can_manage_agenda_settings = $is_superadmin;

$agenda = new AgendaHelper();
$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
$espacos = $agenda->obterEspacos();
$usuarios = $agenda->obterUsuariosComCores();

// Definir a página atual para o sidebar_unified.php
$_GET['page'] = 'agenda_config';

// Não precisa verificar perm_agenda_ver pois é apenas configurações de usuário
// if (!$agenda->canAccessAgenda($usuario_id)) {
//     header('Location: index.php?page=dashboard');
//     exit;
// }

// Processar atualizações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $userColors = $_POST['user_colors'] ?? [];
        if (is_array($userColors)) {
            $stmtColor = $GLOBALS['pdo']->prepare("UPDATE usuarios SET cor_agenda = ? WHERE id = ? AND ativo = TRUE");
            foreach ($userColors as $targetUserId => $colorValue) {
                $targetUserId = (int)$targetUserId;
                $color = agenda_config_normalize_color($colorValue);
                if ($targetUserId <= 0 || $color === null) {
                    continue;
                }

                $stmtColor->execute([$color, $targetUserId]);
            }
        } elseif (isset($_POST['cor_agenda'])) {
            $color = agenda_config_normalize_color($_POST['cor_agenda']);
            if ($color !== null) {
                $stmtColor = $GLOBALS['pdo']->prepare("UPDATE usuarios SET cor_agenda = ? WHERE id = ?");
                $stmtColor->execute([$color, $usuario_id]);
            }
        }

        if ($can_manage_agenda_settings) {
            $lembrete_padrao = $_POST['agenda_lembrete_padrao_min'] ?? 60;
            $stmt = $GLOBALS['pdo']->prepare("
                UPDATE usuarios SET agenda_lembrete_padrao_min = ?
                WHERE id = ?
            ");
            $stmt->execute([$lembrete_padrao, $usuario_id]);

            $visit_responsible_logins = [];
            foreach (($_POST['visit_responsible_logins'] ?? []) as $login) {
                $login = strtolower(trim((string)$login));
                if ($login !== '') {
                    $visit_responsible_logins[] = $login;
                }
            }
            $visit_responsible_logins = array_values(array_unique($visit_responsible_logins));

            $visit_type_durations = [];
            foreach (($_POST['visit_type_durations'] ?? []) as $type => $duration) {
                $type = trim((string)$type);
                if ($type !== '') {
                    $visit_type_durations[$type] = max(1, (int)$duration);
                }
            }

            $space_transit_groups = [];
            foreach (($_POST['space_transit_groups'] ?? []) as $slug => $group) {
                $slug = strtolower(trim((string)$slug));
                $group = strtolower(trim((string)$group));
                if ($slug !== '' && $group !== '') {
                    $space_transit_groups[$slug] = $group;
                }
            }

            $agenda->saveAgendaGlobalSettings([
                'visit_responsible_logins' => $visit_responsible_logins,
                'visit_type_durations' => $visit_type_durations,
                'transit_min_minutes' => max(0, (int)($_POST['transit_min_minutes'] ?? 30)),
                'space_transit_groups' => $space_transit_groups,
            ]);
        }

        $usuarios = $agenda->obterUsuariosComCores();
        $success = $can_manage_agenda_settings
            ? "Configurações atualizadas com sucesso!"
            : "Cores da agenda atualizadas com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao atualizar configurações: " . $e->getMessage();
    }
}

$agenda_settings = $agenda->getAgendaGlobalSettings();

// Obter configurações atuais
$stmt = $GLOBALS['pdo']->prepare("
    SELECT cor_agenda, agenda_lembrete_padrao_min 
    FROM usuarios 
    WHERE id = ?
");
$stmt->execute([$usuario_id]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$base_url = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));

if ($base_url === '' && $host !== '') {
    $forwarded_proto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $forwarded_proto = strtolower(trim(explode(',', $forwarded_proto)[0] ?? ''));
    $is_https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $forwarded_proto !== '' ? $forwarded_proto : ($is_https ? 'https' : 'http');
    $base_url = $scheme . '://' . $host;
}

$base_url = rtrim($base_url, '/');
$ics_sync_url = ($base_url !== '' ? $base_url : '') . '/agenda_ics.php?u=' . (int)$usuario_id;
?>
<style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
            color: #333;
            margin: 0;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s ease;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        h1 {
            color: #1e3a8a;
            font-size: 2.2rem;
            margin-bottom: 25px;
            border-bottom: 2px solid #e0e7ff;
            padding-bottom: 15px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
            box-sizing: border-box;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #1e3a8a;
            outline: none;
        }

        .color-preview {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 2px solid #ddd;
            display: inline-block;
            margin-left: 10px;
            vertical-align: middle;
        }

        .user-color-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .user-color-card {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 12px;
            background: #fff;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            padding: 12px;
        }

        .user-color-card input[type="color"] {
            width: 48px;
            height: 38px;
            border: 0;
            padding: 0;
            background: transparent;
        }

        .user-color-name {
            font-weight: 700;
            color: #1f2937;
        }

        .user-color-login {
            color: #64748b;
            font-size: .85rem;
            margin-top: 2px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            font-size: 1rem;
        }

        .btn-primary {
            background-color: #1e3a8a;
            color: #fff;
            border: 1px solid #1e3a8a;
        }

        .btn-primary:hover {
            background-color: #1c327a;
            border-color: #1c327a;
            transform: translateY(-1px);
        }

        .btn-outline {
            background-color: transparent;
            color: #1e3a8a;
            border: 1px solid #1e3a8a;
        }

        .btn-outline:hover {
            background-color: #e0e7ff;
            transform: translateY(-1px);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-error {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .section {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .section h3 {
            margin-top: 0;
            color: #1e3a8a;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .check-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }

        .check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            padding: 10px 12px;
            font-weight: 600;
        }

        .check-item input {
            width: auto;
        }

        .config-note {
            color: #64748b;
            display: block;
            font-size: .9rem;
            margin-top: 6px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            border-top: 1px solid #e0e7ff;
            padding-top: 20px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .container {
                padding: 15px;
            }
            h1 {
                font-size: 1.8rem;
            }
        }
    </style>
<?php includeSidebar('Configurações da Agenda'); ?>

<div style="padding: 20px;">
    <h1>⚙️ Configurações da Agenda</h1>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    ✅ <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    ❌ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="section">
                    <h3>🎨 Aparência</h3>
                    <div class="form-group">
                        <label>Cores dos usuários na agenda</label>
                        <div class="user-color-grid">
                            <?php foreach ($usuarios as $user): ?>
                                <?php
                                    $targetUserId = (int)($user['id'] ?? 0);
                                    if ($targetUserId <= 0) { continue; }
                                    $targetColor = agenda_config_normalize_color($user['cor_agenda'] ?? '') ?: AgendaHelper::corUsuarioAgenda($targetUserId, '');
                                    $targetName = trim((string)($user['nome'] ?? '')) ?: trim((string)($user['login'] ?? 'Usuário'));
                                    $targetLogin = trim((string)($user['login'] ?? ''));
                                ?>
                                <div class="user-color-card">
                                    <input
                                        type="color"
                                        id="user_color_<?= $targetUserId ?>"
                                        name="user_colors[<?= $targetUserId ?>]"
                                        value="<?= htmlspecialchars($targetColor) ?>"
                                        data-preview="user_color_preview_<?= $targetUserId ?>"
                                    >
                                    <label for="user_color_<?= $targetUserId ?>">
                                        <div class="user-color-name"><?= htmlspecialchars($targetName) ?></div>
                                        <?php if ($targetLogin !== ''): ?>
                                            <div class="user-color-login"><?= htmlspecialchars($targetLogin) ?></div>
                                        <?php endif; ?>
                                    </label>
                                    <div
                                        class="color-preview"
                                        id="user_color_preview_<?= $targetUserId ?>"
                                        style="background-color: <?= htmlspecialchars($targetColor) ?>"
                                    ></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <small style="color: #666; margin-top: 5px; display: block;">
                            Qualquer usuário logado pode ajustar a cor que identifica cada responsável no calendário.
                        </small>
                    </div>
                </div>

                <?php if ($can_manage_agenda_settings): ?>
                <div class="section">
                    <h3>🔔 Notificações</h3>
                    <div class="form-group">
                        <label for="agenda_lembrete_padrao_min">Lembrete padrão (minutos antes)</label>
                        <select id="agenda_lembrete_padrao_min" name="agenda_lembrete_padrao_min">
                            <option value="0">Sem lembrete</option>
                            <option value="15" <?= $config['agenda_lembrete_padrao_min'] == 15 ? 'selected' : '' ?>>15 minutos</option>
                            <option value="30" <?= $config['agenda_lembrete_padrao_min'] == 30 ? 'selected' : '' ?>>30 minutos</option>
                            <option value="60" <?= $config['agenda_lembrete_padrao_min'] == 60 ? 'selected' : '' ?>>1 hora</option>
                            <option value="120" <?= $config['agenda_lembrete_padrao_min'] == 120 ? 'selected' : '' ?>>2 horas</option>
                            <option value="240" <?= $config['agenda_lembrete_padrao_min'] == 240 ? 'selected' : '' ?>>4 horas</option>
                            <option value="1440" <?= $config['agenda_lembrete_padrao_min'] == 1440 ? 'selected' : '' ?>>1 dia</option>
                        </select>
                        <small style="color: #666; margin-top: 5px; display: block;">
                            Tempo de antecedência para receber lembretes por e-mail
                        </small>
                    </div>
                </div>
                
                <div class="section">
                    <h3>📱 Sincronização</h3>
                    <div class="form-group">
                        <label>Link para sincronizar com seu calendário</label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="icsLink" readonly 
                                   value="<?= h($ics_sync_url) ?>" 
                                   style="flex: 1; background: #f3f4f6;">
                            <button type="button" class="btn btn-outline" onclick="copyICSLink()">
                                📋 Copiar
                            </button>
                        </div>
                        <small style="color: #666; margin-top: 5px; display: block;">
                            Use este link para sincronizar sua agenda com Google Calendar, Outlook ou iPhone
                        </small>
                    </div>
                </div>

                <div class="section">
                    <h3>🗓️ Nova Visita</h3>
                    <div class="form-group">
                        <label>Responsáveis disponíveis em Nova Visita</label>
                        <div class="check-list">
                            <?php foreach ($usuarios as $user): ?>
                                <?php
                                    $login = trim((string)($user['login'] ?? ''));
                                    if ($login === '') { continue; }
                                    $login_key = strtolower($login);
                                    $checked = in_array($login_key, $agenda_settings['visit_responsible_logins'] ?? [], true);
                                ?>
                                <label class="check-item">
                                    <input
                                        type="checkbox"
                                        name="visit_responsible_logins[]"
                                        value="<?= htmlspecialchars($login_key) ?>"
                                        <?= $checked ? 'checked' : '' ?>
                                    >
                                    <?= htmlspecialchars($login) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <span class="config-note">Controla quem aparece no campo Responsável ao criar uma nova visita.</span>
                    </div>

                    <div class="settings-grid">
                        <?php foreach (($agenda_settings['visit_type_durations'] ?? []) as $visit_type => $duration): ?>
                            <div class="form-group">
                                <label><?= htmlspecialchars((string)$visit_type) ?></label>
                                <select name="visit_type_durations[<?= htmlspecialchars((string)$visit_type) ?>]">
                                    <?php foreach ([15, 30, 45, 60, 90, 120, 150, 180] as $duration_option): ?>
                                        <option value="<?= $duration_option ?>" <?= (int)$duration === $duration_option ? 'selected' : '' ?>>
                                            <?= $duration_option ?> minutos
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="config-note">Ao alterar o tipo de visita, a duração é atualizada automaticamente na Agenda.</span>
                </div>

                <div class="section">
                    <h3>⚠️ Conflitos e Deslocamento</h3>
                    <div class="form-group">
                        <label for="transit_min_minutes">Tempo mínimo entre unidades diferentes</label>
                        <select id="transit_min_minutes" name="transit_min_minutes">
                            <?php foreach ([0, 15, 30, 45, 60, 90] as $minutes): ?>
                                <option value="<?= $minutes ?>" <?= (int)($agenda_settings['transit_min_minutes'] ?? 30) === $minutes ? 'selected' : '' ?>>
                                    <?= $minutes === 0 ? 'Sem exigência' : $minutes . ' minutos' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="config-note">Eventos internos do Google Calendar não entram nessa conta.</span>
                    </div>

                    <div class="settings-grid">
                        <?php
                            $group_options = [];
                            foreach ($espacos as $group_space) {
                                $group_slug = strtolower((string)($group_space['slug'] ?? ''));
                                if ($group_slug === '') {
                                    continue;
                                }
                                $group_value = in_array($group_slug, ['garden', 'cristal'], true)
                                    ? 'garden_cristal'
                                    : $group_slug;
                                $group_options[$group_value][] = (string)($group_space['nome'] ?? $group_slug);
                            }
                        ?>
                        <?php foreach ($espacos as $espaco): ?>
                            <?php
                                $slug = strtolower((string)($espaco['slug'] ?? ''));
                                $current_group = (string)(($agenda_settings['space_transit_groups'] ?? [])[$slug] ?? $slug);
                            ?>
                            <div class="form-group">
                                <label><?= htmlspecialchars((string)$espaco['nome']) ?></label>
                                <select name="space_transit_groups[<?= htmlspecialchars($slug) ?>]">
                                    <?php foreach ($group_options as $group_value => $group_names): ?>
                                        <option value="<?= htmlspecialchars((string)$group_value) ?>" <?= $current_group === (string)$group_value ? 'selected' : '' ?>>
                                            Mesmo grupo de <?= htmlspecialchars(implode(' / ', $group_names)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="config-note">Unidades no mesmo grupo não exigem tempo de trânsito. Por padrão, Garden e Cristal ficam juntos.</span>
                </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <a href="index.php?page=agenda" class="btn btn-outline">
                        ← Voltar para Agenda
                    </a>
                    <button type="submit" class="btn btn-primary">
                        💾 <?= $can_manage_agenda_settings ? 'Salvar Configurações' : 'Salvar Cores' ?>
                    </button>
                </div>
            </form>
</div>

<script>
        document.querySelectorAll('input[type="color"][data-preview]').forEach((input) => {
            input.addEventListener('input', function() {
                const preview = document.getElementById(this.dataset.preview);
                if (preview) {
                    preview.style.backgroundColor = this.value;
                }
            });
        });
        
        // Copiar link ICS
        function copyICSLink() {
            const link = document.getElementById('icsLink');
            link.select();
            link.setSelectionRange(0, 99999);
            document.execCommand('copy');
            
            // Feedback visual
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = '✅ Copiado!';
            btn.style.background = '#10b981';
            btn.style.color = 'white';
            
            setTimeout(() => {
                btn.textContent = originalText;
                btn.style.background = '';
                btn.style.color = '';
            }, 2000);
        }
    </script>
    
    <!-- Custom Modals CSS -->
    <link rel="stylesheet" href="assets/css/custom_modals.css">
    <!-- Custom Modals JS -->
    <script src="assets/js/custom_modals.js"></script>
<?php endSidebar(); ?>
