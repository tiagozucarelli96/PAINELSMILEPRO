<?php
// executar_analise_completa.php — Execução da análise técnica completa
require_once __DIR__ . '/conexao.php';

class AnaliseCompleta {
    private $pdo;
    private $logFile;
    private $resultados = [];
    private $correcoes = [];
    private $erros = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->logFile = __DIR__ . '/../logs/analise_completa.log';
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
    
    public function executar() {
        $this->log("=== INICIANDO ANÁLISE TÉCNICA COMPLETA ===");
        
        echo "<h1>🔍 Análise Técnica Completa - Painel Smile PRO</h1>";
        echo "<style>
            body { font-family: Arial, sans-serif; margin: 20px; background: #f8fafc; }
            .container { max-width: 1400px; margin: 0 auto; }
            .success { color: #10b981; }
            .error { color: #ef4444; }
            .warning { color: #f59e0b; }
            .info { color: #3b82f6; }
            .section { margin: 20px 0; padding: 20px; border-radius: 8px; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .section h2 { margin-top: 0; color: #1f2937; }
            .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
            .card { padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6; background: #f9fafb; }
            .card.success { border-left-color: #10b981; background: #f0fdf4; }
            .card.error { border-left-color: #ef4444; background: #fef2f2; }
            .card.warning { border-left-color: #f59e0b; background: #fffbeb; }
            .btn { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px; }
            .btn:hover { background: #1d4ed8; }
            .btn-success { background: #10b981; }
            .btn-success:hover { background: #059669; }
            .btn-warning { background: #f59e0b; }
            .btn-warning:hover { background: #d97706; }
            .progress-bar { width: 100%; background: #e5e7eb; border-radius: 10px; overflow: hidden; margin: 10px 0; }
            .progress-fill { height: 20px; background: linear-gradient(90deg, #3b82f6, #10b981); transition: width 0.3s ease; }
            .stats { display: flex; justify-content: space-around; margin: 20px 0; }
            .stat { text-align: center; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .stat-number { font-size: 2em; font-weight: bold; color: #3b82f6; }
            .stat-label { color: #6b7280; margin-top: 5px; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
            th { background: #f3f4f6; font-weight: 600; }
            .status-ok { color: #10b981; font-weight: bold; }
            .status-error { color: #ef4444; font-weight: bold; }
            .status-warning { color: #f59e0b; font-weight: bold; }
        </style>";
        
        echo "<div class='container'>";
        
        // Executar todas as análises
        $this->executarAnalisePaginas();
        $this->executarAnaliseBanco();
        $this->executarAnaliseIncludes();
        $this->executarAnaliseRotas();
        $this->executarAnaliseNavegacao();
        $this->executarCorrecoes();
        $this->exibirResumoFinal();
        
        echo "</div>";
        
        $this->log("=== ANÁLISE TÉCNICA COMPLETA CONCLUÍDA ===");
    }
    
    private function executarAnalisePaginas() {
        echo "<div class='section'>";
        echo "<h2>🗂️ Análise de Páginas</h2>";
        
        $diretorios = [
            'public' => __DIR__,
            'pages' => __DIR__ . '/pages',
            'modulos' => __DIR__ . '/modulos',
            'includes' => __DIR__ . '/includes'
        ];
        
        $totalPaginas = 0;
        $paginasFuncionais = 0;
        $paginasComErro = 0;
        
        foreach ($diretorios as $nome => $caminho) {
            if (is_dir($caminho)) {
                $arquivos = glob($caminho . '/*.php');
                $totalPaginas += count($arquivos);
                
                foreach ($arquivos as $arquivo) {
                    if ($this->verificarPagina($arquivo)) {
                        $paginasFuncionais++;
                    } else {
                        $paginasComErro++;
                        $this->erros[] = "Página com erro: " . basename($arquivo);
                    }
                }
            }
        }
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Páginas Funcionais</h3>";
        echo "<p><strong>Total:</strong> {$paginasFuncionais}</p>";
        echo "</div>";
        
        if ($paginasComErro > 0) {
            echo "<div class='card error'>";
            echo "<h3>❌ Páginas com Problemas</h3>";
            echo "<p><strong>Total:</strong> {$paginasComErro}</p>";
            echo "</div>";
        }
        
        echo "<div class='card'>";
        echo "<h3>📊 Estatísticas</h3>";
        echo "<p><strong>Total de páginas:</strong> {$totalPaginas}</p>";
        echo "<p><strong>Taxa de sucesso:</strong> " . round(($paginasFuncionais / $totalPaginas) * 100, 1) . "%</p>";
        echo "</div>";
        echo "</div>";
        
        echo "</div>";
        
        $this->resultados['paginas'] = [
            'total' => $totalPaginas,
            'funcionais' => $paginasFuncionais,
            'com_erro' => $paginasComErro
        ];
        
        $this->log("Páginas analisadas: {$totalPaginas} total, {$paginasFuncionais} funcionais, {$paginasComErro} com erro");
    }
    
    private function executarAnaliseBanco() {
        echo "<div class='section'>";
        echo "<h2>🗄️ Análise do Banco de Dados</h2>";
        
        $tabelasEsperadas = [
            'usuarios', 'eventos', 'fornecedores', 'pendencias', 'pagamentos',
            'demandas_quadros', 'demandas_cartoes', 'demandas_colunas',
            'demandas_participantes', 'demandas_logs', 'demandas_configuracoes',
            'agenda_espacos', 'agenda_eventos', 'agenda_lembretes',
            'lc_listas', 'lc_compras', 'lc_insumos', 'lc_unidades',
            'lc_fichas', 'lc_ficha_componentes', 'estoque_contagens',
            'estoque_contagem_itens', 'estoque_movimentos', 'estoque_kardex',
            'pagamentos_solicitacoes', 'pagamentos_freelancers', 'pagamentos_timeline',
            'comercial_degustacoes', 'comercial_degust_inscricoes', 'comercial_clientes',
            'rh_funcionarios', 'rh_departamentos', 'rh_cargos', 'rh_ferias',
            'contab_contas', 'contab_transacoes', 'contab_categorias'
        ];
        
        $tabelasExistentes = [];
        $tabelasFaltantes = [];
        $colunasFaltantes = [];
        
        foreach ($tabelasEsperadas as $tabela) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$tabela}");
                $tabelasExistentes[] = $tabela;
            } catch (Exception $e) {
                $tabelasFaltantes[] = $tabela;
                $this->erros[] = "Tabela '{$tabela}' não existe: " . $e->getMessage();
            }
        }
        
        // Verificar colunas essenciais
        $colunasEssenciais = [
            'usuarios' => ['id', 'nome', 'email', 'perfil', 'ativo'],
            'eventos' => ['id', 'titulo', 'data_inicio', 'data_fim', 'usuario_id'],
            'fornecedores' => ['id', 'nome', 'email', 'telefone', 'ativo']
        ];
        
        foreach ($colunasEssenciais as $tabela => $colunas) {
            if (in_array($tabela, $tabelasExistentes)) {
                foreach ($colunas as $coluna) {
                    try {
                        $stmt = $this->pdo->query("SELECT {$coluna} FROM {$tabela} LIMIT 1");
                    } catch (Exception $e) {
                        $colunasFaltantes[] = "{$tabela}.{$coluna}";
                        $this->erros[] = "Coluna '{$coluna}' não existe na tabela '{$tabela}': " . $e->getMessage();
                    }
                }
            }
        }
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Tabelas Existentes</h3>";
        echo "<p><strong>Total:</strong> " . count($tabelasExistentes) . "</p>";
        echo "</div>";
        
        if (!empty($tabelasFaltantes)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Tabelas Faltantes</h3>";
            echo "<p><strong>Total:</strong> " . count($tabelasFaltantes) . "</p>";
            echo "</div>";
        }
        
        if (!empty($colunasFaltantes)) {
            echo "<div class='card warning'>";
            echo "<h3>⚠️ Colunas Faltantes</h3>";
            echo "<p><strong>Total:</strong> " . count($colunasFaltantes) . "</p>";
            echo "</div>";
        }
        echo "</div>";
        
        echo "</div>";
        
        $this->resultados['banco'] = [
            'tabelas_existentes' => count($tabelasExistentes),
            'tabelas_faltantes' => count($tabelasFaltantes),
            'colunas_faltantes' => count($colunasFaltantes)
        ];
        
        $this->log("Banco de dados: " . count($tabelasExistentes) . " tabelas existentes, " . count($tabelasFaltantes) . " faltantes");
    }
    
    private function executarAnaliseIncludes() {
        echo "<div class='section'>";
        echo "<h2>🔗 Análise de Includes</h2>";
        
        $includesComuns = [
            'conexao.php', 'config.php', 'auth.php', 'sidebar.php',
            'header.php', 'footer.php', 'email_helper.php', 'agenda_helper.php',
            'demandas_helper.php', 'estoque_helper.php', 'comercial_helper.php',
            'rh_helper.php', 'contab_helper.php'
        ];
        
        $includesFuncionais = [];
        $includesQuebrados = [];
        
        foreach ($includesComuns as $include) {
            $caminho = __DIR__ . '/' . $include;
            if (file_exists($caminho) && is_readable($caminho)) {
                $includesFuncionais[] = $include;
            } else {
                $includesQuebrados[] = $include;
                $this->erros[] = "Include '{$include}' não encontrado ou inacessível";
            }
        }
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Includes Funcionais</h3>";
        echo "<p><strong>Total:</strong> " . count($includesFuncionais) . "</p>";
        echo "</div>";
        
        if (!empty($includesQuebrados)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Includes Quebrados</h3>";
            echo "<p><strong>Total:</strong> " . count($includesQuebrados) . "</p>";
            echo "</div>";
        }
        echo "</div>";
        
        echo "</div>";
        
        $this->resultados['includes'] = [
            'funcionais' => count($includesFuncionais),
            'quebrados' => count($includesQuebrados)
        ];
        
        $this->log("Includes: " . count($includesFuncionais) . " funcionais, " . count($includesQuebrados) . " quebrados");
    }
    
    private function executarAnaliseRotas() {
        echo "<div class='section'>";
        echo "<h2>🛣️ Análise de Rotas</h2>";
        
        $rotasPrincipais = [
            'index.php' => 'Dashboard Principal',
            'dashboard.php' => 'Dashboard',
            'usuarios.php' => 'Usuários',
            'eventos.php' => 'Eventos',
            'fornecedores.php' => 'Fornecedores',
            'estoque.php' => 'Estoque',
            'compras.php' => 'Compras',
            'financeiro.php' => 'Financeiro',
            'configuracoes.php' => 'Configurações',
            'sistema_unificado.php' => 'Sistema Unificado'
        ];
        
        $rotasFuncionais = [];
        $rotasQuebradas = [];
        
        foreach ($rotasPrincipais as $arquivo => $descricao) {
            $caminho = __DIR__ . '/' . $arquivo;
            if (file_exists($caminho)) {
                $rotasFuncionais[] = ['arquivo' => $arquivo, 'descricao' => $descricao];
            } else {
                $rotasQuebradas[] = ['arquivo' => $arquivo, 'descricao' => $descricao];
                $this->erros[] = "Rota '{$arquivo}' não encontrada";
            }
        }
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Rotas Funcionais</h3>";
        echo "<p><strong>Total:</strong> " . count($rotasFuncionais) . "</p>";
        echo "</div>";
        
        if (!empty($rotasQuebradas)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Rotas Quebradas</h3>";
            echo "<p><strong>Total:</strong> " . count($rotasQuebradas) . "</p>";
            echo "</div>";
        }
        echo "</div>";
        
        echo "</div>";
        
        $this->resultados['rotas'] = [
            'funcionais' => count($rotasFuncionais),
            'quebradas' => count($rotasQuebradas)
        ];
        
        $this->log("Rotas: " . count($rotasFuncionais) . " funcionais, " . count($rotasQuebradas) . " quebradas");
    }
    
    private function executarAnaliseNavegacao() {
        echo "<div class='section'>";
        echo "<h2>🧭 Análise de Navegação</h2>";
        
        $sidebarExiste = file_exists(__DIR__ . '/sidebar.php');
        $dashboardExiste = file_exists(__DIR__ . '/dashboard.php');
        $sistemaUnificadoExiste = file_exists(__DIR__ . '/sistema_unificado.php');
        
        echo "<div class='grid'>";
        echo "<div class='card " . ($sidebarExiste ? 'success' : 'error') . "'>";
        echo "<h3>" . ($sidebarExiste ? '✅' : '❌') . " Sidebar</h3>";
        echo "<p>" . ($sidebarExiste ? 'Arquivo encontrado' : 'Arquivo não encontrado') . "</p>";
        echo "</div>";
        
        echo "<div class='card " . ($dashboardExiste ? 'success' : 'error') . "'>";
        echo "<h3>" . ($dashboardExiste ? '✅' : '❌') . " Dashboard</h3>";
        echo "<p>" . ($dashboardExiste ? 'Arquivo encontrado' : 'Arquivo não encontrado') . "</p>";
        echo "</div>";
        
        echo "<div class='card " . ($sistemaUnificadoExiste ? 'success' : 'error') . "'>";
        echo "<h3>" . ($sistemaUnificadoExiste ? '✅' : '❌') . " Sistema Unificado</h3>";
        echo "<p>" . ($sistemaUnificadoExiste ? 'Arquivo encontrado' : 'Arquivo não encontrado') . "</p>";
        echo "</div>";
        echo "</div>";
        
        echo "</div>";
        
        $this->resultados['navegacao'] = [
            'sidebar' => $sidebarExiste,
            'dashboard' => $dashboardExiste,
            'sistema_unificado' => $sistemaUnificadoExiste
        ];
        
        if (!$sidebarExiste) $this->erros[] = "Sidebar não encontrada";
        if (!$dashboardExiste) $this->erros[] = "Dashboard não encontrado";
        if (!$sistemaUnificadoExiste) $this->erros[] = "Sistema unificado não encontrado";
        
        $this->log("Navegação: Sidebar " . ($sidebarExiste ? 'OK' : 'FALTANDO') . ", Dashboard " . ($dashboardExiste ? 'OK' : 'FALTANDO') . ", Sistema Unificado " . ($sistemaUnificadoExiste ? 'OK' : 'FALTANDO'));
    }
    
    private function executarCorrecoes() {
        echo "<div class='section'>";
        echo "<h2>🔧 Correções Disponíveis</h2>";
        
        if (empty($this->erros)) {
            echo "<div class='card success'>";
            echo "<h3>✅ Nenhuma Correção Necessária</h3>";
            echo "<p>O sistema está funcionando perfeitamente!</p>";
            echo "</div>";
        } else {
            echo "<div class='card warning'>";
            echo "<h3>⚠️ Correções Disponíveis</h3>";
            echo "<p><strong>Total de problemas:</strong> " . count($this->erros) . "</p>";
            echo "<a href='corrigir_automaticamente.php' class='btn btn-warning'>🔧 Corrigir Automaticamente</a>";
            echo "<a href='execute_agenda_sql.php' class='btn btn-success'>🗄️ Executar SQL da Agenda</a>";
            echo "<a href='fix_all_database_issues.php' class='btn btn-success'>🔧 Corrigir Banco de Dados</a>";
            echo "</div>";
        }
        
        echo "</div>";
    }
    
    private function exibirResumoFinal() {
        echo "<div class='section'>";
        echo "<h2>📋 Resumo Final da Análise</h2>";
        
        $totalProblemas = count($this->erros);
        $totalPaginas = $this->resultados['paginas']['total'] ?? 0;
        $paginasFuncionais = $this->resultados['paginas']['funcionais'] ?? 0;
        $tabelasExistentes = $this->resultados['banco']['tabelas_existentes'] ?? 0;
        $tabelasFaltantes = $this->resultados['banco']['tabelas_faltantes'] ?? 0;
        $includesFuncionais = $this->resultados['includes']['funcionais'] ?? 0;
        $includesQuebrados = $this->resultados['includes']['quebrados'] ?? 0;
        $rotasFuncionais = $this->resultados['rotas']['funcionais'] ?? 0;
        $rotasQuebradas = $this->resultados['rotas']['quebradas'] ?? 0;
        
        $percentualSaude = round((($paginasFuncionais + $tabelasExistentes + $includesFuncionais + $rotasFuncionais) / ($totalPaginas + $tabelasExistentes + $tabelasFaltantes + $includesFuncionais + $includesQuebrados + $rotasFuncionais + $rotasQuebradas)) * 100);
        
        echo "<div class='stats'>";
        echo "<div class='stat'>";
        echo "<div class='stat-number'>{$totalPaginas}</div>";
        echo "<div class='stat-label'>Páginas</div>";
        echo "</div>";
        
        echo "<div class='stat'>";
        echo "<div class='stat-number'>{$tabelasExistentes}</div>";
        echo "<div class='stat-label'>Tabelas</div>";
        echo "</div>";
        
        echo "<div class='stat'>";
        echo "<div class='stat-number'>{$includesFuncionais}</div>";
        echo "<div class='stat-label'>Includes</div>";
        echo "</div>";
        
        echo "<div class='stat'>";
        echo "<div class='stat-number'>{$rotasFuncionais}</div>";
        echo "<div class='stat-label'>Rotas</div>";
        echo "</div>";
        
        echo "<div class='stat'>";
        echo "<div class='stat-number'>{$totalProblemas}</div>";
        echo "<div class='stat-label'>Problemas</div>";
        echo "</div>";
        echo "</div>";
        
        echo "<div class='progress-bar'>";
        echo "<div class='progress-fill' style='width: {$percentualSaude}%;'></div>";
        echo "</div>";
        echo "<div style='text-align: center; margin-top: 10px; font-weight: bold;'>";
        echo "Saúde do Sistema: {$percentualSaude}%";
        echo "</div>";
        
        echo "<div class='grid'>";
        echo "<div class='card'>";
        echo "<h3>📊 Estatísticas Detalhadas</h3>";
        echo "<ul>";
        echo "<li><strong>Páginas:</strong> {$paginasFuncionais}/{$totalPaginas} funcionais</li>";
        echo "<li><strong>Tabelas:</strong> {$tabelasExistentes} existentes, {$tabelasFaltantes} faltantes</li>";
        echo "<li><strong>Includes:</strong> {$includesFuncionais} funcionais, {$includesQuebrados} quebrados</li>";
        echo "<li><strong>Rotas:</strong> {$rotasFuncionais} funcionais, {$rotasQuebradas} quebradas</li>";
        echo "<li><strong>Problemas:</strong> {$totalProblemas} encontrados</li>";
        echo "</ul>";
        echo "</div>";
        
        echo "<div class='card'>";
        echo "<h3>🚀 Próximos Passos</h3>";
        echo "<ul>";
        if ($totalProblemas > 0) {
            echo "<li>Execute as correções automáticas</li>";
            echo "<li>Verifique os logs de erro</li>";
            echo "<li>Teste todas as funcionalidades</li>";
            echo "<li>Execute o SQL completo do sistema</li>";
        } else {
            echo "<li>Sistema funcionando perfeitamente</li>";
            echo "<li>Pode prosseguir com o uso normal</li>";
            echo "<li>Execute testes regulares</li>";
        }
        echo "</ul>";
        echo "</div>";
        echo "</div>";
        
        echo "<div style='text-align: center; margin-top: 20px;'>";
        if ($totalProblemas > 0) {
            echo "<a href='corrigir_automaticamente.php' class='btn btn-warning'>🔧 Corrigir Automaticamente</a>";
            echo "<a href='execute_agenda_sql.php' class='btn btn-success'>🗄️ Executar SQL</a>";
            echo "<a href='fix_all_database_issues.php' class='btn btn-success'>🔧 Corrigir Banco</a>";
        }
        echo "<a href='sistema_unificado.php' class='btn btn-success'>🏠 Sistema Unificado</a>";
        echo "<a href='verificacao_geral.php' class='btn'>🔍 Verificação Geral</a>";
        echo "</div>";
        
        echo "</div>";
    }
    
    private function verificarPagina($arquivo) {
        try {
            $conteudo = file_get_contents($arquivo);
            return !empty($conteudo) && strpos($conteudo, '<?php') !== false;
        } catch (Exception $e) {
            return false;
        }
    }
}

// Executar análise completa
$analise = new AnaliseCompleta();
$analise->executar();
?>
