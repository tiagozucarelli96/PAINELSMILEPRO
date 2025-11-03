<?php
/**
 * Sistema robusto de salvamento de usuários
 * Detecta dinamicamente todas as colunas existentes e constrói SQL seguro
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';

// Garantir que $pdo está disponível
if (!isset($pdo)) {
    global $pdo;
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Erro de conexão com banco de dados']);
    exit;
}

/**
 * Classe para gerenciar salvamento de usuários de forma robusta
 */
class UsuarioSaveManager {
    private $pdo;
    private $existingColumns = null;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Obter todas as colunas existentes na tabela usuarios
     */
    private function getExistingColumns() {
        if ($this->existingColumns !== null) {
            return $this->existingColumns;
        }
        
        try {
            $stmt = $this->pdo->query("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND table_name = 'usuarios'
                ORDER BY ordinal_position
            ");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $this->existingColumns = array_flip($columns); // Usar array_flip para busca O(1)
            return $this->existingColumns;
        } catch (Exception $e) {
            error_log("Erro ao obter colunas: " . $e->getMessage());
            // Retornar colunas básicas como fallback
            return array_flip(['id', 'nome', 'email', 'senha']);
        }
    }
    
    /**
     * Verificar se uma coluna existe
     */
    private function columnExists($columnName) {
        $columns = $this->getExistingColumns();
        return isset($columns[$columnName]);
    }
    
    /**
     * Validar nome de coluna (segurança)
     */
    private function validateColumnName($columnName) {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $columnName);
    }
    
    /**
     * Salvar usuário (criar ou atualizar)
     */
    public function save($data, $userId = 0) {
        try {
            $columns = $this->getExistingColumns();
            
            // Campos obrigatórios
            $required = ['nome', 'email'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Campo obrigatório faltando: $field");
                }
            }
            
            // Validar dados básicos
            $nome = trim($data['nome'] ?? '');
            $email = trim($data['email'] ?? '');
            $senha = trim($data['senha'] ?? '');
            $login = trim($data['login'] ?? $email); // Fallback para email se login não existir
            
            if (empty($nome) || empty($email)) {
                throw new Exception("Nome e email são obrigatórios");
            }
            
            if ($userId === 0 && empty($senha)) {
                throw new Exception("Senha é obrigatória para novos usuários");
            }
            
            // Campos opcionais com verificações
            $optionalFields = [
                'login', 'cargo', 'cpf', 'admissao_data', 'salario_base',
                'pix_tipo', 'pix_chave', 'status_empregado'
            ];
            
            // Permissões (todas as que começam com perm_)
            $permissions = [];
            foreach ($data as $key => $value) {
                if (strpos($key, 'perm_') === 0 && $this->validateColumnName($key)) {
                    $permissions[$key] = (isset($data[$key]) && $data[$key]) ? 1 : 0;
                }
            }
            
            if ($userId > 0) {
                return $this->update($userId, $nome, $email, $senha, $login, $optionalFields, $permissions, $data, $columns);
            } else {
                return $this->insert($nome, $email, $senha, $login, $optionalFields, $permissions, $data, $columns);
            }
            
        } catch (Exception $e) {
            error_log("Erro ao salvar usuário: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Atualizar usuário existente
     */
    private function update($userId, $nome, $email, $senha, $login, $optionalFields, $permissions, $data, $columns) {
        $sql = "UPDATE usuarios SET nome = :nome, email = :email";
        $params = [':nome' => $nome, ':email' => $email];
        
        // Adicionar login se existir
        if ($this->columnExists('login')) {
            $sql .= ", login = :login";
            $params[':login'] = $login;
        }
        
        // Adicionar senha se fornecida
        if (!empty($senha) && $this->columnExists('senha')) {
            $sql .= ", senha = :senha";
            $params[':senha'] = password_hash($senha, PASSWORD_DEFAULT);
        }
        
        // Adicionar campos opcionais
        foreach ($optionalFields as $field) {
            if ($this->columnExists($field)) {
                $value = $data[$field] ?? null;
                if ($field === 'salario_base') {
                    $value = (float)($value ?? 0);
                } elseif ($field === 'admissao_data') {
                    $value = !empty($value) ? $value : null;
                } else {
                    $value = trim($value ?? '');
                }
                
                $sql .= ", $field = :$field";
                $params[":$field"] = $value;
            }
        }
        
        // Adicionar permissões
        foreach ($permissions as $perm => $value) {
            if ($this->columnExists($perm)) {
                $sql .= ", $perm = :$perm";
                $params[":$perm"] = $value;
            }
        }
        
        $sql .= " WHERE id = :id";
        $params[':id'] = $userId;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return ['success' => true, 'message' => 'Usuário atualizado com sucesso', 'id' => $userId];
    }
    
    /**
     * Inserir novo usuário
     */
    private function insert($nome, $email, $senha, $login, $optionalFields, $permissions, $data, $columns) {
        $sqlCols = ['nome', 'email', 'senha'];
        $sqlVals = [':nome', ':email', ':senha'];
        $params = [
            ':nome' => $nome,
            ':email' => $email,
            ':senha' => password_hash($senha, PASSWORD_DEFAULT)
        ];
        
        // Adicionar login se existir
        if ($this->columnExists('login')) {
            $sqlCols[] = 'login';
            $sqlVals[] = ':login';
            $params[':login'] = $login;
        }
        
        // Adicionar campos opcionais
        foreach ($optionalFields as $field) {
            if ($this->columnExists($field)) {
                $value = $data[$field] ?? null;
                if ($field === 'salario_base') {
                    $value = (float)($value ?? 0);
                } elseif ($field === 'admissao_data') {
                    $value = !empty($value) ? $value : null;
                } else {
                    $value = trim($value ?? '');
                }
                
                $sqlCols[] = $field;
                $sqlVals[] = ":$field";
                $params[":$field"] = $value;
            }
        }
        
        // Adicionar permissões
        foreach ($permissions as $perm => $value) {
            if ($this->columnExists($perm)) {
                $sqlCols[] = $perm;
                $sqlVals[] = ":$perm";
                $params[":$perm"] = $value;
            }
        }
        
        $sql = "INSERT INTO usuarios (" . implode(', ', $sqlCols) . ") 
                VALUES (" . implode(', ', $sqlVals) . ") 
                RETURNING id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $newId = $result['id'] ?? null;
        
        return ['success' => true, 'message' => 'Usuário criado com sucesso', 'id' => $newId];
    }
}

