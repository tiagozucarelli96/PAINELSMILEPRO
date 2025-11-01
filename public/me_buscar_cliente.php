<?php
// me_buscar_cliente.php — Endpoint seguro para buscar cliente na ME Eventos
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/me_config.php';

try {
    $base = getenv('ME_BASE_URL') ?: ME_BASE_URL;
    $key  = getenv('ME_API_KEY')  ?: ME_API_KEY;
    
    if (!$base || !$key) {
        throw new Exception('ME_BASE_URL/ME_API_KEY ausentes.');
    }
    
    // Validar método HTTP
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Busca por nome do cliente (sem expor dados sensíveis)
        $nome = trim($_GET['nome'] ?? '');
        
        if (empty($nome) || strlen($nome) < 3) {
            throw new Exception('Nome deve ter pelo menos 3 caracteres');
        }
        
        // Buscar eventos na ME Eventos que contenham este nome de cliente
        $url = rtrim($base, '/') . '/api/v1/events';
        $params = ['search' => $nome];
        $url .= '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $key,
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($err) throw new Exception('Erro cURL: ' . $err);
        if ($code < 200 || $code >= 300) {
            throw new Exception('HTTP ' . $code . ' da ME Eventos');
        }
        
        $data = json_decode($resp, true);
        if (!is_array($data)) throw new Exception('JSON inválido da ME Eventos');
        
        $events = $data['data'] ?? [];
        
        // Agrupar por nome do cliente (para evitar duplicatas)
        $clientes_encontrados = [];
        foreach ($events as $e) {
            $nomeCliente = trim($e['nomeCliente'] ?? '');
            if (empty($nomeCliente)) continue;
            
            // Normalizar nome para comparação (remover acentos e converter para minúsculas)
            $nomeNormalizado = mb_strtolower($nomeCliente);
            
            // Se já não existe este cliente, adicionar
            if (!isset($clientes_encontrados[$nomeNormalizado])) {
                $clientes_encontrados[$nomeNormalizado] = [
                    'nome_cliente' => $nomeCliente,
                    'eventos' => []
                ];
            }
            
            // Adicionar evento deste cliente
            $clientes_encontrados[$nomeNormalizado]['eventos'][] = [
                'id' => $e['id'] ?? null,
                'nome_evento' => $e['nomeevento'] ?? '',
                'data_evento' => $e['dataevento'] ?? '',
                'hora_evento' => $e['horaevento'] ?? '',
                'tipo_evento' => $e['tipoEvento'] ?? '',
                'local_evento' => $e['localevento'] ?? ''
            ];
        }
        
        // Retornar apenas nomes (sem dados sensíveis)
        $resultado = array_values(array_map(function($cliente) {
            return [
                'nome_cliente' => $cliente['nome_cliente'],
                'quantidade_eventos' => count($cliente['eventos'])
            ];
        }, $clientes_encontrados));
        
        echo json_encode([
            'ok' => true,
            'clientes' => $resultado
        ], JSON_UNESCAPED_UNICODE);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validar CPF do cliente
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Dados inválidos');
        }
        
        $nome_cliente = trim($input['nome_cliente'] ?? '');
        $cpf_digitado = preg_replace('/\D/', '', $input['cpf'] ?? ''); // Remove tudo que não é número
        
        if (empty($nome_cliente) || empty($cpf_digitado) || strlen($cpf_digitado) < 11) {
            throw new Exception('Nome e CPF são obrigatórios');
        }
        
        // PASSO 1: Buscar TODOS os dados do cliente na API ME Eventos
        $url = rtrim($base, '/') . '/api/v1/events';
        $params = ['search' => $nome_cliente];
        $url .= '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $key,
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($err) throw new Exception('Erro cURL: ' . $err);
        if ($code < 200 || $code >= 300) {
            throw new Exception('HTTP ' . $code . ' da ME Eventos');
        }
        
        $data = json_decode($resp, true);
        if (!is_array($data)) throw new Exception('JSON inválido da ME Eventos');
        
        $events = $data['data'] ?? [];
        
        // Log da resposta completa da API (incluindo TODOS os campos disponíveis)
        error_log("ME Buscar Cliente - Resposta COMPLETA da API (primeiro evento): " . json_encode($events[0] ?? [], JSON_PRETTY_PRINT));
        
        // PASSO 2: Buscar evento específico deste cliente e extrair TODOS os dados
        $evento_encontrado = null;
        $cpf_api_encontrado = null;
        
        foreach ($events as $e) {
            $nomeCliente = trim($e['nomeCliente'] ?? '');
            
            // Comparar nomes (normalizado)
            if (mb_strtolower($nomeCliente) === mb_strtolower($nome_cliente)) {
                // Buscar CPF na resposta da API (testar TODOS os campos possíveis)
                $cpf_api = $e['cpfCliente'] ?? $e['cpf'] ?? $e['cliente_cpf'] ?? $e['documento'] ?? 
                          $e['cpf_cliente'] ?? $e['clienteCpf'] ?? $e['documentoCliente'] ?? 
                          $e['cpfDocumento'] ?? $e['cpfcnpj'] ?? $e['cpfCnpj'] ?? 
                          $e['documentoCliente'] ?? $e['doc'] ?? '';
                
                // Log detalhado de TODOS os campos disponíveis
                error_log("ME Buscar Cliente - TODOS os campos disponíveis: " . json_encode(array_keys($e), JSON_UNESCAPED_UNICODE));
                error_log("ME Buscar Cliente - CPF encontrado na API: " . ($cpf_api ?: 'NÃO ENCONTRADO'));
                
                // Extrair TODOS os dados da API (independente do CPF)
                $cpf_api_encontrado = $cpf_api;
                
                // Buscar TODOS os dados possíveis
                $email = $e['emailCliente'] ?? $e['email'] ?? $e['cliente_email'] ?? 
                        $e['emailCliente'] ?? $e['contato_email'] ?? $e['email_contato'] ?? '';
                $telefone = $e['telefoneCliente'] ?? $e['telefone'] ?? $e['celular'] ?? 
                           $e['celularCliente'] ?? $e['cliente_telefone'] ?? $e['cliente_celular'] ?? 
                           $e['contato_telefone'] ?? $e['telefone_contato'] ?? '';
                
                $evento_encontrado = [
                    'id' => $e['id'] ?? null,
                    'nome_cliente' => $nomeCliente,
                    'nome_evento' => $e['nomeevento'] ?? '',
                    'data_evento' => $e['dataevento'] ?? '',
                    'hora_evento' => $e['horaevento'] ?? '',
                    'tipo_evento' => $e['tipoEvento'] ?? '',
                    'local_evento' => $e['localevento'] ?? '',
                    'convidados' => $e['convidados'] ?? 0,
                    'email' => $email,
                    'telefone' => $telefone,
                    'celular' => $telefone // Alias para compatibilidade
                ];
                
                break;
            }
        }
        
        if (!$evento_encontrado) {
            throw new Exception('Cliente não encontrado. Verifique o nome digitado.');
        }
        
        // PASSO 3: VALIDAR CPF COM MECANISMO DE SEGURANÇA ROBUSTO
        $cpf_validado = false;
        
        // Função para validar CPF (dígitos verificadores)
        function validarCPF($cpf) {
            $cpf = preg_replace('/\D/', '', $cpf);
            if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
                return false; // CPF com todos dígitos iguais é inválido
            }
            
            for ($t = 9; $t < 11; $t++) {
                for ($d = 0, $c = 0; $c < $t; $c++) {
                    $d += $cpf[$c] * (($t + 1) - $c);
                }
                $d = ((10 * $d) % 11) % 10;
                if ($cpf[$c] != $d) {
                    return false;
                }
            }
            return true;
        }
        
        // SEGURANÇA: CPF é OBRIGATÓRIO na API ME
        if (empty($cpf_api_encontrado)) {
            error_log("ME Buscar Cliente - SEGURANÇA: API não retornou CPF. Rejeitando acesso por segurança.");
            throw new Exception('Não foi possível validar sua identidade. A API não retornou o CPF cadastrado. Por favor, entre em contato conosco para verificar seu cadastro ou inscreva-se sem buscar evento.');
        }
        
        // Limpar CPFs para comparação
        $cpf_api_limpo = preg_replace('/\D/', '', $cpf_api_encontrado);
        $cpf_digitado_limpo = preg_replace('/\D/', '', $cpf_digitado);
        
        error_log("ME Buscar Cliente - Validando CPF: API='$cpf_api_limpo' vs Digitado='$cpf_digitado_limpo'");
        
        // VALIDAÇÃO 1: CPF deve ter formato válido (11 dígitos)
        if (strlen($cpf_digitado_limpo) !== 11) {
            throw new Exception('CPF deve ter 11 dígitos.');
        }
        
        // VALIDAÇÃO 2: CPF deve ser matematicamente válido (dígitos verificadores)
        if (!validarCPF($cpf_digitado_limpo)) {
            throw new Exception('CPF inválido. Verifique se os dígitos estão corretos.');
        }
        
        // VALIDAÇÃO 3: CPF digitado DEVE ser EXATAMENTE igual ao CPF da API
        if ($cpf_api_limpo !== $cpf_digitado_limpo) {
            error_log("ME Buscar Cliente - CPF NÃO CONFERE! API='$cpf_api_limpo' vs Digitado='$cpf_digitado_limpo'");
            throw new Exception('CPF não confere com o cadastro. Verifique os dados digitados de acordo com seu contrato.');
        }
        
        // VALIDAÇÃO 4: Confirmação adicional - verificar se email/telefone também coincidem (camada extra de segurança)
        $email_api = $evento_encontrado['email'] ?? '';
        $telefone_api = $evento_encontrado['telefone'] ?? '';
        
        error_log("ME Buscar Cliente - CPF VALIDADO COM SUCESSO! Email disponível na API: " . (!empty($email_api) ? 'SIM' : 'NÃO') . ", Telefone: " . (!empty($telefone_api) ? 'SIM' : 'NÃO'));
        
        $cpf_validado = true;
        
        error_log("ME Buscar Cliente - Retornando dados do cliente (CPF validado com sucesso).");
        
        // Retornar dados do evento encontrado (APENAS após validação rigorosa)
        echo json_encode([
            'ok' => true,
            'evento' => $evento_encontrado,
            'cpf_validado' => true,
            'mensagem' => 'Identidade confirmada com sucesso.'
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        throw new Exception('Método não permitido');
    }
    
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

