<?php
// database_helper.php
// Helper para resolver problemas de schema

class DatabaseHelper {
    private $pdo;
    private $schema = 'smilee12_painel_smile';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Executa query com schema explícito
     */
    public function query($sql, $params = []) {
        // Substituir tabelas sem schema por tabelas com schema
        $sql = $this->addSchemaToTables($sql);
        
        if (empty($params)) {
            return $this->pdo->query($sql);
        } else {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }
    }
    
    /**
     * Adiciona schema às tabelas na query
     */
    private function addSchemaToTables($sql) {
        // Lista de tabelas que precisam do schema
        $tables = [
            'usuarios', 'eventos', 'fornecedores', 'lc_categorias', 'lc_unidades',
            'lc_fichas', 'lc_listas', 'lc_insumos', 'comercial_degustacoes',
            'comercial_campos_padrao', 'pagamentos_solicitacoes', 'estoque_contagens',
            'demandas_quadros', 'demandas_cartoes', 'agenda_eventos', 'agenda_espacos'
        ];
        
        foreach ($tables as $table) {
            // Substituir tabela sem schema por tabela com schema
            $sql = preg_replace(
                '/\b' . preg_quote($table) . '\b(?!\s*\.)/',
                $this->schema . '.' . $table,
                $sql
            );
        }
        
        return $sql;
    }
    
    /**
     * Executa query e retorna todos os resultados
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Executa query e retorna um resultado
     */
    public function fetch($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Executa query e retorna uma coluna
     */
    public function fetchColumn($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Executa query de inserção/atualização
     */
    public function execute($sql, $params = []) {
        $stmt = $this->pdo->prepare($this->addSchemaToTables($sql));
        return $stmt->execute($params);
    }
    
    /**
     * Retorna o último ID inserido
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}

// Função global para facilitar uso
function db() {
    global $pdo;
    static $helper = null;
    if ($helper === null) {
        $helper = new DatabaseHelper($pdo);
    }
    return $helper;
}
?>
