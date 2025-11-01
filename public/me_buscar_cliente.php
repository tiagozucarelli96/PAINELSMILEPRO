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
        $cpf = preg_replace('/\D/', '', $input['cpf'] ?? ''); // Remove tudo que não é número
        
        if (empty($nome_cliente) || empty($cpf) || strlen($cpf) < 11) {
            throw new Exception('Nome e CPF são obrigatórios');
        }
        
        // Buscar eventos deste cliente
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
        
        // Log da resposta completa para debug (remover em produção)
        error_log("ME Buscar Cliente - Resposta completa: " . json_encode($events[0] ?? [], JSON_PRETTY_PRINT));
        
        // Buscar evento específico deste cliente
        $evento_encontrado = null;
        foreach ($events as $e) {
            $nomeCliente = trim($e['nomeCliente'] ?? '');
            
            // Comparar nomes (normalizado)
            if (mb_strtolower($nomeCliente) === mb_strtolower($nome_cliente)) {
                // Buscar CPF na resposta da API (testar TODOS os campos possíveis)
                // A API ME Eventos pode retornar CPF em diferentes formatos
                $cpf_api = $e['cpfCliente'] ?? $e['cpf'] ?? $e['cliente_cpf'] ?? $e['documento'] ?? 
                          $e['cpf_cliente'] ?? $e['clienteCpf'] ?? $e['cpfCliente'] ?? 
                          $e['documentoCliente'] ?? $e['cpfDocumento'] ?? $e['cpfcnpj'] ?? '';
                
                // Log detalhado para debug
                error_log("ME Buscar Cliente - Tentando encontrar CPF na API. Campos testados: " . json_encode([
                    'cpfCliente' => $e['cpfCliente'] ?? null,
                    'cpf' => $e['cpf'] ?? null,
                    'cliente_cpf' => $e['cliente_cpf'] ?? null,
                    'documento' => $e['documento'] ?? null,
                    'cpf_cliente' => $e['cpf_cliente'] ?? null,
                    'clienteCpf' => $e['clienteCpf'] ?? null,
                    'documentoCliente' => $e['documentoCliente'] ?? null,
                    'cpfDocumento' => $e['cpfDocumento'] ?? null,
                    'cpfcnpj' => $e['cpfcnpj'] ?? null,
                    'cpf_encontrado' => $cpf_api ?: 'NÃO ENCONTRADO'
                ], JSON_UNESCAPED_UNICODE));
                
                // VALIDAÇÃO OBRIGATÓRIA: A API DEVE retornar CPF para validar
                if (empty($cpf_api)) {
                    error_log("ME Buscar Cliente - ERRO: CPF não encontrado na resposta da API ME Eventos");
                    throw new Exception('Não foi possível validar o CPF. A API não retornou os dados necessários. Entre em contato com a equipe.');
                }
                
                // CPF encontrado na API - validar
                $cpf_api_limpo = preg_replace('/\D/', '', $cpf_api);
                $cpf_limpo = preg_replace('/\D/', '', $cpf);
                
                error_log("ME Buscar Cliente - Comparando CPFs: API='$cpf_api_limpo' vs Digitado='$cpf_limpo'");
                
                // CPF deve bater EXATAMENTE
                if ($cpf_api_limpo !== $cpf_limpo) {
                    error_log("ME Buscar Cliente - CPF NÃO CONFERE! Rejeitando.");
                    throw new Exception('CPF não confere com o cadastro. Verifique os dados digitados.');
                }
                
                error_log("ME Buscar Cliente - CPF VALIDADO COM SUCESSO!");
                
                // Buscar email e telefone do cliente se disponível na API (testar todos os campos possíveis)
                $email = $e['emailCliente'] ?? $e['email'] ?? $e['cliente_email'] ?? 
                        $e['emailCliente'] ?? $e['emailCliente'] ?? $e['contato_email'] ?? 
                        $e['email_contato'] ?? '';
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
                
                // Log dos dados encontrados para debug
                error_log("ME Buscar Cliente - Evento encontrado: " . json_encode($evento_encontrado, JSON_UNESCAPED_UNICODE));
                
                break;
            }
        }
        
        if (!$evento_encontrado) {
            throw new Exception('Cliente não encontrado. Verifique o nome digitado.');
        }
        
        // Retornar dados do evento encontrado
        echo json_encode([
            'ok' => true,
            'evento' => $evento_encontrado,
            'cpf_validado' => true // Será true quando validar CPF na API
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

