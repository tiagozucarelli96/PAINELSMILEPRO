<?php
// google_calendar_debug.php — Debug da sincronização Google Calendar
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

$helper = new GoogleCalendarHelper();
$debug_info = [];

function google_calendar_debug_normalize_webhook_url(string $url): string {
    if (strpos($url, '/google/webhook') !== false) {
        return str_replace('/google/webhook', '/google_calendar_webhook.php', $url);
    }
    return $url;
}

try {
    // Verificar conexão
    $debug_info['conectado'] = $helper->isConnected();
    
    // Obter configuração
    $config = $helper->getConfig();
    $debug_info['config'] = $config;
    $debug_info['webhook_url'] = google_calendar_debug_normalize_webhook_url(
        getenv('GOOGLE_WEBHOOK_URL') ?: ($_ENV['GOOGLE_WEBHOOK_URL'] ?? 'https://painelsmilepro-production.up.railway.app/google_calendar_webhook.php')
    );
    
    if ($config && isset($_GET['test_sync'])) {
        // Testar sincronização
        $calendar_id = $config['google_calendar_id'];
        $debug_info['testando_calendario'] = $calendar_id;
        
        // Buscar eventos diretamente
        $time_min = gmdate('Y-m-d\TH:i:s\Z', strtotime('today midnight'));
        $time_max = gmdate('Y-m-d\TH:i:s\Z', strtotime('+180 days 23:59:59'));
        
        $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendar_id) . "/events";
        $url .= "?timeMin=" . urlencode($time_min);
        $url .= "&timeMax=" . urlencode($time_max);
        $url .= "&singleEvents=true";
        $url .= "&orderBy=startTime";
        $url .= "&maxResults=10"; // Apenas 10 para teste
        
        $access_token = $helper->getValidAccessToken();
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $debug_info['test_url'] = $url;
        $debug_info['test_http_code'] = $http_code;
        $debug_info['test_response'] = json_decode($response, true);
        $debug_info['test_response_raw'] = substr($response, 0, 1000);
        
        if ($http_code === 200 && isset($debug_info['test_response']['items'])) {
            $debug_info['test_total_eventos'] = count($debug_info['test_response']['items']);
            $debug_info['test_primeiros_eventos'] = array_slice($debug_info['test_response']['items'], 0, 3);
        }
    }

    if (isset($_GET['test_webhook'])) {
        $debug_info['testando_webhook'] = true;
        $debug_info['hide_calendars'] = true;
    }

    if ($config && isset($_GET['test_webhook'])) {
        try {
            $resultado = $helper->registerWebhook($config['google_calendar_id'], $debug_info['webhook_url']);
            $debug_info['test_webhook_result'] = $resultado;
        } catch (Exception $e) {
            $debug_info['test_webhook_error'] = $e->getMessage();
        }
    }
    
    // Listar calendários
    if ($helper->isConnected() && empty($debug_info['hide_calendars'])) {
        $calendars = $helper->listCalendars();
        $debug_info['calendarios'] = $calendars['items'] ?? [];
    }
    
} catch (Exception $e) {
    $debug_info['erro'] = $e->getMessage();
    $debug_info['erro_trace'] = $e->getTraceAsString();
}

ob_start();
?>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
.container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
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
pre {
    background: #f8f8f8;
    padding: 1rem;
    border-radius: 6px;
    overflow-x: auto;
    font-size: 0.875rem;
}
.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    background: #1e40af;
    color: white;
    margin-top: 1rem;
}
.btn:hover { background: #1e3a8a; }
.alert {
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}
.alert-success { background: #d1fae5; color: #065f46; }
.alert-error { background: #fee2e2; color: #991b1b; }
</style>

<div class="container">
    <div class="section">
        <h2>🔍 Debug Google Calendar</h2>
        
        <a href="index.php?page=google_calendar_config" class="btn">← Voltar para Configuração</a>
        <a href="index.php?page=google_calendar_debug&test_sync=1" class="btn" style="background: #10b981;">🧪 Testar Sincronização</a>
        <a href="index.php?page=google_calendar_debug&test_webhook=1" class="btn" style="background: #2563eb;">🔔 Testar Webhook</a>
        
        <?php if (!empty($debug_info['testando_webhook'])): ?>
        <div class="alert <?= !empty($debug_info['test_webhook_result']) ? 'alert-success' : 'alert-error' ?>">
            <?= !empty($debug_info['test_webhook_result']) ? '✅ Teste de webhook executado' : '❌ Falha ao executar teste de webhook' ?>
        </div>
        <?php endif; ?>

        <h3 style="margin-top: 2rem; margin-bottom: 1rem;">Status da Conexão</h3>
        <pre><?= $debug_info['conectado'] ? '✅ Conectado' : '❌ Não Conectado' ?></pre>
        
        <?php if (isset($debug_info['config'])): ?>
        <h3 style="margin-top: 2rem; margin-bottom: 1rem;">Configuração Atual</h3>
        <pre><?= htmlspecialchars(json_encode($debug_info['config'], JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
        
        <h3 style="margin-top: 2rem; margin-bottom: 1rem;">Webhook URL</h3>
        <pre><?= htmlspecialchars($debug_info['webhook_url'] ?? 'N/A') ?></pre>

        <?php if (!empty($debug_info['testando_webhook'])): ?>
        <h3 style="margin-top: 2rem; margin-bottom: 1rem;">Teste de Webhook</h3>
        <?php if (!empty($debug_info['test_webhook_result'])): ?>
        <div class="alert alert-success">✅ Webhook registrado</div>
        <pre><?= htmlspecialchars(json_encode($debug_info['test_webhook_result'], JSON_PRETTY_PRINT)) ?></pre>
        <?php else: ?>
        <div class="alert alert-error">❌ Falha ao registrar webhook</div>
        <pre><?= htmlspecialchars($debug_info['test_webhook_error'] ?? 'Sem detalhes') ?></pre>
        <?php endif; ?>
        <?php endif; ?>
        
        <?php if (isset($debug_info['testando_calendario'])): ?>
        <h3 style="margin-top: 2rem; margin-bottom: 1rem;">Teste de Sincronização</h3>
        <p><strong>Calendário testado:</strong> <?= htmlspecialchars($debug_info['testando_calendario']) ?></p>
        <p><strong>HTTP Code:</strong> <?= $debug_info['test_http_code'] ?></p>
        
        <?php if ($debug_info['test_http_code'] === 200): ?>
        <div class="alert alert-success">
            ✅ Requisição bem-sucedida!
        </div>
        <p><strong>Total de eventos encontrados:</strong> <?= $debug_info['test_total_eventos'] ?? 0 ?></p>
        
        <?php if (isset($debug_info['test_primeiros_eventos']) && !empty($debug_info['test_primeiros_eventos'])): ?>
        <h4 style="margin-top: 1rem;">Primeiros Eventos:</h4>
        <pre><?= htmlspecialchars(json_encode($debug_info['test_primeiros_eventos'], JSON_PRETTY_PRINT)) ?></pre>
        <?php else: ?>
        <div class="alert alert-error">
            ⚠️ Nenhum evento encontrado no período especificado.
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="alert alert-error">
            ❌ Erro na requisição
        </div>
        <pre><?= htmlspecialchars($debug_info['test_response_raw'] ?? 'Sem resposta') ?></pre>
        <?php endif; ?>
        
        <h4 style="margin-top: 1rem;">URL da Requisição:</h4>
        <pre><?= htmlspecialchars($debug_info['test_url'] ?? 'N/A') ?></pre>
        <?php endif; ?>

        <?php if (isset($debug_info['calendarios']) && empty($debug_info['hide_calendars'])): ?>
        <h3 style="margin-top: 2rem; margin-bottom: 1rem;">Calendários Disponíveis</h3>
        <pre><?= htmlspecialchars(json_encode($debug_info['calendarios'], JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
        
        <?php if (isset($debug_info['erro'])): ?>
        <h3 style="margin-top: 2rem; margin-bottom: 1rem;">Erro</h3>
        <div class="alert alert-error">
            <?= htmlspecialchars($debug_info['erro']) ?>
        </div>
        <pre><?= htmlspecialchars($debug_info['erro_trace'] ?? '') ?></pre>
        <?php endif; ?>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Debug Google Calendar');
echo $conteudo;
endSidebar();
?>
