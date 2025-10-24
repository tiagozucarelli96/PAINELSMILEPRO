<?php
// verificacao_geral.php — Análise técnica completa do Painel Smile PRO
require_once __DIR__ . '/conexao.php';

class VerificacaoGeral {
    private $pdo;
    private $logFile;
    private $resultados = [];
    private $erros = [];
    private $correcoes = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->logFile = __DIR__ . '/../logs/verificacao.log';
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
    
    public function executarVerificacaoCompleta() {
        $this->log("=== INICIANDO VERIFICAÇÃO GERAL DO PAINEL SMILE PRO ===");
        
        echo "<h1>🔍 Análise Técnica Completa - Painel Smile PRO</h1>";
        echo "<style>
            body { font-family: Arial, sans-serif; margin: 20px; background: #f8fafc; }
            .container { max-width: 1200px; margin: 0 auto; }
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
        
        // Estatísticas gerais
        $this->exibirEstatisticas();
        
        // 1. Mapear todas as páginas
        $this->mapearPaginas();
        
        // 2. Verificar banco de dados
        $this->verificarBancoDados();
        
        // 3. Verificar includes e caminhos
        $this->verificarIncludes();
        
        // 4. Verificar rotas e redirecionamentos
        $this->verificarRotas();
        
        // 5. Verificar sidebar e dashboard
        $this->verificarNavegacao();
        
        // 6. Gerar correções automáticas
        $this->gerarCorrecoes();
        
        // 7. Resumo final
        $this->exibirResumo();
        
        echo "</div>";
        
        $this->log("=== VERIFICAÇÃO GERAL CONCLUÍDA ===");
    }
    
    private function exibirEstatisticas() {
        echo "<div class='section'>";
        echo "<h2>📊 Estatísticas do Sistema</h2>";
        echo "<div class='stats'>";
        
        $totalPaginas = $this->contarPaginas();
        $totalTabelas = $this->contarTabelas();
        $totalIncludes = $this->contarIncludes();
        $totalErros = count($this->erros);
        
        echo "<div class='stat'>";
        echo "<div class='stat-number'>{$totalPaginas}</div>";
        echo "<div class='stat-label'>Páginas</div>";
        echo "</div>";
        
        echo "<div class='stat'>";
        echo "<div class='stat-number'>{$totalTabelas}</div>";
        echo "<div class='stat-label'>Tabelas</div>";
        echo "</div>";
        
        echo "<div class='stat'>";
        echo "<div class='stat-number'>{$totalIncludes}</div>";
        echo "<div class='stat-label'>Includes</div>";
        echo "</div>";
        
        echo "<div class='stat'>";
        echo "<div class='stat-number'>{$totalErros}</div>";
        echo "<div class='stat-label'>Problemas</div>";
        echo "</div>";
        
        echo "</div>";
        echo "</div>";
    }
    
    private function mapearPaginas() {
        echo "<div class='section'>";
        echo "<h2>🗂️ Mapeamento de Páginas</h2>";
        
        $diretorios = [
            'public' => __DIR__,
            'pages' => __DIR__ . '/pages',
            'modulos' => __DIR__ . '/modulos',
            'includes' => __DIR__ . '/includes'
        ];
        
        $paginasEncontradas = [];
        $paginasComErro = [];
        
        foreach ($diretorios as $nome => $caminho) {
            if (is_dir($caminho)) {
                $arquivos = glob($caminho . '/*.php');
                foreach ($arquivos as $arquivo) {
                    $nomeArquivo = basename($arquivo);
                    $caminhoRelativo = str_replace(__DIR__ . '/', '', $arquivo);
                    
                    // Verificar se o arquivo é acessível
                    if ($this->verificarAcessibilidade($arquivo)) {
                        $paginasEncontradas[] = [
                            'arquivo' => $nomeArquivo,
                            'caminho' => $caminhoRelativo,
                            'diretorio' => $nome,
                            'tamanho' => filesize($arquivo),
                            'modificado' => date('d/m/Y H:i', filemtime($arquivo))
                        ];
                    } else {
                        $paginasComErro[] = [
                            'arquivo' => $nomeArquivo,
                            'caminho' => $caminhoRelativo,
                            'erro' => 'Arquivo inacessível ou com erro'
                        ];
                    }
                }
            }
        }
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Páginas Funcionais</h3>";
        echo "<p><strong>Total:</strong> " . count($paginasEncontradas) . "</p>";
        echo "<table>";
        echo "<tr><th>Arquivo</th><th>Diretório</th><th>Tamanho</th><th>Modificado</th></tr>";
        foreach (array_slice($paginasEncontradas, 0, 10) as $pagina) {
            echo "<tr>";
            echo "<td>{$pagina['arquivo']}</td>";
            echo "<td>{$pagina['diretorio']}</td>";
            echo "<td>" . number_format($pagina['tamanho']) . " bytes</td>";
            echo "<td>{$pagina['modificado']}</td>";
            echo "</tr>";
        }
        if (count($paginasEncontradas) > 10) {
            echo "<tr><td colspan='4'>... e mais " . (count($paginasEncontradas) - 10) . " páginas</td></tr>";
        }
        echo "</table>";
        echo "</div>";
        
        if (!empty($paginasComErro)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Páginas com Problemas</h3>";
            echo "<p><strong>Total:</strong> " . count($paginasComErro) . "</p>";
            echo "<table>";
            echo "<tr><th>Arquivo</th><th>Erro</th></tr>";
            foreach ($paginasComErro as $pagina) {
                echo "<tr>";
                echo "<td>{$pagina['arquivo']}</td>";
                echo "<td>{$pagina['erro']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        
        echo "</div>";
        echo "</div>";
        
        $this->log("Páginas mapeadas: " . count($paginasEncontradas) . " funcionais, " . count($paginasComErro) . " com problemas");
    }
    
    private function verificarBancoDados() {
        echo "<div class='section'>";
        echo "<h2>🗄️ Verificação do Banco de Dados</h2>";
        
        $tabelasEsperadas = [
            'usuarios', 'eventos', 'fornecedores', 'pendencias', 'pagamentos',
            'estoque', 'configuracoes', 'logs', 'demandas_quadros', 'demandas_cartoes',
            'demandas_colunas', 'demandas_participantes', 'demandas_logs',
            'demandas_configuracoes', 'agenda_espacos', 'agenda_eventos',
            'agenda_lembretes', 'agenda_tokens_ics', 'lc_listas', 'lc_compras',
            'lc_insumos', 'lc_unidades', 'lc_fichas', 'lc_ficha_componentes',
            'lc_evento_cardapio', 'lc_listas_eventos', 'lc_encomendas',
            'lc_encomendas_itens', 'lc_compras_consolidadas', 'lc_fornecedores',
            'lc_categorias', 'lc_arredondamentos', 'lc_configuracoes',
            'estoque_contagens', 'estoque_contagem_itens', 'estoque_movimentos',
            'estoque_alertas', 'estoque_kardex', 'pagamentos_solicitacoes',
            'pagamentos_freelancers', 'pagamentos_timeline', 'comercial_degustacoes',
            'comercial_degust_inscricoes', 'comercial_clientes', 'rh_funcionarios',
            'rh_departamentos', 'rh_cargos', 'rh_ferias', 'rh_beneficios',
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
        echo "<ul>";
        foreach ($tabelasExistentes as $tabela) {
            echo "<li>{$tabela}</li>";
        }
        echo "</ul>";
        echo "</div>";
        
        if (!empty($tabelasFaltantes)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Tabelas Faltantes</h3>";
            echo "<p><strong>Total:</strong> " . count($tabelasFaltantes) . "</p>";
            echo "<ul>";
            foreach ($tabelasFaltantes as $tabela) {
                echo "<li>{$tabela}</li>";
            }
            echo "</ul>";
            echo "</div>";
        }
        
        if (!empty($colunasFaltantes)) {
            echo "<div class='card warning'>";
            echo "<h3>⚠️ Colunas Faltantes</h3>";
            echo "<p><strong>Total:</strong> " . count($colunasFaltantes) . "</p>";
            echo "<ul>";
            foreach ($colunasFaltantes as $coluna) {
                echo "<li>{$coluna}</li>";
            }
            echo "</ul>";
            echo "</div>";
        }
        
        echo "</div>";
        echo "</div>";
        
        $this->log("Banco de dados: " . count($tabelasExistentes) . " tabelas existentes, " . count($tabelasFaltantes) . " faltantes");
    }
    
    private function verificarIncludes() {
        echo "<div class='section'>";
        echo "<h2>🔗 Verificação de Includes</h2>";
        
        $includesComuns = [
            'conexao.php', 'config.php', 'auth.php', 'sidebar.php',
            'header.php', 'footer.php', 'email_helper.php', 'agenda_helper.php'
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
        echo "<ul>";
        foreach ($includesFuncionais as $include) {
            echo "<li>{$include}</li>";
        }
        echo "</ul>";
        echo "</div>";
        
        if (!empty($includesQuebrados)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Includes Quebrados</h3>";
            echo "<p><strong>Total:</strong> " . count($includesQuebrados) . "</p>";
            echo "<ul>";
            foreach ($includesQuebrados as $include) {
                echo "<li>{$include}</li>";
            }
            echo "</ul>";
            echo "</div>";
        }
        
        echo "</div>";
        echo "</div>";
        
        $this->log("Includes: " . count($includesFuncionais) . " funcionais, " . count($includesQuebrados) . " quebrados");
    }
    
    private function verificarRotas() {
        echo "<div class='section'>";
        echo "<h2>🛣️ Verificação de Rotas</h2>";
        
        $rotasPrincipais = [
            'index.php' => 'Dashboard Principal',
            'dashboard.php' => 'Dashboard',
            'usuarios.php' => 'Usuários',
            'eventos.php' => 'Eventos',
            'fornecedores.php' => 'Fornecedores',
            'estoque.php' => 'Estoque',
            'compras.php' => 'Compras',
            'financeiro.php' => 'Financeiro',
            'configuracoes.php' => 'Configurações'
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
        echo "<table>";
        echo "<tr><th>Arquivo</th><th>Descrição</th></tr>";
        foreach ($rotasFuncionais as $rota) {
            echo "<tr>";
            echo "<td>{$rota['arquivo']}</td>";
            echo "<td>{$rota['descricao']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        if (!empty($rotasQuebradas)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Rotas Quebradas</h3>";
            echo "<p><strong>Total:</strong> " . count($rotasQuebradas) . "</p>";
            echo "<table>";
            echo "<tr><th>Arquivo</th><th>Descrição</th></tr>";
            foreach ($rotasQuebradas as $rota) {
                echo "<tr>";
                echo "<td>{$rota['arquivo']}</td>";
                echo "<td>{$rota['descricao']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        
        echo "</div>";
        echo "</div>";
        
        $this->log("Rotas: " . count($rotasFuncionais) . " funcionais, " . count($rotasQuebradas) . " quebradas");
    }
    
    private function verificarNavegacao() {
        echo "<div class='section'>";
        echo "<h2>🧭 Verificação de Navegação</h2>";
        
        // Verificar se sidebar e dashboard existem
        $sidebarExiste = file_exists(__DIR__ . '/sidebar.php');
        $dashboardExiste = file_exists(__DIR__ . '/dashboard.php');
        
        echo "<div class='grid'>";
        echo "<div class='card " . ($sidebarExiste ? 'success' : 'error') . "'>";
        echo "<h3>" . ($sidebarExiste ? '✅' : '❌') . " Sidebar</h3>";
        echo "<p>" . ($sidebarExiste ? 'Arquivo encontrado' : 'Arquivo não encontrado') . "</p>";
        echo "</div>";
        
        echo "<div class='card " . ($dashboardExiste ? 'success' : 'error') . "'>";
        echo "<h3>" . ($dashboardExiste ? '✅' : '❌') . " Dashboard</h3>";
        echo "<p>" . ($dashboardExiste ? 'Arquivo encontrado' : 'Arquivo não encontrado') . "</p>";
        echo "</div>";
        echo "</div>";
        
        echo "</div>";
        
        if (!$sidebarExiste) {
            $this->erros[] = "Sidebar não encontrada";
        }
        if (!$dashboardExiste) {
            $this->erros[] = "Dashboard não encontrado";
        }
        
        $this->log("Navegação: Sidebar " . ($sidebarExiste ? 'OK' : 'FALTANDO') . ", Dashboard " . ($dashboardExiste ? 'OK' : 'FALTANDO'));
    }
    
    private function gerarCorrecoes() {
        echo "<div class='section'>";
        echo "<h2>🔧 Correções Automáticas</h2>";
        
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
            echo "</div>";
        }
        
        echo "</div>";
    }
    
    private function exibirResumo() {
        echo "<div class='section'>";
        echo "<h2>📋 Resumo da Verificação</h2>";
        
        $totalPaginas = $this->contarPaginas();
        $totalTabelas = $this->contarTabelas();
        $totalIncludes = $this->contarIncludes();
        $totalErros = count($this->erros);
        
        $percentualSaude = round((($totalPaginas + $totalTabelas + $totalIncludes - $totalErros) / ($totalPaginas + $totalTabelas + $totalIncludes)) * 100);
        
        echo "<div class='progress-bar'>";
        echo "<div class='progress-fill' style='width: {$percentualSaude}%;'></div>";
        echo "</div>";
        echo "<div style='text-align: center; margin-top: 10px; font-weight: bold;'>";
        echo "Saúde do Sistema: {$percentualSaude}%";
        echo "</div>";
        
        echo "<div class='grid'>";
        echo "<div class='card'>";
        echo "<h3>📊 Estatísticas</h3>";
        echo "<ul>";
        echo "<li><strong>Páginas:</strong> {$totalPaginas}</li>";
        echo "<li><strong>Tabelas:</strong> {$totalTabelas}</li>";
        echo "<li><strong>Includes:</strong> {$totalIncludes}</li>";
        echo "<li><strong>Problemas:</strong> {$totalErros}</li>";
        echo "</ul>";
        echo "</div>";
        
        echo "<div class='card'>";
        echo "<h3>🚀 Próximos Passos</h3>";
        echo "<ul>";
        if ($totalErros > 0) {
            echo "<li>Execute as correções automáticas</li>";
            echo "<li>Verifique os logs de erro</li>";
            echo "<li>Teste todas as funcionalidades</li>";
        } else {
            echo "<li>Sistema funcionando perfeitamente</li>";
            echo "<li>Pode prosseguir com o uso normal</li>";
        }
        echo "</ul>";
        echo "</div>";
        echo "</div>";
        
        echo "<div style='text-align: center; margin-top: 20px;'>";
        echo "<a href='corrigir_automaticamente.php' class='btn btn-success'>🔧 Corrigir Automaticamente</a>";
        echo "<a href='index.php' class='btn'>🏠 Voltar ao Dashboard</a>";
        echo "</div>";
        
        echo "</div>";
    }
    
    private function verificarAcessibilidade($arquivo) {
        try {
            $conteudo = file_get_contents($arquivo);
            return !empty($conteudo) && strpos($conteudo, '<?php') !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function contarPaginas() {
        $diretorios = ['public', 'pages', 'modulos', 'includes'];
        $total = 0;
        foreach ($diretorios as $dir) {
            $caminho = __DIR__ . '/' . $dir;
            if (is_dir($caminho)) {
                $total += count(glob($caminho . '/*.php'));
            }
        }
        return $total;
    }
    
    private function contarTabelas() {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    private function contarIncludes() {
        $includes = glob(__DIR__ . '/*.php');
        return count($includes);
    }
}

// Executar verificação
$verificacao = new VerificacaoGeral();
$verificacao->executarVerificacaoCompleta();
?>
