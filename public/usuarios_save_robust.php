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
            // Tentar múltiplas estratégias
            $stmt = $this->pdo->query("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND table_name = 'usuarios'
                ORDER BY ordinal_position
            ");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Se não encontrar, tentar sem especificar schema
            if (empty($columns)) {
                error_log("DEBUG: Tentando buscar colunas sem especificar schema");
                $stmt = $this->pdo->query("
                    SELECT column_name 
                    FROM information_schema.columns 
                    WHERE table_name = 'usuarios'
                    ORDER BY ordinal_position
                ");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            
            $this->existingColumns = array_flip($columns); // Usar array_flip para busca O(1)
            error_log("DEBUG getExistingColumns: Encontradas " . count($columns) . " colunas");
            error_log("DEBUG getExistingColumns: login existe? " . (isset($this->existingColumns['login']) ? 'SIM' : 'NÃO'));
            error_log("DEBUG getExistingColumns: email existe? " . (isset($this->existingColumns['email']) ? 'SIM' : 'NÃO'));
            error_log("DEBUG getExistingColumns: senha existe? " . (isset($this->existingColumns['senha']) ? 'SIM' : 'NÃO'));
            
            return $this->existingColumns;
        } catch (Exception $e) {
            error_log("Erro ao obter colunas: " . $e->getMessage());
            // Retornar colunas básicas como fallback
            return array_flip(['id', 'nome', 'email', 'senha', 'login']);
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
            
            // Campos obrigatórios - apenas nome é sempre obrigatório
            if (empty($data['nome'])) {
                throw new Exception("Nome é obrigatório");
            }
            
            // Validar dados básicos
            $nome = trim($data['nome'] ?? '');
            $email = trim($data['email'] ?? '');
            $senha = trim($data['senha'] ?? '');
            
            // Login: usar email como fallback se coluna login não existir
            $hasLoginColumn = $this->columnExists('login');
            error_log("DEBUG SAVE: hasLoginColumn = " . ($hasLoginColumn ? 'SIM' : 'NÃO'));
            error_log("DEBUG SAVE: data[login] = " . ($data['login'] ?? 'NÃO DEFINIDO'));
            error_log("DEBUG SAVE: email = " . ($email ?? 'NÃO DEFINIDO'));
            
            $login = $hasLoginColumn ? trim($data['login'] ?? $email ?? '') : ($email ?? '');
            error_log("DEBUG SAVE: login após trim = '$login'");
            
            // Se login está vazio mas coluna existe, usar email como fallback
            if ($hasLoginColumn && empty($login) && !empty($email)) {
                error_log("DEBUG SAVE: login vazio, usando email como fallback");
                $login = $email;
            }
            
            // Se login ainda está vazio mas coluna existe, é obrigatório
            if ($hasLoginColumn && empty($login)) {
                error_log("DEBUG SAVE: ERRO - login obrigatório mas está vazio!");
                throw new Exception("Login é obrigatório (use email como login se necessário)");
            }
            
            error_log("DEBUG SAVE: login final = '$login'");
            
            if (empty($nome)) {
                throw new Exception("Nome é obrigatório");
            }
            
            if (empty($email)) {
                throw new Exception("Email é obrigatório");
            }
            
            // Verificar se senha é obrigatória (coluna existe e é novo usuário)
            if ($this->columnExists('senha') && $userId === 0 && empty($senha)) {
                throw new Exception("Senha é obrigatória para novos usuários");
            }
            
            // Campos opcionais com verificações (login é tratado separadamente acima)
            $optionalFields = [
                'cargo', 'cpf', 'admissao_data', 'salario_base',
                'pix_tipo', 'pix_chave', 'status_empregado', 'foto'
            ];
            
            // Campos obrigatórios que podem ter valores padrão
            // Buscar TODAS as colunas NOT NULL que não são campos principais nem permissões
            $requiredFields = [];
            try {
                error_log("DEBUG SAVE: Iniciando busca de colunas NOT NULL...");
                
                // Tentar múltiplas estratégias
                $stmt = $this->pdo->query("
                    SELECT column_name, column_default, data_type
                    FROM information_schema.columns 
                    WHERE table_schema = 'public' 
                    AND table_name = 'usuarios'
                    AND is_nullable = 'NO'
                    AND column_name NOT IN ('id', 'nome', 'email', 'senha', 'login', 'created_at', 'updated_at')
                    AND column_name NOT LIKE 'perm_%'
                    ORDER BY column_name
                ");
                $notNullCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Se não encontrar, tentar sem especificar schema
                if (empty($notNullCols)) {
                    error_log("DEBUG SAVE: Tentando sem especificar schema...");
                    $stmt = $this->pdo->query("
                        SELECT column_name, column_default, data_type
                        FROM information_schema.columns 
                        WHERE table_name = 'usuarios'
                        AND is_nullable = 'NO'
                        AND column_name NOT IN ('id', 'nome', 'email', 'senha', 'login', 'created_at', 'updated_at')
                        AND column_name NOT LIKE 'perm_%'
                        ORDER BY column_name
                    ");
                    $notNullCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                error_log("DEBUG SAVE: Encontradas " . count($notNullCols) . " colunas NOT NULL");
                
                foreach ($notNullCols as $col) {
                    $colName = $col['column_name'];
                    error_log("DEBUG SAVE: Processando coluna NOT NULL: $colName");
                    
                    // Pular se já temos no data ou é campo que já tratamos
                    if (in_array($colName, ['cargo', 'cpf', 'admissao_data', 'salario_base', 'pix_tipo', 'pix_chave', 'status_empregado'])) {
                        error_log("DEBUG SAVE: Coluna $colName já tratada em optionalFields, pulando");
                        continue; // Já tratado em optionalFields
                    }
                    
                    // Obter valor do formulário ou usar default
                    $value = $data[$colName] ?? null;
                    error_log("DEBUG SAVE: Valor inicial de $colName = " . ($value ?? 'NULL'));
                    
                    // Se não tem valor e tem default, usar default
                    if (empty($value) && !empty($col['column_default'])) {
                        $default = $col['column_default'];
                        
                        // Se o default é CURRENT_TIMESTAMP ou similar, usar timestamp atual em PHP
                        if (preg_match('/CURRENT_TIMESTAMP|NOW\(\)|clock_timestamp\(\)|now\(\)/i', $default)) {
                            $value = date('Y-m-d H:i:s');
                            error_log("DEBUG SAVE: Default é CURRENT_TIMESTAMP/NOW(), usando timestamp atual: $value");
                        } else {
                            // Remover aspas se for string
                            $default = preg_replace("/^'(.*)'$/", '$1', $default);
                            $value = $default;
                            error_log("DEBUG SAVE: Usando default de $colName = $value");
                        }
                    }
                    
                    // Se ainda não tem valor, usar valor padrão baseado no tipo
                    if (empty($value) && $value !== '0' && $value !== 0 && $value !== false) {
                        if ($colName === 'funcao') {
                            $value = 'OPER'; // Valor padrão para funcao
                            error_log("DEBUG SAVE: Aplicando valor padrão 'OPER' para funcao");
                        } elseif (in_array($colName, ['criado_em', 'created_at', 'updated_at', 'atualizado_em'])) {
                            // Timestamps: usar timestamp atual
                            $value = date('Y-m-d H:i:s');
                            error_log("DEBUG SAVE: Coluna timestamp $colName, usando timestamp atual: $value");
                        } elseif (strpos($col['data_type'], 'timestamp') !== false || strpos($col['data_type'], 'date') !== false) {
                            // Qualquer coluna de data/timestamp: usar timestamp atual
                            $value = date('Y-m-d H:i:s');
                            error_log("DEBUG SAVE: Coluna de data/timestamp $colName, usando timestamp atual: $value");
                        } elseif (strpos($col['data_type'], 'int') !== false || strpos($col['data_type'], 'numeric') !== false) {
                            $value = 0;
                        } elseif (strpos($col['data_type'], 'bool') !== false) {
                            $value = false;
                        } else {
                            $value = ''; // String vazia como último recurso
                        }
                        error_log("DEBUG SAVE: Valor padrão para $colName = " . var_export($value, true));
                    }
                    
                    $requiredFields[$colName] = $value;
                    error_log("DEBUG SAVE: Coluna NOT NULL $colName adicionada com valor = " . var_export($value, true));
                }
                
                error_log("DEBUG SAVE: Total de requiredFields = " . count($requiredFields));
                if (!empty($requiredFields)) {
                    error_log("DEBUG SAVE: requiredFields = " . implode(', ', array_keys($requiredFields)));
                }
                
                // FALLBACK: Verificar especificamente colunas conhecidas que podem ser NOT NULL
                // Isso garante que mesmo se a query falhar, campos críticos serão incluídos
                $knownRequiredFields = ['funcao'];
                foreach ($knownRequiredFields as $fieldName) {
                    if (!isset($requiredFields[$fieldName]) && $this->columnExists($fieldName)) {
                        // Verificar se é NOT NULL
                        try {
                            $stmtCheck = $this->pdo->query("
                                SELECT is_nullable 
                                FROM information_schema.columns 
                                WHERE table_schema = 'public' 
                                AND table_name = 'usuarios' 
                                AND column_name = '$fieldName'
                            ");
                            $checkResult = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                            
                            // Se não encontrar, tentar sem schema
                            if (!$checkResult) {
                                $stmtCheck = $this->pdo->query("
                                    SELECT is_nullable 
                                    FROM information_schema.columns 
                                    WHERE table_name = 'usuarios' 
                                    AND column_name = '$fieldName'
                                ");
                                $checkResult = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                            }
                            
                            if ($checkResult && $checkResult['is_nullable'] === 'NO') {
                                $value = $data[$fieldName] ?? 'OPER';
                                $requiredFields[$fieldName] = $value;
                                error_log("DEBUG SAVE: FALLBACK - Adicionando $fieldName = $value (NOT NULL detectado)");
                            }
                        } catch (Exception $e) {
                            error_log("Erro ao verificar $fieldName: " . $e->getMessage());
                            // Se der erro mas coluna existe, adicionar mesmo assim como segurança
                            if ($this->columnExists($fieldName)) {
                                $value = $data[$fieldName] ?? 'OPER';
                                $requiredFields[$fieldName] = $value;
                                error_log("DEBUG SAVE: FALLBACK - Adicionando $fieldName = $value (por segurança)");
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("ERRO ao verificar colunas NOT NULL: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
            
            // Permissões (todas as que começam com perm_)
            $permissions = [];
            foreach ($data as $key => $value) {
                if (strpos($key, 'perm_') === 0 && $this->validateColumnName($key)) {
                    $permissions[$key] = (isset($data[$key]) && $data[$key]) ? 1 : 0;
                }
            }
            
            if ($userId > 0) {
                return $this->update($userId, $nome, $email, $senha, $login, $optionalFields, $requiredFields, $permissions, $data, $columns);
            } else {
                return $this->insert($nome, $email, $senha, $login, $optionalFields, $requiredFields, $permissions, $data, $columns);
            }
            
        } catch (Exception $e) {
            error_log("Erro ao salvar usuário: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Atualizar usuário existente
     */
    private function update($userId, $nome, $email, $senha, $login, $optionalFields, $requiredFields, $permissions, $data, $columns) {
        $sql = "UPDATE usuarios SET nome = :nome";
        $params = [':nome' => $nome];
        
        // Adicionar email se coluna existir
        if ($this->columnExists('email')) {
            $sql .= ", email = :email";
            $params[':email'] = $email;
        }
        
        // Adicionar login se existir
        if ($this->columnExists('login')) {
            $sql .= ", login = :login";
            $params[':login'] = $login;
        }
        
        // Adicionar senha se fornecida e coluna existir
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
    private function insert($nome, $email, $senha, $login, $optionalFields, $requiredFields, $permissions, $data, $columns) {
        error_log("DEBUG INSERT: nome=$nome, email=$email, login=$login, senha=" . (empty($senha) ? 'VAZIA' : 'PREENCHIDA'));
        
        $sqlCols = ['nome'];
        $sqlVals = [':nome'];
        $params = [':nome' => $nome];
        
        // Adicionar email se coluna existir
        if ($this->columnExists('email')) {
            error_log("DEBUG INSERT: Adicionando email");
            $sqlCols[] = 'email';
            $sqlVals[] = ':email';
            $params[':email'] = $email;
        } else {
            error_log("DEBUG INSERT: Coluna email NÃO existe");
        }
        
        // Adicionar senha se coluna existir e senha fornecida
        if ($this->columnExists('senha')) {
            if (empty($senha)) {
                throw new Exception("Senha é obrigatória para novos usuários");
            }
            error_log("DEBUG INSERT: Adicionando senha");
            $sqlCols[] = 'senha';
            $sqlVals[] = ':senha';
            $params[':senha'] = password_hash($senha, PASSWORD_DEFAULT);
        } else {
            error_log("DEBUG INSERT: Coluna senha NÃO existe");
        }
        
        // Adicionar login - SEMPRE adicionar se recebemos um valor (coluna provavelmente existe)
        // Se columnExists falhar mas login foi passado, adicionar mesmo assim
        $hasLogin = $this->columnExists('login');
        error_log("DEBUG INSERT: Coluna login existe? " . ($hasLogin ? 'SIM' : 'NÃO'));
        error_log("DEBUG INSERT: login recebido = '$login'");
        
        // Se login está vazio, usar email como fallback
        if (empty($login) && !empty($email)) {
            error_log("DEBUG INSERT: login está vazio, usando email como fallback");
            $login = $email;
        }
        
        // Se ainda estiver vazio, lançar erro
        if (empty($login)) {
            error_log("DEBUG INSERT: ERRO - login e email ambos vazios!");
            throw new Exception("Login é obrigatório. Preencha o campo Login ou Email.");
        }
        
        // SEMPRE adicionar login se temos um valor (assumindo que coluna existe)
        // Baseado no teste, sabemos que a coluna existe no banco
        error_log("DEBUG INSERT: Adicionando login com valor: $login");
        $sqlCols[] = 'login';
        $sqlVals[] = ':login';
        $params[':login'] = $login;
        
        // VERIFICAR E ADICIONAR TODAS AS COLUNAS NOT NULL QUE NÃO FORAM INCLUÍDAS
        // Isso garante que nenhuma coluna obrigatória seja esquecida
        // IMPORTANTE: Esta verificação acontece ANTES de finalizar o INSERT
        try {
            error_log("DEBUG INSERT: Verificando todas as colunas NOT NULL antes de finalizar INSERT...");
            
            // Tentar múltiplas estratégias para encontrar colunas NOT NULL
            $allNotNullCols = [];
            
            // Estratégia 1: Com schema 'public'
            try {
                $stmt = $this->pdo->query("
                    SELECT column_name, column_default, data_type
                    FROM information_schema.columns 
                    WHERE table_schema = 'public'
                    AND table_name = 'usuarios'
                    AND is_nullable = 'NO'
                    AND column_name NOT IN ('id', 'created_at', 'updated_at')
                    AND column_name NOT LIKE 'perm_%'
                ");
                $allNotNullCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("DEBUG INSERT: Estratégia 1 encontrou " . count($allNotNullCols) . " colunas NOT NULL");
            } catch (Exception $e) {
                error_log("DEBUG INSERT: Estratégia 1 falhou: " . $e->getMessage());
            }
            
            // Estratégia 2: Sem especificar schema
            if (empty($allNotNullCols)) {
                try {
                    $stmt = $this->pdo->query("
                        SELECT column_name, column_default, data_type
                        FROM information_schema.columns 
                        WHERE table_name = 'usuarios'
                        AND is_nullable = 'NO'
                        AND column_name NOT IN ('id', 'created_at', 'updated_at')
                        AND column_name NOT LIKE 'perm_%'
                    ");
                    $allNotNullCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    error_log("DEBUG INSERT: Estratégia 2 encontrou " . count($allNotNullCols) . " colunas NOT NULL");
                } catch (Exception $e) {
                    error_log("DEBUG INSERT: Estratégia 2 falhou: " . $e->getMessage());
                }
            }
            
            error_log("DEBUG INSERT: Total de colunas NOT NULL encontradas: " . count($allNotNullCols));
            
            foreach ($allNotNullCols as $col) {
                $colName = $col['column_name'];
                
                // Se já está incluída, pular
                if (in_array($colName, $sqlCols)) {
                    continue;
                }
                
                // Se está em optionalFields, já será tratado abaixo
                if (in_array($colName, $optionalFields)) {
                    continue;
                }
                
                // Obter valor
                $value = null;
                
                // 1. Tentar do formulário
                if (isset($data[$colName])) {
                    $value = $data[$colName];
                }
                
                // 2. Tentar default do banco
                if (empty($value) && !empty($col['column_default'])) {
                    $default = $col['column_default'];
                    
                    // Se o default é CURRENT_TIMESTAMP ou similar, usar timestamp atual em PHP
                    if (preg_match('/CURRENT_TIMESTAMP|NOW\(\)|clock_timestamp\(\)/i', $default)) {
                        $value = date('Y-m-d H:i:s');
                        error_log("DEBUG INSERT: Default é CURRENT_TIMESTAMP, usando timestamp atual: $value");
                    } else {
                        // Remover aspas se for string
                        $default = preg_replace("/^'(.*)'$/", '$1', $default);
                        $value = $default;
                    }
                }
                
                // 3. Valor padrão baseado no tipo/nome
                if (empty($value) && $value !== '0' && $value !== 0 && $value !== false) {
                    if ($colName === 'funcao') {
                        $value = 'OPER';
                    } elseif ($colName === 'nome') {
                        $value = $nome; // Já temos
                    } elseif ($colName === 'email') {
                        $value = $email; // Já temos
                    } elseif ($colName === 'login') {
                        $value = $login; // Já temos
                    } elseif ($colName === 'senha') {
                        $value = password_hash($senha, PASSWORD_DEFAULT); // Já temos
                    } elseif (in_array($colName, ['criado_em', 'created_at', 'updated_at', 'atualizado_em'])) {
                        // Timestamps: usar timestamp atual
                        $value = date('Y-m-d H:i:s');
                        error_log("DEBUG INSERT: Coluna timestamp $colName, usando timestamp atual: $value");
                    } elseif (strpos($col['data_type'], 'timestamp') !== false || strpos($col['data_type'], 'date') !== false) {
                        // Qualquer coluna de data/timestamp: usar timestamp atual
                        $value = date('Y-m-d H:i:s');
                        error_log("DEBUG INSERT: Coluna de data/timestamp $colName, usando timestamp atual: $value");
                    } elseif (strpos($col['data_type'], 'int') !== false || strpos($col['data_type'], 'numeric') !== false) {
                        $value = 0;
                    } elseif (strpos($col['data_type'], 'bool') !== false) {
                        $value = false;
                    } else {
                        $value = '';
                    }
                }
                
                // Adicionar ao INSERT
                if ($value !== null) {
                    // Verificar se já foi adicionada (pode ter sido adicionada pelos campos principais)
                    if (!in_array($colName, $sqlCols)) {
                        $sqlCols[] = $colName;
                        $sqlVals[] = ":$colName";
                        $params[":$colName"] = $value;
                        error_log("DEBUG INSERT: Coluna NOT NULL adicionada: $colName = " . var_export($value, true));
                    } else {
                        error_log("DEBUG INSERT: Coluna NOT NULL $colName já está incluída, pulando");
                    }
                } else {
                    error_log("DEBUG INSERT: AVISO - Coluna NOT NULL $colName não tem valor, pode causar erro!");
                }
            }
        } catch (Exception $e) {
            error_log("ERRO ao verificar colunas NOT NULL no INSERT: " . $e->getMessage());
        }
        
        // Adicionar campos obrigatórios do requiredFields (se ainda não foram adicionados)
        foreach ($requiredFields as $field => $value) {
            if (!in_array($field, $sqlCols) && $this->columnExists($field)) {
                error_log("DEBUG INSERT: Adicionando campo obrigatório $field = " . var_export($value, true));
                $sqlCols[] = $field;
                $sqlVals[] = ":$field";
                $params[":$field"] = $value;
            }
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
        
        error_log("DEBUG INSERT: SQL final = " . $sql);
        error_log("DEBUG INSERT: Colunas = " . implode(', ', $sqlCols));
        error_log("DEBUG INSERT: Params = " . json_encode(array_keys($params)));
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $newId = $result['id'] ?? null;
        
        return ['success' => true, 'message' => 'Usuário criado com sucesso', 'id' => $newId];
    }
}

