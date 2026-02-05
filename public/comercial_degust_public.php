<?php
// comercial_degust_public.php — Página pública de inscrição
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/asaas_helper.php';

$token = $_GET['t'] ?? '';
if (!$token) {
    die('Token de acesso inválido');
}

// Buscar degustação pelo token
$stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE token_publico = :token");
$stmt->execute([':token' => $token]);
$degustacao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$degustacao) {
    die('Degustação não encontrada');
}

// Verificar se está publicada
if ($degustacao['status'] !== 'publicado') {
    die('Degustação não está disponível para inscrições');
}

// Verificar data limite - bloquear ao final do dia (23:59:59)
$hoje = date('Y-m-d');
$agora = new DateTime();
$data_limite = new DateTime($degustacao['data_limite'] . ' 23:59:59');

if ($agora > $data_limite) {
    $inscricoes_encerradas = true;
} else {
    $inscricoes_encerradas = false;
}

// Verificar capacidade (IMPORTANTE: contar apenas confirmados, não lista_espera)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = :id AND status = 'confirmado'");
$stmt->execute([':id' => $degustacao['id']]);
$inscritos_count = $stmt->fetchColumn();

// Degustação está lotada quando confirmados >= capacidade
$lotado = $inscritos_count >= $degustacao['capacidade'];
// Aceita lista de espera APENAS se degustação aceita E está lotada
$aceita_lista_espera = ($degustacao['lista_espera'] ?? false) && $lotado;

// Processar inscrição
$success_message = '';
$error_message = '';

// Verificar pagamento via AJAX se solicitado (ANTES de qualquer HTML)
if (isset($_GET['verificar_pagamento']) && isset($_GET['inscricao_id'])) {
    // Headers para evitar cache do navegador e servidor
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    require_once __DIR__ . '/conexao.php';
    $check_id = (int)$_GET['inscricao_id'];
    
    // Verificar quais colunas de valor existem
    $check_valor_cols = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name IN ('valor_total', 'valor_pago')
    ");
    $valor_columns = $check_valor_cols->fetchAll(PDO::FETCH_COLUMN);
    $has_valor_total = in_array('valor_total', $valor_columns);
    $has_valor_pago = in_array('valor_pago', $valor_columns);
    
    // Montar expressão de valor dinamicamente
    if ($has_valor_total && $has_valor_pago) {
        $valor_expr = "COALESCE(valor_total, valor_pago, 0) as valor_pago";
    } elseif ($has_valor_total) {
        $valor_expr = "COALESCE(valor_total, 0) as valor_pago";
    } elseif ($has_valor_pago) {
        $valor_expr = "COALESCE(valor_pago, 0) as valor_pago";
    } else {
        $valor_expr = "0 as valor_pago";
    }
    
    // Buscar status de pagamento e valor (mesma lógica da página de inscritos)
    $stmt = $pdo->prepare("
        SELECT 
            pagamento_status,
            $valor_expr
        FROM comercial_inscricoes 
        WHERE id = :id
    ");
    $stmt->execute([':id' => $check_id]);
    $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inscricao) {
        echo json_encode([
            'status' => 'erro',
            'message' => 'Inscrição não encontrada',
            'inscricao_id' => $check_id
        ]);
        exit;
    }
    
    // Converter status para texto legível (mesma lógica da página de inscritos)
    $status_text = match($inscricao['pagamento_status']) {
        'pago' => 'Pago',
        'aguardando' => 'Aguardando',
        'expirado' => 'Expirado',
        'cancelado' => 'Cancelado',
        default => 'N/A'
    };
    
    echo json_encode([
        'status' => $inscricao['pagamento_status'] ?? 'aguardando',
        'status_text' => $status_text,
        'valor_pago' => (float)($inscricao['valor_pago'] ?? 0),
        'pago' => ($inscricao['pagamento_status'] === 'pago'),
        'inscricao_id' => $check_id
    ]);
    exit;
}

if ($_POST && !$inscricoes_encerradas) {
    try {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $tipo_festa = $_POST['tipo_festa'] ?? '';
        $qtd_pessoas = (int)($_POST['qtd_pessoas'] ?? 1);
        $fechou_contrato = $_POST['fechou_contrato'] ?? 'nao';
        $nome_titular_contrato = trim($_POST['nome_titular_contrato'] ?? '');
        $cpf_3_digitos = trim($_POST['cpf_3_digitos'] ?? '');
        
        // Respostas do formulário
        $dados_json = [];
        $campos = json_decode($degustacao['campos_json'], true) ?: [];
        foreach ($campos as $campo) {
            $value = $_POST[$campo['name']] ?? '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $dados_json[$campo['name']] = $value;
        }
        
        // Validar campos obrigatórios
        if (!$nome || !$email || !$tipo_festa || !$qtd_pessoas) {
            throw new Exception("Preencha todos os campos obrigatórios");
        }
        
        // Usar valores calculados pelo JavaScript ou calcular no servidor
        $valor_total = (float)($_POST['valor_total'] ?? 0);
        $extras = (int)($_POST['extras'] ?? 0);
        
        if ($valor_total <= 0) {
            // Fallback: calcular no servidor se não veio do JavaScript
            $incluidos = $tipo_festa === 'casamento' ? $degustacao['incluidos_casamento'] : $degustacao['incluidos_15anos'];
            $extras = max(0, $qtd_pessoas - $incluidos);
            $preco_base = $tipo_festa === 'casamento' ? $degustacao['preco_casamento'] : $degustacao['preco_15anos'];
            $valor_total = $preco_base + ($extras * $degustacao['preco_extra']);
        }
        
        // Determinar status baseado na capacidade (verificar novamente ANTES de inserir para evitar condições de corrida)
        // IMPORTANTE: Re-verificar capacidade aqui porque pode ter mudado entre a primeira verificação e o INSERT
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = :id AND status = 'confirmado'");
        $stmt->execute([':id' => $degustacao['id']]);
        $inscritos_atual = $stmt->fetchColumn();
        
        $lotado_atual = $inscritos_atual >= $degustacao['capacidade'];
        $aceita_lista_espera_atual = ($degustacao['lista_espera'] ?? false) && $lotado_atual;
        
        $status = 'confirmado';
        if ($lotado_atual && !$aceita_lista_espera_atual) {
            throw new Exception("Degustação lotada e não aceita lista de espera");
        } elseif ($lotado_atual && $aceita_lista_espera_atual) {
            $status = 'lista_espera';
        }
        
        // Verificar se já existe inscrição com este e-mail
        $stmt = $pdo->prepare("SELECT id FROM comercial_inscricoes WHERE degustacao_id = :degustacao_id AND email = :email");
        $stmt->execute([':degustacao_id' => $degustacao['id'], ':email' => $email]);
        if ($stmt->fetch()) {
            throw new Exception("Já existe uma inscrição com este e-mail para esta degustação");
        }
        
        // Verificar se colunas existem antes de inserir
        try {
            $check_stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                                       WHERE table_name = 'comercial_inscricoes' 
                                       AND column_name IN ('nome_titular_contrato', 'cpf_3_digitos', 'me_event_id', 'me_cliente_cpf')");
            $check_columns = $check_stmt->fetchAll(PDO::FETCH_COLUMN);
            $has_nome_titular = in_array('nome_titular_contrato', $check_columns);
            $has_cpf_3 = in_array('cpf_3_digitos', $check_columns);
            $has_me_event_id = in_array('me_event_id', $check_columns);
            $has_me_cpf = in_array('me_cliente_cpf', $check_columns);
        } catch (PDOException $e) {
            $has_nome_titular = false;
            $has_cpf_3 = false;
            $has_me_event_id = false;
            $has_me_cpf = false;
        }
        
        $cpf_3_digitos = substr(preg_replace('/\D/', '', $_POST['me_cliente_cpf'] ?? ''), 0, 3);
        $me_cliente_cpf = preg_replace('/\D/', '', $_POST['me_cliente_cpf'] ?? '');
        $me_event_id = (int)($_POST['me_event_id'] ?? 0);
        
        // Montar SQL dinamicamente baseado nas colunas existentes
        // Verificar quais colunas existem na tabela
        try {
            $check_all_cols = $pdo->query("SELECT column_name FROM information_schema.columns 
                                           WHERE table_name = 'comercial_inscricoes'");
            $existing_columns = $check_all_cols->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $existing_columns = [];
        }
        
        // Verificar se coluna é telefone ou celular
        $telefone_col = in_array('telefone', $existing_columns) ? 'telefone' : 
                       (in_array('celular', $existing_columns) ? 'celular' : 'telefone');
        
        $telefone = $_POST['telefone'] ?? $_POST['celular'] ?? '';
        
        // Campos obrigatórios (sempre existem)
        $campos = ['degustacao_id', 'status', 'fechou_contrato', 'nome', 'email', 'dados_json'];
        $valores = [':degustacao_id', ':status', ':fechou_contrato', ':nome', ':email', ':dados_json'];
        $params = [
            ':degustacao_id' => $degustacao['id'],
            ':status' => $status,
            ':fechou_contrato' => $fechou_contrato,
            ':nome' => $nome,
            ':email' => $email,
            ':dados_json' => json_encode($dados_json)
        ];
        
        // Campos opcionais - adicionar apenas se existirem na tabela
        if (in_array($telefone_col, $existing_columns)) {
            $campos[] = $telefone_col;
            $valores[] = ':telefone';
            $params[':telefone'] = $telefone;
        }
        
        if (in_array('qtd_pessoas', $existing_columns)) {
            $campos[] = 'qtd_pessoas';
            $valores[] = ':qtd_pessoas';
            $params[':qtd_pessoas'] = $qtd_pessoas;
        }
        
        if (in_array('tipo_festa', $existing_columns)) {
            $campos[] = 'tipo_festa';
            $valores[] = ':tipo_festa';
            $params[':tipo_festa'] = $tipo_festa;
        }
        
        if (in_array('extras', $existing_columns)) {
            $campos[] = 'extras';
            $valores[] = ':extras';
            $params[':extras'] = $extras;
        }
        
        if (in_array('pagamento_status', $existing_columns)) {
            $campos[] = 'pagamento_status';
            $valores[] = ':pagamento_status';
            $params[':pagamento_status'] = $fechou_contrato === 'sim' ? 'nao_aplicavel' : 'aguardando';
        }
        
        if (in_array('valor_pago', $existing_columns)) {
            $campos[] = 'valor_pago';
            $valores[] = ':valor_pago';
            $params[':valor_pago'] = $fechou_contrato === 'sim' ? 0 : $valor_total;
        }
        
        if (in_array('ip_origem', $existing_columns)) {
            $campos[] = 'ip_origem';
            $valores[] = ':ip_origem';
            $params[':ip_origem'] = $_SERVER['REMOTE_ADDR'] ?? null;
        }
        
        if (in_array('user_agent_origem', $existing_columns)) {
            $campos[] = 'user_agent_origem';
            $valores[] = ':user_agent_origem';
            $params[':user_agent_origem'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
        }
        
        // Adicionar colunas opcionais se existirem
        if ($has_nome_titular) {
            $campos[] = 'nome_titular_contrato';
            $valores[] = ':nome_titular_contrato';
            $params[':nome_titular_contrato'] = $nome_titular_contrato;
        }
        
        if ($has_cpf_3) {
            $campos[] = 'cpf_3_digitos';
            $valores[] = ':cpf_3_digitos';
            $params[':cpf_3_digitos'] = $cpf_3_digitos;
        }
        
        if ($has_me_event_id && $me_event_id > 0) {
            $campos[] = 'me_event_id';
            $valores[] = ':me_event_id';
            $params[':me_event_id'] = $me_event_id;
        }
        
        if ($has_me_cpf && !empty($me_cliente_cpf)) {
            $campos[] = 'me_cliente_cpf';
            $valores[] = ':me_cliente_cpf';
            $params[':me_cliente_cpf'] = $me_cliente_cpf;
        }
        
        // Montar SQL final
        $sql = "INSERT INTO comercial_inscricoes (" . implode(', ', $campos) . ") VALUES (" . implode(', ', $valores) . ")";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inscricao_id = $pdo->lastInsertId();
        
        // Enviar e-mail de confirmação via sistema global (Resend + sistema_email_config)
        try {
            require_once __DIR__ . '/comercial_email_helper.php';
            $emailHelper = new ComercialEmailHelper();
            $inscricao = [
                'nome' => $nome,
                'email' => $email,
                'celular' => $telefone,
                'qtd_pessoas' => $qtd_pessoas,
                'tipo_festa' => $tipo_festa,
            ];
            if ($status === 'lista_espera') {
                $emailHelper->sendListaEsperaNotification($inscricao, $degustacao);
            } else {
                $emailHelper->sendInscricaoConfirmation($inscricao, $degustacao);
            }
        } catch (Exception $e) {
            error_log("Degustação: falha ao enviar e-mail de confirmação (inscrição #{$inscricao_id}): " . $e->getMessage());
        }
        
        // Se não fechou contrato, processar pagamento via Asaas Checkout
        if ($fechou_contrato === 'nao' && $valor_total > 0) {
            try {
                $asaasHelper = new AsaasHelper();
                
                // URLs de redirecionamento
                $base_url = "https://{$_SERVER['HTTP_HOST']}";
                $current_url = $base_url . $_SERVER['REQUEST_URI'];
                
                // Descrição detalhada do item (máximo 37 caracteres conforme API Asaas)
                $incluidos = $tipo_festa === 'casamento' ? $degustacao['incluidos_casamento'] : $degustacao['incluidos_15anos'];
                $extras = max(0, $qtd_pessoas - $incluidos);
                
                // Montar descrição curta (API Asaas limita a 37 caracteres)
                $descricao_base = "Degustação: {$degustacao['nome']}";
                // Se a descrição for muito longa, encurtar
                if (mb_strlen($descricao_base) > 37) {
                    $descricao_item = mb_substr($descricao_base, 0, 34) . '...';
                } else {
                    $descricao_item = $descricao_base;
                    // Tentar adicionar tipo de festa se couber
                    $com_tipo = "{$descricao_item} - {$tipo_festa}";
                    if (mb_strlen($com_tipo) <= 37) {
                        $descricao_item = $com_tipo;
                    }
                }
                
                // ============================================
                // QR CODE ESTÁTICO (TESTE - MAIS PRÁTICO)
                // Exibe QR Code direto na página, cliente paga sem sair
                // ============================================
                
                // Obter chave PIX do Asaas (deve estar configurada em ASAAS_PIX_ADDRESS_KEY)
                require_once __DIR__ . '/config_env.php';
                $pix_address_key = $_ENV['ASAAS_PIX_ADDRESS_KEY'] ?? ASAAS_PIX_ADDRESS_KEY ?? '';
                
                if (empty($pix_address_key)) {
                    throw new Exception("Chave PIX não configurada. Configure ASAAS_PIX_ADDRESS_KEY no Railway.");
                }
                
                // Criar QR Code estático conforme modelo Asaas
                // Calcula data de expiração: data/hora atual + 1 hora
                $expiration_date = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Valor total já foi calculado anteriormente (base + extras)
                // $valor_total contém: preco_base + (extras * preco_extra)
                
                $qr_code_data = [
                    'addressKey' => $pix_address_key,
                    'description' => $descricao_item,
                    'value' => (float)$valor_total, // Valor total: base + adicional
                    'expirationDate' => $expiration_date, // Data/hora de expiração (1 hora a partir de agora)
                    'format' => 'IMAGE', // Solicitar formato de imagem (Base64)
                    'allowsMultiplePayments' => true // Permite múltiplos pagamentos (conforme modelo)
                    // NÃO enviar expirationSeconds quando usar expirationDate (API aceita apenas um)
                ];
                
                $qr_code_response = $asaasHelper->createStaticQrCode($qr_code_data);
                
                // Log completo da resposta para debug
                error_log("📋 Resposta completa do QR Code: " . json_encode($qr_code_response, JSON_PRETTY_PRINT));
                
                if ($qr_code_response && isset($qr_code_response['id'])) {
                    $qr_code_id = $qr_code_response['id'];
                    
                    // O Asaas pode retornar a imagem em diferentes campos
                    // Tentar: encodedImage, payload, ou image
                    $qr_code_payload = $qr_code_response['encodedImage'] 
                        ?? $qr_code_response['payload'] 
                        ?? $qr_code_response['image']
                        ?? '';
                    
                    error_log("✅ QR Code estático criado: $qr_code_id");
                    error_log("📷 Imagem encontrada: " . (!empty($qr_code_payload) ? 'SIM (' . strlen($qr_code_payload) . ' chars)' : 'NÃO'));
                    error_log("📋 Campos disponíveis: " . implode(', ', array_keys($qr_code_response)));
                    
                    // Verificar/criar colunas necessárias
                    try {
                        $check_columns = $pdo->query("
                            SELECT column_name 
                            FROM information_schema.columns 
                            WHERE table_name = 'comercial_inscricoes' 
                            AND column_name IN ('asaas_qr_code_id', 'qr_code_image')
                        ");
                        $existing_columns = $check_columns->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (!in_array('asaas_qr_code_id', $existing_columns)) {
                            $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN asaas_qr_code_id VARCHAR(255) NULL");
                        }
                        if (!in_array('qr_code_image', $existing_columns)) {
                            $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN qr_code_image TEXT NULL");
                        }
                    } catch (Exception $e) {
                        error_log("Erro ao verificar/criar colunas: " . $e->getMessage());
                    }
                    
                    // Salvar QR Code na inscrição
                    $update_fields = [
                        "pagamento_status = 'aguardando'",
                        "asaas_qr_code_id = :qr_code_id",
                        "qr_code_image = :qr_code_image"
                    ];
                    $update_params = [
                        ':id' => $inscricao_id,
                        ':qr_code_id' => $qr_code_id,
                        ':qr_code_image' => $qr_code_payload
                    ];
                    
                    $update_sql = "UPDATE comercial_inscricoes SET " . implode(', ', $update_fields) . " WHERE id = :id";
                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute($update_params);
                    
                    error_log("✅ Inscrição ID $inscricao_id atualizada com QR Code: $qr_code_id");
                    
                    // Armazenar dados do QR Code em variáveis para exibir na página
                    $_SESSION['qr_code_inscricao_id'] = $inscricao_id;
                    $_SESSION['qr_code_id'] = $qr_code_id;
                    $_SESSION['qr_code_image'] = $qr_code_payload;
                    $_SESSION['qr_code_value'] = $valor_total;
                    
                    // Redirecionar para mesma página com flag de QR Code
                    header("Location: " . $current_url . "&qr_code=1&inscricao_id=" . $inscricao_id);
                    exit;
                } else {
                    throw new Exception("Erro ao criar QR Code: resposta inválida do Asaas");
                }
                
                // ============================================
                // OPÇÃO 2: CHECKOUT (VERSÃO FUNCIONANDO - BACKUP)
                // Se QR Code der erro, descomentar e comentar opção acima
                // ============================================
                /*
                // Criar Checkout conforme documentação Asaas
                $checkout_data = [
                    'billingTypes' => ['PIX'],
                    'chargeTypes' => ['DETACHED'],
                    'callback' => [
                        'cancelUrl' => $current_url . '&cancelado=1',
                        'expiredUrl' => $current_url . '&expirado=1',
                        'successUrl' => $base_url . '/comercial_pagamento.php?inscricao_id=' . $inscricao_id
                    ],
                    'items' => [
                        [
                            'name' => "Degustação: {$degustacao['nome']}",
                            'description' => $descricao_item,
                            'quantity' => 1,
                            'value' => (float)$valor_total
                        ]
                    ],
                    'minutesToExpire' => 60,
                    'externalReference' => 'inscricao_' . $inscricao_id
                ];
                
                $checkout_response = $asaasHelper->createCheckout($checkout_data);
                
                if ($checkout_response && isset($checkout_response['id'])) {
                    $checkout_id_asaas = $checkout_response['id'];
                    
                    // Salvar checkout_id
                    try {
                        $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN IF NOT EXISTS asaas_checkout_id VARCHAR(255) NULL");
                    } catch (Exception $e) {}
                    
                    $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'aguardando', asaas_checkout_id = :checkout_id WHERE id = :id")
                        ->execute([':checkout_id' => $checkout_id_asaas, ':id' => $inscricao_id]);
                    
                    if (isset($checkout_response['checkoutUrl'])) {
                        header("Location: " . $checkout_response['checkoutUrl']);
                        exit;
                    }
                }
                */
                
            } catch (Exception $e) {
                $error_message = "Erro ao processar pagamento: " . $e->getMessage();
                error_log("Erro ao criar checkout Asaas: " . $e->getMessage());
            }
        }
        
        // Usar mensagem personalizada se configurada, senão usar mensagem padrão
        if (!empty($degustacao['msg_sucesso_html'])) {
            $success_message = $degustacao['msg_sucesso_html'];
        } else {
        $success_message = "Inscrição realizada com sucesso!";
        }
        
    } catch (Exception $e) {
        $error_message = "Erro: " . $e->getMessage();
    }
}

// Definir antes do HTML para decidir entre tela de confirmação ou formulário/QR
$show_qr_code = isset($_GET['qr_code']) && $_GET['qr_code'] == '1';
$qr_inscricao_id = (int)($_GET['inscricao_id'] ?? 0);
$mostrar_tela_confirmacao = !empty($success_message) && !($show_qr_code && $qr_inscricao_id > 0);

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <link rel="stylesheet" href="assets/css/custom_modals.css">
    <script src="assets/js/custom_modals.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($degustacao['nome']) ?> - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .public-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .event-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 12px;
        }
        
        .event-title {
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 10px 0;
        }
        
        .event-details {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .instructions {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .form-container {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
            background: white;
        }
        
        .form-radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .form-radio {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-radio input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: #3b82f6;
        }
        
        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .form-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #3b82f6;
        }
        
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .btn-submit:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fbbf24;
        }
        
        .price-info {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .price-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .price-total {
            font-weight: 700;
            font-size: 18px;
            border-top: 1px solid #0ea5e9;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .hidden {
            display: none;
        }
        
        /* Tela dedicada de confirmação (logo + mensagem configurada) */
        .confirmacao-tela {
            min-height: 80vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem 1.5rem;
        }
        .confirmacao-logo {
            margin-bottom: 2.5rem;
        }
        .confirmacao-logo-img {
            max-width: 220px;
            height: auto;
        }
        .confirmacao-logo-texto {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e3a8a;
            letter-spacing: -0.02em;
            margin: 0;
        }
        .confirmacao-mensagem {
            max-width: 560px;
            margin: 0 auto;
            padding: 2rem;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        .confirmacao-mensagem :first-child { margin-top: 0; }
        .confirmacao-mensagem :last-child { margin-bottom: 0; }
    </style>
</head>
<body>
    <div class="public-container">
        <?php if ($mostrar_tela_confirmacao): ?>
        <!-- Tela dedicada: sair da degustação e mostrar apenas logo + mensagem configurada -->
        <div class="confirmacao-tela">
            <div class="confirmacao-logo">
                <?php
                $logo_url = 'assets/logo_smile.png';
                $logo_path = __DIR__ . '/' . $logo_url;
                if (file_exists($logo_path)): ?>
                    <img src="<?= h($logo_url) ?>" alt="GRUPO Smile EVENTOS" class="confirmacao-logo-img">
                <?php else: ?>
                    <p class="confirmacao-logo-texto">GRUPO Smile EVENTOS</p>
                <?php endif; ?>
            </div>
            <div class="confirmacao-mensagem">
                <?php if (!empty($degustacao['msg_sucesso_html'])): ?>
                    <?= $degustacao['msg_sucesso_html'] ?>
                <?php else: ?>
                    <p style="margin: 0; font-size: 1.125rem; color: #374151; line-height: 1.6;">✅ <?= h($success_message) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <!-- Header do Evento -->
        <div class="event-header">
            <h1 class="event-title"><?= h($degustacao['nome']) ?></h1>
            <div class="event-details">
                📅 <?= date('d/m/Y', strtotime($degustacao['data'])) ?> 
                🕐 <?= date('H:i', strtotime($degustacao['hora_inicio'])) ?> - <?= date('H:i', strtotime($degustacao['hora_fim'])) ?>
                📍 <?= h($degustacao['local']) ?>
            </div>
        </div>
        
        <!-- Instruções -->
        <?php if ($degustacao['instrutivo_html']): ?>
        <div class="instructions">
            <?= $degustacao['instrutivo_html'] ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                ❌ <?= h($error_message) ?>
            </div>
        <?php endif; ?>
        
        <?php 
        // Exibir QR Code PIX se foi gerado
        $show_qr_code = isset($_GET['qr_code']) && $_GET['qr_code'] == '1';
        $qr_inscricao_id = (int)($_GET['inscricao_id'] ?? 0);
        
        if ($show_qr_code && $qr_inscricao_id > 0) {
            // Buscar dados do QR Code (da sessão ou do banco)
            $qr_code_image = $_SESSION['qr_code_image'] ?? '';
            $qr_code_id = $_SESSION['qr_code_id'] ?? '';
            $qr_code_value = $_SESSION['qr_code_value'] ?? 0;
            
            // Se não estiver na sessão, buscar do banco
            if (empty($qr_code_image)) {
                try {
                    // Verificar quais colunas de valor existem
                    $check_valor_cols = $pdo->query("
                        SELECT column_name 
                        FROM information_schema.columns 
                        WHERE table_name = 'comercial_inscricoes' 
                        AND column_name IN ('valor_total', 'valor_pago')
                    ");
                    $valor_columns = $check_valor_cols->fetchAll(PDO::FETCH_COLUMN);
                    $has_valor_total = in_array('valor_total', $valor_columns);
                    $has_valor_pago = in_array('valor_pago', $valor_columns);
                    
                    // Montar expressão de valor dinamicamente
                    if ($has_valor_total && $has_valor_pago) {
                        $valor_expr = "COALESCE(valor_total, valor_pago, 0) as valor_total";
                    } elseif ($has_valor_total) {
                        $valor_expr = "COALESCE(valor_total, 0) as valor_total";
                    } elseif ($has_valor_pago) {
                        $valor_expr = "COALESCE(valor_pago, 0) as valor_total";
                    } else {
                        $valor_expr = "0 as valor_total";
                    }
                    
                    // Buscar também pagamento_status para verificar se já foi pago
                    $stmt = $pdo->prepare("
                        SELECT 
                            qr_code_image, 
                            asaas_qr_code_id, 
                            $valor_expr,
                            pagamento_status
                        FROM comercial_inscricoes 
                        WHERE id = :id
                    ");
                    $stmt->execute([':id' => $qr_inscricao_id]);
                    $qr_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($qr_data) {
                        $qr_code_image = $qr_data['qr_code_image'] ?? '';
                        $qr_code_id = $qr_data['asaas_qr_code_id'] ?? '';
                        $qr_code_value = $qr_data['valor_total'] ?? 0;
                        $pagamento_status = $qr_data['pagamento_status'] ?? 'aguardando';
                    } else {
                        $pagamento_status = 'aguardando';
                    }
                } catch (Exception $e) {
                    error_log("Erro ao buscar QR Code: " . $e->getMessage());
                    $pagamento_status = 'aguardando';
                }
            } else {
                // Se está na sessão, SEMPRE buscar status atualizado do banco para garantir dados mais recentes
                try {
                    $stmt = $pdo->prepare("SELECT pagamento_status FROM comercial_inscricoes WHERE id = :id");
                    $stmt->execute([':id' => $qr_inscricao_id]);
                    $status_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    $pagamento_status = $status_data['pagamento_status'] ?? 'aguardando';
                } catch (Exception $e) {
                    error_log("Erro ao buscar status do pagamento: " . $e->getMessage());
                    $pagamento_status = 'aguardando';
                }
            }
            
            // SEMPRE verificar status novamente para garantir que está atualizado (mesma lógica da página de inscritos)
            try {
                $stmt_check = $pdo->prepare("SELECT pagamento_status FROM comercial_inscricoes WHERE id = :id");
                $stmt_check->execute([':id' => $qr_inscricao_id]);
                $status_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                if ($status_check) {
                    $pagamento_status = $status_check['pagamento_status'] ?? $pagamento_status;
                }
            } catch (Exception $e) {
                // Se der erro, manter status anterior
                error_log("Erro na verificação final de status: " . $e->getMessage());
            }
            
            // Se já foi pago, não mostrar QR Code, mostrar confirmação
            if ($pagamento_status === 'pago') {
                // Buscar valor pago para exibir
                try {
                    // Verificar quais colunas de valor existem
                    $check_valor_cols = $pdo->query("
                        SELECT column_name 
                        FROM information_schema.columns 
                        WHERE table_name = 'comercial_inscricoes' 
                        AND column_name IN ('valor_total', 'valor_pago')
                    ");
                    $valor_columns = $check_valor_cols->fetchAll(PDO::FETCH_COLUMN);
                    $has_valor_total = in_array('valor_total', $valor_columns);
                    $has_valor_pago = in_array('valor_pago', $valor_columns);
                    
                    // Montar expressão de valor dinamicamente
                    if ($has_valor_total && $has_valor_pago) {
                        $valor_expr = "COALESCE(valor_total, valor_pago, 0) as valor_pago";
                    } elseif ($has_valor_total) {
                        $valor_expr = "COALESCE(valor_total, 0) as valor_pago";
                    } elseif ($has_valor_pago) {
                        $valor_expr = "COALESCE(valor_pago, 0) as valor_pago";
                    } else {
                        $valor_expr = "0 as valor_pago";
                    }
                    
                    $stmt = $pdo->prepare("SELECT $valor_expr FROM comercial_inscricoes WHERE id = :id");
                    $stmt->execute([':id' => $qr_inscricao_id]);
                    $valor_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    $valor_pago = $valor_data['valor_pago'] ?? $qr_code_value;
                } catch (Exception $e) {
                    $valor_pago = $qr_code_value;
                }
            }
            
            // Se pagamento já foi confirmado, mostrar mensagem de sucesso
            if ($pagamento_status === 'pago'): ?>
                <div class="qr-code-container" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: 3px solid #10b981; border-radius: 12px; padding: 40px; margin: 30px 0; text-align: center; box-shadow: 0 8px 16px rgba(16, 185, 129, 0.3);">
                    <div style="font-size: 64px; margin-bottom: 20px;">✅</div>
                    <h2 style="color: white; margin-bottom: 15px; font-size: 28px;">🎉 Pagamento Confirmado!</h2>
                    <p style="color: rgba(255, 255, 255, 0.95); margin-bottom: 20px; font-size: 18px; line-height: 1.6;">
                        Sua inscrição foi confirmada com sucesso!
                    </p>
                    
                    <div style="margin-top: 25px; padding: 20px; background: rgba(255, 255, 255, 0.2); border-radius: 8px; backdrop-filter: blur(10px);">
                        <p style="margin: 0; font-size: 32px; font-weight: 700; color: white;">
                            R$ <?= number_format($valor_pago, 2, ',', '.') ?>
                        </p>
                        <p style="margin: 10px 0 0 0; font-size: 14px; color: rgba(255, 255, 255, 0.9);">
                            Valor pago
                        </p>
                    </div>
                    
                    <p style="color: rgba(255, 255, 255, 0.9); font-size: 14px; margin-top: 25px; line-height: 1.6;">
                        📧 Você receberá um e-mail de confirmação em breve<br>
                        📅 Fique atento às informações da degustação
                    </p>
                </div>
                <?php elseif (!empty($qr_code_image)): ?>
                <div class="qr-code-container" style="background: white; border: 2px solid #3b82f6; border-radius: 12px; padding: 30px; margin: 30px 0; text-align: center; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                    <h2 style="color: #1e40af; margin-bottom: 20px;">💰 Pague com PIX</h2>
                    <p style="color: #6b7280; margin-bottom: 15px; font-size: 16px;">
                        Escaneie o QR Code abaixo com o app do seu banco para finalizar o pagamento
                    </p>
                    
                    <div style="background: white; padding: 20px; border-radius: 8px; display: inline-block; margin: 20px 0;">
                        <?php 
                        // Garantir que o Base64 está no formato correto
                        // Se já tem o prefixo data:image, usar direto, senão adicionar
                        if (strpos($qr_code_image, 'data:image') === 0) {
                            $img_src = $qr_code_image;
                        } elseif (!empty($qr_code_image)) {
                            // Adicionar prefixo se não tiver
                            $img_src = 'data:image/png;base64,' . $qr_code_image;
                        } else {
                            $img_src = '';
                        }
                        ?>
                        <?php if (!empty($img_src)): ?>
                            <img src="<?= h($img_src) ?>" 
                                 alt="QR Code PIX" 
                                 style="max-width: 300px; width: 100%; height: auto;">
                        <?php else: ?>
                            <div style="padding: 40px; text-align: center; color: #6b7280;">
                                <p>⚠️ QR Code não encontrado</p>
                                <p style="font-size: 12px; margin-top: 10px;">ID: <?= h($qr_code_id ?? 'N/A') ?></p>
                                <p style="font-size: 12px;">Verifique os logs do servidor para mais detalhes.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #f0f9ff; border-radius: 8px;">
                        <p style="margin: 0; font-size: 24px; font-weight: 700; color: #1e40af;">
                            R$ <?= number_format($qr_code_value, 2, ',', '.') ?>
                        </p>
                    </div>
                    
                    <p style="color: #6b7280; font-size: 14px; margin-top: 20px;">
                        ⏱️ Este QR Code expira em 1 hora<br>
                        💡 Após o pagamento, sua inscrição será confirmada automaticamente
                    </p>
                    
                    <div id="statusPagamentoContainer" style="margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; display: none;">
                        <p id="statusPagamentoTexto" style="margin: 0; font-size: 14px; color: #6b7280; text-align: center;">
                            ⏳ Verificando pagamento...
                        </p>
                    </div>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                        <button id="btnVerificarPagamento" onclick="verificarPagamento()" style="background: #3b82f6; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; transition: all 0.2s;">
                            🔄 Verificar Pagamento
                        </button>
                    </div>
                </div>
                
                <script>
                let intervaloVerificacao = null;
                
                // Verificação SILENCIOSA em background (sem mostrar erros ou tocar no botão)
                function verificarPagamentoSilencioso() {
                    const url = '<?= $_SERVER['REQUEST_URI'] ?? '' ?>';
                    const baseUrl = url.split('?')[0] + '?t=<?= urlencode($token) ?>';
                    // Adicionar timestamp para evitar cache do navegador
                    const timestamp = new Date().getTime();
                    const verificarUrl = baseUrl + '&verificar_pagamento=1&inscricao_id=<?= $qr_inscricao_id ?>&_t=' + timestamp;
                    
                    fetch(verificarUrl, {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Cache-Control': 'no-cache, no-store, must-revalidate',
                            'Pragma': 'no-cache',
                            'Expires': '0'
                        },
                        cache: 'no-store' // Forçar sem cache
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        // Se pagamento confirmado, mostrar confirmação e recarregar
                        if (data.pago === true || data.status === 'pago') {
                            const statusContainer = document.getElementById('statusPagamentoContainer');
                            const statusTexto = document.getElementById('statusPagamentoTexto');
                            
                            // Parar verificação automática
                            if (intervaloVerificacao) {
                                clearInterval(intervaloVerificacao);
                                intervaloVerificacao = null;
                            }
                            
                            // Mostrar confirmação
                            statusContainer.style.display = 'block';
                            statusTexto.innerHTML = `✅ <strong>Pagamento Confirmado!</strong><br>R$ ${parseFloat(data.valor_pago || 0).toFixed(2).replace('.', ',')}`;
                            statusTexto.style.color = '#059669';
                            
                            // Recarregar página após 2 segundos para mostrar confirmação completa
                            setTimeout(() => {
                                window.location.href = baseUrl + '&qr_code=1&inscricao_id=<?= $qr_inscricao_id ?>';
                            }, 2000);
                        }
                        // Se não estiver pago, não fazer nada (verificação silenciosa)
                    })
                    .catch(error => {
                        // Erro silencioso - apenas logar no console, não mostrar ao usuário
                        console.log('🔍 Verificação automática (sem erros visíveis):', error.message);
                        // Não fazer nada visualmente para não confundir o cliente
                    });
                }
                
                // Verificação MANUAL quando o usuário clica no botão (com feedback visual)
                function verificarPagamento() {
                    const statusContainer = document.getElementById('statusPagamentoContainer');
                    const statusTexto = document.getElementById('statusPagamentoTexto');
                    const btnVerificar = document.getElementById('btnVerificarPagamento');
                    
                    // Mostrar loading
                    statusContainer.style.display = 'block';
                    statusTexto.innerHTML = '⏳ Verificando pagamento...';
                    statusTexto.style.color = '#6b7280';
                    btnVerificar.disabled = true;
                    btnVerificar.style.opacity = '0.6';
                    
                    const url = '<?= $_SERVER['REQUEST_URI'] ?? '' ?>';
                    const baseUrl = url.split('?')[0] + '?t=<?= urlencode($token) ?>';
                    const timestamp = new Date().getTime();
                    const verificarUrl = baseUrl + '&verificar_pagamento=1&inscricao_id=<?= $qr_inscricao_id ?>&_t=' + timestamp;
                    
                    fetch(verificarUrl, {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Cache-Control': 'no-cache, no-store, must-revalidate',
                            'Pragma': 'no-cache',
                            'Expires': '0'
                        },
                        cache: 'no-store'
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        btnVerificar.disabled = false;
                        btnVerificar.style.opacity = '1';
                        
                        if (data.pago === true || data.status === 'pago') {
                            // Pagamento confirmado!
                            statusTexto.innerHTML = `✅ <strong>Pagamento Confirmado!</strong><br>R$ ${parseFloat(data.valor_pago || 0).toFixed(2).replace('.', ',')}`;
                            statusTexto.style.color = '#059669';
                            
                            // Parar verificação automática
                            if (intervaloVerificacao) {
                                clearInterval(intervaloVerificacao);
                                intervaloVerificacao = null;
                            }
                            
                            // Recarregar página após 2 segundos
                            setTimeout(() => {
                                window.location.href = baseUrl + '&qr_code=1&inscricao_id=<?= $qr_inscricao_id ?>';
                            }, 2000);
                        } else {
                            // Ainda aguardando
                            const statusText = data.status_text || 'Aguardando';
                            statusTexto.innerHTML = `⏳ Status: <strong>${statusText}</strong>`;
                            statusTexto.style.color = data.status === 'expirado' ? '#dc2626' : '#f59e0b';
                        }
                    })
                    .catch(error => {
                        btnVerificar.disabled = false;
                        btnVerificar.style.opacity = '1';
                        statusTexto.innerHTML = '❌ Erro ao verificar. Tente novamente.';
                        statusTexto.style.color = '#dc2626';
                    });
                }
                
                // Verificação automática SILENCIOSA a cada 10 segundos (em background, sem tocar no botão)
                intervaloVerificacao = setInterval(() => verificarPagamentoSilencioso(), 10000);
                
                // Verificar imediatamente também (silenciosamente)
                setTimeout(() => verificarPagamentoSilencioso(), 2000);
                </script>
                
                <?php
                // Exibir mensagem de confirmação se pagamento foi confirmado
                if (isset($_GET['pagamento_confirmado'])): ?>
                    <div class="alert alert-success" style="margin-top: 20px;">
                        <h3>✅ Pagamento Confirmado!</h3>
                        <p>Sua inscrição foi confirmada com sucesso. Você receberá um e-mail de confirmação em breve.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php } ?>
        
        <?php if ($inscricoes_encerradas): ?>
            <div class="alert alert-warning">
                ⏰ Inscrições encerradas. A data limite foi <?= date('d/m/Y', strtotime($degustacao['data_limite'])) ?>.
            </div>
        <?php elseif ($lotado && !$aceita_lista_espera): ?>
            <div class="alert alert-warning">
                🚫 Degustação lotada. Capacidade: <?= $degustacao['capacidade'] ?> pessoas.
            </div>
        <?php elseif ($lotado && $aceita_lista_espera): ?>
            <div class="alert alert-warning">
                ⚠️ Degustação lotada, mas você pode se inscrever na lista de espera.
            </div>
        <?php elseif (!($show_qr_code && $qr_inscricao_id > 0)): ?>
        
        <!-- Formulário de Inscrição (apenas quando NÃO estiver na tela do QR Code) -->
        <div class="form-container">
            <form method="POST" id="inscricaoForm">
                <h2 style="margin-bottom: 20px; color: #1e3a8a;">📝 Inscrição</h2>
                
                <!-- Já fechou contrato? (PRIMEIRO) -->
                <div class="form-group">
                    <label class="form-label">Já fechou seu evento conosco? *</label>
                    <div class="form-radio-group">
                        <div class="form-radio">
                            <input type="radio" name="fechou_contrato" value="sim" id="fechou_sim" required onchange="toggleContratoInfo()">
                            <label for="fechou_sim">Sim, já fechei</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="fechou_contrato" value="nao" id="fechou_nao" required onchange="toggleContratoInfo()">
                            <label for="fechou_nao">Não, ainda não fechei</label>
                        </div>
                    </div>
                </div>
                
                <!-- Buscar Evento (se já fechou) -->
                <div id="buscaEventoContainer" class="hidden" style="margin-bottom: 20px; padding: 15px; background: #f0f9ff; border: 2px solid #0ea5e9; border-radius: 8px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <span style="font-weight: 600; color: #0c4a6e;">🔍 Busque seu evento cadastrado</span>
                    </div>
                    <button type="button" onclick="abrirModalBuscaME()" class="btn-secondary" style="width: 100%; padding: 12px 20px; white-space: nowrap;">
                        🔍 Buscar Evento
                    </button>
                    <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 8px;">
                        💡 Se você já fechou evento conosco, busque aqui e seus dados serão preenchidos automaticamente
                    </small>
                    
                    <!-- Informações do evento encontrado -->
                    <div id="meEventInfo" class="alert alert-success hidden" style="margin-top: 15px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">✅ Evento encontrado!</h4>
                        <div id="meEventDetails"></div>
                    </div>
                </div>
                
                <!-- Dados Básicos (preenchidos automaticamente ou manualmente) -->
                <div class="form-group">
                    <label class="form-label">Nome Completo *</label>
                    <input type="text" name="nome" id="nomeInput" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">E-mail *</label>
                    <input type="email" name="email" id="emailInput" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Celular *</label>
                    <input type="tel" name="telefone" id="telefoneInput" class="form-input" required>
                </div>
                
                <!-- Tipo de Festa -->
                <div class="form-group">
                    <label class="form-label">Tipo de Festa *</label>
                    <div class="form-radio-group">
                        <div class="form-radio">
                            <input type="radio" name="tipo_festa" value="casamento" id="casamento" required onchange="mostrarPessoasIncluidas()">
                            <label for="casamento">Casamento (R$ <?= number_format($degustacao['preco_casamento'], 2, ',', '.') ?>)</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="tipo_festa" value="15anos" id="15anos" required onchange="mostrarPessoasIncluidas()">
                            <label for="15anos">15 Anos (R$ <?= number_format($degustacao['preco_15anos'], 2, ',', '.') ?>)</label>
                        </div>
                    </div>
                </div>
                
                <!-- Quantidade de Pessoas -->
                <div class="form-group">
                    <label class="form-label">Quantidade de Pessoas Adicionais *</label>
                    <input type="number" name="qtd_pessoas" id="qtdPessoasInput" class="form-input" min="0" value="0" required onchange="calcularPreco()">
                    <small style="color: #6b7280; font-size: 14px; display: block; margin-top: 5px;">
                        💡 Digite quantas pessoas adicionais além das incluídas no valor base
                    </small>
                    <div id="pessoasIncluidasInfo" style="margin-top: 8px; padding: 10px; background: #f0f9ff; border-left: 3px solid #0ea5e9; border-radius: 4px; display: none;">
                        <span style="font-weight: 600; color: #0c4a6e;">ℹ️ Total incluído no valor base:</span>
                        <span id="totalIncluido" style="color: #0369a1; font-weight: 600;"></span>
                        <span style="color: #0369a1;"> pessoas</span>
                    </div>
                </div>
                
                <!-- Informações de Preço -->
                <div id="priceInfo" class="price-info" style="display: none;">
                    <h3 style="margin: 0 0 10px 0; color: #0ea5e9;">💰 Resumo do Valor</h3>
                    <div class="price-item">
                        <span>Valor base:</span>
                        <span id="precoBase">R$ 0,00</span>
                    </div>
                    <div class="price-item">
                        <span>Pessoas extras:</span>
                        <span id="extrasInfo">0 x R$ <?= number_format($degustacao['preco_extra'], 2, ',', '.') ?></span>
                    </div>
                    <div class="price-item price-total">
                        <span>Total:</span>
                        <span id="valorTotal">R$ 0,00</span>
                    </div>
                </div>
                
                <!-- Campos ocultos para dados da ME -->
                <input type="hidden" name="nome_titular_contrato" id="nomeTitularHidden">
                <input type="hidden" name="cpf_3_digitos" id="cpf3DigitosHidden">
                <input type="hidden" name="me_cliente_cpf" id="meClienteCpfHidden">
                <input type="hidden" name="me_event_id" id="meEventIdHidden">
                
                <!-- Campos dinâmicos do Form Builder -->
                <?php
                $campos = json_decode($degustacao['campos_json'], true) ?: [];
                foreach ($campos as $campo):
                ?>
                    <div class="form-group">
                        <label class="form-label"><?= h($campo['label']) ?> <?= $campo['required'] ? '*' : '' ?></label>
                        
                        <?php if ($campo['type'] === 'texto' || $campo['type'] === 'email' || $campo['type'] === 'celular' || $campo['type'] === 'cpf_cnpj' || $campo['type'] === 'numero'): ?>
                            <input type="<?= $campo['type'] === 'email' ? 'email' : ($campo['type'] === 'numero' ? 'number' : 'text') ?>" 
                                   name="<?= h($campo['name']) ?>" class="form-input" 
                                   <?= $campo['required'] ? 'required' : '' ?>>
                        
                        <?php elseif ($campo['type'] === 'data'): ?>
                            <input type="date" name="<?= h($campo['name']) ?>" class="form-input" 
                                   <?= $campo['required'] ? 'required' : '' ?>>
                        
                        <?php elseif ($campo['type'] === 'select'): ?>
                            <select name="<?= h($campo['name']) ?>" class="form-select" 
                                    <?= $campo['required'] ? 'required' : '' ?>>
                                <option value="">Selecione...</option>
                                <?php foreach ($campo['options'] as $option): ?>
                                    <option value="<?= h($option) ?>"><?= h($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        
                        <?php elseif ($campo['type'] === 'radio'): ?>
                            <div class="form-radio-group">
                                <?php foreach ($campo['options'] as $option): ?>
                                    <div class="form-radio">
                                        <input type="radio" name="<?= h($campo['name']) ?>" value="<?= h($option) ?>" 
                                               id="<?= h($campo['name'] . '_' . $option) ?>" 
                                               <?= $campo['required'] ? 'required' : '' ?>>
                                        <label for="<?= h($campo['name'] . '_' . $option) ?>"><?= h($option) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        
                        <?php elseif ($campo['type'] === 'checkbox'): ?>
                            <div class="form-checkbox">
                                <?php foreach ($campo['options'] as $option): ?>
                                    <div class="form-checkbox">
                                        <input type="checkbox" name="<?= h($campo['name']) ?>[]" value="<?= h($option) ?>" 
                                               id="<?= h($campo['name'] . '_' . $option) ?>">
                                        <label for="<?= h($campo['name'] . '_' . $option) ?>"><?= h($option) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        
                        <?php elseif ($campo['type'] === 'textarea'): ?>
                            <textarea name="<?= h($campo['name']) ?>" class="form-input" rows="4" 
                                      <?= $campo['required'] ? 'required' : '' ?>></textarea>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <!-- Campos ocultos para cálculo -->
                <input type="hidden" name="valor_total" id="valorTotalHidden" value="0">
                <input type="hidden" name="extras" id="extrasHidden" value="0">
                
                <button type="submit" class="btn-submit" id="btnSubmitInscricao">
                    <?= $lotado && $aceita_lista_espera ? '📋 Inscrever na Lista de Espera' : '✅ Inscrever-se' ?>
                </button>
            </form>
        </div>
        
        <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Busca ME Eventos - RECRIADO PARA CENTRALIZAÇÃO -->
    <div id="modalBuscaME" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); z-index: 99999; margin: 0; padding: 0; overflow: hidden;" onclick="if(event.target === this) fecharModalBuscaME()">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); max-width: 600px; width: calc(100% - 40px); max-width: calc(100vw - 40px); max-height: 90vh; overflow-y: auto; margin: 0; padding: 0;" onclick="event.stopPropagation()">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); border-radius: 16px 16px 0 0;">
                <h3 style="font-size: 20px; font-weight: 700; color: white; margin: 0; display: flex; align-items: center; gap: 8px;">🔍 Buscar Evento</h3>
                <button onclick="fecharModalBuscaME()" style="background: rgba(255, 255, 255, 0.2); border: none; color: white; font-size: 28px; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; line-height: 1;">&times;</button>
            </div>
            
            <div style="padding: 24px; background: #f9fafb;">
                <!-- Busca por Nome -->
                <div id="buscaMEPorNome">
                    <div class="form-group">
                        <label class="form-label">Digite o nome do titular do contrato</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="buscaMENome" class="form-input" placeholder="Ex: João Silva" style="flex: 1;">
                            <button type="button" onclick="buscarClienteME()" class="btn-primary" style="padding: 12px 24px;">
                                🔍 Buscar
                            </button>
                        </div>
                        <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                            Digite pelo menos 3 caracteres para buscar
                        </small>
                    </div>
                    
                    <div id="buscaMELoading" style="display: none; text-align: center; padding: 20px; color: #64748b;">
                        <div>⏳ Buscando...</div>
                    </div>
                    
                    <div id="buscaMEResultados" style="margin-top: 15px;"></div>
                </div>
                
                <!-- Validação de CPF -->
                <div id="buscaMEValidarCPF" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                    <div class="form-group">
                        <label class="form-label">Cliente selecionado:</label>
                        <div style="padding: 10px; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 6px; font-weight: 600; color: #0c4a6e;">
                            <span id="nomeClienteSelecionado"></span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Digite seu CPF completo para confirmar *</label>
                        <input type="text" id="buscaMECpf" class="form-input" placeholder="000.000.000-00" maxlength="14" 
                               oninput="this.value = this.value.replace(/\D/g, '').replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')">
                        <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                            🔒 Seus dados estão seguros. O CPF será usado apenas para confirmação.
                        </small>
                    </div>
                    
                    <div id="buscaMEValidarLoading" style="display: none; text-align: center; padding: 10px; color: #64748b;">
                        ⏳ Validando CPF...
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 15px;">
                        <button type="button" onclick="fecharModalBuscaME()" class="btn-cancel" style="flex: 1;">
                            Cancelar
                        </button>
                        <button type="button" onclick="validarCPFME()" class="btn-save" style="flex: 1;">
                            ✅ Confirmar CPF
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .cliente-item-me {
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            background: #f8fafc;
        }
        
        .cliente-item-me:hover {
            background: #e0f2fe !important;
            border-color: #0ea5e9 !important;
            transform: translateX(2px);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
    </style>
    
    <script>
        const PRECOS = {
            casamento: {
                base: <?= $degustacao['preco_casamento'] ?>,
                incluidos: <?= $degustacao['incluidos_casamento'] ?>
            },
            '15anos': {
                base: <?= $degustacao['preco_15anos'] ?>,
                incluidos: <?= $degustacao['incluidos_15anos'] ?>
            },
            extra: <?= $degustacao['preco_extra'] ?>
        };
        
        // Mostrar pessoas incluídas baseado no tipo de festa
        function mostrarPessoasIncluidas() {
            const tipoFesta = document.querySelector('input[name="tipo_festa"]:checked');
            if (!tipoFesta) {
                document.getElementById('pessoasIncluidasInfo').style.display = 'none';
                return;
            }
            
            const incluidos = tipoFesta.value === 'casamento' ? 
                PRECOS.casamento.incluidos : 
                PRECOS['15anos'].incluidos;
            
            document.getElementById('totalIncluido').textContent = incluidos;
            document.getElementById('pessoasIncluidasInfo').style.display = 'block';
            
            // Campo quantidade sempre começa em 0 (extras)
            document.getElementById('qtdPessoasInput').value = 0;
            
            // Calcular preço automaticamente
            calcularPreco();
        }
        
        function calcularPreco() {
            const tipoFesta = document.querySelector('input[name="tipo_festa"]:checked');
            const qtdExtras = parseInt(document.getElementById('qtdPessoasInput').value) || 0;
            
            if (!tipoFesta) {
                document.getElementById('priceInfo').style.display = 'none';
                return;
            }
            
            const precoInfo = tipoFesta.value === 'casamento' ? PRECOS.casamento : PRECOS['15anos'];
            const precoBase = precoInfo.base;
            const incluidos = precoInfo.incluidos;
            const precoExtra = PRECOS.extra;
            
            // Total de pessoas = incluídos + extras
            const totalPessoas = incluidos + qtdExtras;
            
            // Valor total = base + (extras * preço extra)
            const valorTotal = precoBase + (qtdExtras * precoExtra);
            
            document.getElementById('precoBase').textContent = 'R$ ' + precoBase.toFixed(2).replace('.', ',');
            
            if (qtdExtras > 0) {
                document.getElementById('extrasInfo').textContent = qtdExtras + ' pessoa(s) extra(s) x R$ ' + precoExtra.toFixed(2).replace('.', ',') + ' = R$ ' + (qtdExtras * precoExtra).toFixed(2).replace('.', ',');
            } else {
                document.getElementById('extrasInfo').textContent = '0 pessoa(s) extra(s) (dentro do valor base)';
            }
            
            document.getElementById('valorTotal').textContent = 'R$ ' + valorTotal.toFixed(2).replace('.', ',');
            
            document.getElementById('priceInfo').style.display = 'block';
            
            // Atualizar campos ocultos para o formulário (total de pessoas = incluídos + extras)
            document.getElementById('valorTotalHidden').value = valorTotal;
            document.getElementById('extrasHidden').value = qtdExtras;
            
            // Atualizar campo qtd_pessoas com o total (incluídos + extras)
            document.getElementById('qtdPessoasInput').setAttribute('data-total', totalPessoas);
        }
        
        
        // Mostrar pessoas incluídas quando tipo de festa mudar
        document.querySelectorAll('input[name="tipo_festa"]').forEach(radio => {
            radio.addEventListener('change', function() {
                mostrarPessoasIncluidas();
            });
        });
        
        // Ao submeter, garantir que qtd_pessoas seja o total (incluídos + extras)
        document.getElementById('inscricaoForm')?.addEventListener('submit', function(e) {
            const qtdPessoasInput = document.getElementById('qtdPessoasInput');
            const totalPessoas = parseInt(qtdPessoasInput.getAttribute('data-total')) || parseInt(qtdPessoasInput.value) || 0;
            qtdPessoasInput.value = totalPessoas;
        });
        
        // Inicializar texto do botão baseado na seleção inicial
        document.addEventListener('DOMContentLoaded', function() {
            const fechouNao = document.getElementById('fechou_nao');
            if (fechouNao && fechouNao.checked) {
                const btnSubmit = document.getElementById('btnSubmitInscricao');
                if (btnSubmit) {
                    btnSubmit.innerHTML = '💳 Realizar Pagamento';
                    btnSubmit.style.background = 'linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%)';
                }
            }
        });
        
        // Variáveis globais para busca ME
        let clienteSelecionadoME = null;
        
        // Função para abrir modal de busca ME - SIMPLIFICADA
        function abrirModalBuscaME() {
            const modal = document.getElementById('modalBuscaME');
            modal.style.display = 'block';
            document.getElementById('buscaMENome').value = '';
            document.getElementById('buscaMEResultados').innerHTML = '';
            document.getElementById('buscaMELoading').style.display = 'none';
            document.getElementById('buscaMEValidarCPF').style.display = 'none';
            clienteSelecionadoME = null;
            // Focar no campo de busca
            setTimeout(() => {
                document.getElementById('buscaMENome')?.focus();
            }, 100);
        }
        
        function toggleContratoInfo() {
            const fechouSim = document.getElementById('fechou_sim').checked;
            const buscaContainer = document.getElementById('buscaEventoContainer');
            const btnSubmit = document.getElementById('btnSubmitInscricao');
            
            if (fechouSim) {
                buscaContainer.classList.remove('hidden');
                // Se já fechou contrato, mudar texto do botão
                if (btnSubmit) {
                    btnSubmit.innerHTML = '✅ Inscrever-se';
                    btnSubmit.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
                }
            } else {
                buscaContainer.classList.add('hidden');
                document.getElementById('meEventInfo').classList.add('hidden');
                
                // Limpar campos ocultos
                document.getElementById('nomeTitularHidden').value = '';
                document.getElementById('cpf3DigitosHidden').value = '';
                document.getElementById('meClienteCpfHidden').value = '';
                document.getElementById('meEventIdHidden').value = '';
                clienteSelecionadoME = null;
                
                // Se não fechou contrato, mudar texto do botão para "Realizar Pagamento"
                if (btnSubmit) {
                    btnSubmit.innerHTML = '💳 Realizar Pagamento';
                    btnSubmit.style.background = 'linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%)';
                }
            }
        }
        
        function fecharModalBuscaME() {
            const modal = document.getElementById('modalBuscaME');
            if (modal) {
                modal.style.display = 'none';
            }
        }
        
        // Buscar cliente na ME Eventos
        async function buscarClienteME() {
            const nome = document.getElementById('buscaMENome').value.trim();
            
            if (nome.length < 3) {
                if (typeof customAlert === 'function') {
                    customAlert('Digite pelo menos 3 caracteres para buscar', '⚠️ Validação');
                } else {
                    alert('Digite pelo menos 3 caracteres para buscar');
                }
                return;
            }
            
            const loadingDiv = document.getElementById('buscaMELoading');
            const resultadosDiv = document.getElementById('buscaMEResultados');
            
            loadingDiv.style.display = 'block';
            resultadosDiv.innerHTML = '';
            
            try {
                const response = await fetch(`me_buscar_cliente.php?nome=${encodeURIComponent(nome)}`);
                const data = await response.json();
                
                loadingDiv.style.display = 'none';
                
                if (!data.ok) {
                    throw new Error(data.error || 'Erro ao buscar cliente');
                }
                
                if (!data.clientes || data.clientes.length === 0) {
                    resultadosDiv.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">Nenhum cliente encontrado com este nome.</div>';
                    return;
                }
                
                // Mostrar lista de clientes encontrados
                let html = '<div style="margin-bottom: 10px; font-weight: 600; color: #374151;">Cliente(s) encontrado(s):</div>';
                html += '<div style="display: flex; flex-direction: column; gap: 8px; max-height: 300px; overflow-y: auto;">';
                
                data.clientes.forEach((cliente, index) => {
                    html += `
                        <div class="cliente-item-me" onclick="selecionarClienteME('${cliente.nome_cliente.replace(/'/g, "\\'")}', ${cliente.quantidade_eventos})" 
                             style="padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: #f8fafc;">
                            <div style="font-weight: 600; color: #1e3a8a;">${escapeHtml(cliente.nome_cliente)}</div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">${cliente.quantidade_eventos} evento(s) encontrado(s)</div>
                        </div>
                    `;
                });
                
                html += '</div>';
                resultadosDiv.innerHTML = html;
                
                } catch (error) {
                    loadingDiv.style.display = 'none';
                    const errorMsg = error.message || 'Erro ao buscar cliente';
                    if (typeof customAlert === 'function') {
                        customAlert(errorMsg, '❌ Erro');
                    } else {
                        resultadosDiv.innerHTML = `<div style="padding: 20px; text-align: center; color: #dc2626;">Erro: ${escapeHtml(errorMsg)}</div>`;
                    }
                }
        }
        
        // Selecionar cliente da lista
        function selecionarClienteME(nomeCliente, qtdEventos) {
            clienteSelecionadoME = { nome: nomeCliente, eventos: qtdEventos };
            
            // Log da seleção
            console.log('Cliente selecionado:', nomeCliente, 'com', qtdEventos, 'evento(s)');
            
            // Esconder resultados e mostrar validação de CPF
            document.getElementById('buscaMEResultados').innerHTML = '';
            document.getElementById('buscaMEValidarCPF').style.display = 'block';
            document.getElementById('nomeClienteSelecionado').textContent = nomeCliente;
            
            // Limpar campo de CPF para nova validação
            document.getElementById('buscaMECpf').value = '';
            document.getElementById('buscaMECpf').focus();
        }
        
        // Validar CPF do cliente
        async function validarCPFME() {
            if (!clienteSelecionadoME) {
                if (typeof customAlert === 'function') {
                    customAlert('Selecione um cliente primeiro', '⚠️ Validação');
                } else {
                    alert('Selecione um cliente primeiro');
                }
                return;
            }
            
            const cpf = document.getElementById('buscaMECpf').value.replace(/\D/g, '');
            
            if (cpf.length !== 11) {
                if (typeof customAlert === 'function') {
                    customAlert('CPF deve ter 11 dígitos', '⚠️ Validação');
                } else {
                    alert('CPF deve ter 11 dígitos');
                }
                return;
            }
            
            const loadingDiv = document.getElementById('buscaMEValidarLoading');
            loadingDiv.style.display = 'block';
            
            try {
                const response = await fetch('me_buscar_cliente.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        nome_cliente: clienteSelecionadoME.nome,
                        cpf: cpf,
                        degustacao_token: '<?= h($token) ?>' // Token da degustação atual
                    })
                });
                
                const data = await response.json();
                loadingDiv.style.display = 'none';
                
                if (!data.ok) {
                    // Limpar mensagem de evento encontrado anterior
                    document.getElementById('meEventInfo').classList.add('hidden');
                    document.getElementById('meEventDetails').innerHTML = '';
                    
                    // Limpar campos preenchidos anteriormente
                    document.getElementById('nomeInput').value = '';
                    document.getElementById('emailInput').value = '';
                    document.getElementById('telefoneInput').value = '';
                    document.getElementById('nomeTitularHidden').value = '';
                    document.getElementById('cpf3DigitosHidden').value = '';
                    document.getElementById('meClienteCpfHidden').value = '';
                    document.getElementById('meEventIdHidden').value = '';
                    
                    // Mostrar erro com mensagem clara e orientações
                    const errorMsg = data.error || 'Erro ao validar dados';
                    
                    // Determinar tipo de erro para mensagem específica
                    let tipoErro = 'geral';
                    let instrucoes = '';
                    
                    if (errorMsg.includes('CPF não confere') || errorMsg.includes('não confere com o cadastro')) {
                        tipoErro = 'cpf';
                        instrucoes = `
                            <strong style="color: #991b1b;">O CPF digitado não corresponde ao cadastrado.</strong><br>
                            • Verifique o CPF conforme consta no seu contrato<br>
                            • Digite apenas os números, sem pontos ou traços<br>
                            • Certifique-se de não ter invertido números<br>
                        `;
                    } else if (errorMsg.includes('CPF inválido') || errorMsg.includes('dígitos verificadores')) {
                        tipoErro = 'cpf_invalido';
                        instrucoes = `
                            <strong style="color: #991b1b;">O CPF digitado é inválido matematicamente.</strong><br>
                            • Verifique se todos os dígitos estão corretos<br>
                            • O CPF deve ter 11 dígitos válidos<br>
                        `;
                    } else if (errorMsg.includes('não retornou o CPF')) {
                        tipoErro = 'sem_cpf_api';
                        instrucoes = `
                            <strong style="color: #991b1b;">Não foi possível validar sua identidade automaticamente.</strong><br>
                            • Nossa API não retornou o CPF cadastrado<br>
                            • Você pode se inscrever normalmente selecionando "Não, ainda não fechei"<br>
                            • Ou entre em contato conosco para verificar seu cadastro<br>
                        `;
                    } else {
                        instrucoes = `
                            <strong style="color: #991b1b;">Por favor, verifique:</strong><br>
                            • O nome digitado está correto e completo?<br>
                            • O CPF está de acordo com o contrato?<br>
                            • Você está usando os mesmos dados do cadastro?<br>
                        `;
                    }
                    
                    document.getElementById('buscaMEResultados').innerHTML = `
                        <div style="padding: 24px; text-align: center; background: #fef2f2; border: 2px solid #dc2626; border-radius: 12px; color: #991b1b; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                            <div style="font-size: 48px; margin-bottom: 16px;">🔒</div>
                            <h3 style="margin: 0 0 16px 0; color: #991b1b; font-size: 20px;">Validação de Identidade</h3>
                            <p style="margin: 0 0 16px 0; line-height: 1.8; font-size: 15px;">
                                ${escapeHtml(errorMsg)}
                            </p>
                            <div style="margin: 16px 0; padding: 16px; background: #fee2e2; border-radius: 8px; text-align: left; font-size: 14px; line-height: 1.8;">
                                ${instrucoes}
                            </div>
                            <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #fca5a5;">
                                <small style="color: #991b1b; opacity: 0.8; display: block; margin-bottom: 12px;">
                                    Você pode tentar novamente ou se inscrever sem buscar evento
                                </small>
                                <button type="button" onclick="document.getElementById('buscaMEValidarCPF').style.display='none'; document.getElementById('buscaMENome').value='${clienteSelecionadoME ? escapeHtml(clienteSelecionadoME.nome) : ''}'; clienteSelecionadoME=null;" 
                                        style="background: #dc2626; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                    🔄 Tentar Novamente
                                </button>
                            </div>
                        </div>
                    `;
                    
                    // Voltar para tela de busca
                    document.getElementById('buscaMEValidarCPF').style.display = 'none';
                    if (clienteSelecionadoME) {
                        document.getElementById('buscaMENome').value = clienteSelecionadoME.nome;
                    }
                    clienteSelecionadoME = null;
                    
                    throw new Error(errorMsg);
                }
                
                // CPF validado com sucesso! Preencher campos automaticamente
                console.log('✅ CPF validado! Resposta completa do backend:', data);
                console.log('   data.ok =', data.ok);
                console.log('   data.evento =', data.evento);
                console.log('   Tipo de data.evento:', typeof data.evento);
                
                const evento = data.evento;
                
                if (!evento) {
                    console.error('❌ ERRO CRÍTICO: data.evento está null ou undefined!');
                    console.log('   Resposta completa:', JSON.stringify(data, null, 2));
                    return;
                }
                
                console.log('✅ CPF validado com sucesso! Preenchendo campos...');
                console.log('Dados do evento recebidos:', evento);
                console.log('   Todos os campos do evento:', Object.keys(evento));
                console.log('   Estrutura JSON completa:', JSON.stringify(evento, null, 2));
                
                // Limpar mensagem de erro anterior se existir
                document.getElementById('meEventInfo').classList.add('hidden');
                
                // Preencher campos principais automaticamente
                const nomeInput = document.getElementById('nomeInput');
                const emailInput = document.getElementById('emailInput');
                const telefoneInput = document.getElementById('telefoneInput');
                
                if (nomeInput) {
                    nomeInput.value = evento.nome_cliente || '';
                    console.log('✅ Nome preenchido:', nomeInput.value);
                } else {
                    console.error('❌ Campo nomeInput não encontrado!');
                }
                
                // Preencher email (buscar em múltiplos campos possíveis)
                // IMPORTANTE: Verificar TODOS os campos possíveis que podem conter email
                console.log('🔍 Buscando email no evento...');
                console.log('   evento.email =', evento.email);
                console.log('   evento.emailCliente =', evento.emailCliente);
                
                let emailVal = '';
                
                // Lista de TODOS os campos possíveis
                const camposEmail = [
                    'email',
                    'emailCliente',
                    'cliente_email',
                    'clienteEmail',
                    'contato_email',
                    'contatoEmail',
                    'email_contato',
                    'emailContato',
                    'e_mail',
                    'e-mail',
                    'e_mailCliente',
                    'e-mailCliente',
                    'mail',
                    'correio',
                    'correio_eletronico'
                ];
                
                // Tentar cada campo
                for (const campo of camposEmail) {
                    const valor = evento[campo];
                    console.log(`   Verificando evento.${campo} =`, valor);
                    if (valor && typeof valor === 'string' && valor.trim().length > 0 && valor.includes('@')) {
                        emailVal = valor.trim();
                        console.log(`   ✅ Email encontrado em evento.${campo}:`, emailVal);
                        break;
                    }
                }
                
                // Se ainda não encontrou, buscar em qualquer campo que contenha '@'
                if (!emailVal) {
                    console.log('   Buscando email em qualquer campo que contenha @...');
                    for (const key in evento) {
                        const valor = evento[key];
                        if (typeof valor === 'string' && valor.includes('@') && valor.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                            emailVal = valor.trim();
                            console.log(`   ✅ Email encontrado em campo genérico '${key}':`, emailVal);
                            break;
                        }
                    }
                }
                
                // Tentar preencher
                if (emailInput) {
                    if (emailVal) {
                        emailInput.value = emailVal;
                        console.log('✅ Email preenchido com sucesso:', emailVal);
                        // Disparar evento input para trigger de validações
                        emailInput.dispatchEvent(new Event('input', { bubbles: true }));
                    } else {
                        console.warn('⚠️ Email NÃO encontrado em nenhum campo!');
                        console.log('   Todos os campos do evento:', Object.keys(evento));
                        console.log('   Todos os valores do evento:', JSON.stringify(evento, null, 2));
                    }
                } else {
                    console.error('❌ Campo emailInput não encontrado no DOM!');
                    console.log('   Tentando encontrar campo de email de outras formas...');
                    // Tentar encontrar por ID, name, placeholder, etc
                    const emailInputAlt = document.querySelector('input[type="email"], input[name*="email" i], input[id*="email" i], input[placeholder*="email" i]');
                    if (emailInputAlt) {
                        console.log('   ✅ Campo de email encontrado por seletor alternativo:', emailInputAlt);
                        if (emailVal) {
                            emailInputAlt.value = emailVal;
                            console.log('✅ Email preenchido (campo alternativo):', emailVal);
                        }
                    } else {
                        console.error('   ❌ Nenhum campo de email encontrado no formulário!');
                    }
                }
                
                // Preencher telefone/celular (buscar em múltiplos campos possíveis)
                const telefoneVal = evento.telefone || evento.celular || evento.telefoneCliente || 
                                   evento.celularCliente || evento.cliente_telefone || evento.cliente_celular || 
                                   evento.contato_telefone || evento.telefone_contato || '';
                if (telefoneInput && telefoneVal) {
                    telefoneInput.value = telefoneVal;
                    console.log('✅ Telefone preenchido:', telefoneVal);
                } else if (telefoneInput) {
                    console.warn('⚠️ Telefone não encontrado na resposta da API ME Eventos');
                } else {
                    console.error('❌ Campo telefoneInput não encontrado!');
                }
                
                // Disparar evento de mudança nos campos para garantir que qualquer validação JavaScript seja executada
                if (nomeInput) nomeInput.dispatchEvent(new Event('input', { bubbles: true }));
                if (emailInput) emailInput.dispatchEvent(new Event('input', { bubbles: true }));
                if (telefoneInput) telefoneInput.dispatchEvent(new Event('input', { bubbles: true }));
                
                // Preencher campos ocultos
                document.getElementById('nomeTitularHidden').value = evento.nome_cliente || '';
                document.getElementById('cpf3DigitosHidden').value = cpf.substring(0, 3);
                document.getElementById('meClienteCpfHidden').value = cpf;
                document.getElementById('meEventIdHidden').value = evento.id || '';
                
                // Mostrar informações do evento
                const eventDetails = `
                    <div style="margin-top: 10px; font-size: 14px;">
                        <div style="margin-bottom: 5px;"><strong>📅 Evento:</strong> ${escapeHtml(evento.nome_evento || 'N/A')}</div>
                        <div style="margin-bottom: 5px;"><strong>📆 Data:</strong> ${formatarData(evento.data_evento)}</div>
                        <div style="margin-bottom: 5px;"><strong>🎉 Tipo:</strong> ${escapeHtml(evento.tipo_evento || 'N/A')}</div>
                        ${evento.local_evento ? `<div><strong>📍 Local:</strong> ${escapeHtml(evento.local_evento)}</div>` : ''}
                    </div>
                `;
                
                document.getElementById('meEventDetails').innerHTML = eventDetails;
                document.getElementById('meEventInfo').classList.remove('hidden');
                
                // Fechar modal
                fecharModalBuscaME();
                
            } catch (error) {
                loadingDiv.style.display = 'none';
                const errorMsg = 'Erro ao validar CPF: ' + error.message;
                if (typeof customAlert === 'function') {
                    customAlert(errorMsg, '❌ Erro');
                } else {
                    alert(errorMsg);
                }
            }
        }
        
        function formatarData(data) {
            if (!data) return '';
            const d = new Date(data + 'T00:00:00');
            return d.toLocaleDateString('pt-BR');
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Permitir busca ao pressionar Enter
        document.getElementById('buscaMENome')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                buscarClienteME();
            }
        });
        
        document.getElementById('buscaMECpf')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                validarCPFME();
            }
        });
    </script>
</body>
</html>
