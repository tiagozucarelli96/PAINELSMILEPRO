<?php
// verificar_includes.php — Verificação completa de includes e caminhos
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

class VerificadorIncludes {
    private $pdo;
    private $logFile;
    private $includesQuebrados = [];
    private $caminhosQuebrados = [];
    private $correcoesAplicadas = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->logFile = __DIR__ . '/../logs/includes.log';
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
    
    public function executarVerificacao() {
        $this->log("=== INICIANDO VERIFICAÇÃO DE INCLUDES ===");
        
        echo "<h1>🔍 Verificação de Includes e Caminhos</h1>";
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
            .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
            .card { padding: 15px; border-radius: 8px; background: #f9fafb; border-left: 4px solid #3b82f6; }
            .card.success { border-left-color: #10b981; background: #f0fdf4; }
            .card.error { border-left-color: #ef4444; background: #fef2f2; }
            .card.warning { border-left-color: #f59e0b; background: #fffbeb; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
            th { background: #f3f4f6; font-weight: 600; }
        </style>";
        
        echo "<div class='container'>";
        
        // 1. Verificar includes principais
        $this->verificarIncludesPrincipais();
        
        // 2. Verificar includes em arquivos PHP
        $this->verificarIncludesEmArquivos();
        
        // 3. Verificar caminhos relativos
        $this->verificarCaminhosRelativos();
        
        // 4. Verificar autoloads
        $this->verificarAutoloads();
        
        // 5. Corrigir includes quebrados
        $this->corrigirIncludesQuebrados();
        
        // 6. Resumo final
        $this->exibirResumo();
        
        echo "</div>";
        
        $this->log("=== VERIFICAÇÃO DE INCLUDES CONCLUÍDA ===");
    }
    
    private function verificarIncludesPrincipais() {
        echo "<div class='section'>";
        echo "<h2>🔗 Verificação de Includes Principais</h2>";
        
        $includesPrincipais = [
            'conexao.php' => 'Conexão com banco de dados',
            'config.php' => 'Configurações gerais',
            'auth.php' => 'Sistema de autenticação',
            'sidebar.php' => 'Menu lateral',
            'header.php' => 'Cabeçalho das páginas',
            'footer.php' => 'Rodapé das páginas',
            'email_helper.php' => 'Helper para envio de e-mails',
            'agenda_helper.php' => 'Helper para agenda',
            'demandas_helper.php' => 'Helper para demandas',
            'estoque_helper.php' => 'Helper para estoque',
            'comercial_helper.php' => 'Helper para comercial',
            'rh_helper.php' => 'Helper para RH'
        ];
        
        $includesFuncionais = [];
        $includesFaltantes = [];
        
        foreach ($includesPrincipais as $arquivo => $descricao) {
            $caminho = __DIR__ . '/' . $arquivo;
            if (file_exists($caminho) && is_readable($caminho)) {
                $tamanho = filesize($caminho);
                $modificado = date('d/m/Y H:i', filemtime($caminho));
                $includesFuncionais[] = [
                    'arquivo' => $arquivo,
                    'descricao' => $descricao,
                    'tamanho' => $tamanho,
                    'modificado' => $modificado
                ];
            } else {
                $includesFaltantes[] = [
                    'arquivo' => $arquivo,
                    'descricao' => $descricao,
                    'erro' => 'Arquivo não encontrado ou inacessível'
                ];
                $this->includesQuebrados[] = $arquivo;
            }
        }
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Includes Funcionais</h3>";
        echo "<p><strong>Total:</strong> " . count($includesFuncionais) . "</p>";
        echo "<table>";
        echo "<tr><th>Arquivo</th><th>Descrição</th><th>Tamanho</th><th>Modificado</th></tr>";
        foreach ($includesFuncionais as $include) {
            echo "<tr>";
            echo "<td>{$include['arquivo']}</td>";
            echo "<td>{$include['descricao']}</td>";
            echo "<td>" . number_format($include['tamanho']) . " bytes</td>";
            echo "<td>{$include['modificado']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        if (!empty($includesFaltantes)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Includes Faltantes</h3>";
            echo "<p><strong>Total:</strong> " . count($includesFaltantes) . "</p>";
            echo "<table>";
            echo "<tr><th>Arquivo</th><th>Descrição</th><th>Erro</th></tr>";
            foreach ($includesFaltantes as $include) {
                echo "<tr>";
                echo "<td>{$include['arquivo']}</td>";
                echo "<td>{$include['descricao']}</td>";
                echo "<td>{$include['erro']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        
        echo "</div>";
        echo "</div>";
        
        $this->log("Includes principais: " . count($includesFuncionais) . " funcionais, " . count($includesFaltantes) . " faltantes");
    }
    
    private function verificarIncludesEmArquivos() {
        echo "<div class='section'>";
        echo "<h2>📁 Verificação de Includes em Arquivos PHP</h2>";
        
        $arquivosPHP = glob(__DIR__ . '/*.php');
        $includesEncontrados = [];
        $includesQuebrados = [];
        
        foreach ($arquivosPHP as $arquivo) {
            $nomeArquivo = basename($arquivo);
            if (in_array($nomeArquivo, ['verificar_includes.php', 'verificacao_geral.php', 'corrigir_automaticamente.php'])) {
                continue; // Pular arquivos de verificação
            }
            
            $conteudo = file_get_contents($arquivo);
            $includes = $this->extrairIncludes($conteudo);
            
            foreach ($includes as $include) {
                $caminhoInclude = $this->resolverCaminhoInclude($include, $arquivo);
                
                if (file_exists($caminhoInclude)) {
                    $includesEncontrados[] = [
                        'arquivo' => $nomeArquivo,
                        'include' => $include,
                        'caminho' => $caminhoInclude,
                        'status' => 'OK'
                    ];
                } else {
                    $includesQuebrados[] = [
                        'arquivo' => $nomeArquivo,
                        'include' => $include,
                        'caminho' => $caminhoInclude,
                        'status' => 'QUEBRADO'
                    ];
                    $this->caminhosQuebrados[] = $include;
                }
            }
        }
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Includes Encontrados</h3>";
        echo "<p><strong>Total:</strong> " . count($includesEncontrados) . "</p>";
        if (count($includesEncontrados) > 0) {
            echo "<table>";
            echo "<tr><th>Arquivo</th><th>Include</th><th>Status</th></tr>";
            foreach (array_slice($includesEncontrados, 0, 10) as $include) {
                echo "<tr>";
                echo "<td>{$include['arquivo']}</td>";
                echo "<td>{$include['include']}</td>";
                echo "<td class='success'>{$include['status']}</td>";
                echo "</tr>";
            }
            if (count($includesEncontrados) > 10) {
                echo "<tr><td colspan='3'>... e mais " . (count($includesEncontrados) - 10) . " includes</td></tr>";
            }
            echo "</table>";
        }
        echo "</div>";
        
        if (!empty($includesQuebrados)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Includes Quebrados</h3>";
            echo "<p><strong>Total:</strong> " . count($includesQuebrados) . "</p>";
            echo "<table>";
            echo "<tr><th>Arquivo</th><th>Include</th><th>Status</th></tr>";
            foreach ($includesQuebrados as $include) {
                echo "<tr>";
                echo "<td>{$include['arquivo']}</td>";
                echo "<td>{$include['include']}</td>";
                echo "<td class='error'>{$include['status']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        
        echo "</div>";
        echo "</div>";
        
        $this->log("Includes em arquivos: " . count($includesEncontrados) . " encontrados, " . count($includesQuebrados) . " quebrados");
    }
    
    private function verificarCaminhosRelativos() {
        echo "<div class='section'>";
        echo "<h2>🛣️ Verificação de Caminhos Relativos</h2>";
        
        $caminhosRelativos = [
            '../sql/' => 'Diretório SQL',
            '../logs/' => 'Diretório de logs',
            '../uploads/' => 'Diretório de uploads',
            '../assets/' => 'Diretório de assets',
            '../vendor/' => 'Diretório vendor',
            '../config/' => 'Diretório de configuração'
        ];
        
        $caminhosFuncionais = [];
        $caminhosFaltantes = [];
        
        foreach ($caminhosRelativos as $caminho => $descricao) {
            $caminhoCompleto = __DIR__ . '/' . $caminho;
            if (is_dir($caminhoCompleto) && is_readable($caminhoCompleto)) {
                $arquivos = glob($caminhoCompleto . '*');
                $caminhosFuncionais[] = [
                    'caminho' => $caminho,
                    'descricao' => $descricao,
                    'arquivos' => count($arquivos)
                ];
            } else {
                $caminhosFaltantes[] = [
                    'caminho' => $caminho,
                    'descricao' => $descricao,
                    'erro' => 'Diretório não encontrado ou inacessível'
                ];
            }
        }
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Caminhos Funcionais</h3>";
        echo "<p><strong>Total:</strong> " . count($caminhosFuncionais) . "</p>";
        echo "<table>";
        echo "<tr><th>Caminho</th><th>Descrição</th><th>Arquivos</th></tr>";
        foreach ($caminhosFuncionais as $caminho) {
            echo "<tr>";
            echo "<td>{$caminho['caminho']}</td>";
            echo "<td>{$caminho['descricao']}</td>";
            echo "<td>{$caminho['arquivos']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        if (!empty($caminhosFaltantes)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Caminhos Faltantes</h3>";
            echo "<p><strong>Total:</strong> " . count($caminhosFaltantes) . "</p>";
            echo "<table>";
            echo "<tr><th>Caminho</th><th>Descrição</th><th>Erro</th></tr>";
            foreach ($caminhosFaltantes as $caminho) {
                echo "<tr>";
                echo "<td>{$caminho['caminho']}</td>";
                echo "<td>{$caminho['descricao']}</td>";
                echo "<td>{$caminho['erro']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        
        echo "</div>";
        echo "</div>";
        
        $this->log("Caminhos relativos: " . count($caminhosFuncionais) . " funcionais, " . count($caminhosFaltantes) . " faltantes");
    }
    
    private function verificarAutoloads() {
        echo "<div class='section'>";
        echo "<h2>🔄 Verificação de Autoloads</h2>";
        
        $autoloads = [
            'vendor/autoload.php' => 'Composer autoload',
            'autoload.php' => 'Autoload personalizado',
            'bootstrap.php' => 'Bootstrap do sistema'
        ];
        
        $autoloadsFuncionais = [];
        $autoloadsFaltantes = [];
        
        foreach ($autoloads as $arquivo => $descricao) {
            $caminho = __DIR__ . '/' . $arquivo;
            if (file_exists($caminho) && is_readable($caminho)) {
                $autoloadsFuncionais[] = [
                    'arquivo' => $arquivo,
                    'descricao' => $descricao,
                    'status' => 'OK'
                ];
            } else {
                $autoloadsFaltantes[] = [
                    'arquivo' => $arquivo,
                    'descricao' => $descricao,
                    'status' => 'FALTANDO'
                ];
            }
        }
        
        echo "<div class='grid'>";
        echo "<div class='card success'>";
        echo "<h3>✅ Autoloads Funcionais</h3>";
        echo "<p><strong>Total:</strong> " . count($autoloadsFuncionais) . "</p>";
        echo "<table>";
        echo "<tr><th>Arquivo</th><th>Descrição</th><th>Status</th></tr>";
        foreach ($autoloadsFuncionais as $autoload) {
            echo "<tr>";
            echo "<td>{$autoload['arquivo']}</td>";
            echo "<td>{$autoload['descricao']}</td>";
            echo "<td class='success'>{$autoload['status']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        if (!empty($autoloadsFaltantes)) {
            echo "<div class='card error'>";
            echo "<h3>❌ Autoloads Faltantes</h3>";
            echo "<p><strong>Total:</strong> " . count($autoloadsFaltantes) . "</p>";
            echo "<table>";
            echo "<tr><th>Arquivo</th><th>Descrição</th><th>Status</th></tr>";
            foreach ($autoloadsFaltantes as $autoload) {
                echo "<tr>";
                echo "<td>{$autoload['arquivo']}</td>";
                echo "<td>{$autoload['descricao']}</td>";
                echo "<td class='error'>{$autoload['status']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        
        echo "</div>";
        echo "</div>";
        
        $this->log("Autoloads: " . count($autoloadsFuncionais) . " funcionais, " . count($autoloadsFaltantes) . " faltantes");
    }
    
    private function corrigirIncludesQuebrados() {
        echo "<div class='section'>";
        echo "<h2>🔧 Correção de Includes Quebrados</h2>";
        
        if (empty($this->includesQuebrados) && empty($this->caminhosQuebrados)) {
            echo "<div class='step success'>";
            echo "<h3>✅ Nenhuma Correção Necessária</h3>";
            echo "<p>Todos os includes estão funcionando corretamente!</p>";
            echo "</div>";
        } else {
            echo "<div class='step warning'>";
            echo "<h3>⚠️ Correções Disponíveis</h3>";
            echo "<p><strong>Includes quebrados:</strong> " . count($this->includesQuebrados) . "</p>";
            echo "<p><strong>Caminhos quebrados:</strong> " . count($this->caminhosQuebrados) . "</p>";
            echo "<a href='corrigir_includes.php' class='btn btn-success'>🔧 Corrigir Automaticamente</a>";
            echo "</div>";
        }
        
        echo "</div>";
    }
    
    private function exibirResumo() {
        echo "<div class='section'>";
        echo "<h2>📊 Resumo da Verificação</h2>";
        
        $totalIncludes = count($this->includesQuebrados) + count($this->caminhosQuebrados);
        
        echo "<div class='grid'>";
        echo "<div class='card'>";
        echo "<h3>📊 Estatísticas</h3>";
        echo "<ul>";
        echo "<li><strong>Includes quebrados:</strong> " . count($this->includesQuebrados) . "</li>";
        echo "<li><strong>Caminhos quebrados:</strong> " . count($this->caminhosQuebrados) . "</li>";
        echo "<li><strong>Total de problemas:</strong> {$totalIncludes}</li>";
        echo "</ul>";
        echo "</div>";
        
        echo "<div class='card'>";
        echo "<h3>🚀 Próximos Passos</h3>";
        echo "<ul>";
        if ($totalIncludes > 0) {
            echo "<li>Execute as correções automáticas</li>";
            echo "<li>Verifique os logs de erro</li>";
            echo "<li>Teste todos os includes</li>";
        } else {
            echo "<li>Sistema de includes funcionando perfeitamente</li>";
            echo "<li>Pode prosseguir com o uso normal</li>";
        }
        echo "</ul>";
        echo "</div>";
        echo "</div>";
        
        echo "<div style='text-align: center; margin-top: 20px;'>";
        if ($totalIncludes > 0) {
            echo "<a href='corrigir_includes.php' class='btn btn-success'>🔧 Corrigir Includes</a>";
        }
        echo "<a href='verificacao_geral.php' class='btn'>🔍 Verificação Geral</a>";
        echo "<a href='index.php' class='btn'>🏠 Dashboard</a>";
        echo "</div>";
        
        echo "</div>";
    }
    
    private function extrairIncludes($conteudo) {
        $includes = [];
        
        // Padrões de include
        $padroes = [
            '/require_once\s+[\'"]([^\'"]+)[\'"]/',
            '/include_once\s+[\'"]([^\'"]+)[\'"]/',
            '/require\s+[\'"]([^\'"]+)[\'"]/',
            '/include\s+[\'"]([^\'"]+)[\'"]/'
        ];
        
        foreach ($padroes as $padrao) {
            preg_match_all($padrao, $conteudo, $matches);
            if (!empty($matches[1])) {
                $includes = array_merge($includes, $matches[1]);
            }
        }
        
        return array_unique($includes);
    }
    
    private function resolverCaminhoInclude($include, $arquivoAtual) {
        $diretorioAtual = dirname($arquivoAtual);
        
        if (strpos($include, '/') === 0) {
            // Caminho absoluto
            return $include;
        } elseif (strpos($include, './') === 0) {
            // Caminho relativo
            return $diretorioAtual . '/' . substr($include, 2);
        } elseif (strpos($include, '../') === 0) {
            // Caminho relativo para cima
            return $diretorioAtual . '/' . $include;
        } else {
            // Caminho relativo simples
            return $diretorioAtual . '/' . $include;
        }
    }
}

// Executar verificação
$verificador = new VerificadorIncludes();
$verificador->executarVerificacao();
?>
