<?php
// google_calendar_config.php — Configuração Google Calendar
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/google_calendar_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

$mensagem = '';
$erro = '';
$helper = new GoogleCalendarHelper();

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'salvar_calendario') {
        try {
            $calendar_id = trim($_POST['calendar_id'] ?? '');
            $calendar_name = trim($_POST['calendar_name'] ?? '');
            $dias_futuro = (int)($_POST['dias_futuro'] ?? 180);
            
            if (empty($calendar_id)) {
                throw new Exception('Selecione um calendário');
            }
            
            // Log para debug
            error_log("[GOOGLE_CALENDAR_CONFIG] Salvando calendário - ID: $calendar_id, Nome: $calendar_name");
            
            // Deletar configs antigas
            $pdo->exec("DELETE FROM google_calendar_config");
            
            $stmt = $pdo->prepare("
                INSERT INTO google_calendar_config (google_calendar_id, google_calendar_name, sync_dias_futuro)
                VALUES (:calendar_id, :calendar_name, :dias_futuro)
            ");
            $stmt->execute([
                ':calendar_id' => $calendar_id,
                ':calendar_name' => $calendar_name,
                ':dias_futuro' => $dias_futuro
            ]);
            
            // Verificar se foi salvo corretamente
            $stmt_check = $pdo->prepare("SELECT * FROM google_calendar_config WHERE google_calendar_id = :calendar_id");
            $stmt_check->execute([':calendar_id' => $calendar_id]);
            $saved = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($saved) {
                error_log("[GOOGLE_CALENDAR_CONFIG] Calendário salvo: ID={$saved['google_calendar_id']}, Nome={$saved['google_calendar_name']}");
            } else {
                error_log("[GOOGLE_CALENDAR_CONFIG] ERRO: Calendário não foi salvo!");
            }
            
            $mensagem = 'Calendário configurado com sucesso!';
        } catch (Exception $e) {
            $erro = $e->getMessage();
            error_log("[GOOGLE_CALENDAR_CONFIG] Erro ao salvar: " . $e->getMessage());
        }
    }
    
    if ($acao === 'sincronizar') {
        try {
            $config = $helper->getConfig();
            if (!$config) {
                throw new Exception('Configure um calendário primeiro');
            }
            
            // Log para debug
            error_log("[GOOGLE_CALENDAR_CONFIG] Sincronizando - ID: {$config['google_calendar_id']}, Nome: {$config['google_calendar_name']}");
            
            $resultado = $helper->syncCalendarEvents(
                $config['google_calendar_id'],
                $config['sync_dias_futuro'] ?? 180
            );
            
            $mensagem = sprintf(
                'Sincronização concluída! Importados: %d, Atualizados: %d%s',
                $resultado['importados'],
                $resultado['atualizados'],
                isset($resultado['total_encontrado']) ? " (Total encontrado: {$resultado['total_encontrado']})" : ''
            );
            
            if (isset($resultado['pulados']) && $resultado['pulados'] > 0) {
                $mensagem .= " | Pulados: {$resultado['pulados']}";
            }
        } catch (Exception $e) {
            $erro = 'Erro ao sincronizar: ' . $e->getMessage();
            error_log("[GOOGLE_CALENDAR_CONFIG] Erro na sincronização: " . $e->getMessage());
            error_log("[GOOGLE_CALENDAR_CONFIG] Stack trace: " . $e->getTraceAsString());
        }
    }
}

// Verificar se está conectado
$is_connected = $helper->isConnected();

// Obter calendários se estiver conectado
$calendars = [];
if ($is_connected) {
    try {
        $response = $helper->listCalendars();
        if (isset($response['items'])) {
            $calendars = $response['items'];
        }
    } catch (Exception $e) {
        $erro = 'Erro ao listar calendários: ' . $e->getMessage();
    }
}

// Obter configuração atual
$config = $helper->getConfig();

// Obter logs de sincronização
$logs = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM google_calendar_sync_logs 
        ORDER BY criado_em DESC 
        LIMIT 10
    ");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir ainda
}

ob_start();
?>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
.container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
.header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: white;
    padding: 1.5rem 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
}
.header h1 { font-size: 1.5rem; font-weight: 700; }
.section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.section h2 {
    color: #1e40af;
    margin-bottom: 1.5rem;
    font-size: 1.25rem;
}
.alert {
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1.5rem;
}
.alert-success { background: #d1fae5; color: #065f46; }
.alert-error { background: #fee2e2; color: #991b1b; }
.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
}
.btn-primary {
    background: #1e40af;
    color: white;
}
.btn-primary:hover { background: #1e3a8a; }
.btn-secondary {
    background: #6b7280;
    color: white;
}
.form-group {
    margin-bottom: 1.5rem;
}
.form-label {
    display: block;
    font-weight: 500;
    color: #374151;
    margin-bottom: 0.5rem;
}
.form-input, .form-select {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
}
.form-input:focus, .form-select:focus {
    outline: none;
    border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}
.status-badge {
    display: inline-block;
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-weight: 500;
    font-size: 0.875rem;
}
.status-connected { background: #d1fae5; color: #065f46; }
.status-disconnected { background: #fee2e2; color: #991b1b; }
.calendar-list {
    display: grid;
    gap: 1rem;
    margin-top: 1rem;
}
.calendar-item {
    padding: 1rem;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}
.calendar-item:hover {
    border-color: #1e40af;
    background: #eff6ff;
}
.calendar-item.selected {
    border-color: #1e40af;
    background: #dbeafe;
}
.logs-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
}
.logs-table th,
.logs-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}
.logs-table th {
    background: #f3f4f6;
    font-weight: 600;
    color: #374151;
}
</style>

<div class="container">
    <div class="header">
        <h1>📅 Google Calendar - Configuração</h1>
    </div>
    
    <?php if ($mensagem): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    
    <?php if (isset($_GET['oauth']) && $_GET['oauth'] === 'success'): ?>
    <div class="alert alert-success">✅ Google Calendar conectado com sucesso! Configure o calendário abaixo.</div>
    <?php endif; ?>
    
    <!-- Status da Conexão -->
    <div class="section">
        <h2>🔗 Status da Conexão</h2>
        <?php if ($is_connected): ?>
        <div>
            <span class="status-badge status-connected">✅ Conectado</span>
            <p style="margin-top: 1rem; color: #64748b;">
                O Google Calendar está conectado e pronto para uso.
            </p>
        </div>
        <?php else: ?>
        <div>
            <span class="status-badge status-disconnected">❌ Não Conectado</span>
            <p style="margin-top: 1rem; color: #64748b; margin-bottom: 1rem;">
                Conecte sua conta do Google para sincronizar eventos.
            </p>
            <a href="<?= htmlspecialchars($helper->getAuthorizationUrl()) ?>" class="btn btn-primary">
                🔗 Conectar Google Calendar
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if ($is_connected): ?>
    <!-- Seleção de Calendário -->
    <div class="section">
        <h2>📋 Selecionar Calendário</h2>
        <?php if (empty($calendars)): ?>
        <p style="color: #64748b;">Carregando calendários...</p>
        <?php else: ?>
        <form method="POST" onsubmit="return validateCalendarSelection()">
            <input type="hidden" name="acao" value="salvar_calendario">
            
            <div class="form-group">
                <label class="form-label">Calendário</label>
                <div class="calendar-list">
                    <?php foreach ($calendars as $cal): ?>
                    <div class="calendar-item <?= $config && $config['google_calendar_id'] === $cal['id'] ? 'selected' : '' ?>" 
                         onclick="selectCalendar('<?= htmlspecialchars($cal['id']) ?>', '<?= htmlspecialchars(addslashes($cal['summary'] ?? '')) ?>')">
                        <input type="radio" 
                               name="calendar_id" 
                               id="calendar_<?= htmlspecialchars($cal['id']) ?>" 
                               value="<?= htmlspecialchars($cal['id']) ?>"
                               <?= $config && $config['google_calendar_id'] === $cal['id'] ? 'checked' : '' ?>
                               onchange="selectCalendar('<?= htmlspecialchars($cal['id']) ?>', '<?= htmlspecialchars(addslashes($cal['summary'] ?? '')) ?>')"
                               required>
                        <label for="calendar_<?= htmlspecialchars($cal['id']) ?>" style="cursor: pointer; margin-left: 0.5rem;">
                            <strong><?= htmlspecialchars($cal['summary'] ?? 'Sem nome') ?></strong>
                            <?php if (isset($cal['description'])): ?>
                            <br><small style="color: #64748b;"><?= htmlspecialchars($cal['description']) ?></small>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <!-- Campo único para o nome do calendário selecionado -->
                    <input type="hidden" name="calendar_name" id="selected_calendar_name" value="<?= htmlspecialchars($config['google_calendar_name'] ?? '') ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Dias Futuros para Sincronizar</label>
                <input type="number" 
                       name="dias_futuro" 
                       class="form-input" 
                       value="<?= $config['sync_dias_futuro'] ?? 180 ?>" 
                       min="1" 
                       max="365" 
                       required>
                <small style="color: #64748b;">Eventos de hoje até N dias no futuro serão sincronizados.</small>
            </div>
            
            <button type="submit" class="btn btn-primary">💾 Salvar Configuração</button>
        </form>
        <?php endif; ?>
    </div>
    
    <!-- Sincronização -->
    <?php if ($config): ?>
    <div class="section">
        <h2>🔄 Sincronização</h2>
        <p style="color: #64748b; margin-bottom: 1rem;">
            Calendário: <strong><?= htmlspecialchars($config['google_calendar_name']) ?></strong><br>
            <?php if ($config['ultima_sincronizacao']): ?>
            Última sincronização: <?= date('d/m/Y H:i', strtotime($config['ultima_sincronizacao'])) ?>
            <?php else: ?>
            Nenhuma sincronização realizada ainda.
            <?php endif; ?>
        </p>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="acao" value="sincronizar">
            <button type="submit" class="btn btn-primary">🔄 Sincronizar Agora</button>
        </form>
        
        <a href="index.php?page=google_calendar_debug" class="btn btn-secondary" style="margin-left: 10px; text-decoration: none;">
            🔍 Debug
        </a>
    </div>
    
    <!-- Logs de Sincronização -->
    <?php if (!empty($logs)): ?>
    <div class="section">
        <h2>📊 Logs de Sincronização</h2>
        <table class="logs-table">
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Tipo</th>
                    <th>Total de Eventos</th>
                    <th>Detalhes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= date('d/m/Y H:i', strtotime($log['criado_em'])) ?></td>
                    <td><?= htmlspecialchars($log['tipo']) ?></td>
                    <td><?= $log['total_eventos'] ?></td>
                    <td>
                        <?php if ($log['detalhes']): ?>
                        <?php 
                        $detalhes = json_decode($log['detalhes'], true);
                        if ($detalhes): 
                        ?>
                        Importados: <?= $detalhes['importados'] ?? 0 ?>, 
                        Atualizados: <?= $detalhes['atualizados'] ?? 0 ?>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function selectCalendar(calendarId, calendarName) {
    // Marcar o radio button
    const radio = document.getElementById('calendar_' + calendarId);
    if (radio) {
        radio.checked = true;
    }
    
    // Atualizar o campo hidden com o nome do calendário selecionado
    const nameField = document.getElementById('selected_calendar_name');
    if (nameField) {
        nameField.value = calendarName;
        console.log('Calendário selecionado:', calendarId, 'Nome:', calendarName);
    }
    
    // Atualizar visualmente a seleção
    updateCalendarSelection();
}

function updateCalendarSelection() {
    document.querySelectorAll('.calendar-item').forEach(item => {
        item.classList.remove('selected');
    });
    document.querySelectorAll('input[name="calendar_id"]:checked').forEach(radio => {
        radio.closest('.calendar-item').classList.add('selected');
    });
}

function validateCalendarSelection() {
    const checkedRadio = document.querySelector('input[name="calendar_id"]:checked');
    if (!checkedRadio) {
        alert('Por favor, selecione um calendário');
        return false;
    }
    
    const calendarId = checkedRadio.value;
    const calendarItem = checkedRadio.closest('.calendar-item');
    const calendarName = calendarItem.querySelector('strong').textContent.trim();
    const nameField = document.getElementById('selected_calendar_name');
    
    if (nameField) {
        nameField.value = calendarName;
        console.log('Enviando formulário - Calendário ID:', calendarId, 'Nome:', calendarName);
    }
    
    return true;
}

// Inicializar seleção e campo hidden
document.addEventListener('DOMContentLoaded', function() {
    updateCalendarSelection();
    
    // Garantir que o campo hidden tenha o valor correto do calendário já selecionado
    const checkedRadio = document.querySelector('input[name="calendar_id"]:checked');
    if (checkedRadio) {
        const calendarItem = checkedRadio.closest('.calendar-item');
        const calendarName = calendarItem.querySelector('strong').textContent.trim();
        const nameField = document.getElementById('selected_calendar_name');
        if (nameField) {
            nameField.value = calendarName;
        }
    }
});
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Google Calendar');
echo $conteudo;
endSidebar();
?>
