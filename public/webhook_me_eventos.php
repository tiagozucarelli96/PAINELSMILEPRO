<?php
// webhook_me_eventos.php — Endpoint para receber webhooks da ME Eventos
header('Content-Type: application/json');

// Configurações
$TOKEN_VALIDO = 'smile-token-2025';
$LOG_FILE = __DIR__ . '/logs/webhook_me_eventos.log';

// Criar diretório de logs se não existir
if (!is_dir(dirname($LOG_FILE))) {
    mkdir(dirname($LOG_FILE), 0755, true);
}

function logWebhook($message) {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($LOG_FILE, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

function validarToken($token) {
    global $TOKEN_VALIDO;
    return hash_equals($TOKEN_VALIDO, $token);
}

function processarWebhook($data) {
    global $pdo;
    
    try {
        // Estrutura real da ME Eventos:
        // {
        //   "id_event": 83913,
        //   "event": "event_created",
        //   "dateCreated": "2024-12-18 13:58:08",
        //   "data": [{ "id": "1725", "nomeevento": "...", ... }]
        // }
        
        // Validar estrutura do payload
        if (empty($data['event'])) {
            throw new Exception('Campo "event" obrigatório ausente');
        }
        
        // Pegar o primeiro item do array data
        $evento_data = !empty($data['data']) && is_array($data['data']) ? $data['data'][0] : [];
        
        if (empty($evento_data['id'])) {
            throw new Exception('ID do evento não encontrado no payload');
        }
        
        // Preparar dados para inserção - estrutura correta da ME Eventos
        // IMPORTANTE: Não atualizar recebido_em no ON CONFLICT para manter a data original
        $stmt = $pdo->prepare("
            INSERT INTO me_eventos_webhook (
                evento_id, nome, data_evento, status, tipo_evento, 
                cliente_nome, cliente_email, valor, webhook_tipo, webhook_data, recebido_em
            ) VALUES (
                :evento_id, :nome, :data_evento, :status, :tipo_evento,
                :cliente_nome, :cliente_email, :valor, :webhook_tipo, :webhook_data, NOW()
            ) ON CONFLICT (evento_id, webhook_tipo) DO UPDATE SET
                nome = EXCLUDED.nome,
                data_evento = EXCLUDED.data_evento,
                status = EXCLUDED.status,
                tipo_evento = EXCLUDED.tipo_evento,
                cliente_nome = EXCLUDED.cliente_nome,
                cliente_email = EXCLUDED.cliente_email,
                valor = EXCLUDED.valor,
                webhook_data = EXCLUDED.webhook_data,
                processado = FALSE
                -- recebido_em NÃO é atualizado no ON CONFLICT para manter a data original
        ");
        
        // Mapear dados da ME Eventos para nossa estrutura
        // webhook_tipo vem do campo "event" (event_created, event_updated, event_canceled, etc)
        $webhook_tipo = $data['event']; // 'event_created', 'event_updated', 'event_canceled', etc
        
        // Converter webhook_tipo para formato compatível com a query da dashboard
        // Se for event_created, manter como 'created' para compatibilidade
        $webhook_tipo_original = $webhook_tipo;
        if ($webhook_tipo === 'event_created') {
            $webhook_tipo = 'created';
        } elseif ($webhook_tipo === 'event_updated') {
            $webhook_tipo = 'updated';
        } elseif ($webhook_tipo === 'event_canceled' || $webhook_tipo === 'event_deleted') {
            $webhook_tipo = 'deleted';
        }
        
        // Extrair dados do evento
        $evento_id = (string)$evento_data['id'];
        $nome = $evento_data['nomeevento'] ?? $evento_data['nome'] ?? 'Evento sem nome';
        $data_evento = $evento_data['dataevento'] ?? $evento_data['data'] ?? null;
        $tipo_evento = $evento_data['tipoevento'] ?? $evento_data['tipo'] ?? 'evento';
        $valor = isset($evento_data['valor']) ? (float)$evento_data['valor'] : 0.00;
        
        // Status baseado no tipo de evento
        $status = 'ativo';
        if ($webhook_tipo_original === 'event_canceled' || $webhook_tipo_original === 'event_deleted') {
            $status = 'cancelado';
        } elseif ($webhook_tipo_original === 'event_reactivated') {
            $status = 'ativo';
        }
        
        // Dados do cliente (pode vir em outros eventos ou não estar disponível)
        $cliente_nome = $evento_data['client_name'] ?? $evento_data['nomecliente'] ?? null;
        $cliente_email = $evento_data['client_email'] ?? $evento_data['emailcliente'] ?? null;
        
        // Se não tiver dados do cliente no evento, tentar buscar pelo idcliente
        if (empty($cliente_nome) && !empty($evento_data['idcliente'])) {
            // Opcional: buscar dados do cliente na tabela de clientes se existir
            // Por enquanto, deixar como null
        }
        
        $stmt->execute([
            ':evento_id' => $evento_id,
            ':nome' => $nome,
            ':data_evento' => $data_evento,
            ':status' => $status,
            ':tipo_evento' => (string)$tipo_evento,
            ':cliente_nome' => $cliente_nome,
            ':cliente_email' => $cliente_email,
            ':valor' => $valor,
            ':webhook_tipo' => $webhook_tipo, // Usar formato 'created' para compatibilidade
            ':webhook_data' => json_encode($data) // Salvar payload completo original
        ]);
        
        logWebhook("Webhook processado com sucesso: {$webhook_tipo_original} ({$webhook_tipo}) - Evento ID: {$evento_id}");
        return true;
        
    } catch (Exception $e) {
        logWebhook("Erro ao processar webhook: " . $e->getMessage() . " | Payload: " . json_encode($data));
        return false;
    }
}

// Verificar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido']);
    logWebhook("ERRO: Método " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . " não permitido. Esperado: POST");
    exit;
}

// Verificar token de autenticação
// A ME Eventos pode enviar o token de várias formas
$headers = getallheaders() ?: [];
$token = '';

// Tentar diferentes formatos de header
if (isset($headers['Authorization'])) {
    $token = $headers['Authorization'];
    // Remover "Bearer " se presente
    $token = preg_replace('/^Bearer\s+/i', '', $token);
} elseif (isset($headers['X-Token'])) {
    $token = $headers['X-Token'];
} elseif (isset($headers['x-token'])) {
    $token = $headers['x-token'];
} elseif (isset($_GET['token'])) {
    $token = $_GET['token'];
} elseif (isset($_POST['token'])) {
    $token = $_POST['token'];
}

// Log para debug (sem mostrar o token completo por segurança)
logWebhook("Tentativa de autenticação. Token recebido: " . (empty($token) ? 'NENHUM' : substr($token, 0, 10) . '...'));
logWebhook("Headers recebidos: " . json_encode($headers));

if (empty($token) || !validarToken($token)) {
    http_response_code(401);
    echo json_encode(['erro' => 'Token inválido ou ausente']);
    logWebhook("Tentativa de acesso com token inválido ou ausente. Headers: " . json_encode($headers));
    exit;
}

// Conectar ao banco de dados
try {
    require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro de conexão com banco de dados']);
    logWebhook("Erro de conexão: " . $e->getMessage());
    exit;
}

// Obter dados do webhook
$input = file_get_contents('php://input');

// Log de todas as requisições recebidas
logWebhook("=== REQUISIÇÃO RECEBIDA ===");
logWebhook("Método: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
logWebhook("URL: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN'));
logWebhook("Headers: " . json_encode(getallheaders() ?: []));
logWebhook("GET params: " . json_encode($_GET));
logWebhook("POST params: " . json_encode($_POST));
logWebhook("Raw input length: " . strlen($input) . " bytes");
logWebhook("Raw input preview: " . substr($input, 0, 500));

$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['erro' => 'JSON inválido']);
    logWebhook("JSON inválido recebido. Erro: " . json_last_error_msg() . " | Input: " . substr($input, 0, 500));
    exit;
}

// Log do webhook recebido (completo)
logWebhook("Webhook recebido e parseado com sucesso: " . json_encode($data));

// Processar webhook
if (processarWebhook($data)) {
    http_response_code(200);
    echo json_encode([
        'status' => 'sucesso',
        'mensagem' => 'Webhook processado com sucesso',
        'evento_id' => !empty($data['data']) && is_array($data['data']) && !empty($data['data'][0]['id']) ? $data['data'][0]['id'] : null,
        'webhook_tipo' => $data['event'] ?? null
    ]);
} else {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao processar webhook']);
}
