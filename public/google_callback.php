<?php
// google_callback.php — Endpoint de callback OAuth do Google
// Rota pública: /google/callback

// Log da requisição
$timestamp = date('Y-m-d H:i:s');
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$remote_addr = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

// Capturar parâmetros OAuth
$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$scope = $_GET['scope'] ?? null;
$error = $_GET['error'] ?? null;
$error_description = $_GET['error_description'] ?? null;

// Preparar dados para log
$log_data = [
    'timestamp' => $timestamp,
    'method' => $request_method,
    'uri' => $request_uri,
    'remote_addr' => $remote_addr,
    'user_agent' => $user_agent,
    'code' => $code ? 'presente' : 'ausente',
    'state' => $state,
    'scope' => $scope,
    'error' => $error,
    'error_description' => $error_description
];

// Registrar em log
$log_message = sprintf(
    "[GOOGLE_OAUTH_CALLBACK] %s | Method: %s | URI: %s | IP: %s | Code: %s | State: %s | Error: %s",
    $timestamp,
    $request_method,
    $request_uri,
    $remote_addr,
    $code ? 'SIM' : 'NÃO',
    $state ?? 'N/A',
    $error ?? 'N/A'
);

error_log($log_message);

// Log detalhado (apenas em desenvolvimento ou se necessário)
if (getenv('APP_DEBUG') === '1' || isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === '1') {
    error_log("[GOOGLE_OAUTH_CALLBACK] Dados completos: " . json_encode($log_data, JSON_PRETTY_PRINT));
}

// Validar se o parâmetro "code" existe
if (!$code) {
    // Se não tem code, pode ser um erro ou cancelamento
    if ($error) {
        error_log("[GOOGLE_OAUTH_CALLBACK] Erro recebido: $error - $error_description");
        http_response_code(400);
        echo "Callback Google recebido com erro: " . htmlspecialchars($error);
        if ($error_description) {
            echo " - " . htmlspecialchars($error_description);
        }
        exit;
    }
    
    // Se não tem code nem error, pode ser uma requisição inválida
    error_log("[GOOGLE_OAUTH_CALLBACK] Aviso: Callback chamado sem parâmetro 'code'");
    http_response_code(400);
    echo "Callback Google recebido, mas parâmetro 'code' não encontrado.";
    exit;
}

// Callback recebido com sucesso
http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Callback Google OAuth</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
        }
        .container {
            background: white;
            color: #1e3a8a;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 500px;
        }
        h1 {
            margin: 0 0 1rem 0;
            font-size: 1.5rem;
        }
        .success-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .details {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
            font-size: 0.875rem;
            color: #64748b;
            text-align: left;
        }
        .detail-item {
            margin: 0.5rem 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">✅</div>
        <h1>Callback Google recebido com sucesso</h1>
        <p>O endpoint de callback OAuth do Google foi chamado corretamente.</p>
        
        <div class="details">
            <div class="detail-item"><strong>Status:</strong> Sucesso</div>
            <div class="detail-item"><strong>Code:</strong> Presente</div>
            <?php if ($state): ?>
            <div class="detail-item"><strong>State:</strong> <?= htmlspecialchars($state) ?></div>
            <?php endif; ?>
            <?php if ($scope): ?>
            <div class="detail-item"><strong>Scope:</strong> <?= htmlspecialchars($scope) ?></div>
            <?php endif; ?>
            <div class="detail-item"><strong>Timestamp:</strong> <?= $timestamp ?></div>
        </div>
        
        <p style="margin-top: 1.5rem; font-size: 0.875rem; color: #64748b;">
            Esta URL pode ser usada como redirect URI no Google Cloud Console.
        </p>
    </div>
</body>
</html>
<?php
exit;
