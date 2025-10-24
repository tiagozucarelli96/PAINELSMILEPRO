<?php
// corrigir_automaticamente.php — Correções automáticas do sistema
require_once __DIR__ . '/conexao.php';

class CorrecoesAutomaticas {
    private $pdo;
    private $logFile;
    private $correcoesAplicadas = [];
    private $errosEncontrados = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->logFile = __DIR__ . '/../logs/correcoes.log';
        $this->criarDiretorioLogs();
    }
    
    private function criarDiretorioLogs() {
        $logsDir = dirname($this->logFile);
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
    }
    
    private function log($mensagem, $tipo = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$tipo}] {$mensagem}\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    public function executarCorrecoes() {
        $this->log("=== INICIANDO CORREÇÕES AUTOMÁTICAS ===");
        
        echo "<h1>🔧 Correções Automáticas - Painel Smile PRO</h1>";
        echo "<style>
            body { font-family: Arial, sans-serif; margin: 20px; background: #f8fafc; }
            .container { max-width: 1200px; margin: 0 auto; }
            .success { color: #10b981; }
            .error { color: #ef4444; }
            .warning { color: #f59e0b; }
            .info { color: #3b82f6; }
            .section { margin: 20px 0; padding: 20px; border-radius: 8px; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .section h2 { margin-top: 0; color: #1f2937; }
            .step { margin: 15px 0; padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6; background: #f9fafb; }
            .step.success { border-left-color: #10b981; background: #f0fdf4; }
            .step.error { border-left-color: #ef4444; background: #fef2f2; }
            .step.warning { border-left-color: #f59e0b; background: #fffbeb; }
            .btn { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px; }
            .btn:hover { background: #1d4ed8; }
            .btn-success { background: #10b981; }
            .btn-success:hover { background: #059669; }
            .progress-bar { width: 100%; background: #e5e7eb; border-radius: 10px; overflow: hidden; margin: 10px 0; }
            .progress-fill { height: 20px; background: linear-gradient(90deg, #3b82f6, #10b981); transition: width 0.3s ease; }
            .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
            .card { padding: 15px; border-radius: 8px; background: #f9fafb; border-left: 4px solid #3b82f6; }
            .card.success { border-left-color: #10b981; background: #f0fdf4; }
            .card.error { border-left-color: #ef4444; background: #fef2f2; }
            .card.warning { border-left-color: #f59e0b; background: #fffbeb; }
        </style>";
        
        echo "<div class='container'>";
        
        // 1. Corrigir estrutura do banco
        $this->corrigirBancoDados();
        
        // 2. Corrigir includes quebrados
        $this->corrigirIncludes();
        
        // 3. Corrigir rotas quebradas
        $this->corrigirRotas();
        
        // 4. Corrigir navegação
        $this->corrigirNavegacao();
        
        // 5. Gerar schema consolidado
        $this->gerarSchemaConsolidado();
        
        // 6. Resumo final
        $this->exibirResumoCorrecoes();
        
        echo "</div>";
        
        $this->log("=== CORREÇÕES AUTOMÁTICAS CONCLUÍDAS ===");
    }
    
    private function corrigirBancoDados() {
        echo "<div class='section'>";
        echo "<h2>🗄️ Correção do Banco de Dados</h2>";
        
        // 1. Adicionar colunas faltantes
        $this->adicionarColunasFaltantes();
        
        // 2. Criar tabelas faltantes
        $this->criarTabelasFaltantes();
        
        // 3. Corrigir índices e FKs
        $this->corrigirIndicesFKs();
        
        echo "</div>";
    }
    
    private function adicionarColunasFaltantes() {
        echo "<div class='step'>";
        echo "<h3>📝 Adicionando Colunas Faltantes</h3>";
        
        $colunasParaAdicionar = [
            'usuarios' => [
                'email' => 'VARCHAR(255)',
                'perfil' => 'VARCHAR(20) DEFAULT \'OPER\'',
                'ativo' => 'BOOLEAN DEFAULT TRUE',
                'created_at' => 'TIMESTAMP DEFAULT NOW()',
                'updated_at' => 'TIMESTAMP DEFAULT NOW()'
            ],
            'eventos' => [
                'usuario_id' => 'INT REFERENCES usuarios(id)',
                'created_at' => 'TIMESTAMP DEFAULT NOW()',
                'updated_at' => 'TIMESTAMP DEFAULT NOW()'
            ],
            'fornecedores' => [
                'ativo' => 'BOOLEAN DEFAULT TRUE',
                'created_at' => 'TIMESTAMP DEFAULT NOW()',
                'updated_at' => 'TIMESTAMP DEFAULT NOW()'
            ]
        ];
        
        $colunasAdicionadas = 0;
        foreach ($colunasParaAdicionar as $tabela => $colunas) {
            try {
                // Verificar se tabela existe
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '{$tabela}'");
                if ($stmt->fetchColumn() > 0) {
                    foreach ($colunas as $coluna => $definicao) {
                        try {
                            $stmt = $this->pdo->query("SELECT {$coluna} FROM {$tabela} LIMIT 1");
                        } catch (Exception $e) {
                            // Coluna não existe, adicionar
                            $sql = "ALTER TABLE {$tabela} ADD COLUMN {$coluna} {$definicao}";
                            $this->pdo->exec($sql);
                            $colunasAdicionadas++;
                            $this->correcoesAplicadas[] = "Coluna {$coluna} adicionada à tabela {$tabela}";
                        }
                    }
                }
            } catch (Exception $e) {
                $this->errosEncontrados[] = "Erro ao verificar tabela {$tabela}: " . $e->getMessage();
            }
        }
        
        if ($colunasAdicionadas > 0) {
            echo "<p class='success'>✅ {$colunasAdicionadas} colunas adicionadas</p>";
        } else {
            echo "<p class='info'>ℹ️ Nenhuma coluna faltante encontrada</p>";
        }
        
        echo "</div>";
    }
    
    private function criarTabelasFaltantes() {
        echo "<div class='step'>";
        echo "<h3>🏗️ Criando Tabelas Faltantes</h3>";
        
        $tabelasParaCriar = [
            'demandas_logs' => "
                CREATE TABLE IF NOT EXISTS demandas_logs (
                    id SERIAL PRIMARY KEY,
                    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                    acao VARCHAR(100) NOT NULL,
                    entidade VARCHAR(50) NOT NULL,
                    entidade_id INT NOT NULL,
                    dados_anteriores JSONB,
                    dados_novos JSONB,
                    ip_origem INET,
                    user_agent TEXT,
                    criado_em TIMESTAMP DEFAULT NOW()
                );
            ",
            'demandas_configuracoes' => "
                CREATE TABLE IF NOT EXISTS demandas_configuracoes (
                    id SERIAL PRIMARY KEY,
                    chave VARCHAR(100) UNIQUE NOT NULL,
                    valor TEXT,
                    descricao TEXT,
                    tipo VARCHAR(50) DEFAULT 'string' CHECK (tipo IN ('string', 'number', 'boolean', 'json')),
                    criado_em TIMESTAMP DEFAULT NOW(),
                    atualizado_em TIMESTAMP DEFAULT NOW()
                );
            ",
            'agenda_espacos' => "
                CREATE TABLE IF NOT EXISTS agenda_espacos (
                    id SERIAL PRIMARY KEY,
                    nome VARCHAR(100) NOT NULL,
                    slug VARCHAR(100) UNIQUE NOT NULL,
                    descricao TEXT,
                    cor VARCHAR(7) DEFAULT '#3b82f6',
                    ativo BOOLEAN DEFAULT TRUE,
                    criado_em TIMESTAMP DEFAULT NOW(),
                    atualizado_em TIMESTAMP DEFAULT NOW()
                );
            ",
            'agenda_eventos' => "
                CREATE TABLE IF NOT EXISTS agenda_eventos (
                    id SERIAL PRIMARY KEY,
                    titulo VARCHAR(255) NOT NULL,
                    descricao TEXT,
                    data_inicio TIMESTAMP NOT NULL,
                    data_fim TIMESTAMP NOT NULL,
                    espaco_id INT REFERENCES agenda_espacos(id),
                    usuario_id INT REFERENCES usuarios(id),
                    tipo VARCHAR(50) DEFAULT 'evento' CHECK (tipo IN ('evento', 'visita', 'bloqueio')),
                    cor VARCHAR(7),
                    observacoes TEXT,
                    criado_em TIMESTAMP DEFAULT NOW(),
                    atualizado_em TIMESTAMP DEFAULT NOW()
                );
            "
        ];
        
        $tabelasCriadas = 0;
        foreach ($tabelasParaCriar as $nomeTabela => $sql) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '{$nomeTabela}'");
                if ($stmt->fetchColumn() == 0) {
                    $this->pdo->exec($sql);
                    $tabelasCriadas++;
                    $this->correcoesAplicadas[] = "Tabela {$nomeTabela} criada";
                }
            } catch (Exception $e) {
                $this->errosEncontrados[] = "Erro ao criar tabela {$nomeTabela}: " . $e->getMessage();
            }
        }
        
        if ($tabelasCriadas > 0) {
            echo "<p class='success'>✅ {$tabelasCriadas} tabelas criadas</p>";
        } else {
            echo "<p class='info'>ℹ️ Todas as tabelas já existem</p>";
        }
        
        echo "</div>";
    }
    
    private function corrigirIndicesFKs() {
        echo "<div class='step'>";
        echo "<h3>🔗 Corrigindo Índices e FKs</h3>";
        
        $indicesParaCriar = [
            'CREATE INDEX IF NOT EXISTS idx_usuarios_perfil ON usuarios(perfil);',
            'CREATE INDEX IF NOT EXISTS idx_usuarios_ativo ON usuarios(ativo);',
            'CREATE INDEX IF NOT EXISTS idx_eventos_data ON eventos(data_inicio);',
            'CREATE INDEX IF NOT EXISTS idx_eventos_usuario ON eventos(usuario_id);',
            'CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio);',
            'CREATE INDEX IF NOT EXISTS idx_agenda_eventos_espaco ON agenda_eventos(espaco_id);',
            'CREATE INDEX IF NOT EXISTS idx_demandas_logs_entidade ON demandas_logs(entidade, entidade_id);'
        ];
        
        $indicesCriados = 0;
        foreach ($indicesParaCriar as $sql) {
            try {
                $this->pdo->exec($sql);
                $indicesCriados++;
            } catch (Exception $e) {
                $this->errosEncontrados[] = "Erro ao criar índice: " . $e->getMessage();
            }
        }
        
        if ($indicesCriados > 0) {
            echo "<p class='success'>✅ {$indicesCriados} índices criados</p>";
        } else {
            echo "<p class='info'>ℹ️ Todos os índices já existem</p>";
        }
        
        echo "</div>";
    }
    
    private function corrigirIncludes() {
        echo "<div class='section'>";
        echo "<h2>🔗 Correção de Includes</h2>";
        
        $includesNecessarios = [
            'conexao.php' => 'Conexão com banco de dados',
            'config.php' => 'Configurações gerais',
            'auth.php' => 'Sistema de autenticação',
            'sidebar.php' => 'Menu lateral',
            'header.php' => 'Cabeçalho das páginas',
            'footer.php' => 'Rodapé das páginas',
            'email_helper.php' => 'Helper para envio de e-mails',
            'agenda_helper.php' => 'Helper para agenda'
        ];
        
        $includesCriados = 0;
        foreach ($includesNecessarios as $arquivo => $descricao) {
            $caminho = __DIR__ . '/' . $arquivo;
            if (!file_exists($caminho)) {
                $this->criarIncludeBasico($arquivo, $descricao);
                $includesCriados++;
                $this->correcoesAplicadas[] = "Include {$arquivo} criado";
            }
        }
        
        if ($includesCriados > 0) {
            echo "<div class='step success'>";
            echo "<h3>✅ Includes Criados</h3>";
            echo "<p><strong>Total:</strong> {$includesCriados}</p>";
            echo "</div>";
        } else {
            echo "<div class='step'>";
            echo "<h3>ℹ️ Todos os Includes Existem</h3>";
            echo "<p>Nenhum include faltante encontrado</p>";
            echo "</div>";
        }
        
        echo "</div>";
    }
    
    private function criarIncludeBasico($arquivo, $descricao) {
        $caminho = __DIR__ . '/' . $arquivo;
        
        switch ($arquivo) {
            case 'conexao.php':
                $conteudo = "<?php\n// Conexão com banco de dados\n// Este arquivo deve conter a configuração de conexão\n?>";
                break;
            case 'config.php':
                $conteudo = "<?php\n// Configurações gerais do sistema\n// Defina aqui as configurações principais\n?>";
                break;
            case 'auth.php':
                $conteudo = "<?php\n// Sistema de autenticação\n// Verificação de login e permissões\n?>";
                break;
            case 'sidebar.php':
                $conteudo = "<?php\n// Menu lateral do sistema\n// Navegação principal\n?>";
                break;
            case 'header.php':
                $conteudo = "<?php\n// Cabeçalho das páginas\n// HTML comum a todas as páginas\n?>";
                break;
            case 'footer.php':
                $conteudo = "<?php\n// Rodapé das páginas\n// HTML comum a todas as páginas\n?>";
                break;
            case 'email_helper.php':
                $conteudo = "<?php\n// Helper para envio de e-mails\n// Funções de notificação\n?>";
                break;
            case 'agenda_helper.php':
                $conteudo = "<?php\n// Helper para agenda\n// Funções de calendário\n?>";
                break;
            default:
                $conteudo = "<?php\n// {$descricao}\n?>";
        }
        
        file_put_contents($caminho, $conteudo);
    }
    
    private function corrigirRotas() {
        echo "<div class='section'>";
        echo "<h2>🛣️ Correção de Rotas</h2>";
        
        $rotasNecessarias = [
            'dashboard.php' => 'Dashboard principal',
            'usuarios.php' => 'Gerenciamento de usuários',
            'eventos.php' => 'Gerenciamento de eventos',
            'fornecedores.php' => 'Gerenciamento de fornecedores',
            'estoque.php' => 'Controle de estoque',
            'compras.php' => 'Sistema de compras',
            'financeiro.php' => 'Sistema financeiro',
            'configuracoes.php' => 'Configurações do sistema'
        ];
        
        $rotasCriadas = 0;
        foreach ($rotasNecessarias as $arquivo => $descricao) {
            $caminho = __DIR__ . '/' . $arquivo;
            if (!file_exists($caminho)) {
                $this->criarRotaBasica($arquivo, $descricao);
                $rotasCriadas++;
                $this->correcoesAplicadas[] = "Rota {$arquivo} criada";
            }
        }
        
        if ($rotasCriadas > 0) {
            echo "<div class='step success'>";
            echo "<h3>✅ Rotas Criadas</h3>";
            echo "<p><strong>Total:</strong> {$rotasCriadas}</p>";
            echo "</div>";
        } else {
            echo "<div class='step'>";
            echo "<h3>ℹ️ Todas as Rotas Existem</h3>";
            echo "<p>Nenhuma rota faltante encontrada</p>";
            echo "</div>";
        }
        
        echo "</div>";
    }
    
    private function criarRotaBasica($arquivo, $descricao) {
        $caminho = __DIR__ . '/' . $arquivo;
        
        $conteudo = "<?php
// {$descricao}
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/auth.php';

// Verificar autenticação
if (!isset(\$_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

// Incluir header
include __DIR__ . '/header.php';
?>

<div class='container'>
    <h1>{$descricao}</h1>
    <p>Página em desenvolvimento...</p>
</div>

<?php
// Incluir footer
include __DIR__ . '/footer.php';
?>";
        
        file_put_contents($caminho, $conteudo);
    }
    
    private function corrigirNavegacao() {
        echo "<div class='section'>";
        echo "<h2>🧭 Correção de Navegação</h2>";
        
        // Verificar se sidebar existe
        $sidebarExiste = file_exists(__DIR__ . '/sidebar.php');
        $dashboardExiste = file_exists(__DIR__ . '/dashboard.php');
        
        if (!$sidebarExiste) {
            $this->criarSidebarBasica();
            $this->correcoesAplicadas[] = "Sidebar criada";
        }
        
        if (!$dashboardExiste) {
            $this->criarDashboardBasico();
            $this->correcoesAplicadas[] = "Dashboard criado";
        }
        
        echo "<div class='step success'>";
        echo "<h3>✅ Navegação Corrigida</h3>";
        echo "<p>Sidebar: " . ($sidebarExiste ? 'OK' : 'Criada') . "</p>";
        echo "<p>Dashboard: " . ($dashboardExiste ? 'OK' : 'Criado') . "</p>";
        echo "</div>";
        
        echo "</div>";
    }
    
    private function criarSidebarBasica() {
        $caminho = __DIR__ . '/sidebar.php';
        
        $conteudo = "<?php
// Sidebar do sistema
if (!isset(\$_SESSION['usuario_id'])) {
    return;
}
?>

<nav class='sidebar'>
    <div class='sidebar-header'>
        <h3>Painel Smile PRO</h3>
    </div>
    
    <ul class='sidebar-menu'>
        <li><a href='dashboard.php'><i class='fas fa-home'></i> Dashboard</a></li>
        <li><a href='usuarios.php'><i class='fas fa-users'></i> Usuários</a></li>
        <li><a href='eventos.php'><i class='fas fa-calendar'></i> Eventos</a></li>
        <li><a href='fornecedores.php'><i class='fas fa-truck'></i> Fornecedores</a></li>
        <li><a href='estoque.php'><i class='fas fa-boxes'></i> Estoque</a></li>
        <li><a href='compras.php'><i class='fas fa-shopping-cart'></i> Compras</a></li>
        <li><a href='financeiro.php'><i class='fas fa-dollar-sign'></i> Financeiro</a></li>
        <li><a href='configuracoes.php'><i class='fas fa-cog'></i> Configurações</a></li>
    </ul>
</nav>";
        
        file_put_contents($caminho, $conteudo);
    }
    
    private function criarDashboardBasico() {
        $caminho = __DIR__ . '/dashboard.php';
        
        $conteudo = "<?php
// Dashboard principal
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/auth.php';

// Verificar autenticação
if (!isset(\$_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

// Incluir header
include __DIR__ . '/header.php';
?>

<div class='container'>
    <h1>Dashboard</h1>
    <div class='dashboard-grid'>
        <div class='dashboard-card'>
            <h3>Usuários</h3>
            <p>Gerenciar usuários do sistema</p>
            <a href='usuarios.php' class='btn'>Acessar</a>
        </div>
        
        <div class='dashboard-card'>
            <h3>Eventos</h3>
            <p>Gerenciar eventos</p>
            <a href='eventos.php' class='btn'>Acessar</a>
        </div>
        
        <div class='dashboard-card'>
            <h3>Fornecedores</h3>
            <p>Gerenciar fornecedores</p>
            <a href='fornecedores.php' class='btn'>Acessar</a>
        </div>
        
        <div class='dashboard-card'>
            <h3>Estoque</h3>
            <p>Controle de estoque</p>
            <a href='estoque.php' class='btn'>Acessar</a>
        </div>
    </div>
</div>

<?php
// Incluir footer
include __DIR__ . '/footer.php';
?>";
        
        file_put_contents($caminho, $conteudo);
    }
    
    private function gerarSchemaConsolidado() {
        echo "<div class='section'>";
        echo "<h2>📋 Schema Consolidado</h2>";
        
        try {
            $schemaFile = __DIR__ . '/../sql/schema_verificado.sql';
            $schemaContent = $this->gerarSchemaCompleto();
            
            file_put_contents($schemaFile, $schemaContent);
            
            echo "<div class='step success'>";
            echo "<h3>✅ Schema Consolidado Gerado</h3>";
            echo "<p><strong>Arquivo:</strong> sql/schema_verificado.sql</p>";
            echo "<p><strong>Tamanho:</strong> " . number_format(strlen($schemaContent)) . " bytes</p>";
            echo "<a href='../sql/schema_verificado.sql' class='btn' target='_blank'>📄 Ver Schema</a>";
            echo "</div>";
            
            $this->correcoesAplicadas[] = "Schema consolidado gerado";
        } catch (Exception $e) {
            echo "<div class='step error'>";
            echo "<h3>❌ Erro ao Gerar Schema</h3>";
            echo "<p>" . $e->getMessage() . "</p>";
            echo "</div>";
        }
        
        echo "</div>";
    }
    
    private function gerarSchemaCompleto() {
        $schema = "-- Schema Consolidado do Painel Smile PRO\n";
        $schema .= "-- Gerado automaticamente em " . date('Y-m-d H:i:s') . "\n\n";
        
        // Obter todas as tabelas
        $stmt = $this->pdo->query("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public' 
            ORDER BY table_name
        ");
        $tabelas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($tabelas as $tabela) {
            $schema .= "-- Tabela: {$tabela}\n";
            
            // Obter estrutura da tabela
            $stmt = $this->pdo->query("
                SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns 
                WHERE table_name = '{$tabela}' 
                ORDER BY ordinal_position
            ");
            $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $schema .= "CREATE TABLE IF NOT EXISTS {$tabela} (\n";
            $colunasSql = [];
            
            foreach ($colunas as $coluna) {
                $tipo = $coluna['data_type'];
                $nullable = $coluna['is_nullable'] === 'YES' ? '' : ' NOT NULL';
                $default = $coluna['column_default'] ? " DEFAULT {$coluna['column_default']}" : '';
                
                $colunasSql[] = "    {$coluna['column_name']} {$tipo}{$nullable}{$default}";
            }
            
            $schema .= implode(",\n", $colunasSql) . "\n);\n\n";
        }
        
        return $schema;
    }
    
    private function exibirResumoCorrecoes() {
        echo "<div class='section'>";
        echo "<h2>📊 Resumo das Correções</h2>";
        
        $totalCorrecoes = count($this->correcoesAplicadas);
        $totalErros = count($this->errosEncontrados);
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Correções Aplicadas</h3>";
        echo "<p><strong>Total:</strong> {$totalCorrecoes}</p>";
        if ($totalCorrecoes > 0) {
            echo "<ul>";
            foreach ($this->correcoesAplicadas as $correcao) {
                echo "<li>{$correcao}</li>";
            }
            echo "</ul>";
        }
        echo "</div>";
        
        if ($totalErros > 0) {
            echo "<div class='card error'>";
            echo "<h3>❌ Erros Encontrados</h3>";
            echo "<p><strong>Total:</strong> {$totalErros}</p>";
            echo "<ul>";
            foreach ($this->errosEncontrados as $erro) {
                echo "<li>{$erro}</li>";
            }
            echo "</ul>";
            echo "</div>";
        }
        
        echo "</div>";
        
        echo "<div style='text-align: center; margin-top: 20px;'>";
        echo "<a href='verificacao_geral.php' class='btn'>🔍 Verificar Novamente</a>";
        echo "<a href='index.php' class='btn btn-success'>🏠 Ir para Dashboard</a>";
        echo "</div>";
        
        echo "</div>";
    }
}

// Executar correções
$correcoes = new CorrecoesAutomaticas();
$correcoes->executarCorrecoes();
?>
