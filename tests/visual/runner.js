#!/usr/bin/env node

const { chromium } = require('playwright');
const fs = require('fs-extra');
const path = require('path');
const pixelmatch = require('pixelmatch');
const { PNG } = require('pngjs');

class VisualTestRunner {
    constructor() {
        this.baseUrl = process.env.BASE_URL || 'http://localhost';
        this.viewports = {
            desktop: { width: 1440, height: 900 },
            tablet: { width: 1024, height: 768 },
            mobile: { width: 390, height: 844 }
        };
        this.results = {
            total: 0,
            passed: 0,
            failed: 0,
            errors: [],
            screenshots: [],
            diffs: []
        };
        this.routes = [];
        this.updateBaseline = process.argv.includes('--update-baseline');
        this.routesFile = process.argv.find(arg => arg.startsWith('--routes='))?.split('=')[1];
    }

    async init() {
        console.log('🚀 Iniciando validação visual do Painel Smile PRO...');
        
        // Criar diretórios necessários
        await fs.ensureDir('tests/visual/screens');
        await fs.ensureDir('tests/visual/baseline');
        await fs.ensureDir('tests/visual/diff');
        await fs.ensureDir('tests/visual/logs');
        
        // Carregar rotas
        await this.loadRoutes();
        
        // Executar testes
        await this.runTests();
        
        // Gerar relatórios
        await this.generateReport();
        
        // Código de saída
        const exitCode = this.results.failed > 0 ? 1 : 0;
        console.log(`\n📊 Resultados: ${this.results.passed} ✅ | ${this.results.failed} ❌`);
        console.log(`Exit code: ${exitCode}`);
        process.exit(exitCode);
    }

    async loadRoutes() {
        console.log('📋 Carregando rotas...');
        
        if (this.routesFile && await fs.pathExists(this.routesFile)) {
            // Carregar rotas do arquivo
            const content = await fs.readFile(this.routesFile, 'utf8');
            this.routes = content.split('\n')
                .map(line => line.trim())
                .filter(line => line && !line.startsWith('#'));
        } else {
            // Descoberta automática de rotas
            this.routes = await this.discoverRoutes();
        }
        
        console.log(`📋 ${this.routes.length} rotas encontradas`);
    }

    async discoverRoutes() {
        const routes = [];
        const publicDir = path.join(process.cwd(), 'public');
        
        if (await fs.pathExists(publicDir)) {
            const files = await fs.readdir(publicDir);
            
            for (const file of files) {
                if (file.endsWith('.php') && !this.isExcludedFile(file)) {
                    routes.push(file);
                }
            }
        }
        
        // Adicionar rotas principais manualmente
        const mainRoutes = [
            'index.php',
            'dashboard.php',
            'sistema_unificado.php',
            'usuarios.php',
            'eventos.php',
            'fornecedores.php',
            'estoque_contagens.php',
            'lc_index.php',
            'pagamentos_painel.php',
            'demandas_quadros.php',
            'agenda.php',
            'comercial_degustacoes.php',
            'rh_funcionarios.php',
            'contab_transacoes.php',
            'configuracoes.php',
            'verificacao_geral.php',
            'executar_analise_completa.php'
        ];
        
        for (const route of mainRoutes) {
            if (!routes.includes(route)) {
                routes.push(route);
            }
        }
        
        return routes;
    }

    isExcludedFile(filename) {
        const excluded = [
            'conexao.php',
            'config.php',
            'auth.php',
            'header.php',
            'footer.php',
            'sidebar.php',
            'email_helper.php',
            'agenda_helper.php',
            'demandas_helper.php',
            'estoque_helper.php',
            'comercial_helper.php',
            'rh_helper.php',
            'contab_helper.php',
            'registrar_acesso.php',
            'corrigir_automaticamente.php',
            'fix_all_database_issues.php',
            'execute_agenda_sql.php',
            'verificar_includes.php',
            'test_',
            'debug_',
            'check_',
            'setup_',
            'fix_',
            'minimal_',
            'basic_',
            'quick_',
            'simple_',
            'direct_',
            'final_',
            'no_auth',
            'correct_',
            'real_',
            'structure',
            'markers',
            'schema',
            'arredondamentos',
            'categorias',
            'fichas',
            'itens_fixos',
            'itens',
            'configurar',
            'corrigir',
            'simplify',
            'test_all',
            'test_conexao',
            'test_ficha',
            'test_final',
            'test_me',
            'test_sidebar',
            'uso_fiorino',
            'usuario_editar',
            'usuario_novo',
            'ver.php',
            'xhr_',
            'callback.php',
            'manifest.php',
            'me_config.php',
            'me_proxy.php',
            'portao.php',
            'reset_database.php',
            'router.php',
            'scan_mysql_markers.php',
            'setup_recipes_web.php',
            'sidebar_moderna.php',
            'simplify_queries.php',
            'test_all_deletes.php',
            'test_all_fixes.php',
            'test_conexao.php',
            'test_ficha_tecnica.php',
            'test_final_fixes.php',
            'test_me_api.php',
            'test_sidebar.php',
            'uso_fiorino.php',
            'usuario_editar.php',
            'usuario_novo.php',
            'usuarios.php',
            'ver.php',
            'xhr_ficha.php'
        ];
        
        return excluded.some(exclude => filename.includes(exclude));
    }

    async runTests() {
        const browser = await chromium.launch({ 
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox']
        });
        
        try {
            for (const route of this.routes) {
                await this.testRoute(browser, route);
            }
        } finally {
            await browser.close();
        }
    }

    async testRoute(browser, route) {
        console.log(`\n🔍 Testando: ${route}`);
        this.results.total++;
        
        const context = await browser.newContext({
            viewport: this.viewports.desktop,
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        });
        
        const page = await context.newPage();
        
        // Configurar listeners de erro
        const errors = [];
        const networkErrors = [];
        
        page.on('console', msg => {
            if (msg.type() === 'error') {
                errors.push({
                    type: 'console',
                    message: msg.text(),
                    timestamp: new Date().toISOString()
                });
            }
        });
        
        page.on('pageerror', error => {
            errors.push({
                type: 'page',
                message: error.message,
                stack: error.stack,
                timestamp: new Date().toISOString()
            });
        });
        
        page.on('response', response => {
            if (response.status() >= 400) {
                networkErrors.push({
                    url: response.url(),
                    status: response.status(),
                    statusText: response.statusText(),
                    timestamp: new Date().toISOString()
                });
            }
        });
        
        try {
            const startTime = Date.now();
            const url = `${this.baseUrl}/public/${route}`;
            
            // Navegar para a página
            await page.goto(url, { 
                waitUntil: 'networkidle',
                timeout: 30000 
            });
            
            // Aguardar um pouco mais para garantir carregamento completo
            await page.waitForTimeout(1000);
            
            const loadTime = Date.now() - startTime;
            
            // Testar cada viewport
            for (const [viewportName, viewport] of Object.entries(this.viewports)) {
                await this.testViewport(page, route, viewportName, viewport, errors, networkErrors, loadTime);
            }
            
            this.results.passed++;
            console.log(`✅ ${route} - OK`);
            
        } catch (error) {
            this.results.failed++;
            this.results.errors.push({
                route,
                error: error.message,
                timestamp: new Date().toISOString()
            });
            console.log(`❌ ${route} - ERRO: ${error.message}`);
        } finally {
            await context.close();
        }
    }

    async testViewport(page, route, viewportName, viewport, errors, networkErrors, loadTime) {
        // Definir viewport
        await page.setViewportSize(viewport);
        
        // Aguardar um pouco para o layout se ajustar
        await page.waitForTimeout(500);
        
        // Gerar slug para o arquivo
        const slug = route.replace('.php', '').replace(/[^a-zA-Z0-9]/g, '_');
        const screenshotPath = `tests/visual/screens/${viewportName}/${slug}.png`;
        const baselinePath = `tests/visual/baseline/${viewportName}/${slug}.png`;
        const diffPath = `tests/visual/diff/${viewportName}/${slug}.png`;
        
        // Capturar screenshot
        await fs.ensureDir(path.dirname(screenshotPath));
        await page.screenshot({ 
            path: screenshotPath,
            fullPage: true,
            animations: 'disabled'
        });
        
        // Salvar log da página
        const logPath = `tests/visual/logs/${slug}_${viewportName}.log`;
        await fs.ensureDir(path.dirname(logPath));
        await fs.writeFile(logPath, JSON.stringify({
            route,
            viewport: viewportName,
            loadTime,
            errors,
            networkErrors,
            timestamp: new Date().toISOString()
        }, null, 2));
        
        // Verificar se existe baseline
        if (await fs.pathExists(baselinePath)) {
            if (this.updateBaseline) {
                // Atualizar baseline
                await fs.copy(screenshotPath, baselinePath);
                console.log(`📸 Baseline atualizado: ${baselineName}`);
            } else {
                // Comparar com baseline
                await this.compareWithBaseline(screenshotPath, baselinePath, diffPath, route, viewportName);
            }
        } else {
            // Criar baseline se não existir
            await fs.copy(screenshotPath, baselinePath);
            console.log(`📸 Baseline criado: ${baselinePath}`);
        }
        
        // Adicionar à lista de screenshots
        this.results.screenshots.push({
            route,
            viewport: viewportName,
            screenshot: screenshotPath,
            baseline: baselinePath,
            diff: await fs.pathExists(diffPath) ? diffPath : null,
            errors: errors.length,
            networkErrors: networkErrors.length,
            loadTime
        });
    }

    async compareWithBaseline(currentPath, baselinePath, diffPath, route, viewportName) {
        try {
            const current = PNG.sync.read(await fs.readFile(currentPath));
            const baseline = PNG.sync.read(await fs.readFile(baselinePath));
            
            const { width, height } = current;
            const diff = new PNG({ width, height });
            
            const diffPixels = pixelmatch(
                current.data, 
                baseline.data, 
                diff.data, 
                width, 
                height, 
                { threshold: 0.1 }
            );
            
            const diffPercentage = (diffPixels / (width * height)) * 100;
            
            if (diffPercentage > 1) {
                // Salvar diff
                await fs.ensureDir(path.dirname(diffPath));
                await fs.writeFile(diffPath, PNG.sync.write(diff));
                
                this.results.diffs.push({
                    route,
                    viewport: viewportName,
                    diffPercentage,
                    diffPath
                });
                
                this.results.failed++;
                console.log(`❌ ${route} (${viewportName}) - DIFERENÇA: ${diffPercentage.toFixed(2)}%`);
            } else {
                console.log(`✅ ${route} (${viewportName}) - OK`);
            }
            
        } catch (error) {
            console.log(`⚠️ Erro ao comparar ${route} (${viewportName}): ${error.message}`);
        }
    }

    async generateReport() {
        console.log('\n📊 Gerando relatório...');
        
        const reportHtml = this.generateReportHtml();
        const reportPath = 'tests/visual/report.html';
        await fs.writeFile(reportPath, reportHtml);
        
        const logJson = {
            summary: {
                total: this.results.total,
                passed: this.results.passed,
                failed: this.results.failed,
                timestamp: new Date().toISOString()
            },
            results: this.results.screenshots,
            errors: this.results.errors,
            diffs: this.results.diffs
        };
        
        const logPath = 'tests/visual/log.json';
        await fs.writeFile(logPath, JSON.stringify(logJson, null, 2));
        
        console.log(`📊 Relatório gerado: ${reportPath}`);
        console.log(`📋 Log gerado: ${logPath}`);
    }

    generateReportHtml() {
        const timestamp = new Date().toLocaleString();
        const passRate = this.results.total > 0 ? ((this.results.passed / this.results.total) * 100).toFixed(1) : 0;
        
        return `
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Validação Visual - Painel Smile PRO</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f8fafc;
            color: #1f2937;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0 0 10px 0;
            color: #1f2937;
            font-size: 2.5em;
        }
        .header .subtitle {
            color: #6b7280;
            font-size: 1.2em;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            margin: 0;
        }
        .stat-number.success { color: #10b981; }
        .stat-number.error { color: #ef4444; }
        .stat-number.warning { color: #f59e0b; }
        .stat-label {
            color: #6b7280;
            margin-top: 5px;
        }
        .filters {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .filter-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-group label {
            font-weight: 600;
            color: #374151;
        }
        .filter-group select, .filter-group input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            font-size: 14px;
        }
        .results {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .result-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        .result-card:hover {
            transform: translateY(-5px);
        }
        .result-header {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        .result-title {
            font-weight: 600;
            margin: 0 0 5px 0;
            color: #1f2937;
        }
        .result-meta {
            font-size: 0.9em;
            color: #6b7280;
        }
        .result-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 600;
            margin-top: 5px;
        }
        .status-ok {
            background: #d1fae5;
            color: #065f46;
        }
        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-diff {
            background: #fef3c7;
            color: #92400e;
        }
        .result-screenshots {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 15px;
        }
        .screenshot {
            text-align: center;
        }
        .screenshot img {
            width: 100%;
            height: auto;
            border-radius: 5px;
            border: 1px solid #e5e7eb;
        }
        .screenshot-label {
            font-size: 0.8em;
            color: #6b7280;
            margin-top: 5px;
        }
        .result-errors {
            padding: 15px;
            background: #fef2f2;
            border-top: 1px solid #e5e7eb;
        }
        .error-item {
            font-size: 0.9em;
            color: #991b1b;
            margin: 5px 0;
        }
        .no-results {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Validação Visual - Painel Smile PRO</h1>
            <div class="subtitle">Relatório gerado em ${timestamp}</div>
        </div>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number success">${this.results.passed}</div>
                <div class="stat-label">Páginas OK</div>
            </div>
            <div class="stat-card">
                <div class="stat-number error">${this.results.failed}</div>
                <div class="stat-label">Páginas com Problemas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number warning">${this.results.diffs.length}</div>
                <div class="stat-label">Diferenças Visuais</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">${passRate}%</div>
                <div class="stat-label">Taxa de Sucesso</div>
            </div>
        </div>
        
        <div class="filters">
            <div class="filter-group">
                <label for="viewport-filter">Viewport:</label>
                <select id="viewport-filter">
                    <option value="">Todos</option>
                    <option value="desktop">Desktop</option>
                    <option value="tablet">Tablet</option>
                    <option value="mobile">Mobile</option>
                </select>
                
                <label for="status-filter">Status:</label>
                <select id="status-filter">
                    <option value="">Todos</option>
                    <option value="ok">OK</option>
                    <option value="error">Erro</option>
                    <option value="diff">Diferença</option>
                </select>
                
                <label for="search">Buscar:</label>
                <input type="text" id="search" placeholder="Nome da página...">
            </div>
        </div>
        
        <div class="results" id="results">
            ${this.generateResultsHtml()}
        </div>
        
        <div class="footer">
            <p>Relatório gerado automaticamente pelo sistema de validação visual</p>
        </div>
    </div>
    
    <script>
        // Filtros
        const viewportFilter = document.getElementById('viewport-filter');
        const statusFilter = document.getElementById('status-filter');
        const searchInput = document.getElementById('search');
        const resultsContainer = document.getElementById('results');
        
        function filterResults() {
            const viewport = viewportFilter.value;
            const status = statusFilter.value;
            const search = searchInput.value.toLowerCase();
            
            const cards = document.querySelectorAll('.result-card');
            
            cards.forEach(card => {
                const cardViewport = card.dataset.viewport;
                const cardStatus = card.dataset.status;
                const cardTitle = card.querySelector('.result-title').textContent.toLowerCase();
                
                const viewportMatch = !viewport || cardViewport === viewport;
                const statusMatch = !status || cardStatus === status;
                const searchMatch = !search || cardTitle.includes(search);
                
                if (viewportMatch && statusMatch && searchMatch) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        viewportFilter.addEventListener('change', filterResults);
        statusFilter.addEventListener('change', filterResults);
        searchInput.addEventListener('input', filterResults);
    </script>
</body>
</html>`;
    }

    generateResultsHtml() {
        if (this.results.screenshots.length === 0) {
            return '<div class="no-results">Nenhum resultado encontrado</div>';
        }
        
        return this.results.screenshots.map(result => {
            const status = result.errors > 0 || result.networkErrors > 0 ? 'error' : 
                          this.results.diffs.some(diff => diff.route === result.route && diff.viewport === result.viewport) ? 'diff' : 'ok';
            
            const statusText = status === 'ok' ? 'OK' : 
                              status === 'error' ? 'ERRO' : 'DIFERENÇA';
            
            const statusClass = status === 'ok' ? 'status-ok' : 
                               status === 'error' ? 'status-error' : 'status-diff';
            
            const diff = this.results.diffs.find(d => d.route === result.route && d.viewport === result.viewport);
            
            return `
                <div class="result-card" data-viewport="${result.viewport}" data-status="${status}">
                    <div class="result-header">
                        <div class="result-title">${result.route}</div>
                        <div class="result-meta">
                            Viewport: ${result.viewport} | 
                            Tempo: ${result.loadTime}ms | 
                            Erros: ${result.errors} | 
                            Network: ${result.networkErrors}
                        </div>
                        <div class="result-status ${statusClass}">${statusText}</div>
                    </div>
                    
                    <div class="result-screenshots">
                        <div class="screenshot">
                            <img src="${result.screenshot}" alt="Screenshot atual">
                            <div class="screenshot-label">Atual</div>
                        </div>
                        
                        ${result.baseline ? `
                            <div class="screenshot">
                                <img src="${result.baseline}" alt="Baseline">
                                <div class="screenshot-label">Baseline</div>
                            </div>
                        ` : ''}
                        
                        ${diff ? `
                            <div class="screenshot">
                                <img src="${diff.diffPath}" alt="Diferença">
                                <div class="screenshot-label">Diferença (${diff.diffPercentage.toFixed(2)}%)</div>
                            </div>
                        ` : ''}
                    </div>
                    
                    ${result.errors > 0 || result.networkErrors > 0 ? `
                        <div class="result-errors">
                            <strong>Problemas encontrados:</strong>
                            ${result.errors > 0 ? `<div class="error-item">${result.errors} erros de console</div>` : ''}
                            ${result.networkErrors > 0 ? `<div class="error-item">${result.networkErrors} erros de rede</div>` : ''}
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');
    }
}

// Executar se chamado diretamente
if (require.main === module) {
    const runner = new VisualTestRunner();
    runner.init().catch(console.error);
}

module.exports = VisualTestRunner;
