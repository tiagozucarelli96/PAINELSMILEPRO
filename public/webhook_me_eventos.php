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
        // Validar dados obrigatórios
        if (empty($data['evento_id']) || empty($data['webhook_tipo'])) {
            throw new Exception('Dados obrigatórios ausentes');
        }
        
        // Preparar dados para inserção
        $stmt = $pdo->prepare("
            INSERT INTO me_eventos_webhook (
                evento_id, nome, data_evento, status, tipo_evento, 
                cliente_nome, cliente_email, valor, webhook_tipo, webhook_data
            ) VALUES (
                :evento_id, :nome, :data_evento, :status, :tipo_evento,
                :cliente_nome, :cliente_email, :valor, :webhook_tipo, :webhook_data
            ) ON CONFLICT (evento_id, webhook_tipo) DO UPDATE SET
                nome = EXCLUDED.nome,
                data_evento = EXCLUDED.data_evento,
                status = EXCLUDED.status,
                tipo_evento = EXCLUDED.tipo_evento,
                cliente_nome = EXCLUDED.cliente_nome,
                cliente_email = EXCLUDED.cliente_email,
                valor = EXCLUDED.valor,
                webhook_data = EXCLUDED.webhook_data,
                recebido_em = NOW(),
                processado = FALSE
        ");
        
        $stmt->execute([
            ':evento_id' => $data['evento_id'],
            ':nome' => $data['nome'] ?? 'Evento sem nome',
            ':data_evento' => $data['data_evento'] ?? null,
            ':status' => $data['status'] ?? 'ativo',
            ':tipo_evento' => $data['tipo_evento'] ?? 'evento',
            ':cliente_nome' => $data['cliente_nome'] ?? null,
            ':cliente_email' => $data['cliente_email'] ?? null,
            ':valor' => $data['valor'] ?? 0.00,
            ':webhook_tipo' => $data['webhook_tipo'],
            ':webhook_data' => json_encode($data)
        ]);
        
        logWebhook("Webhook processado com sucesso: {$data['webhook_tipo']} - {$data['evento_id']}");
        return true;
        
    } catch (Exception $e) {
        logWebhook("Erro ao processar webhook: " . $e->getMessage());
        return false;
    }
}

// Verificar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido']);
    exit;
}

// Verificar token de autenticação
$headers = getallheaders();
$token = $headers['Authorization'] ?? $headers['X-Token'] ?? $_GET['token'] ?? '';

if (!validarToken($token)) {
    http_response_code(401);
    echo json_encode(['erro' => 'Token inválido']);
    logWebhook("Tentativa de acesso com token inválido: $token");
    exit;
}

// Conectar ao banco de dados
try {
    require_once __DIR__ . '/conexao.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro de conexão com banco de dados']);
    logWebhook("Erro de conexão: " . $e->getMessage());
    exit;
}

// Obter dados do webhook
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['erro' => 'JSON inválido']);
    logWebhook("JSON inválido recebido: $input");
    exit;
}

// Log do webhook recebido
logWebhook("Webhook recebido: " . $input);

// Processar webhook
if (processarWebhook($data)) {
    http_response_code(200);
    echo json_encode([
        'status' => 'sucesso',
        'mensagem' => 'Webhook processado com sucesso',
        'evento_id' => $data['evento_id'] ?? null,
        'webhook_tipo' => $data['webhook_tipo'] ?? null
    ]);
} else {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao processar webhook']);
}
