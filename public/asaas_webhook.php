<?php
// asaas_webhook.php — Webhook para receber notificações do ASAAS (Checkout e Pagamentos)
// Documentação: https://docs.asaas.com/docs/eventos-para-checkout

// ============================================
// CRÍTICO: ISOLAMENTO TOTAL DO WEBHOOK
// ============================================
// Este arquivo DEVE ser acessado DIRETAMENTE, nunca via index.php ou router
// NUNCA deve requerer autenticação ou retornar HTML

// 1. Desabilitar completamente output buffering ANTES de qualquer coisa
while (ob_get_level()) {
    ob_end_clean();
}

// 2. Limpar TODOS os headers que possam existir
header_remove();

// 3. Fechar sessão se existir (ANTES de incluir qualquer arquivo)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 4. Desabilitar qualquer auto-start de sessão
ini_set('session.auto_start', '0');

// 5. Garantir que não há redirects automáticos
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 6. Definir headers corretos IMEDIATAMENTE (antes de qualquer include)
http_response_code(200);
header('Content-Type: application/json', true);
header('Cache-Control: no-cache, no-store, must-revalidate', true);
header('X-Content-Type-Options: nosniff', true);
header('Access-Control-Allow-Origin: *', true);
header('Access-Control-Allow-Methods: POST', true);

// 7. Verificar se está sendo acessado via router (logar mas NÃO recusar)
// IMPORTANTE: Asaas exige HTTP 200, então mesmo se acessado via router, retornar 200
if (isset($_GET['page']) || strpos($_SERVER['REQUEST_URI'] ?? '', 'index.php') !== false) {
    // Logar aviso mas continuar processamento (não pode retornar 400)
    error_log("⚠️ Webhook acessado via router (não recomendado) - mas processando mesmo assim");
}

// 8. Verificar método HTTP ANTES de incluir arquivos
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // IMPORTANTE: Asaas exige HTTP 200 sempre
    // Retornar APENAS HTTP 200 - sem corpo (Asaas só verifica o código HTTP)
    http_response_code(200);
    exit;
}

// 9. Definir função de log ANTES de incluir arquivos (para garantir que sempre funcione)
function logWebhook($data) {
    $log_file = __DIR__ . '/logs/asaas_webhook.log';
    if (!is_dir(dirname($log_file))) {
        @mkdir(dirname($log_file), 0755, true);
    }
    $log = date('Y-m-d H:i:s') . " - " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($log_file, $log, FILE_APPEND | LOCK_EX);
}

// Log imediato de que o webhook foi chamado
logWebhook(['webhook_called' => true, 'request_uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN', 'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN']);

// 10. Incluir arquivos necessários (mas NUNCA incluir index.php ou router)
// NUNCA incluir arquivos que façam verificação de autenticação
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// ============================================
// HEADERS JÁ FORAM DEFINIDOS NO INÍCIO DO ARQUIVO
// NÃO redefinir aqui para evitar conflitos
// ============================================

// Obter dados do webhook
// IMPORTANTE: Asaas pode enviar como form data (application/x-www-form-urlencoded)
// ou como JSON bruto (application/json)
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
$input = file_get_contents('php://input');

// Log do INPUT BRUTO ANTES de tentar decodificar (para debug)
logWebhook([
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'content_type' => $content_type,
    'content_length' => strlen($input),
    'raw_input_preview' => substr($input, 0, 500), // Primeiros 500 caracteres
    'has_input' => !empty($input),
    'has_post_data' => !empty($_POST)
]);

// Se for form data (application/x-www-form-urlencoded), extrair o parâmetro 'data'
if (strpos($content_type, 'application/x-www-form-urlencoded') !== false) {
    // Asaas envia como form data com parâmetro 'data'
    if (isset($_POST['data'])) {
        $input = $_POST['data'];
        logWebhook(['extracted_from_form' => true, 'extracted_length' => strlen($input)]);
    } elseif (!empty($input) && strpos($input, 'data=') === 0) {
        // Se veio no raw input como 'data=...', extrair
        parse_str($input, $parsed);
        if (isset($parsed['data'])) {
            $input = $parsed['data'];
            logWebhook(['extracted_from_parsed' => true, 'extracted_length' => strlen($input)]);
        }
    }
}

// Tentar decodificar JSON
$webhook_data = json_decode($input, true);
$json_error = json_last_error();

// Verificar se JSON está vazio ou inválido
if (empty($input)) {
    // Input vazio - Asaas pode estar enviando requisição vazia (teste?)
    logWebhook([
        'warning' => 'Input vazio recebido',
        'content_length' => strlen($input),
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'UNKNOWN'
    ]);
    
    // Retornar APENAS HTTP 200 - sem corpo (Asaas só verifica o código HTTP)
    http_response_code(200);
    exit;
}

// Verificar erro de JSON (código 4 = JSON_ERROR_SYNTAX)
if ($json_error !== JSON_ERROR_NONE || $webhook_data === null || $webhook_data === false) {
    // Obter mensagem de erro ANTES de fazer qualquer outra operação que possa limpar o erro
    $error_msg = json_last_error_msg();
    
    // Log do erro de JSON detalhado - INCLUIR INPUT COMPLETO para debug
    logWebhook([
        'json_error_code' => $json_error,
        'json_error_name' => $json_error === 4 ? 'JSON_ERROR_SYNTAX' : 'UNKNOWN',
        'json_error_message' => $error_msg,
        'input_length' => strlen($input),
        'input_complete' => $input, // LOG COMPLETO para ver o problema
        'input_first_500' => substr($input, 0, 500),
        'input_last_200' => substr($input, -200),
        'is_null' => ($webhook_data === null),
        'is_false' => ($webhook_data === false),
        'decoded_result' => var_export($webhook_data, true)
    ]);
    
    // Tentar corrigir encoding comum (se houver problema de charset)
    $input_fixed = $input;
    if (!mb_check_encoding($input, 'UTF-8')) {
        $input_fixed = mb_convert_encoding($input, 'UTF-8', 'UTF-8');
        $webhook_data_fixed = json_decode($input_fixed, true);
        if ($webhook_data_fixed !== null) {
            logWebhook(['fixed' => 'Encoding corrigido com sucesso']);
            $webhook_data = $webhook_data_fixed;
            $json_error = JSON_ERROR_NONE;
        }
    }
    
    // Se ainda tiver erro após tentar corrigir
    if ($json_error !== JSON_ERROR_NONE || $webhook_data === null || $webhook_data === false) {
        // IMPORTANTE: Asaas exige HTTP 200 sempre, mesmo para erros
        // Logar erro mas retornar 200 para não pausar fila
        // CRÍTICO: Retornar APENAS HTTP 200
        // O Asaas só verifica o código HTTP (200), não o conteúdo JSON
        // Erro foi logado - retornar 200 para não pausar fila
        http_response_code(200);
        exit;
    }
}

// Log do webhook recebido e decodificado com sucesso
logWebhook([
    'webhook_received' => true,
    'event' => $webhook_data['event'] ?? 'UNKNOWN',
    'event_id' => $webhook_data['id'] ?? 'NO_ID',
    'has_payment' => isset($webhook_data['payment']),
    'has_checkout' => isset($webhook_data['checkout'])
]);

try {
    // IDEMPOTÊNCIA: Verificar se evento já foi processado
    // Conforme documentação: https://docs.asaas.com/docs/como-implementar-idempotencia-em-webhooks
    $event_id = $webhook_data['id'] ?? null;
    if ($event_id) {
        try {
            // Tentar inserir evento na tabela de eventos processados
            $stmt = $pdo->prepare("INSERT INTO asaas_webhook_events (asaas_event_id, event_type, payload, processed_at) VALUES (:event_id, :event_type, :payload, NOW())");
            $stmt->execute([
                ':event_id' => $event_id,
                ':event_type' => $webhook_data['event'] ?? 'UNKNOWN',
                ':payload' => $input
            ]);
        } catch (PDOException $e) {
            // Se erro for violação de constraint único (evento já processado)
            if ($e->getCode() == 23505 || strpos($e->getMessage(), 'duplicate key') !== false || strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                logWebhook("Evento já processado (idempotência): $event_id");
                // Retornar APENAS HTTP 200 - sem corpo (Asaas só verifica o código HTTP)
                http_response_code(200);
                exit;
            }
            // Se for outro erro, logar mas continuar processamento
            logWebhook("Erro ao inserir evento (não é duplicata): " . $e->getMessage());
        }
    }
    
    // Verificar tipo de evento
    $event = $webhook_data['event'] ?? '';
    
    // EVENTOS DE CHECKOUT (conforme documentação)
    if (in_array($event, ['CHECKOUT_CREATED', 'CHECKOUT_CANCELED', 'CHECKOUT_EXPIRED', 'CHECKOUT_PAID'])) {
        $checkout_id = $webhook_data['checkout']['id'] ?? '';
        
        if (!$checkout_id) {
            throw new Exception('Checkout ID not found');
        }
        
        logWebhook("Processando evento de Checkout: $event - Checkout ID: $checkout_id");
        
        // Buscar inscrição pelo checkout_id do Asaas
        $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_checkout_id = :checkout_id");
        $stmt->execute([':checkout_id' => $checkout_id]);
        $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Se não encontrou por checkout_id, tentar por payment_id (retrocompatibilidade)
        if (!$inscricao) {
            $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_payment_id = :checkout_id");
            $stmt->execute([':checkout_id' => $checkout_id]);
            $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Processar eventos de checkout
        switch ($event) {
            case 'CHECKOUT_PAID':
                // Checkout pago
                if ($inscricao) {
                    $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'pago' WHERE id = :id");
                    $stmt->execute([':id' => $inscricao['id']]);
                    
                    // Buscar dados da degustação
                    $stmt_deg = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :degustacao_id");
                    $stmt_deg->execute([':degustacao_id' => $inscricao['degustacao_id']]);
                    $degustacao = $stmt_deg->fetch(PDO::FETCH_ASSOC);
                    
                    // Enviar e-mail de confirmação
                    if ($degustacao && class_exists('ComercialEmailHelper')) {
                        require_once __DIR__ . '/comercial_email_helper.php';
                        $emailHelper = new ComercialEmailHelper();
                        $emailHelper->sendInscricaoConfirmation($inscricao, $degustacao);
                    }
                    
                    logWebhook("Checkout pago confirmado para inscrição ID: " . $inscricao['id']);
                } else {
                    logWebhook("Checkout pago mas inscrição não encontrada: checkout_id=$checkout_id");
                }
                break;
                
            case 'CHECKOUT_CANCELED':
                // Checkout cancelado
                if ($inscricao) {
                    $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'cancelado' WHERE id = :id");
                    $stmt->execute([':id' => $inscricao['id']]);
                    logWebhook("Checkout cancelado para inscrição ID: " . $inscricao['id']);
                }
                break;
                
            case 'CHECKOUT_EXPIRED':
                // Checkout expirado
                if ($inscricao) {
                    $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'expirado' WHERE id = :id");
                    $stmt->execute([':id' => $inscricao['id']]);
                    logWebhook("Checkout expirado para inscrição ID: " . $inscricao['id']);
                }
                break;
                
            case 'CHECKOUT_CREATED':
                // Checkout criado (apenas log)
                logWebhook("Checkout criado: checkout_id=$checkout_id");
                break;
        }
        
        // CRÍTICO: Asaas só aceita HTTP 200 como sucesso
        // Limpar qualquer output anterior
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Remover TODOS os headers
        header_remove();
        
        // Retornar APENAS HTTP 200 - sem corpo (Asaas só verifica o código HTTP)
        http_response_code(200);
        
        logWebhook("✅ Checkout webhook processado com sucesso - Evento: $event");
        exit;
    }
    
    // EVENTOS DE PAGAMENTO (modo antigo - retrocompatibilidade + QR Code estático)
    $payment_id = $webhook_data['payment']['id'] ?? '';
    $pix_qr_code_id = $webhook_data['payment']['pixQrCodeId'] ?? null; // ID do QR Code estático
    
    if ($payment_id) {
        logWebhook("Processando evento de Pagamento: $event - Payment ID: $payment_id" . ($pix_qr_code_id ? " - QR Code ID: $pix_qr_code_id" : ""));
        
        $inscricao = null;
        
        // PRIORIDADE 1: Buscar por QR Code estático (se veio de QR Code)
        if ($pix_qr_code_id) {
            try {
                // Verificar se coluna existe
                $check_col = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'comercial_inscricoes' AND column_name = 'asaas_qr_code_id'");
                if ($check_col->rowCount() > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_qr_code_id = :qr_code_id");
                    $stmt->execute([':qr_code_id' => $pix_qr_code_id]);
                    $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($inscricao) {
                        logWebhook("Inscrição encontrada via QR Code estático: ID=" . $inscricao['id']);
                        
                        // Salvar também o payment_id para referência futura
                        try {
                            $check_payment_col = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'comercial_inscricoes' AND column_name = 'asaas_payment_id'");
                            if ($check_payment_col->rowCount() > 0) {
                                $stmt_update = $pdo->prepare("UPDATE comercial_inscricoes SET asaas_payment_id = :payment_id WHERE id = :id");
                                $stmt_update->execute([':payment_id' => $payment_id, ':id' => $inscricao['id']]);
                            }
                        } catch (Exception $e) {
                            logWebhook("Erro ao salvar payment_id: " . $e->getMessage());
                        }
                    }
                }
            } catch (Exception $e) {
                logWebhook("Erro ao buscar por QR Code: " . $e->getMessage());
            }
        }
        
        // PRIORIDADE 2: Buscar inscrição pelo payment_id do ASAAS (se não encontrou por QR Code)
        if (!$inscricao) {
    $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_payment_id = :payment_id");
    $stmt->execute([':payment_id' => $payment_id]);
    $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inscricao) {
                logWebhook("Inscrição encontrada via payment_id: ID=" . $inscricao['id']);
            }
        }
    
    if (!$inscricao) {
            // IMPORTANTE: Logar aviso mas RETORNAR SUCESSO para não pausar fila
            // O pagamento foi recebido pelo Asaas - isso é um sucesso!
            // Apenas não temos inscrição vinculada (pode ser QR Code de teste)
            logWebhook([
                'info' => 'Pagamento recebido mas inscrição não encontrada',
                'payment_id' => $payment_id,
                'pix_qr_code_id' => $pix_qr_code_id,
                'note' => 'Este pagamento pode ter sido feito via QR Code de teste (que não cria inscrição no banco)',
                'action' => 'Returning success to avoid queue pause'
            ]);
            
            // CRÍTICO: Retornar APENAS HTTP 200
            // O Asaas só verifica o código HTTP (200), não o conteúdo JSON
            // Pagamento foi recebido - isso é sucesso, mesmo sem inscrição vinculada
            http_response_code(200);
            exit;
    }
    
    // Buscar dados da degustação
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :degustacao_id");
        $stmt->execute([':degustacao_id' => $inscricao['degustacao_id']]);
    $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$degustacao) {
        throw new Exception("Degustação não encontrada");
    }
    
        // Processar diferentes tipos de eventos de pagamento
    switch ($event) {
        case 'PAYMENT_CONFIRMED':
        case 'PAYMENT_RECEIVED':
            // Pagamento confirmado
            $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'pago' WHERE id = :id");
            $stmt->execute([':id' => $inscricao['id']]);
            
            // Enviar e-mail de confirmação
            if (class_exists('ComercialEmailHelper')) {
                require_once __DIR__ . '/comercial_email_helper.php';
                $emailHelper = new ComercialEmailHelper();
                $emailHelper->sendInscricaoConfirmation($inscricao, $degustacao);
            }
            
            logWebhook("Pagamento confirmado para inscrição ID: " . $inscricao['id']);
            break;
            
        case 'PAYMENT_OVERDUE':
            // Pagamento vencido
            $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'expirado' WHERE id = :id");
            $stmt->execute([':id' => $inscricao['id']]);
            
            logWebhook("Pagamento vencido para inscrição ID: " . $inscricao['id']);
            break;
            
        case 'PAYMENT_DELETED':
            // Pagamento deletado
            $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'expirado' WHERE id = :id");
            $stmt->execute([':id' => $inscricao['id']]);
            
            logWebhook("Pagamento deletado para inscrição ID: " . $inscricao['id']);
            break;
            
        default:
                logWebhook("Evento de pagamento não tratado: $event");
            break;
        }
        
        // CRÍTICO: Asaas só aceita HTTP 200 como sucesso
        // Qualquer outro código (3xx, 4xx, 5xx) pausa a fila após 15 tentativas
        // GARANTIR que retorna EXATAMENTE 200
        
        // Limpar qualquer output anterior
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Remover TODOS os headers que podem causar redirect ou problema
        header_remove();
        
        // Definir headers corretos - APENAS HTTP 200
        http_response_code(200); // CRÍTICO: Deve ser exatamente 200
        
        // Log de sucesso
        logWebhook("✅ Webhook processado com sucesso - Evento: $event, Inscrição ID: " . ($inscricao['id'] ?? 'N/A'));
        
        // Retornar APENAS HTTP 200 - sem corpo JSON (Asaas só precisa do código)
        exit;
    }
    
    // Evento não reconhecido - MAS RETORNAR 200 para não pausar fila
    // Asaas só aceita 200 como sucesso, qualquer outro código pausa a fila
    logWebhook("⚠️ Evento não reconhecido mas retornando 200: $event");
    
    // Limpar output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header_remove();
    
    // Retornar APENAS HTTP 200 - sem corpo (Asaas só verifica o código HTTP)
    http_response_code(200);
    
} catch (Exception $e) {
    // IMPORTANTE: Mesmo em caso de erro, retornar 200 para não pausar a fila
    // O erro será logado, mas não deve quebrar o webhook
    logWebhook("❌ Erro no webhook: " . $e->getMessage() . " | Stack: " . $e->getTraceAsString());
    
    // Limpar output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header_remove();
    
    // Retornar APENAS HTTP 200 - sem corpo (Asaas só verifica o código HTTP)
    http_response_code(200);
}
