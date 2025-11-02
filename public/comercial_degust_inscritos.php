<?php
// comercial_degust_inscritos.php — Lista de inscritos de uma degustação específica
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Verificar permissões
if (!lc_can_manage_inscritos()) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

$event_id = (int)($_GET['event_id'] ?? 0);
if (!$event_id) {
    header('Location: index.php?page=comercial_degustacoes&error=invalid_event');
    exit;
}

// Buscar dados da degustação
$stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
$stmt->execute([':id' => $event_id]);
$degustacao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$degustacao) {
    header('Location: index.php?page=comercial_degustacoes&error=event_not_found');
    exit;
}

// Processar mensagens de sucesso via GET
if (isset($_GET['success'])) {
    $success_message = match($_GET['success']) {
        'comparecimento_atualizado' => 'Comparecimento atualizado com sucesso!',
        'contrato_atualizado' => 'Status de contrato atualizado com sucesso!',
        'payment_created' => 'Link de pagamento gerado com sucesso!',
        'inscrito_excluido' => 'Inscrito excluído com sucesso!',
        default => 'Operação realizada com sucesso!'
    };
}

// Processar ações
$action = $_POST['action'] ?? '';
$inscricao_id = (int)($_POST['inscricao_id'] ?? 0);

// Ação de excluir inscrito
if ($action === 'excluir_inscrito' && $inscricao_id > 0) {
    try {
        $stmt = $pdo->prepare("DELETE FROM comercial_inscricoes WHERE id = :id");
        $stmt->execute([':id' => $inscricao_id]);
        
        header("Location: index.php?page=comercial_degust_inscritos&event_id={$event_id}&success=inscrito_excluido");
        exit;
    } catch (Exception $e) {
        $error_message = "Erro ao excluir inscrito: " . $e->getMessage();
    }
}

// Ação unificada: marcar contrato e comparecimento (checkbox única)
if ($action === 'marcar_contrato_comparecimento' && $inscricao_id > 0) {
    try {
        $fechou_contrato = isset($_POST['fechou_contrato']) && $_POST['fechou_contrato'] === '1' ? 'sim' : 'nao';
        $compareceu = isset($_POST['compareceu']) && $_POST['compareceu'] === '1' ? 1 : 0;
        
        // Se marcou contrato, atualizar também comparecimento para 1
        if ($fechou_contrato === 'sim') {
            $compareceu = 1;
        }
        
        // Não solicita mais nome do titular
        $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET fechou_contrato = :fechou_contrato, compareceu = :compareceu WHERE id = :id");
        $stmt->execute([
            ':fechou_contrato' => $fechou_contrato,
            ':compareceu' => $compareceu,
            ':id' => $inscricao_id
        ]);
        
        header("Location: index.php?page=comercial_degust_inscritos&event_id={$event_id}&success=contrato_atualizado");
        exit;
    } catch (Exception $e) {
        $error_message = "Erro ao atualizar: " . $e->getMessage();
    }
}

if ($action === 'marcar_comparecimento' && $inscricao_id > 0) {
    try {
        // CORRIGIDO: Verificar o valor explicitamente, não apenas se existe
        // Quando enviamos '0', isset() retorna true mas precisamos verificar o valor
        $compareceu = (isset($_POST['compareceu']) && $_POST['compareceu'] === '1') ? 1 : 0;
        
        error_log("Atualizando comparecimento - Inscrição ID: {$inscricao_id}, Compareceu: {$compareceu}, POST: " . json_encode($_POST));
        
        $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET compareceu = :compareceu WHERE id = :id");
        $stmt->execute([':compareceu' => $compareceu, ':id' => $inscricao_id]);
        
        error_log("Comparecimento atualizado com sucesso");
        
        // Redirecionar para evitar reenvio de formulário
        header("Location: index.php?page=comercial_degust_inscritos&event_id={$event_id}&success=comparecimento_atualizado");
        exit;
    } catch (Exception $e) {
        error_log("ERRO ao atualizar comparecimento: " . $e->getMessage());
        $error_message = "Erro ao atualizar comparecimento: " . $e->getMessage();
    }
}

if ($action === 'marcar_fechou_contrato' && $inscricao_id > 0) {
    try {
        $fechou_contrato = $_POST['fechou_contrato'] ?? 'nao';
        // Não solicita mais nome do titular nem CPF
        $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET fechou_contrato = :fechou_contrato WHERE id = :id");
        $stmt->execute([
            ':fechou_contrato' => $fechou_contrato,
            ':id' => $inscricao_id
        ]);
        
        // Redirecionar para evitar reenvio de formulário
        header("Location: index.php?page=comercial_degust_inscritos&event_id={$event_id}&success=contrato_atualizado");
        exit;
    } catch (Exception $e) {
        $error_message = "Erro ao atualizar contrato: " . $e->getMessage();
    }
}

// Ação para adicionar pessoa e gerar cobrança adicional
if ($action === 'adicionar_pessoa' && $inscricao_id > 0) {
    // CRÍTICO: Limpar qualquer output anterior (HTML, whitespace, etc)
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Definir headers ANTES de qualquer output
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Função auxiliar para retornar JSON e sair
    function returnJson($data, $httpCode = 200) {
        http_response_code($httpCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // Capturar erros fatais
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro fatal do servidor',
                'message' => 'Erro interno do servidor. Verifique os logs.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    });
    
    try {
        require_once __DIR__ . '/asaas_helper.php';
        require_once __DIR__ . '/config_env.php';
        
        // Buscar inscrição
        $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE id = :id");
        $stmt->execute([':id' => $inscricao_id]);
        $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$inscricao) {
            throw new Exception("Inscrição não encontrada");
        }
        
        // Verificar se fechou contrato (não precisa pagar adicional)
        if ($inscricao['fechou_contrato'] === 'sim') {
            returnJson([
                'success' => false,
                'error' => 'Cliente já fechou contrato',
                'message' => 'Cliente já fechou contrato, não é necessário pagamento adicional.'
            ], 400);
        }
        
        // Buscar dados da degustação para pegar o preço extra
        $check_col = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'comercial_inscricoes' 
            AND column_name IN ('degustacao_id', 'event_id')
        ");
        $colunas = $check_col->fetchAll(PDO::FETCH_COLUMN);
        $coluna_id = in_array('degustacao_id', $colunas) ? 'degustacao_id' : 'event_id';
        
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $inscricao[$coluna_id]]);
        $degustacao_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$degustacao_info) {
            throw new Exception("Degustação não encontrada");
        }
        
        // Quantidade de pessoas a adicionar (vem do formulário)
        $qtd_adicionar = isset($_POST['qtd_pessoas']) ? (int)$_POST['qtd_pessoas'] : 1;
        
        if ($qtd_adicionar <= 0) {
            throw new Exception("Quantidade de pessoas inválida (deve ser pelo menos 1)");
        }
        
        if ($qtd_adicionar > 10) {
            throw new Exception("Quantidade máxima de pessoas por vez é 10");
        }
        
        // Valor adicional por pessoa (R$50,00)
        $valor_adicional_por_pessoa = (float)($degustacao_info['preco_extra'] ?? 50.00);
        
        if ($valor_adicional_por_pessoa <= 0) {
            throw new Exception("Preço adicional inválido");
        }
        
        // Calcular valor total adicional
        $valor_adicional_total = $valor_adicional_por_pessoa * $qtd_adicionar;
        
        // IMPORTANTE: NÃO atualizar qtd_pessoas e valor_total imediatamente
        // Só atualizar quando o pagamento for confirmado (via webhook)
        
        // Verificar/criar colunas para valores pendentes
        $check_pending_cols = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'comercial_inscricoes' 
            AND column_name IN ('qtd_pessoas_pendente', 'valor_adicional_pendente', 'qr_code_adicional_id', 'qr_code_adicional_expira_em')
        ");
        $pending_columns = $check_pending_cols->fetchAll(PDO::FETCH_COLUMN);
        
        // Criar colunas se não existirem
        if (!in_array('qtd_pessoas_pendente', $pending_columns)) {
            $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN qtd_pessoas_pendente INTEGER DEFAULT NULL");
            error_log("✅ Coluna qtd_pessoas_pendente criada");
        }
        if (!in_array('valor_adicional_pendente', $pending_columns)) {
            $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN valor_adicional_pendente NUMERIC(10,2) DEFAULT NULL");
            error_log("✅ Coluna valor_adicional_pendente criada");
        }
        if (!in_array('qr_code_adicional_id', $pending_columns)) {
            $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN qr_code_adicional_id VARCHAR(255) DEFAULT NULL");
            error_log("✅ Coluna qr_code_adicional_id criada");
        }
        if (!in_array('qr_code_adicional_expira_em', $pending_columns)) {
            $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN qr_code_adicional_expira_em TIMESTAMP DEFAULT NULL");
            error_log("✅ Coluna qr_code_adicional_expira_em criada");
        }
        
        // Calcular valores atuais (sem incluir pendentes)
        $qtd_pessoas_atual = (int)($inscricao['qtd_pessoas'] ?? 1);
        $qtd_pessoas_nova = $qtd_pessoas_atual + $qtd_adicionar;
        
        $check_valor_cols = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'comercial_inscricoes' 
            AND column_name IN ('valor_total', 'valor_pago')
        ");
        $valor_columns = $check_valor_cols->fetchAll(PDO::FETCH_COLUMN);
        
        $valor_atual = 0;
        if (in_array('valor_total', $valor_columns)) {
            $valor_atual = (float)($inscricao['valor_total'] ?? 0);
        } elseif (in_array('valor_pago', $valor_columns)) {
            $valor_atual = (float)($inscricao['valor_pago'] ?? 0);
        }
        
        $valor_novo = $valor_atual + $valor_adicional_total;
        
        // Salvar apenas nos campos PENDENTES (não atualizar valores reais ainda)
        $data_expiracao = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $update_pending = [];
        $update_pending_params = [':id' => $inscricao_id];
        
        $update_pending[] = "qtd_pessoas_pendente = :qtd_pendente";
        $update_pending_params[':qtd_pendente'] = $qtd_adicionar;
        
        $update_pending[] = "valor_adicional_pendente = :valor_pendente";
        $update_pending_params[':valor_pendente'] = $valor_adicional_total;
        
        $update_pending[] = "qr_code_adicional_expira_em = :expira_em";
        $update_pending_params[':expira_em'] = $data_expiracao;
        
        $stmt_pending = $pdo->prepare("UPDATE comercial_inscricoes SET " . implode(', ', $update_pending) . " WHERE id = :id");
        $stmt_pending->execute($update_pending_params);
        
        error_log("💾 Valores pendentes salvos: qtd={$qtd_adicionar}, valor={$valor_adicional_total}, expira em {$data_expiracao}");
        
        // Gerar QR Code apenas para o valor adicional total
        $asaasHelper = new AsaasHelper();
        $descricao = substr("Adicional: {$degustacao_info['nome']}", 0, 37); // Máximo 37 caracteres
        
        $qr_code_data = [
            'addressKey' => ASAAS_PIX_ADDRESS_KEY,
            'description' => $descricao,
            'value' => $valor_adicional_total,
            'expirationDate' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'allowsMultiplePayments' => false,
            'format' => 'PAYLOAD'
        ];
        
        $qr_result = $asaasHelper->createStaticQrCode($qr_code_data);
        
        // Log detalhado da resposta para debug
        error_log("📋 Resposta completa do Asaas (adicionar pessoa): " . json_encode($qr_result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        // Corrigir verificação de erro
        if (!$qr_result) {
            error_log("❌ ERRO: Resposta do Asaas é null/vazia");
            throw new Exception("Erro ao gerar QR Code: Resposta vazia do Asaas");
        }
        
        // Verificar se tem 'success' na resposta
        if (isset($qr_result['success']) && $qr_result['success'] === false) {
            $error_msg = $qr_result['error'] ?? ($qr_result['message'] ?? 'Erro desconhecido ao gerar QR Code');
            error_log("❌ ERRO: Asaas retornou success=false: " . $error_msg);
            throw new Exception("Erro ao gerar QR Code: {$error_msg}");
        }
        
        // Se não tem 'success', verificar se tem 'id' (formato alternativo da resposta)
        if (!isset($qr_result['success']) && !isset($qr_result['id'])) {
            $error_msg = $qr_result['error'] ?? ($qr_result['message'] ?? 'Resposta inválida do Asaas');
            error_log("❌ ERRO: Resposta do Asaas não tem 'id' nem 'success'. Campos disponíveis: " . implode(', ', array_keys($qr_result)));
            error_log("❌ ERRO: Resposta completa: " . json_encode($qr_result));
            throw new Exception("Erro ao gerar QR Code: {$error_msg}");
        }
        
        $qr_code_id = $qr_result['id'] ?? '';
        error_log("✅ QR Code ID obtido: {$qr_code_id}");
        
        // O Asaas pode retornar o payload em diferentes campos dependendo do format
        // Tentar múltiplos campos possíveis
        $qr_code_payload = '';
        if (isset($qr_result['payload']) && !empty($qr_result['payload'])) {
            $qr_code_payload = $qr_result['payload'];
            error_log("✅ Payload encontrado em 'payload' (tamanho: " . strlen($qr_code_payload) . " chars)");
        } elseif (isset($qr_result['pixCopyPaste']) && !empty($qr_result['pixCopyPaste'])) {
            $qr_code_payload = $qr_result['pixCopyPaste'];
            error_log("✅ Payload encontrado em 'pixCopyPaste' (tamanho: " . strlen($qr_code_payload) . " chars)");
        } elseif (isset($qr_result['encodedImage']) && !empty($qr_result['encodedImage'])) {
            $qr_code_payload = $qr_result['encodedImage'];
            error_log("✅ Payload encontrado em 'encodedImage' (tamanho: " . strlen($qr_code_payload) . " chars)");
        } else {
            error_log("⚠️ AVISO: Payload não encontrado em campos esperados. Campos disponíveis: " . implode(', ', array_keys($qr_result)));
            error_log("⚠️ AVISO: Tentando usar primeiro campo que contenha string longa...");
            
            // Última tentativa: buscar qualquer campo que seja uma string longa (provavelmente o payload)
            foreach ($qr_result as $key => $value) {
                if (is_string($value) && strlen($value) > 50) {
                    $qr_code_payload = $value;
                    error_log("✅ Payload encontrado no campo '{$key}' (tamanho: " . strlen($qr_code_payload) . " chars)");
                    break;
                }
            }
        }
        
        if (empty($qr_code_payload)) {
            error_log("❌ ERRO CRÍTICO: Payload vazio após todas as tentativas. Resposta completa: " . json_encode($qr_result));
            throw new Exception("QR Code gerado mas payload não retornado. ID: {$qr_code_id}. Verifique os logs.");
        }
        
        error_log("✅ Payload final obtido com sucesso (tamanho: " . strlen($qr_code_payload) . " chars, primeiros 50 chars: " . substr($qr_code_payload, 0, 50) . "...)");
        
        // Atualizar inscrição com novo QR Code (substituir o anterior, pois é uma cobrança adicional)
        $check_qr_cols = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'comercial_inscricoes' 
            AND column_name IN ('asaas_qr_code_id', 'qr_code_payload', 'qr_code_image')
        ");
        $qr_columns = $check_qr_cols->fetchAll(PDO::FETCH_COLUMN);
        
        $update_fields = [];
        $update_params = [':id' => $inscricao_id];
        
        // Salvar QR Code adicional nos campos específicos (não substituir o QR Code principal)
        // Verificar se coluna qr_code_adicional_id existe (já criamos acima)
        if (in_array('qr_code_adicional_id', $pending_columns) || true) { // true porque acabamos de criar
            $update_fields[] = "qr_code_adicional_id = :qr_code_adicional_id";
            $update_params[':qr_code_adicional_id'] = $qr_code_id;
        }
        
        // Salvar payload do QR Code adicional (usar campo temporário ou existente)
        if (in_array('qr_code_payload', $qr_columns)) {
            // Para QR Code adicional, vamos salvar em um campo separado se existir
            // Por enquanto, vamos usar qr_code_payload mesmo (será substituído quando pagar)
            $update_fields[] = "qr_code_payload = :payload";
            $update_params[':payload'] = $qr_code_payload;
        } elseif (in_array('qr_code_image', $qr_columns)) {
            $update_fields[] = "qr_code_image = :payload";
            $update_params[':payload'] = $qr_code_payload;
        }
        
        if (!empty($update_fields)) {
            $update_sql = "UPDATE comercial_inscricoes SET " . implode(', ', $update_fields) . " WHERE id = :id";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute($update_params);
        }
        
        // Resetar status de pagamento para aguardando (novo pagamento)
        $check_status_col = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'comercial_inscricoes' 
            AND column_name = 'pagamento_status'
        ");
        if ($check_status_col->fetchColumn()) {
            $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'aguardando' WHERE id = :id");
            $stmt->execute([':id' => $inscricao_id]);
        }
        
        returnJson([
            'success' => true,
            'message' => $qtd_adicionar == 1 ? 'QR Code gerado! Após o pagamento, a pessoa será adicionada automaticamente.' : "QR Code gerado! Após o pagamento, {$qtd_adicionar} pessoas serão adicionadas automaticamente.",
            'qr_code_id' => $qr_code_id,
            'payload' => $qr_code_payload,
            'valor' => $valor_adicional_total,
            'valor_por_pessoa' => $valor_adicional_por_pessoa,
            'qtd_adicionada' => $qtd_adicionar,
            'qtd_pessoas_antes' => $qtd_pessoas_atual,
            'qtd_pessoas_nova' => $qtd_pessoas_nova, // Mostrar apenas para preview - não será aplicado até pagar
            'valor_total_novo' => $valor_novo, // Mostrar apenas para preview - não será aplicado até pagar
            'observacao' => 'Os valores serão aplicados automaticamente quando o pagamento for confirmado. Se não pagar em 1 hora, os valores pendentes serão cancelados.'
        ]);
        
    } catch (Throwable $e) {
        error_log("ERRO ao adicionar pessoa: " . $e->getMessage());
        returnJson([
            'success' => false,
            'error' => $e->getMessage(),
            'message' => 'Erro ao adicionar pessoa: ' . $e->getMessage()
        ], 400);
    }
}

if ($action === 'gerar_pagamento' && $inscricao_id > 0) {
    // CRÍTICO: Limpar qualquer output anterior (HTML, whitespace, etc)
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Definir headers ANTES de qualquer output
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Função auxiliar para retornar JSON e sair
    function returnJson($data, $httpCode = 200) {
        http_response_code($httpCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // Capturar erros fatais
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro fatal do servidor',
                'error_type' => 'Erro Fatal',
                'error_details' => 'Erro: ' . $error['message'] . ' em ' . $error['file'] . ':' . $error['line'],
                'message' => 'Erro interno do servidor. Verifique os logs.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    });
    
    try {
        require_once __DIR__ . '/asaas_helper.php';
        require_once __DIR__ . '/config_env.php';
        
        // Log inicial para debug
        error_log("=== INÍCIO geração de cobrança PIX ===");
        error_log("Inscrição ID: {$inscricao_id}");
        error_log("Event ID: " . ($event_id ?? 'não definido'));
        
        // Buscar inscrição
        $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE id = :id");
        $stmt->execute([':id' => $inscricao_id]);
        $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$inscricao) {
            error_log("ERRO: Inscrição não encontrada - ID: {$inscricao_id}");
            throw new Exception("Inscrição não encontrada (ID: {$inscricao_id})");
        }
        
        error_log("Inscrição encontrada: " . json_encode([
            'id' => $inscricao['id'],
            'nome' => $inscricao['nome'],
            'email' => $inscricao['email'],
            'tipo_festa' => $inscricao['tipo_festa'] ?? null,
            'qtd_pessoas' => $inscricao['qtd_pessoas'] ?? null
        ]));
        
        // Verificar se AsaasHelper está disponível (necessário para verificar QR Code existente)
        if (!class_exists('AsaasHelper')) {
            error_log("ERRO: Classe AsaasHelper não encontrada");
            throw new Exception("Erro interno: AsaasHelper não está disponível");
        }
        $asaasHelper = new AsaasHelper();
        
        // Verificar se já tem QR Code - Se sim, verificar se está pago ou expirado
        if (!empty($inscricao['asaas_qr_code_id'])) {
            error_log("QR Code já existe para inscrição {$inscricao_id}: " . $inscricao['asaas_qr_code_id']);
            
            // Verificar status do pagamento na inscrição
            $pagamento_status = $inscricao['pagamento_status'] ?? 'aguardando';
            
            // Se já foi pago, não permitir gerar novo
            if ($pagamento_status === 'pago' || $pagamento_status === 'confirmado' || $pagamento_status === 'paid') {
                error_log("Inscrição {$inscricao_id} já foi paga. Status: {$pagamento_status}");
                returnJson([
                    'success' => false,
                    'error' => 'Pagamento já foi realizado',
                    'error_type' => 'Pagamento Já Realizado',
                    'error_details' => 'Esta inscrição já foi paga. Não é possível gerar nova cobrança.',
                    'message' => 'Esta inscrição já foi paga. Não é possível gerar nova cobrança.'
                ], 400);
            }
            
            // Verificar se o QR Code está expirado consultando pagamentos relacionados no Asaas
            try {
                $qr_code_id = $inscricao['asaas_qr_code_id'];
                error_log("Verificando status do QR Code no Asaas: {$qr_code_id}");
                
                // Buscar pagamentos relacionados a este QR Code
                $payments = $asaasHelper->getPaymentsByStaticQrCode($qr_code_id);
                error_log("Pagamentos encontrados para QR Code {$qr_code_id}: " . json_encode($payments));
                
                $qr_code_valido = false;
                $qr_code_expirado = false;
                $qr_code_pago = false;
                
                if (isset($payments['data']) && is_array($payments['data'])) {
                    foreach ($payments['data'] as $payment) {
                        $payment_status = $payment['status'] ?? '';
                        
                        // Se algum pagamento foi confirmado, o QR Code foi pago
                        if (in_array($payment_status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH_APP', 'AWAITING_RISK_ANALYSIS'])) {
                            $qr_code_pago = true;
                            break;
                        }
                        
                        // Verificar se há pagamentos pendentes (QR Code ainda válido)
                        if ($payment_status === 'PENDING') {
                            $qr_code_valido = true;
                        }
                    }
                }
                
                // Verificar data de expiração do QR Code (se temos informação na resposta)
                // Se não há pagamentos pendentes e não foi pago, pode estar expirado
                if (!$qr_code_valido && !$qr_code_pago) {
                    // Verificar se temos data de criação do QR Code para calcular expiração
                    // QR Codes estáticos expiram em 1 hora por padrão (conforme criação)
                    $qr_code_expirado = true;
                    error_log("QR Code {$qr_code_id} parece estar expirado ou sem pagamentos pendentes");
                }
                
                // Se já foi pago, não permitir gerar novo
                if ($qr_code_pago) {
                    error_log("QR Code {$qr_code_id} já foi pago");
                    returnJson([
                        'success' => false,
                        'error' => 'QR Code já foi pago',
                        'error_type' => 'QR Code Já Pago',
                        'error_details' => 'Este QR Code já foi utilizado para pagamento. Não é possível gerar nova cobrança.',
                        'message' => 'Este QR Code já foi pago. Não é possível gerar nova cobrança.'
                    ], 400);
                }
                
                // Se está válido (tem pagamento pendente), retornar o existente
                if ($qr_code_valido) {
                    error_log("QR Code {$qr_code_id} ainda está válido e tem pagamento pendente");
                    returnJson([
                        'success' => true,
                        'qr_code_id' => $qr_code_id,
                        'payload' => $inscricao['qr_code_payload'] ?? $inscricao['qr_code_image'] ?? '',
                        'valor' => $inscricao['valor_total'] ?? $inscricao['valor_pago'] ?? 0,
                        'message' => 'QR Code válido já existe'
                    ]);
                }
                
                // Se expirado ou inválido, continuar o fluxo para gerar novo QR Code
                if ($qr_code_expirado) {
                    error_log("QR Code {$qr_code_id} expirado. Gerando novo QR Code...");
                    // Limpar QR Code antigo antes de gerar novo (verificando colunas dinamicamente)
                    try {
                        $check_clear_cols = $pdo->query("SELECT column_name FROM information_schema.columns 
                                                        WHERE table_name = 'comercial_inscricoes' 
                                                        AND column_name IN ('asaas_qr_code_id', 'qr_code_payload', 'qr_code_image')");
                        $clear_columns = $check_clear_cols->fetchAll(PDO::FETCH_COLUMN);
                        
                        $clear_fields = [];
                        if (in_array('asaas_qr_code_id', $clear_columns)) {
                            $clear_fields[] = "asaas_qr_code_id = NULL";
                        }
                        if (in_array('qr_code_payload', $clear_columns)) {
                            $clear_fields[] = "qr_code_payload = NULL";
                        } elseif (in_array('qr_code_image', $clear_columns)) {
                            $clear_fields[] = "qr_code_image = NULL";
                        }
                        
                        if (!empty($clear_fields)) {
                            $update_clear_sql = "UPDATE comercial_inscricoes SET " . implode(', ', $clear_fields) . " WHERE id = :id";
                            $update_clear = $pdo->prepare($update_clear_sql);
                            $update_clear->execute([':id' => $inscricao_id]);
                            error_log("QR Code antigo limpo com sucesso");
                        }
                    } catch (Exception $e_clear) {
                        error_log("Erro ao limpar QR Code antigo (não crítico): " . $e_clear->getMessage());
                    }
                    // Continuar o fluxo para gerar novo QR Code
                }
                
            } catch (Exception $e) {
                error_log("Erro ao verificar status do QR Code no Asaas: " . $e->getMessage());
                // Em caso de erro na verificação, assumir que pode estar expirado e permitir gerar novo
                error_log("Assumindo QR Code pode estar expirado e permitindo gerar novo...");
                // Limpar QR Code antigo antes de gerar novo (verificando colunas dinamicamente)
                try {
                    $check_clear_cols = $pdo->query("SELECT column_name FROM information_schema.columns 
                                                    WHERE table_name = 'comercial_inscricoes' 
                                                    AND column_name IN ('asaas_qr_code_id', 'qr_code_payload', 'qr_code_image')");
                    $clear_columns = $check_clear_cols->fetchAll(PDO::FETCH_COLUMN);
                    
                    $clear_fields = [];
                    if (in_array('asaas_qr_code_id', $clear_columns)) {
                        $clear_fields[] = "asaas_qr_code_id = NULL";
                    }
                    if (in_array('qr_code_payload', $clear_columns)) {
                        $clear_fields[] = "qr_code_payload = NULL";
                    } elseif (in_array('qr_code_image', $clear_columns)) {
                        $clear_fields[] = "qr_code_image = NULL";
                    }
                    
                    if (!empty($clear_fields)) {
                        $update_clear_sql = "UPDATE comercial_inscricoes SET " . implode(', ', $clear_fields) . " WHERE id = :id";
                        $update_clear = $pdo->prepare($update_clear_sql);
                        $update_clear->execute([':id' => $inscricao_id]);
                        error_log("QR Code antigo limpo com sucesso (fallback)");
                    }
                } catch (Exception $e2) {
                    error_log("Erro ao limpar QR Code antigo (não crítico): " . $e2->getMessage());
                }
                // Continuar o fluxo para gerar novo QR Code
            }
        }
        
        // Verificar se fechou contrato
        if ($inscricao['fechou_contrato'] === 'sim') {
            error_log("ERRO: Cliente já fechou contrato - Inscrição ID: {$inscricao_id}");
            throw new Exception("Cliente já fechou contrato, não é necessário pagamento");
        }
        
        // Buscar dados da degustação para calcular valor
        if (empty($event_id)) {
            error_log("ERRO: event_id não definido");
            throw new Exception("ID da degustação não foi informado");
        }
        
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $event_id]);
        $degustacao_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$degustacao_info) {
            error_log("ERRO: Degustação não encontrada - ID: {$event_id}");
            throw new Exception("Degustação não encontrada (ID: {$event_id})");
        }
        
        error_log("Degustação encontrada: " . $degustacao_info['nome']);
        
        // Verificar se AsaasHelper está disponível
        if (!class_exists('AsaasHelper')) {
            error_log("ERRO: Classe AsaasHelper não encontrada");
            throw new Exception("Erro interno: AsaasHelper não está disponível");
        }
        
        // Verificar chave PIX
        if (empty(ASAAS_PIX_ADDRESS_KEY)) {
            error_log("ERRO: Chave PIX não configurada");
            throw new Exception("Chave PIX não configurada. Configure ASAAS_PIX_ADDRESS_KEY no Railway.");
        }
        
        // Calcular valor (AsaasHelper já foi instanciado acima quando verificamos QR Code existente)
        $precos = [
            'casamento' => (float)($degustacao_info['preco_casamento'] ?? 150.00),
            '15anos' => (float)($degustacao_info['preco_15anos'] ?? 180.00),
            'incluidos_casamento' => (int)($degustacao_info['incluidos_casamento'] ?? 2),
            'incluidos_15anos' => (int)($degustacao_info['incluidos_15anos'] ?? 3),
            'extra' => (float)($degustacao_info['preco_extra'] ?? 50.00)
        ];
        
        $tipo_festa = $inscricao['tipo_festa'] ?? 'casamento';
        $qtd_pessoas = (int)($inscricao['qtd_pessoas'] ?? 1);
        
        error_log("Calculando valor - Tipo: {$tipo_festa}, Pessoas: {$qtd_pessoas}");
        
        $valor_info = $asaasHelper->calculateTotal($tipo_festa, $qtd_pessoas, $precos);
        $valor_total = $valor_info['valor_total'];
        
        error_log("Valor calculado: R$ " . number_format($valor_total, 2, ',', '.'));
        
        if ($valor_total <= 0) {
            error_log("ERRO: Valor inválido calculado - R$ {$valor_total}");
            throw new Exception("Valor inválido para pagamento (R$ " . number_format($valor_total, 2, ',', '.') . ")");
        }
        
        // Criar QR Code PIX estático (com payload para copia e cola)
        $descricao = substr("Degustação: {$degustacao_info['nome']} - " . ucfirst($tipo_festa), 0, 37); // Máximo 37 caracteres
        
        error_log("Criando QR Code PIX - Descrição: {$descricao}, Valor: R$ " . number_format($valor_total, 2, ',', '.'));
        
        // Especificar format como PAYLOAD para obter o código PIX copia e cola
        $qr_code_data = [
            'addressKey' => ASAAS_PIX_ADDRESS_KEY,
            'description' => $descricao,
            'value' => $valor_total,
            'expirationDate' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'allowsMultiplePayments' => false,
            'format' => 'PAYLOAD' // Formato PAYLOAD para obter código copia e cola
        ];
        
        error_log("Dados do QR Code: " . json_encode($qr_code_data));
        
        try {
            $qr_response = $asaasHelper->createStaticQrCode($qr_code_data);
            error_log("Resposta do Asaas: " . json_encode($qr_response));
        } catch (Exception $e) {
            error_log("ERRO na chamada do Asaas: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e; // Re-lançar para ser capturado pelo catch externo
        }
        
        if (!$qr_response) {
            error_log("ERRO: Resposta do Asaas é nula");
            throw new Exception("Erro ao criar QR Code no ASAAS: resposta vazia");
        }
        
        if (!isset($qr_response['id'])) {
            error_log("ERRO: QR Code criado mas sem ID. Resposta completa: " . json_encode($qr_response));
            throw new Exception("Erro ao criar QR Code no ASAAS: ID não retornado. Resposta: " . json_encode($qr_response));
        }
        
        $qr_code_id = $qr_response['id'];
        error_log("QR Code criado com sucesso - ID: {$qr_code_id}");
        
        // O Asaas retorna o payload no campo 'payload' independente do format
        $qr_payload = $qr_response['payload'] ?? $qr_response['encodedImage'] ?? '';
        
        if (empty($qr_payload)) {
            error_log("ERRO: Payload vazio. Resposta completa: " . json_encode($qr_response));
            throw new Exception("QR Code criado mas payload não retornado. Resposta do Asaas: " . json_encode($qr_response));
        }
        
        error_log("Payload recebido (tamanho): " . strlen($qr_payload) . " caracteres");
        
        // Atualizar inscrição com QR Code
        $update_fields = ["pagamento_status = 'aguardando'"];
        $update_params = [':id' => $inscricao_id];
        
        // Verificar colunas dinamicamente ANTES de adicionar aos parâmetros
        try {
            $check_stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                                       WHERE table_name = 'comercial_inscricoes' 
                                       AND column_name IN ('asaas_qr_code_id', 'qr_code_payload', 'qr_code_image', 'valor_total', 'valor_pago')");
            $check_columns = $check_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Adicionar asaas_qr_code_id se a coluna existir
            if (in_array('asaas_qr_code_id', $check_columns)) {
                $update_fields[] = "asaas_qr_code_id = :qr_code_id";
                $update_params[':qr_code_id'] = $qr_code_id;
            }
            
            // Adicionar payload se a coluna existir (prioridade para qr_code_payload)
            if (in_array('qr_code_payload', $check_columns)) {
                $update_fields[] = "qr_code_payload = :payload";
                $update_params[':payload'] = $qr_payload;
            } elseif (in_array('qr_code_image', $check_columns)) {
                // Se não tem payload mas tem image, usar image (pode ser usado para armazenar payload)
                $update_fields[] = "qr_code_image = :payload";
                $update_params[':payload'] = $qr_payload;
            }
            
            // Adicionar valor se a coluna existir
            if (in_array('valor_total', $check_columns)) {
                $update_fields[] = "valor_total = :valor";
                $update_params[':valor'] = $valor_total;
            } elseif (in_array('valor_pago', $check_columns)) {
                $update_fields[] = "valor_pago = :valor";
                $update_params[':valor'] = $valor_total;
            }
        } catch (PDOException $e) {
            error_log("Erro ao verificar colunas: " . $e->getMessage());
        }
        
        $update_sql = "UPDATE comercial_inscricoes SET " . implode(', ', $update_fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute($update_params);
        
        // Retornar JSON com payload para copiar
        error_log("=== SUCESSO na geração de cobrança PIX ===");
        returnJson([
            'success' => true,
            'qr_code_id' => $qr_code_id,
            'payload' => $qr_payload,
            'valor' => $valor_total,
            'message' => 'QR Code gerado com sucesso'
        ]);
        
    } catch (Exception $e) {
        error_log("=== ERRO FINAL ao gerar cobrança PIX ===");
        error_log("Mensagem: " . $e->getMessage());
        error_log("Arquivo: " . $e->getFile() . " | Linha: " . $e->getLine());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        $error_message = $e->getMessage();
        $error_type = 'Sistema';
        $error_details = '';
        
        // Verificar se é erro do Asaas
        if (strpos($error_message, 'Erro ASAAS') !== false || strpos($error_message, 'ASAAS') !== false || strpos($error_message, 'Asaas') !== false) {
            $error_type = 'Asaas API';
            // Extrair código de erro do Asaas se existir
            if (preg_match('/\[([^\]]+)\]/', $error_message, $matches)) {
                $error_details = 'Código: ' . $matches[1];
            }
            if (preg_match('/HTTP\s+(\d+)/', $error_message, $matches)) {
                $error_details .= ($error_details ? ' | ' : '') . 'HTTP ' . $matches[1];
            }
        } elseif (strpos($error_message, 'SQLSTATE') !== false || strpos($error_message, 'PDO') !== false || strpos($error_message, 'column') !== false) {
            $error_type = 'Banco de Dados';
            $error_details = $error_message;
        } elseif (strpos($error_message, 'cURL') !== false || strpos($error_message, 'HTTP') !== false || strpos($error_message, 'connection') !== false) {
            $error_type = 'Conectividade';
            $error_details = $error_message;
        } elseif (strpos($error_message, 'chave') !== false || strpos($error_message, 'key') !== false || strpos($error_message, 'configurada') !== false) {
            $error_type = 'Configuração';
            $error_details = $error_message;
        }
        
        returnJson([
            'success' => false,
            'message' => $error_message,
            'error' => $error_message,
            'error_type' => $error_type,
            'error_details' => $error_details ?: $error_message,
            'debug' => [
                'inscricao_id' => $inscricao_id ?? null,
                'event_id' => $event_id ?? null,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", substr($e->getTraceAsString(), 0, 500)) // Limitar tamanho
            ]
        ], 400);
    } catch (Throwable $e) {
        // Capturar qualquer erro não Exception (Error, ParseError, etc)
        error_log("=== ERRO FATAL ao gerar cobrança PIX ===");
        error_log("Tipo: " . get_class($e));
        error_log("Mensagem: " . $e->getMessage());
        error_log("Arquivo: " . $e->getFile() . " | Linha: " . $e->getLine());
        
        returnJson([
            'success' => false,
            'message' => 'Erro fatal: ' . $e->getMessage(),
            'error' => $e->getMessage(),
            'error_type' => 'Erro Fatal',
            'error_details' => get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine(),
            'debug' => [
                'inscricao_id' => $inscricao_id ?? null,
                'event_id' => $event_id ?? null,
                'type' => get_class($e)
            ]
        ], 500);
    }
}

// Endpoint para buscar payload PIX
if (isset($_GET['get_pix_payload'])) {
    // CRÍTICO: Limpar qualquer output anterior (HTML, whitespace, etc)
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Definir headers ANTES de qualquer output
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $insc_id = (int)$_GET['get_pix_payload'];
    
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
        
        // Montar SELECT dinamicamente
        $select_fields = [];
        
        // Verificar colunas de payload existem
        $check_payload_cols = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'comercial_inscricoes' 
            AND column_name IN ('qr_code_payload', 'qr_code_image', 'asaas_qr_code_id')
        ");
        $payload_columns = $check_payload_cols->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('qr_code_payload', $payload_columns)) {
            $select_fields[] = 'qr_code_payload';
        }
        if (in_array('qr_code_image', $payload_columns)) {
            $select_fields[] = 'qr_code_image';
        }
        if (in_array('asaas_qr_code_id', $payload_columns)) {
            $select_fields[] = 'asaas_qr_code_id';
        }
        
        if ($has_valor_total) {
            $select_fields[] = 'valor_total as valor';
        } elseif ($has_valor_pago) {
            $select_fields[] = 'valor_pago as valor';
        }
        
        if (empty($select_fields)) {
            throw new Exception('Nenhuma coluna de payload encontrada');
        }
        
        $sql = "SELECT " . implode(', ', $select_fields) . " FROM comercial_inscricoes WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $insc_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            throw new Exception('Inscrição não encontrada');
        }
        
        $payload = $data['qr_code_payload'] ?? $data['qr_code_image'] ?? '';
        $valor = isset($data['valor']) ? (float)$data['valor'] : 0;
        
        echo json_encode([
            'success' => !empty($payload),
            'payload' => $payload,
            'valor' => $valor
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'payload' => '',
            'valor' => 0,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Filtros
$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// A tabela comercial_inscricoes usa degustacao_id (conforme código existente em comercial_degust_public.php)
$where = ['i.degustacao_id = :event_id'];
$params = [':event_id' => $event_id];

// Log para debug
error_log("Buscando inscrições para degustação ID: $event_id usando coluna degustacao_id");

if ($status_filter) {
    $where[] = 'i.status = :status';
    $params[':status'] = $status_filter;
}

if ($search) {
    $where[] = '(i.nome ILIKE :search OR i.email ILIKE :search)';
    $params[':search'] = "%$search%";
}

// Verificar se as colunas de pagamento Asaas existem
$has_asaas_payment_id = false;
$has_valor_pago = false;
try {
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_name = 'comercial_inscricoes' 
                         AND column_name IN ('asaas_payment_id', 'valor_pago')");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $has_asaas_payment_id = in_array('asaas_payment_id', $columns);
    $has_valor_pago = in_array('valor_pago', $columns);
} catch (PDOException $e) {
    error_log("Erro ao verificar colunas: " . $e->getMessage());
}

// Verificar colunas de QR Code
$has_qr_code_payload = false;
$has_qr_code_image = false;
$has_qr_code_id = false;
try {
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_name = 'comercial_inscricoes' 
                         AND column_name IN ('qr_code_payload', 'qr_code_image', 'asaas_qr_code_id')");
    $qr_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $has_qr_code_payload = in_array('qr_code_payload', $qr_columns);
    $has_qr_code_image = in_array('qr_code_image', $qr_columns);
    $has_qr_code_id = in_array('asaas_qr_code_id', $qr_columns);
} catch (PDOException $e) {
    error_log("Erro ao verificar colunas QR Code: " . $e->getMessage());
}

// Buscar inscrições
$sql = "SELECT i.*, 
               CASE WHEN i.fechou_contrato = 'sim' THEN 'Sim' 
                    WHEN i.fechou_contrato = 'nao' THEN 'Não' 
                    ELSE 'Indefinido' END as fechou_contrato_text,
               CASE WHEN i.pagamento_status = 'pago' THEN 'Pago' 
                    WHEN i.pagamento_status = 'aguardando' THEN 'Aguardando' 
                    WHEN i.pagamento_status = 'expirado' THEN 'Expirado' 
                    ELSE 'N/A' END as pagamento_text" . 
        ($has_asaas_payment_id ? ", i.asaas_payment_id" : ", NULL::text as asaas_payment_id") . 
        ($has_valor_pago ? ", i.valor_pago" : ", NULL::numeric as valor_pago") .
        ($has_qr_code_payload ? ", i.qr_code_payload" : ", NULL::text as qr_code_payload") .
        ($has_qr_code_image ? ", i.qr_code_image" : ", NULL::text as qr_code_image") .
        ($has_qr_code_id ? ", i.asaas_qr_code_id" : ", NULL::text as asaas_qr_code_id") . "
        FROM comercial_inscricoes i
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.criado_em DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log para debug
error_log("SQL executado: " . $sql);
error_log("Parâmetros: " . json_encode($params));
error_log("Inscrições encontradas: " . count($inscricoes));
if (count($inscricoes) > 0) {
    error_log("Primeira inscrição: " . json_encode($inscricoes[0]));
}

// Estatísticas
$stats = [
    'total' => count($inscricoes),
    'confirmados' => count(array_filter($inscricoes, fn($i) => $i['status'] === 'confirmado')),
    'lista_espera' => count(array_filter($inscricoes, fn($i) => $i['status'] === 'lista_espera')),
    'fechou_contrato' => count(array_filter($inscricoes, fn($i) => $i['fechou_contrato'] === 'sim')),
        'compareceram' => count(array_filter($inscricoes, fn($i) => ($i['compareceu'] ?? 0) == 1))
];

// Iniciar buffer de saída
ob_start();
?>
<link rel="stylesheet" href="assets/css/custom_modals.css">
    <style>
        .inscritos-container {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0;
        }
        
        /* Garantir que não haja espaços extras ou duplicações */
        body {
            margin: 0;
            padding: 0;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .event-info {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .event-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e3a8a;
            margin: 0 0 10px 0;
        }
        
        .event-details {
            color: #6b7280;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0 0 5px 0;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .status-select {
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .inscritos-table {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            display: table;
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-header {
            background: #f8fafc;
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            color: #374151;
            display: table-row;
        }
        
        .table-header-cell {
            display: table-cell;
            padding: 15px 20px;
            border-right: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        
        .table-header-cell:last-child {
            border-right: none;
        }
        
        .table-row {
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: table-row;
        }
        
        .table-row:hover {
            background: #f8fafc;
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .table-cell {
            display: table-cell;
            padding: 15px 20px;
            border-right: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        
        .table-cell:last-child {
            border-right: none;
        }
        
        .participant-info {
            display: flex;
            flex-direction: column;
        }
        
        .participant-name {
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 5px 0;
        }
        
        .participant-email {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-sm {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-edit {
            background: #3b82f6;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
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
        
        .modal {
            display: none !important;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .modal.active {
            display: flex !important;
            align-items: center;
            justify-content: center;
            opacity: 1;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .form-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-radio-group {
            display: flex;
            gap: 15px;
        }
        
        .form-radio {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .form-radio input[type="radio"] {
            width: 16px;
            height: 16px;
            accent-color: #3b82f6;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .btn-cancel {
            background: #6b7280;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .btn-save {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
        }
    </style>
    
        <div class="inscritos-container">
            <!-- Header -->
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding: 1.25rem 1.5rem; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom: 2px solid #e5e7eb;">
                <div>
            <h1 class="page-title" style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #1e3a8a;">👥 Inscrições da Degustação</h1>
            <div style="margin-top: 0.5rem; font-size: 1.125rem; font-weight: 600; color: #1e3a8a;"><?= h($degustacao['nome']) ?></div>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <button class="btn-primary" onclick="exportCSV()" style="padding: 0.75rem 1.5rem; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3); display: inline-flex; align-items: center; gap: 0.5rem;">📊 Exportar CSV</button>
                </div>
            </div>
            
            <!-- Informações do Evento -->
    <div class="event-info" style="background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
        <div style="color: #6b7280; font-size: 14px; line-height: 1.8;">
            <div style="margin-bottom: 8px;">📅 <strong>Data:</strong> <?= date('d/m/Y', strtotime($degustacao['data'])) ?></div>
            <div style="margin-bottom: 8px;">🕐 <strong>Horário:</strong> <?= date('H:i', strtotime($degustacao['hora_inicio'])) ?> - <?= date('H:i', strtotime($degustacao['hora_fim'])) ?></div>
            <div style="margin-bottom: 8px;">📍 <strong>Local:</strong> <?= h($degustacao['local']) ?></div>
            <div>👥 <strong>Capacidade:</strong> <?= $degustacao['capacidade'] ?> pessoas</div>
                </div>
            </div>
            
            <!-- Mensagens -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    ✅ <?= h($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    ❌ <?= h($error_message) ?>
                </div>
            <?php endif; ?>
            
            <!-- Estatísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total de Inscrições</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['confirmados'] ?></div>
                    <div class="stat-label">Confirmados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['lista_espera'] ?></div>
                    <div class="stat-label">Lista de Espera</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['fechou_contrato'] ?></div>
                    <div class="stat-label">Fecharam Contrato</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['compareceram'] ?></div>
                    <div class="stat-label">Compareceram</div>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="filters">
                <input type="text" class="search-input" placeholder="Pesquisar por nome ou e-mail..." 
                       value="<?= h($search) ?>" onkeyup="searchInscritos(this.value)">
                <select class="status-select" onchange="filterByStatus(this.value)">
                    <option value="">Todos os status</option>
                    <option value="confirmado" <?= $status_filter === 'confirmado' ? 'selected' : '' ?>>Confirmados</option>
                    <option value="lista_espera" <?= $status_filter === 'lista_espera' ? 'selected' : '' ?>>Lista de Espera</option>
                    <option value="cancelado" <?= $status_filter === 'cancelado' ? 'selected' : '' ?>>Cancelados</option>
                </select>
                <button class="btn-primary" onclick="searchInscritos()">🔍 Buscar</button>
            </div>
            
            <!-- Tabela de Inscritos -->
            <table class="inscritos-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr class="table-header">
                        <th class="table-header-cell" style="text-align: left; width: 20%;">Participante</th>
                        <th class="table-header-cell" style="text-align: center; width: 10%;">Status</th>
                        <th class="table-header-cell" style="text-align: center; width: 10%;">Tipo de Festa</th>
                        <th class="table-header-cell" style="text-align: center; width: 8%;">Pessoas</th>
                        <th class="table-header-cell" style="text-align: center; width: 14%;">Contratado</th>
                        <th class="table-header-cell" style="text-align: center; width: 14%;">Comparecimento</th>
                        <th class="table-header-cell" style="text-align: center; width: 12%;">Pagamento</th>
                        <th class="table-header-cell" style="text-align: center; width: 28%;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($inscricoes as $inscricao): ?>
                        <tr class="table-row">
                            <td class="table-cell">
                        <div class="participant-info">
                            <div class="participant-name"><?= h($inscricao['nome']) ?></div>
                            <div class="participant-email"><?= h($inscricao['email']) ?></div>
                        </div>
                            </td>
                            
                            <td class="table-cell" style="text-align: center;">
                                <?php if ($inscricao['status'] === 'confirmado'): ?>
                                    <span style="color: #10b981; font-weight: 600;">✓</span>
                                <?php else: ?>
                                    <?= getStatusBadge($inscricao['status']) ?>
                                <?php endif; ?>
                            </td>
                            
                            <td class="table-cell" style="text-align: center;">
                                <?= ucfirst($inscricao['tipo_festa'] ?? 'N/A') ?>
                            </td>
                            
                            <td class="table-cell" style="text-align: center;">
                                <?= $inscricao['qtd_pessoas'] ?? 0 ?>
                            </td>
                            
                            <td class="table-cell" style="text-align: center;">
                                <?php 
                                $fechou = $inscricao['fechou_contrato'] === 'sim';
                                ?>
                                <label style="display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                                    <input type="checkbox" 
                                           <?= $fechou ? 'checked' : '' ?>
                                           onchange="marcarContrato(<?= $inscricao['id'] ?>, this.checked)"
                                           style="width: 18px; height: 18px; cursor: pointer;">
                                    <span style="font-size: 12px; color: <?= $fechou ? '#10b981' : '#6b7280' ?>; font-weight: 500;">
                                        <?= $fechou ? 'Sim' : 'Não' ?>
                                    </span>
                                </label>
                            </td>
                            
                            <td class="table-cell" style="text-align: center;">
                                <?php 
                                $compareceu = ($inscricao['compareceu'] ?? 0) == 1;
                                ?>
                                <label style="display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                                    <input type="checkbox" 
                                           <?= $compareceu ? 'checked' : '' ?>
                                           onchange="marcarComparecimento(<?= $inscricao['id'] ?>, this.checked)"
                                           style="width: 18px; height: 18px; cursor: pointer;">
                                    <span style="font-size: 12px; color: <?= $compareceu ? '#10b981' : '#6b7280' ?>; font-weight: 500;">
                                        <?= $compareceu ? 'Sim' : 'Não' ?>
                                    </span>
                                </label>
                            </td>
                            
                            <td class="table-cell" style="text-align: center;">
                                <div style="margin-bottom: 5px;"><?= $inscricao['pagamento_text'] ?? 'N/A' ?></div>
                                <?php if (isset($inscricao['valor_pago']) && $inscricao['valor_pago'] > 0): ?>
                                    <div style="font-size: 0.875rem; color: #6b7280;">R$ <?= number_format($inscricao['valor_pago'], 2, ',', '.') ?></div>
                                <?php endif; ?>
                            </td>
                            
                            <td class="table-cell" style="text-align: center;">
                                <div style="display: flex; gap: 5px; flex-wrap: wrap; justify-content: center;">
                                    <?php if ($inscricao['pagamento_status'] !== 'pago'): ?>
                                        <button type="button" class="btn-sm" 
                                                style="background: #3b82f6; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;"
                                                onclick="gerarCobranca(<?= $inscricao['id'] ?>, '<?= h(addslashes($inscricao['nome'])) ?>')"
                                                id="btnGerarCobranca_<?= $inscricao['id'] ?>">
                                            💳 Gerar Cobrança
                            </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($inscricao['fechou_contrato'] !== 'sim'): ?>
                                        <button type="button" class="btn-sm" 
                                                style="background: #10b981; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;"
                                                onclick="adicionarPessoa(<?= $inscricao['id'] ?>, '<?= h(addslashes($inscricao['nome'])) ?>')"
                                                id="btnAdicionarPessoa_<?= $inscricao['id'] ?>"
                                                title="Adicionar uma pessoa e gerar QR Code de R$ 50,00">
                                            ➕ Adicionar Pessoa
                                        </button>
                                    <?php endif; ?>
                                    
                                    <button type="button" class="btn-sm btn-danger" 
                                            onclick="excluirInscrito(<?= $inscricao['id'] ?>, '<?= h(addslashes($inscricao['nome'])) ?>').catch(err => console.error('Erro ao excluir:', err));"
                                            style="background: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                        🗑️ Excluir
                            </button>
                        </div>
                            </td>
                        </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
    </div>
    
    <!-- Modal de Comparecimento -->
<div class="modal" id="comparecimentoModal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Marcar Comparecimento</h3>
                <button class="close-btn" onclick="closeComparecimentoModal()">&times;</button>
            </div>
            
        <form method="POST" action="index.php?page=comercial_degust_inscritos&event_id=<?= $event_id ?>">
                <input type="hidden" name="action" value="marcar_comparecimento">
                <input type="hidden" name="inscricao_id" id="comparecimentoInscricaoId">
                
                <div class="form-group">
                    <div class="form-radio-group">
                        <div class="form-radio">
                            <input type="radio" name="compareceu" value="1" id="compareceu_sim">
                            <label for="compareceu_sim">Compareceu</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="compareceu" value="0" id="compareceu_nao">
                            <label for="compareceu_nao">Não compareceu</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeComparecimentoModal()">Cancelar</button>
                    <button type="submit" class="btn-save">Salvar</button>
                </div>
            </form>
        </div>
    </div>

<!-- Modal de Cobrança PIX -->
<div class="modal" id="cobrancaModal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">💳 Cobrança PIX</h3>
            <button class="close-btn" onclick="closeCobrancaModal()">&times;</button>
        </div>
        
        <div class="modal-body" id="cobrancaModalBody">
            <div style="text-align: center; padding: 2rem;">
                <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #3b82f6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: #64748b;">Gerando cobrança...</p>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.pix-code-container {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
    word-break: break-all;
    font-family: 'Courier New', monospace;
    font-size: 0.875rem;
    color: #1e293b;
}

.pix-code-label {
    font-size: 0.75rem;
    color: #64748b;
    margin-bottom: 0.5rem;
    font-weight: 600;
    text-transform: uppercase;
}

.details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.detail-item {
    padding: 0.75rem;
    background: #f8fafc;
    border-radius: 6px;
}

.detail-label {
    font-size: 0.75rem;
    color: #64748b;
    margin-bottom: 0.25rem;
}

.detail-value {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
}
</style>
    
    <!-- Modal de Contrato -->
<div class="modal" id="contratoModal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Status do Contrato</h3>
                <button class="close-btn" onclick="closeContratoModal()">&times;</button>
            </div>
            
        <form method="POST" action="index.php?page=comercial_degust_inscritos&event_id=<?= $event_id ?>">
                <input type="hidden" name="action" value="marcar_fechou_contrato">
                <input type="hidden" name="inscricao_id" id="contratoInscricaoId">
                
                <div class="form-group">
                    <label class="form-label">Fechou contrato?</label>
                    <div class="form-radio-group">
                        <div class="form-radio">
                            <input type="radio" name="fechou_contrato" value="sim" id="fechou_sim">
                            <label for="fechou_sim">Sim</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="fechou_contrato" value="nao" id="fechou_nao">
                            <label for="fechou_nao">Não</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nome do titular do contrato</label>
                    <input type="text" name="nome_titular_contrato" class="form-input" id="nomeTitular">
                </div>
                
                <div class="form-group">
                    <label class="form-label">3 primeiros dígitos do CPF</label>
                    <input type="text" name="cpf_3_digitos" class="form-input" maxlength="3" pattern="[0-9]{3}">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeContratoModal()">Cancelar</button>
                    <button type="submit" class="btn-save">Salvar</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function searchInscritos(query = '') {
            if (query === '') {
                query = document.querySelector('.search-input').value;
            }
            const status = document.querySelector('.status-select').value;
            let url = '?event_id=<?= $event_id ?>&search=' + encodeURIComponent(query);
            if (status) {
                url += '&status=' + encodeURIComponent(status);
            }
            window.location.href = url;
        }
        
        function filterByStatus(status) {
            const search = document.querySelector('.search-input').value;
            let url = '?event_id=<?= $event_id ?>&search=' + encodeURIComponent(search);
            if (status) {
                url += '&status=' + encodeURIComponent(status);
            }
            window.location.href = url;
        }
        
        function openComparecimentoModal(inscricaoId, compareceu) {
            const modal = document.getElementById('comparecimentoModal');
            document.getElementById('comparecimentoInscricaoId').value = inscricaoId;
            modal.style.display = 'flex';
            modal.classList.add('active');
            
            if (compareceu) {
                document.getElementById('compareceu_sim').checked = true;
            } else {
                document.getElementById('compareceu_nao').checked = true;
            }
        }
        
        function closeComparecimentoModal() {
            const modal = document.getElementById('comparecimentoModal');
            modal.classList.remove('active');
            modal.style.display = 'none';
        }
        
        function openContratoModal(inscricaoId, fechouContrato, nomeTitular) {
            const modal = document.getElementById('contratoModal');
            document.getElementById('contratoInscricaoId').value = inscricaoId;
            document.getElementById('nomeTitular').value = nomeTitular || '';
            modal.style.display = 'flex';
            modal.classList.add('active');
            
            if (fechouContrato === 'sim') {
                document.getElementById('fechou_sim').checked = true;
            } else {
                document.getElementById('fechou_nao').checked = true;
            }
        }
        
        function closeContratoModal() {
            const modal = document.getElementById('contratoModal');
            modal.classList.remove('active');
            modal.style.display = 'none';
        }
        
        function exportCSV() {
            // Coletar dados da tabela
            const rows = document.querySelectorAll('.table-row');
            let csv = 'Participante,Email,Telefone,Status,Tipo Festa,Pessoas,Fechou Contrato,Pagamento,Observações\n';
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('div');
                if (cells.length >= 6) {
                    const nome = cells[0]?.textContent?.trim() || '';
                    const email = cells[1]?.textContent?.trim() || '';
                    const telefone = cells[2]?.textContent?.trim() || '';
                    const status = cells[3]?.textContent?.trim() || '';
                    const tipoFesta = cells[4]?.textContent?.trim() || '';
                    const pessoas = cells[5]?.textContent?.trim() || '';
                    const fechou = cells[6]?.textContent?.trim() || '';
                    const pagamento = cells[7]?.textContent?.trim() || '';
                    const observacoes = ''; // Não disponível nesta view
                    
                    csv += `"${nome}","${email}","${telefone}","${status}","${tipoFesta}","${pessoas}","${fechou}","${pagamento}","${observacoes}"\n`;
                }
            });
            
            // Criar e baixar arquivo
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `inscritos_degustacao_<?= $degustacao['id'] ?>_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Fechar modais ao clicar fora
        document.getElementById('comparecimentoModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeComparecimentoModal();
            }
        });
        
        document.getElementById('contratoModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeContratoModal();
            }
        });
    
    // Gerar pagamento PIX e mostrar botão copiar
    async function gerarPagamentoPix(inscricaoId) {
        const btnPix = document.getElementById('btnPix_' + inscricaoId);
        const btnCopiar = document.getElementById('btnCopiar_' + inscricaoId);
        
        btnPix.disabled = true;
        btnPix.textContent = '⏳ Gerando...';
        
        try {
            const formData = new FormData();
            formData.append('action', 'gerar_pagamento');
            formData.append('inscricao_id', inscricaoId);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Armazenar payload globalmente para copiar depois
                window['pixPayload_' + inscricaoId] = data.payload;
                
                // Mostrar botão copiar e esconder gerar
                btnPix.style.display = 'none';
                if (btnCopiar) {
                    btnCopiar.style.display = 'inline-block';
                }
                
                await customAlert('✅ QR Code PIX gerado com sucesso! Clique em "Copiar PIX" para enviar ao cliente.', 'Sucesso');
            } else {
                await customAlert('❌ Erro: ' + (data.message || 'Não foi possível gerar o QR Code'), 'Erro');
                btnPix.disabled = false;
                btnPix.textContent = '💳 Gerar PIX';
            }
        } catch (error) {
            console.error('Erro ao gerar PIX:', error);
            await customAlert('❌ Erro ao gerar PIX. Tente novamente.', 'Erro');
            btnPix.disabled = false;
            btnPix.textContent = '💳 Gerar PIX';
        }
    }
    
    // Gerar cobrança PIX e mostrar modal com detalhes
    async function gerarCobranca(inscricaoId, nomeInscrito) {
        const btnGerar = document.getElementById('btnGerarCobranca_' + inscricaoId);
        const modal = document.getElementById('cobrancaModal');
        const modalBody = document.getElementById('cobrancaModalBody');
        
        // Abrir modal com loading
        modal.style.display = 'flex';
        modal.classList.add('active');
        modalBody.innerHTML = `
            <div style="text-align: center; padding: 2rem;">
                <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #3b82f6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: #64748b;">Gerando cobrança...</p>
            </div>
        `;
        
        if (btnGerar) {
            btnGerar.disabled = true;
            btnGerar.textContent = '⏳ Gerando...';
        }
        
        try {
            // Gerar novo QR Code PIX com payload
            const formData = new FormData();
            formData.append('action', 'gerar_pagamento');
            formData.append('inscricao_id', inscricaoId);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            // Verificar status HTTP antes de processar JSON
            if (!response.ok) {
                console.error('Erro HTTP:', response.status, response.statusText);
            }
            
            // Verificar se a resposta é JSON válido
            let data;
            const contentType = response.headers.get('content-type') || '';
            
            // Sempre ler como texto primeiro para detectar HTML
            const textResponse = await response.text();
            
            if (contentType.includes('application/json')) {
                try {
                    console.log('Resposta bruta do servidor (primeiros 500 chars):', textResponse.substring(0, 500));
                    data = JSON.parse(textResponse);
                } catch (parseError) {
                    console.error('❌ Erro ao fazer parse do JSON:', parseError);
                    console.error('Resposta completa (primeiros 1000 chars):', textResponse.substring(0, 1000));
                    
                    // Detectar HTML
                    const trimmed = textResponse.trim();
                    if (trimmed.startsWith('<!DOCTYPE') || trimmed.startsWith('<html')) {
                        throw new Error('Servidor retornou HTML em vez de JSON. Isso geralmente indica um erro PHP ou redirecionamento para página de login. Verifique os logs do servidor.');
                    }
                    
                    throw new Error('Resposta do servidor não é JSON válido. Erro de parse: ' + parseError.message);
                }
            } else {
                console.error('❌ Resposta não é JSON. Content-Type:', contentType);
                console.error('Resposta completa (primeiros 1000 chars):', textResponse.substring(0, 1000));
                
                // Detectar HTML
                const trimmed = textResponse.trim();
                if (trimmed.startsWith('<!DOCTYPE') || trimmed.startsWith('<html')) {
                    throw new Error('Servidor retornou HTML em vez de JSON. Isso geralmente indica um erro PHP ou redirecionamento para página de login. Verifique os logs do servidor.');
                }
                
                throw new Error('Resposta do servidor inválida. Esperado JSON, recebido: ' + contentType + ' (Status: ' + response.status + ')');
            }
            
            // Log detalhado para debug
            console.log('✅ Resposta do servidor (parseada):', data);
            
            if (data.success && data.payload) {
                // Dados já estão na resposta inicial, não precisa buscar novamente
                // Mas se precisar buscar, fazer com tratamento de erro adequado
                let data2 = { valor: data.valor || 0, payload: data.payload };
                
                // Tentar buscar dados atualizados apenas se necessário
                try {
                    const response2 = await fetch('?event_id=<?= $event_id ?>&get_pix_payload=' + inscricaoId);
                    
                    // Verificar se é JSON válido
                    const contentType = response2.headers.get('content-type') || '';
                    if (contentType.includes('application/json')) {
                        const textResponse2 = await response2.text();
                        try {
                            const parsed = JSON.parse(textResponse2);
                            if (parsed.payload) {
                                data2 = parsed;
                            }
                        } catch (e) {
                            console.warn('Erro ao parsear resposta do get_pix_payload, usando dados iniciais:', e);
                        }
                    }
                } catch (e) {
                    console.warn('Erro ao buscar payload completo, usando dados iniciais:', e);
                    // Usar dados já recebidos da primeira resposta
                }
                
                const valorFormatado = new Intl.NumberFormat('pt-BR', {
                    style: 'currency',
                    currency: 'BRL'
                }).format(data.valor || data2.valor || 0);
                
                // Mostrar modal com detalhes
                modalBody.innerHTML = `
                    <div style="padding: 1rem;">
                        <div class="details-grid">
                            <div class="detail-item">
                                <div class="detail-label">Participante</div>
                                <div class="detail-value">${nomeInscrito}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Valor</div>
                                <div class="detail-value">${valorFormatado}</div>
                            </div>
                        </div>
                        
                        <div style="margin: 1rem 0;">
                            <div class="detail-label">Código PIX (Copia e Cola)</div>
                            <div class="pix-code-container">
                                <div id="pixCodeText">${data.payload}</div>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                            <button type="button" 
                                    onclick="copiarCodigoPix('${data.payload.replace(/'/g, "\\'")}')" 
                                    style="flex: 1; padding: 0.75rem; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                📋 Copiar Código PIX
                            </button>
                            <button type="button" 
                                    onclick="closeCobrancaModal(); window.location.reload();" 
                                    style="flex: 1; padding: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                ✅ Concluído
                            </button>
                        </div>
                    </div>
                `;
                
                if (btnGerar) {
                    btnGerar.disabled = false;
                    btnGerar.textContent = '💳 Gerar Cobrança';
                }
            } else {
                // Se já existe, buscar dados existentes (com tratamento de erro robusto)
                try {
                    const response2 = await fetch('?event_id=<?= $event_id ?>&get_pix_payload=' + inscricaoId);
                    
                    // Verificar se é JSON válido antes de parsear
                    const contentType2 = response2.headers.get('content-type') || '';
                    let data2;
                    
                    if (contentType2.includes('application/json')) {
                        const textResponse2 = await response2.text();
                        try {
                            data2 = JSON.parse(textResponse2);
                        } catch (parseError) {
                            console.error('Erro ao parsear JSON do get_pix_payload:', parseError);
                            throw new Error('Resposta inválida do servidor');
                        }
                    } else {
                        const textResponse2 = await response2.text();
                        console.error('Resposta não é JSON:', textResponse2.substring(0, 500));
                        throw new Error('Servidor retornou HTML em vez de JSON');
                    }
                    
                    if (data2.payload) {
                        const valorFormatado = new Intl.NumberFormat('pt-BR', {
                            style: 'currency',
                            currency: 'BRL'
                        }).format(data2.valor || 0);
                        
                        modalBody.innerHTML = `
                            <div style="padding: 1rem;">
                                <div class="details-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Participante</div>
                                        <div class="detail-value">${nomeInscrito}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Valor</div>
                                        <div class="detail-value">${valorFormatado}</div>
                                    </div>
                                </div>
                                
                                <div style="margin: 1rem 0;">
                                    <div class="detail-label">Código PIX (Copia e Cola)</div>
                                    <div class="pix-code-container">
                                        <div id="pixCodeText">${data2.payload}</div>
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                                    <button type="button" 
                                            onclick="copiarCodigoPix('${data2.payload.replace(/'/g, "\\'")}')" 
                                            style="flex: 1; padding: 0.75rem; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                        📋 Copiar Código PIX
                                    </button>
                                    <button type="button" 
                                            onclick="closeCobrancaModal()" 
                                            style="flex: 1; padding: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                        Fechar
                                    </button>
                                </div>
                            </div>
                        `;
                        
                        if (btnGerar) {
                            btnGerar.disabled = false;
                            btnGerar.textContent = '💳 Gerar Cobrança';
                        }
                        return;
                    }
                } catch (e) {
                    console.error('Erro ao buscar payload:', e);
                }
                
                // Construir mensagem de erro detalhada
                let errorMessage = data.message || data.error || 'Não foi possível gerar o código PIX';
                let errorTypeInfo = '';
                let errorDetailsInfo = '';
                
                if (data.error_type) {
                    errorTypeInfo = `<div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 0.75rem; margin: 1rem 0; border-radius: 4px; text-align: left;">
                        <div style="font-size: 0.75rem; color: #92400e; font-weight: 600; margin-bottom: 0.25rem;">Tipo de Erro:</div>
                        <div style="font-size: 0.875rem; color: #78350f;">${data.error_type}</div>
                    </div>`;
                }
                
                if (data.error_details) {
                    errorDetailsInfo = `<div style="background: #fee2e2; border-left: 4px solid #ef4444; padding: 0.75rem; margin: 1rem 0; border-radius: 4px; text-align: left;">
                        <div style="font-size: 0.75rem; color: #991b1b; font-weight: 600; margin-bottom: 0.25rem;">Detalhes:</div>
                        <div style="font-size: 0.75rem; color: #7f1d1d; font-family: monospace; word-break: break-all;">${data.error_details}</div>
                    </div>`;
                }
                
                modalBody.innerHTML = `
                    <div style="padding: 2rem;">
                        <div style="text-align: center; margin-bottom: 1.5rem;">
                            <div style="color: #ef4444; font-size: 3rem; margin-bottom: 1rem;">❌</div>
                            <p style="color: #1e293b; font-weight: 600; margin-bottom: 0.5rem; font-size: 1.125rem;">Erro ao gerar cobrança</p>
                            <p style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.5rem;">${errorMessage}</p>
                        </div>
                        
                        ${errorTypeInfo}
                        ${errorDetailsInfo}
                        
                        ${data.debug ? `<div style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 0.75rem; margin: 1rem 0; border-radius: 4px; text-align: left;">
                            <div style="font-size: 0.75rem; color: #475569; font-weight: 600; margin-bottom: 0.5rem; cursor: pointer;" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none';">
                                🔍 Detalhes Técnicos (clique para expandir)
                            </div>
                            <pre style="display: none; font-size: 0.7rem; color: #334155; overflow-x: auto; margin: 0;">${JSON.stringify(data.debug, null, 2)}</pre>
                        </div>` : ''}
                        
                        <div style="text-align: center; margin-top: 1.5rem;">
                            <button type="button" 
                                    onclick="closeCobrancaModal()" 
                                    style="padding: 0.75rem 1.5rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                Fechar
                            </button>
                        </div>
                    </div>
                `;
                
                if (btnGerar) {
                    btnGerar.disabled = false;
                    btnGerar.textContent = '💳 Gerar Cobrança';
                }
            }
            } catch (error) {
            console.error('Erro ao gerar cobrança:', error);
            
            // Tentar obter detalhes do erro
            let errorDetails = 'Ocorreu um erro inesperado.';
            let errorType = 'Erro Desconhecido';
            
            if (error.message) {
                errorDetails = error.message;
            }
            
            modalBody.innerHTML = `
                <div style="padding: 2rem; text-align: center;">
                    <div style="color: #ef4444; font-size: 3rem; margin-bottom: 1rem;">❌</div>
                    <p style="color: #1e293b; font-weight: 600; margin-bottom: 0.5rem;">Erro ao gerar cobrança</p>
                    <p style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.5rem;">${errorDetails}</p>
                    <p style="color: #9ca3af; font-size: 0.75rem; margin-top: 1rem;">Verifique o console do navegador (F12) para mais detalhes.</p>
                    <button type="button" 
                            onclick="closeCobrancaModal()" 
                            style="margin-top: 1.5rem; padding: 0.75rem 1.5rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        Fechar
                    </button>
                </div>
            `;
            
            if (btnGerar) {
                btnGerar.disabled = false;
                btnGerar.textContent = '💳 Gerar Cobrança';
            }
        }
    }
    
    // Adicionar pessoa e gerar cobrança adicional
    async function adicionarPessoa(inscricaoId, nomeInscrito) {
        // Usar modal customizado para perguntar quantidade (em vez de prompt)
        const qtdInput = await customPrompt(
            `➕ Adicionar pessoas à inscrição de "${nomeInscrito}"<br><br>` +
            `Quantas pessoas deseja adicionar?<br>` +
            `(Valor: R$ 50,00 por pessoa)<br><br>` +
            `Digite um número entre 1 e 10:`,
            '1',
            'Adicionar Pessoas'
        );
        
        if (!qtdInput || qtdInput.trim() === '') {
            return; // Usuário cancelou
        }
        
        const qtdPessoas = parseInt(qtdInput.trim());
        
        if (isNaN(qtdPessoas) || qtdPessoas <= 0) {
            await customAlert('❌ Por favor, digite um número válido maior que zero.', 'Valor Inválido');
            return;
        }
        
        if (qtdPessoas > 10) {
            await customAlert('❌ A quantidade máxima permitida é 10 pessoas por vez.', 'Limite Excedido');
            return;
        }
        
        const valorTotal = qtdPessoas * 50.00;
        const mensagemConfirmacao = qtdPessoas == 1 
            ? `Adicionar 1 pessoa à inscrição de "${nomeInscrito}"?\n\n• Valor: R$ 50,00\n• Será gerado um QR Code para o pagamento`
            : `Adicionar ${qtdPessoas} pessoas à inscrição de "${nomeInscrito}"?\n\n• Valor por pessoa: R$ 50,00\n• Total: R$ ${valorTotal.toFixed(2).replace('.', ',')}\n• Será gerado um QR Code para o pagamento`;
        
        const confirmacao = await customConfirm(mensagemConfirmacao, 'Confirmar Adição de Pessoas');
        
        if (!confirmacao) {
            return;
        }
        
        const btnAdicionar = document.getElementById('btnAdicionarPessoa_' + inscricaoId);
        if (btnAdicionar) {
            btnAdicionar.disabled = true;
            btnAdicionar.textContent = '⏳ Adicionando...';
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'adicionar_pessoa');
            formData.append('inscricao_id', inscricaoId);
            formData.append('qtd_pessoas', qtdPessoas);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                cache: 'no-store'
            });
            
            if (!response.ok) {
                throw new Error('Erro HTTP: ' + response.status);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                throw new Error('Resposta não é JSON. Resposta recebida: ' + text.substring(0, 200));
            }
            
            const data = await response.json();
            
            if (data.success) {
                // Restaurar botão ANTES de mostrar o modal
                if (btnAdicionar) {
                    btnAdicionar.disabled = false;
                    btnAdicionar.textContent = '➕ Adicionar Pessoa';
                }
                
                // Mostrar modal com QR Code
                const modal = document.getElementById('cobrancaModal');
                const modalBody = document.getElementById('cobrancaModalBody');
                
                if (!modal || !modalBody) {
                    console.error('❌ Modal não encontrado!');
                    await customAlert('❌ Erro: Modal não encontrado. Mas a pessoa foi adicionada com sucesso! Recarregue a página para ver as alterações.', 'Aviso');
                    window.location.reload();
                    return;
                }
                
                modal.style.display = 'flex';
                modal.classList.add('active');
                
                const qtdAdicionada = data.qtd_adicionada || 1;
                const valorPorPessoa = data.valor_por_pessoa || 50.00;
                const payload = data.payload || '';
                
                if (!payload) {
                    console.error('❌ Payload vazio na resposta:', data);
                    await customAlert('⚠️ Código PIX gerado mas não foi possível exibir. Verifique os logs.', 'Aviso');
                }
                
                modalBody.innerHTML = `
                    <div style="text-align: center;">
                        <h3 style="margin: 0 0 1rem 0; color: #3b82f6;">⏳ QR Code Gerado para Adição de ${qtdAdicionada == 1 ? 'Pessoa' : qtdAdicionada + ' Pessoas'}</h3>
                        <div style="background: #eff6ff; border: 2px solid #3b82f6; border-radius: 8px; padding: 1rem; margin: 1rem 0;">
                            <p style="margin: 0.5rem 0; font-weight: 600; color: #1e40af;">⚠️ IMPORTANTE: Os valores serão aplicados APENAS após confirmação do pagamento</p>
                            <p style="margin: 0.5rem 0; font-size: 0.875rem; color: #64748b;">Após o pagamento, o sistema atualizará automaticamente:</p>
                        </div>
                        <div style="background: #f0fdf4; border: 2px solid #10b981; border-radius: 8px; padding: 1rem; margin: 1rem 0;">
                            <p style="margin: 0.5rem 0;"><strong>Participante:</strong> ${nomeInscrito}</p>
                            <p style="margin: 0.5rem 0;"><strong>Quantidade atual:</strong> ${data.qtd_pessoas_antes} pessoa(s)</p>
                            <p style="margin: 0.5rem 0;"><strong>Quantidade a adicionar:</strong> ${qtdAdicionada} pessoa(s) <span style="color: #f59e0b; font-weight: 600;">(pendente)</span></p>
                            <p style="margin: 0.5rem 0;"><strong>Quantidade após pagamento:</strong> ${data.qtd_pessoas_nova} pessoa(s)</p>
                            <p style="margin: 0.5rem 0;"><strong>Valor por pessoa:</strong> R$ ${valorPorPessoa.toFixed(2).replace('.', ',')}</p>
                            <p style="margin: 0.5rem 0;"><strong>Valor a pagar (adicional):</strong> R$ ${data.valor.toFixed(2).replace('.', ',')}</p>
                            <p style="margin: 0.5rem 0; font-size: 0.875rem; color: #6b7280;">⏰ QR Code expira em 1 hora. Se não pagar, os valores pendentes serão cancelados.</p>
                        </div>
                        <div style="background: #fff; border: 2px solid #e5e7eb; border-radius: 8px; padding: 1.5rem; margin: 1rem 0;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Código PIX (Copiar e Colar):</label>
                            <textarea id="pixCodeAdicional" readonly 
                                      style="width: 100%; min-height: 80px; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-family: monospace; font-size: 0.875rem; resize: vertical; word-break: break-all;"
                                      onclick="this.select()">${payload}</textarea>
                        </div>
                        <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                            <button type="button" 
                                    onclick="copiarCodigoPix('${payload.replace(/'/g, "\\'").replace(/"/g, '&quot;')}')" 
                                    style="flex: 1; padding: 0.75rem; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                📋 Copiar Código PIX
                            </button>
                            <button type="button" 
                                    onclick="closeCobrancaModal(); window.location.reload();" 
                                    style="flex: 1; padding: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                ✅ Concluído
                            </button>
                        </div>
                    </div>
                `;
                
                console.log('✅ Modal exibido com sucesso. Payload tamanho:', payload.length);
            } else {
                await customAlert('❌ Erro: ' + (data.message || data.error || 'Erro desconhecido'), 'Erro');
                if (btnAdicionar) {
                    btnAdicionar.disabled = false;
                    btnAdicionar.textContent = '➕ Adicionar Pessoa';
                }
            }
        } catch (error) {
            console.error('Erro ao adicionar pessoa:', error);
            await customAlert('❌ Erro ao adicionar pessoa: ' + error.message, 'Erro');
            if (btnAdicionar) {
                btnAdicionar.disabled = false;
                btnAdicionar.textContent = '➕ Adicionar Pessoa';
            }
        }
    }
    
    // Copiar código PIX para área de transferência
    async function copiarCodigoPix(pixCode) {
        try {
            await navigator.clipboard.writeText(pixCode);
            await customAlert('✅ Código PIX copiado! Cole no aplicativo do banco do cliente.', 'Copiado');
        } catch (e) {
            // Fallback para navegadores antigos
            const textArea = document.createElement('textarea');
            textArea.value = pixCode;
            textArea.style.position = 'fixed';
            textArea.style.opacity = '0';
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            await customAlert('✅ Código PIX copiado! Cole no aplicativo do banco do cliente.', 'Copiado');
        }
    }
    
    // Fechar modal de cobrança
    function closeCobrancaModal() {
        const modal = document.getElementById('cobrancaModal');
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
    
    // Fechar modal ao clicar fora
    document.addEventListener('DOMContentLoaded', function() {
        const cobrancaModal = document.getElementById('cobrancaModal');
        if (cobrancaModal) {
            cobrancaModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeCobrancaModal();
                }
            });
        }
    });
    
    // Marcar contrato (sem solicitar nome do titular)
    function marcarContrato(inscricaoId, checked, nomeTitularAtual) {
        const formData = new FormData();
        formData.append('action', 'marcar_fechou_contrato');
        formData.append('inscricao_id', inscricaoId);
        formData.append('fechou_contrato', checked ? 'sim' : 'nao');
        // Não envia mais nome_titular_contrato nem cpf_3_digitos
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).then(() => {
            window.location.reload();
        }).catch(async () => {
            await customAlert('❌ Erro ao atualizar. Tente novamente.', 'Erro');
            // Reverter checkbox em caso de erro
            const checkbox = document.querySelector(`input[type="checkbox"][onchange*="marcarContrato(${inscricaoId}"]`);
            if (checkbox) {
                checkbox.checked = !checked;
            }
        });
    }
    
    // Marcar comparecimento
    function marcarComparecimento(inscricaoId, checked) {
        // Encontrar o checkbox que foi clicado para poder revertê-lo em caso de erro
        const checkbox = document.querySelector(`input[type="checkbox"][onchange*="marcarComparecimento(${inscricaoId}"]`);
        const originalChecked = checkbox ? checkbox.checked : checked;
        
        const formData = new FormData();
        formData.append('action', 'marcar_comparecimento');
        formData.append('inscricao_id', inscricaoId);
        formData.append('compareceu', checked ? '1' : '0');
        
        console.log('Atualizando comparecimento:', {
            inscricaoId: inscricaoId,
            checked: checked,
            valorEnviado: checked ? '1' : '0'
        });
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).then((response) => {
            if (!response.ok) {
                throw new Error('Erro HTTP: ' + response.status);
            }
            // Recarregar página para atualizar o estado
            window.location.reload();
        }).catch(async (error) => {
            console.error('Erro ao atualizar comparecimento:', error);
            await customAlert('❌ Erro ao atualizar comparecimento. Tente novamente.', 'Erro');
            // Reverter checkbox ao estado original
            if (checkbox) {
                checkbox.checked = !checked;
            }
        });
    }
    
    // Excluir inscrito - Usando customConfirm para melhor UX
    async function excluirInscrito(inscricaoId, nomeInscrito) {
        // IMPORTANTE: Sempre confirmar ANTES de qualquer ação usando customConfirm
        const mensagem = '⚠️ Tem certeza que deseja excluir o inscrito "' + nomeInscrito + '"?\n\nEsta ação não pode ser desfeita.';
        
        // Usar customConfirm (retorna Promise) em vez de confirm() padrão
        const confirmacao = await customConfirm(mensagem, 'Confirmar Exclusão');
        
        // Se não confirmou, não faz nada
        if (!confirmacao) {
            return false;
        }
        
        // Apenas se confirmou, criar e submeter formulário
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href;
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'excluir_inscrito';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'inscricao_id';
        idInput.value = inscricaoId;
        
        form.appendChild(actionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        
        // Submeter formulário
        form.submit();
        
        return false;
    }
    </script>
    
<link rel="stylesheet" href="assets/css/custom_modals.css">
<script src="assets/js/custom_modals.js"></script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Inscrições - ' . h($degustacao['nome']));
echo $conteudo;
endSidebar();
?>
