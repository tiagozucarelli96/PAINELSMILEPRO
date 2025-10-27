<?php
// agenda_config.php — Configurações da agenda
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/agenda_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

$agenda = new AgendaHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

// Definir a página atual para o sidebar_unified.php
$_GET['page'] = 'agenda_config';

// Não precisa verificar perm_agenda_ver pois é apenas configurações de usuário
// if (!$agenda->canAccessAgenda($usuario_id)) {
//     header('Location: index.php?page=dashboard');
//     exit;
// }

// Processar atualizações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cor_agenda = $_POST['cor_agenda'] ?? '#1E40AF';
    $lembrete_padrao = $_POST['agenda_lembrete_padrao_min'] ?? 60;
    
    try {
        $stmt = $GLOBALS['pdo']->prepare("
            UPDATE usuarios SET 
                cor_agenda = ?, 
                agenda_lembrete_padrao_min = ?
            WHERE id = ?
        ");
        $stmt->execute([$cor_agenda, $lembrete_padrao, $usuario_id]);
        
        $success = "Configurações atualizadas com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao atualizar configurações: " . $e->getMessage();
    }
}

// Obter configurações atuais
$stmt = $GLOBALS['pdo']->prepare("
    SELECT cor_agenda, agenda_lembrete_padrao_min 
    FROM usuarios 
    WHERE id = ?
");
$stmt->execute([$usuario_id]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);
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
                        <label for="cor_agenda">Cor dos seus eventos na agenda</label>
                        <div style="display: flex; align-items: center;">
                            <input type="color" id="cor_agenda" name="cor_agenda" 
                                   value="<?= htmlspecialchars($config['cor_agenda']) ?>" 
                                   style="width: 60px; height: 40px; border: none; border-radius: 8px;">
                            <div class="color-preview" id="colorPreview" 
                                 style="background-color: <?= htmlspecialchars($config['cor_agenda']) ?>"></div>
                        </div>
                        <small style="color: #666; margin-top: 5px; display: block;">
                            Esta cor será usada para identificar seus eventos no calendário
                        </small>
                    </div>
                </div>
                
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
                                   value="<?= 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/agenda_ics.php?u=' . $usuario_id ?>" 
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
                
                <div class="form-actions">
                    <a href="index.php?page=agenda" class="btn btn-outline">
                        ← Voltar para Agenda
                    </a>
                    <button type="submit" class="btn btn-primary">
                        💾 Salvar Configurações
                    </button>
                </div>
            </form>
</div>

<script>
        // Atualizar preview da cor
        document.getElementById('cor_agenda').addEventListener('input', function() {
            document.getElementById('colorPreview').style.backgroundColor = this.value;
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
<?php endSidebar(); ?>
