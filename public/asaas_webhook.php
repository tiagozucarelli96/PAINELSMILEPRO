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

// 6. Definir HTTP 200 IMEDIATAMENTE (antes de qualquer include)
// CRÍTICO: Asaas só precisa do código HTTP 200, nada mais
// NÃO definir headers, NÃO definir Content-Type, APENAS o código HTTP
http_response_code(200);

// 7. Verificar se está sendo acessado via router (logar mas NÃO recusar)
// IMPORTANTE: Asaas exige HTTP 200, então mesmo se acessado via router, retornar 200
if (isset($_GET['page']) || strpos($_SERVER['REQUEST_URI'] ?? '', 'index.php') !== false) {
    // Logar aviso mas continuar processamento (não pode retornar 400)
    error_log("⚠️ Webhook acessado via router (não recomendado) - mas processando mesmo assim");
}

// 8. Verificar método HTTP ANTES de incluir arquivos
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // IMPORTANTE: Asaas exige HTTP 200 sempre
    // Garantir que resposta é enviada
    http_response_code(200);
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
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
    
    // Garantir HTTP 200 e enviar resposta
    http_response_code(200);
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
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
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
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
                // Retornar APENAS HTTP 200
                http_response_code(200);
                if (ob_get_level()) {
                    ob_end_flush();
                }
                flush();
                exit;
            }
            // Se erro for "tabela não existe" (42P01), tentar criar a tabela automaticamente
            // Verificar pelo código SQLSTATE (string) ou pela mensagem de erro
            $error_code = $e->getCode();
            $error_msg = $e->getMessage();
            if ($error_code == '42P01' || $error_code == 42703 || strpos($error_msg, 'does not exist') !== false || strpos($error_msg, 'relation') !== false || strpos($error_msg, 'undefined table') !== false) {
                try {
                    // Tentar criar a tabela
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS asaas_webhook_events (
                            id SERIAL PRIMARY KEY,
                            asaas_event_id TEXT UNIQUE NOT NULL,
                            event_type VARCHAR(100) NOT NULL,
                            payload JSONB NOT NULL,
                            processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
                            created_at TIMESTAMP NOT NULL DEFAULT NOW()
                        );
                    ");
                    // Criar índices
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_asaas_webhook_events_event_id ON asaas_webhook_events(asaas_event_id);");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_asaas_webhook_events_event_type ON asaas_webhook_events(event_type);");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_asaas_webhook_events_processed_at ON asaas_webhook_events(processed_at);");
                    logWebhook("✅ Tabela asaas_webhook_events criada automaticamente");
                    
                    // Tentar inserir novamente
                    $stmt = $pdo->prepare("INSERT INTO asaas_webhook_events (asaas_event_id, event_type, payload, processed_at) VALUES (:event_id, :event_type, :payload, NOW())");
                    $stmt->execute([
                        ':event_id' => $event_id,
                        ':event_type' => $webhook_data['event'] ?? 'UNKNOWN',
                        ':payload' => $input
                    ]);
                } catch (PDOException $e2) {
                    // Se não conseguir criar, logar mas continuar (não é crítico para o processamento)
                    logWebhook("⚠️ Não foi possível criar tabela asaas_webhook_events automaticamente: " . $e2->getMessage() . " - Acesse index.php?page=create_asaas_webhook_table para criar manualmente");
                }
            } else {
                // Se for outro erro, logar mas continuar processamento
                logWebhook("Erro ao inserir evento (não é duplicata): " . $e->getMessage());
            }
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
        
        // Remover TODOS os headers desnecessários
        header_remove();
        
        // Garantir HTTP 200 e enviar resposta imediatamente
        http_response_code(200);
        
        // Garantir que output é enviado (mesmo vazio)
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
        
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
            if (ob_get_level()) {
                ob_end_flush();
            }
            flush();
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
            
            // CRÍTICO: Verificar se há valores pendentes de "adicionar pessoa" e aplicá-los
            try {
                // Verificar se colunas de pendentes existem
                $check_pending = $pdo->query("
                    SELECT column_name 
                    FROM information_schema.columns 
                    WHERE table_name = 'comercial_inscricoes' 
                    AND column_name IN ('qtd_pessoas_pendente', 'valor_adicional_pendente', 'qr_code_adicional_id')
                ");
                $pending_cols = $check_pending->fetchAll(PDO::FETCH_COLUMN);
                
                if (in_array('qr_code_adicional_id', $pending_cols) && $pix_qr_code_id) {
                    // Verificar se este QR Code é um adicional pendente
                    $stmt_check = $pdo->prepare("
                        SELECT qtd_pessoas_pendente, valor_adicional_pendente, qr_code_adicional_id 
                        FROM comercial_inscricoes 
                        WHERE id = :id AND qr_code_adicional_id = :qr_code_id
                        AND qtd_pessoas_pendente IS NOT NULL
                    ");
                    $stmt_check->execute([
                        ':id' => $inscricao['id'],
                        ':qr_code_id' => $pix_qr_code_id
                    ]);
                    $pendente_data = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($pendente_data) {
                        // Este é um pagamento de adicional pendente - aplicar os valores
                        $qtd_pendente = (int)($pendente_data['qtd_pessoas_pendente'] ?? 0);
                        $valor_pendente = (float)($pendente_data['valor_adicional_pendente'] ?? 0);
                        
                        if ($qtd_pendente > 0 || $valor_pendente > 0) {
                            logWebhook("Aplicando valores pendentes: qtd={$qtd_pendente}, valor={$valor_pendente} para inscrição ID: " . $inscricao['id']);
                            
                            // Buscar valores atuais
                            $check_valor = $pdo->query("
                                SELECT column_name 
                                FROM information_schema.columns 
                                WHERE table_name = 'comercial_inscricoes' 
                                AND column_name IN ('qtd_pessoas', 'valor_total', 'valor_pago')
                            ");
                            $valor_cols = $check_valor->fetchAll(PDO::FETCH_COLUMN);
                            
                            // Atualizar quantidade de pessoas
                            $stmt_qtd = $pdo->prepare("
                                UPDATE comercial_inscricoes 
                                SET qtd_pessoas = qtd_pessoas + :qtd 
                                WHERE id = :id
                            ");
                            $stmt_qtd->execute([':qtd' => $qtd_pendente, ':id' => $inscricao['id']]);
                            
                            // Atualizar valor
                            if (in_array('valor_total', $valor_cols)) {
                                $stmt_valor = $pdo->prepare("
                                    UPDATE comercial_inscricoes 
                                    SET valor_total = valor_total + :valor 
                                    WHERE id = :id
                                ");
                                $stmt_valor->execute([':valor' => $valor_pendente, ':id' => $inscricao['id']]);
                            } elseif (in_array('valor_pago', $valor_cols)) {
                                $stmt_valor = $pdo->prepare("
                                    UPDATE comercial_inscricoes 
                                    SET valor_pago = valor_pago + :valor 
                                    WHERE id = :id
                                ");
                                $stmt_valor->execute([':valor' => $valor_pendente, ':id' => $inscricao['id']]);
                            }
                            
                            // Limpar campos pendentes
                            $stmt_clear = $pdo->prepare("
                                UPDATE comercial_inscricoes 
                                SET qtd_pessoas_pendente = NULL,
                                    valor_adicional_pendente = NULL,
                                    qr_code_adicional_id = NULL,
                                    qr_code_adicional_expira_em = NULL
                                WHERE id = :id
                            ");
                            $stmt_clear->execute([':id' => $inscricao['id']]);
                            
                            logWebhook("✅ Valores pendentes aplicados com sucesso para inscrição ID: " . $inscricao['id']);
                        }
                    }
                }
            } catch (Exception $e) {
                logWebhook("Erro ao processar valores pendentes: " . $e->getMessage());
                // Não falhar o webhook por causa disso
            }
            
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
            
            // Limpar valores pendentes se este QR Code for adicional pendente
            try {
                $check_pending = $pdo->query("
                    SELECT column_name 
                    FROM information_schema.columns 
                    WHERE table_name = 'comercial_inscricoes' 
                    AND column_name = 'qr_code_adicional_id'
                ");
                if ($check_pending->rowCount() > 0 && $pix_qr_code_id) {
                    $stmt_clear = $pdo->prepare("
                        UPDATE comercial_inscricoes 
                        SET qtd_pessoas_pendente = NULL,
                            valor_adicional_pendente = NULL,
                            qr_code_adicional_id = NULL,
                            qr_code_adicional_expira_em = NULL
                        WHERE id = :id AND qr_code_adicional_id = :qr_code_id
                    ");
                    $stmt_clear->execute([':id' => $inscricao['id'], ':qr_code_id' => $pix_qr_code_id]);
                    
                    if ($stmt_clear->rowCount() > 0) {
                        logWebhook("Valores pendentes cancelados (QR Code expirado) para inscrição ID: " . $inscricao['id']);
                    }
                }
            } catch (Exception $e) {
                logWebhook("Erro ao limpar valores pendentes expirados: " . $e->getMessage());
            }
            
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
        
        // Remover TODOS os headers desnecessários
        header_remove();
        
        // Garantir HTTP 200 e enviar resposta imediatamente
        http_response_code(200); // CRÍTICO: Deve ser exatamente 200
        
        // Garantir que output é enviado (mesmo vazio)
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
        
        // Log de sucesso (após enviar resposta)
        logWebhook("✅ Webhook processado com sucesso - Evento: $event, Inscrição ID: " . ($inscricao['id'] ?? 'N/A'));
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
    
    // Garantir HTTP 200 e enviar resposta imediatamente
    http_response_code(200);
    
    // Garantir que output é enviado
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
    
} catch (Exception $e) {
    // IMPORTANTE: Mesmo em caso de erro, retornar 200 para não pausar a fila
    // O erro será logado, mas não deve quebrar o webhook
    logWebhook("❌ Erro no webhook: " . $e->getMessage() . " | Stack: " . $e->getTraceAsString());
    
    // Limpar output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header_remove();
    
    // Garantir HTTP 200 e enviar resposta imediatamente
    http_response_code(200);
    
    // Garantir que output é enviado
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
}
