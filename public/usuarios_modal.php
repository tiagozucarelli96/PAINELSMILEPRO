<?php
// usuarios_modal.php
// Modal moderno para cadastro/edição de usuários com integração RH

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/lc_permissions_stub.php';

// Verificar permissões
if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

// Processar ações
$acao = $_POST['acao'] ?? '';
$usuario_id = $_POST['usuario_id'] ?? null;

$response = ['success' => false, 'message' => ''];

try {
    switch ($acao) {
        case 'criar':
        case 'editar':
            $nome = trim($_POST['nome'] ?? '');
            $login = trim($_POST['login'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $senha = trim($_POST['senha'] ?? '');
            $cargo = trim($_POST['cargo'] ?? '');
            $cpf = trim($_POST['cpf'] ?? '');
            $admissao_data = $_POST['admissao_data'] ?? null;
            $salario_base = $_POST['salario_base'] ?? null;
            $pix_tipo = $_POST['pix_tipo'] ?? '';
            $pix_chave = trim($_POST['pix_chave'] ?? '');
            $status_empregado = $_POST['status_empregado'] ?? 'ativo';
            $perfil = $_POST['perfil'] ?? 'OPER';
            
            // Permissões
            $permissoes = [
                'perm_tarefas' => isset($_POST['perm_tarefas']),
                'perm_lista' => isset($_POST['perm_lista']),
                'perm_demandas' => isset($_POST['perm_demandas']),
                'perm_pagamentos' => isset($_POST['perm_pagamentos']),
                'perm_usuarios' => isset($_POST['perm_usuarios']),
                'perm_portao' => isset($_POST['perm_portao']),
                'perm_banco_smile' => isset($_POST['perm_banco_smile']),
                'perm_banco_smile_admin' => isset($_POST['perm_banco_smile_admin']),
                'perm_notas_fiscais' => isset($_POST['perm_notas_fiscais']),
                // 'perm_estoque_logistico' => isset($_POST['perm_estoque_logistico']), // REMOVIDO: Módulo desativado
                'perm_dados_contrato' => isset($_POST['perm_dados_contrato']),
                'perm_uso_fiorino' => isset($_POST['perm_uso_fiorino'])
            ];
            
            // Validações
            if (empty($nome) || empty($login)) {
                throw new Exception('Nome e login são obrigatórios');
            }
            
            if ($acao === 'criar' && empty($senha)) {
                throw new Exception('Senha é obrigatória para novos usuários');
            }
            
            // Verificar se login já existe (apenas para novos usuários)
            if ($acao === 'criar') {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE login = ? OR email = ?");
                $stmt->execute([$login, $email]);
                if ($stmt->fetch()) {
                    throw new Exception('Login ou email já existem');
                }
            }
            
            // Preparar dados para inserção/atualização
            $dados = [
                'nome' => $nome,
                'login' => $login,
                'email' => $email,
                'cargo' => $cargo,
                'cpf' => $cpf,
                'admissao_data' => $admissao_data ?: null,
                'salario_base' => $salario_base ?: null,
                'pix_tipo' => $pix_tipo,
                'pix_chave' => $pix_chave,
                'status_empregado' => $status_empregado,
                'perfil' => $perfil,
                'ativo' => 1
            ];
            
            // Adicionar permissões
            foreach ($permissoes as $perm => $valor) {
                $dados[$perm] = $valor ? 1 : 0;
            }
            
            if ($acao === 'criar') {
                // Criar usuário
                $dados['senha'] = password_hash($senha, PASSWORD_DEFAULT);
                $dados['criado_em'] = date('Y-m-d H:i:s');
                
                $campos = implode(', ', array_keys($dados));
                $placeholders = ':' . implode(', :', array_keys($dados));
                
                $sql = "INSERT INTO usuarios ($campos) VALUES ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($dados);
                
                $response['success'] = true;
                $response['message'] = 'Usuário criado com sucesso!';
                $response['usuario_id'] = $pdo->lastInsertId();
                
            } else {
                // Editar usuário
                if ($usuario_id) {
                    if (!empty($senha)) {
                        $dados['senha'] = password_hash($senha, PASSWORD_DEFAULT);
                    }
                    
                    $dados['atualizado_em'] = date('Y-m-d H:i:s');
                    
                    $sets = [];
                    foreach ($dados as $campo => $valor) {
                        $sets[] = "$campo = :$campo";
                    }
                    
                    $sql = "UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id = :id";
                    $dados['id'] = $usuario_id;
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($dados);
                    
                    $response['success'] = true;
                    $response['message'] = 'Usuário atualizado com sucesso!';
                } else {
                    throw new Exception('ID do usuário não fornecido');
                }
            }
            break;
            
        case 'buscar':
            if ($usuario_id) {
                $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
                $stmt->execute([$usuario_id]);
                $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($usuario) {
                    $response['success'] = true;
                    $response['usuario'] = $usuario;
                } else {
                    $response['message'] = 'Usuário não encontrado';
                }
            } else {
                $response['message'] = 'ID do usuário não fornecido';
            }
            break;
            
        default:
            $response['message'] = 'Ação não reconhecida';
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>
