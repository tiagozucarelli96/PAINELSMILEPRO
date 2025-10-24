#!/usr/bin/env node

// crawler.js — Crawler de links internos do Painel Smile PRO
const axios = require('axios');
const cheerio = require('cheerio');
const fs = require('fs-extra');
const path = require('path');
const { URL } = require('url');
const colors = require('colors');
const cliProgress = require('cli-progress');

class LinksCrawler {
    constructor() {
        this.baseUrl = process.env.BASE_URL || 'http://localhost';
        this.maxDepth = parseInt(process.argv.find(arg => arg.startsWith('--max-depth='))?.split('=')[1]) || 3;
        this.startUrl = process.argv.find(arg => arg.startsWith('--start='))?.split('=')[1] || '/public/dashboard.php';
        this.skipPattern = process.argv.find(arg => arg.startsWith('--skip='))?.split('=')[1];
        
        this.visited = new Set();
        this.toVisit = [];
        this.results = [];
        this.errors = [];
        this.sessionCookie = null;
        this.rateLimit = 3000; // 3 req/s
        this.timeout = 10000; // 10s
        this.maxRetries = 2;
        
        this.progressBar = new cliProgress.SingleBar({
            format: 'Crawling |{bar}| {percentage}% | {value}/{total} | {status}',
            barCompleteChar: '\u2588',
            barIncompleteChar: '\u2591',
            hideCursor: true
        });
    }

    async init() {
        console.log('🕷️ Iniciando crawler de links internos...'.cyan);
        console.log(`📍 URL inicial: ${this.startUrl}`.yellow);
        console.log(`📏 Profundidade máxima: ${this.maxDepth}`.yellow);
        
        try {
            // Autenticar se necessário
            await this.authenticate();
            
            // Iniciar crawling
            await this.crawl();
            
            // Gerar relatórios
            await this.generateReports();
            
            // Código de saída
            const hasErrors = this.errors.some(error => error.status >= 400);
            const exitCode = hasErrors ? 1 : 0;
            
            console.log(`\n📊 Crawling concluído!`.green);
            console.log(`📄 Páginas visitadas: ${this.visited.size}`.blue);
            console.log(`❌ Erros encontrados: ${this.errors.length}`.red);
            console.log(`Exit code: ${exitCode}`.yellow);
            
            process.exit(exitCode);
            
        } catch (error) {
            console.error(`❌ Erro fatal: ${error.message}`.red);
            process.exit(1);
        }
    }

    async authenticate() {
        console.log('🔐 Verificando autenticação...'.yellow);
        
        try {
            // Tentar acessar página protegida
            const response = await axios.get(`${this.baseUrl}${this.startUrl}`, {
                timeout: this.timeout,
                maxRedirects: 5,
                validateStatus: () => true
            });
            
            // Se redirecionou para login, fazer login
            if (response.status === 302 || response.data.includes('login') || response.data.includes('Login')) {
                console.log('🔑 Fazendo login...'.yellow);
                await this.performLogin();
            } else {
                console.log('✅ Já autenticado'.green);
                this.sessionCookie = this.extractCookies(response);
            }
            
        } catch (error) {
            console.log('⚠️ Erro na verificação de autenticação, continuando...'.yellow);
        }
    }

    async performLogin() {
        try {
            // Página de login
            const loginUrl = `${this.baseUrl}/public/login.php`;
            const loginResponse = await axios.get(loginUrl, {
                timeout: this.timeout,
                validateStatus: () => true
            });
            
            if (loginResponse.status !== 200) {
                throw new Error(`Erro ao acessar página de login: ${loginResponse.status}`);
            }
            
            // Extrair token CSRF se existir
            const $ = cheerio.load(loginResponse.data);
            const csrfToken = $('input[name="csrf_token"]').val() || 
                             $('input[name="_token"]').val() || 
                             $('meta[name="csrf-token"]').attr('content');
            
            // Dados de login
            const loginData = {
                username: process.env.TEST_USERNAME || 'admin',
                password: process.env.TEST_PASSWORD || 'admin',
                ...(csrfToken && { csrf_token: csrfToken })
            };
            
            // Fazer login
            const loginPost = await axios.post(loginUrl, loginData, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Cookie': this.extractCookies(loginResponse)
                },
                timeout: this.timeout,
                maxRedirects: 5,
                validateStatus: () => true
            });
            
            this.sessionCookie = this.extractCookies(loginPost);
            console.log('✅ Login realizado com sucesso'.green);
            
        } catch (error) {
            console.log('⚠️ Erro no login, continuando sem autenticação...'.yellow);
        }
    }

    extractCookies(response) {
        const cookies = response.headers['set-cookie'];
        if (!cookies) return '';
        
        return cookies.map(cookie => cookie.split(';')[0]).join('; ');
    }

    async crawl() {
        console.log('🕷️ Iniciando crawling...'.cyan);
        
        // Adicionar URL inicial
        this.toVisit.push({
            url: this.startUrl,
            depth: 0,
            parent: null
        });
        
        this.progressBar.start(0, 0, { status: 'Iniciando...' });
        
        while (this.toVisit.length > 0) {
            const current = this.toVisit.shift();
            
            if (this.visited.has(current.url) || current.depth > this.maxDepth) {
                continue;
            }
            
            this.visited.add(current.url);
            this.progressBar.setTotal(this.toVisit.length + this.visited.size);
            this.progressBar.update(this.visited.size, { status: current.url });
            
            try {
                const result = await this.crawlPage(current);
                this.results.push(result);
                
                // Adicionar links encontrados
                if (result.links && current.depth < this.maxDepth) {
                    for (const link of result.links) {
                        if (!this.visited.has(link.url) && !this.shouldSkip(link.url)) {
                            this.toVisit.push({
                                url: link.url,
                                depth: current.depth + 1,
                                parent: current.url
                            });
                        }
                    }
                }
                
                // Rate limiting
                await this.sleep(this.rateLimit);
                
            } catch (error) {
                this.errors.push({
                    url: current.url,
                    error: error.message,
                    status: error.response?.status || 0,
                    timestamp: new Date().toISOString()
                });
            }
        }
        
        this.progressBar.stop();
    }

    async crawlPage(pageInfo) {
        const startTime = Date.now();
        const fullUrl = this.resolveUrl(pageInfo.url);
        
        try {
            const response = await axios.get(fullUrl, {
                headers: {
                    'Cookie': this.sessionCookie,
                    'User-Agent': 'Mozilla/5.0 (compatible; LinksCrawler/1.0)'
                },
                timeout: this.timeout,
                maxRedirects: 5,
                validateStatus: () => true
            });
            
            const endTime = Date.now();
            const responseTime = endTime - startTime;
            
            const $ = cheerio.load(response.data);
            
            // Extrair links
            const links = this.extractLinks($, fullUrl);
            
            // Extrair assets
            const assets = this.extractAssets($, fullUrl);
            
            // Verificar assets
            const assetResults = await this.checkAssets(assets);
            
            return {
                url: pageInfo.url,
                fullUrl: fullUrl,
                status: response.status,
                responseTime: responseTime,
                depth: pageInfo.depth,
                parent: pageInfo.parent,
                title: $('title').text().trim(),
                links: links,
                assets: assets,
                assetResults: assetResults,
                timestamp: new Date().toISOString()
            };
            
        } catch (error) {
            const endTime = Date.now();
            const responseTime = endTime - startTime;
            
            throw {
                ...error,
                responseTime: responseTime
            };
        }
    }

    extractLinks($, baseUrl) {
        const links = [];
        
        $('a[href]').each((i, element) => {
            const href = $(element).attr('href');
            if (!href) return;
            
            // Ignorar anchors
            if (href.startsWith('#')) return;
            
            // Ignorar logout
            if (href.includes('logout')) return;
            
            // Ignorar downloads
            if (href.includes('download') || href.includes('.pdf') || href.includes('.zip')) return;
            
            // Ignorar webhooks
            if (href.includes('webhook') || href.includes('callback')) return;
            
            // Ignorar POST-only
            if (href.includes('submit') || href.includes('action')) return;
            
            const resolvedUrl = this.resolveUrl(href, baseUrl);
            
            // Verificar se é interno
            if (this.isInternalUrl(resolvedUrl)) {
                links.push({
                    url: this.normalizeUrl(resolvedUrl),
                    text: $(element).text().trim(),
                    href: href
                });
            }
        });
        
        return links;
    }

    extractAssets($, baseUrl) {
        const assets = [];
        
        // CSS
        $('link[rel="stylesheet"]').each((i, element) => {
            const href = $(element).attr('href');
            if (href) {
                assets.push({
                    type: 'css',
                    url: this.resolveUrl(href, baseUrl),
                    original: href
                });
            }
        });
        
        // JavaScript
        $('script[src]').each((i, element) => {
            const src = $(element).attr('src');
            if (src) {
                assets.push({
                    type: 'js',
                    url: this.resolveUrl(src, baseUrl),
                    original: src
                });
            }
        });
        
        // Images
        $('img[src]').each((i, element) => {
            const src = $(element).attr('src');
            if (src) {
                assets.push({
                    type: 'img',
                    url: this.resolveUrl(src, baseUrl),
                    original: src
                });
            }
        });
        
        return assets;
    }

    async checkAssets(assets) {
        const results = [];
        
        for (const asset of assets) {
            if (!this.isInternalUrl(asset.url)) continue;
            
            try {
                const startTime = Date.now();
                const response = await axios.head(asset.url, {
                    headers: {
                        'Cookie': this.sessionCookie,
                        'User-Agent': 'Mozilla/5.0 (compatible; LinksCrawler/1.0)'
                    },
                    timeout: 5000,
                    validateStatus: () => true
                });
                
                const endTime = Date.now();
                
                results.push({
                    ...asset,
                    status: response.status,
                    responseTime: endTime - startTime,
                    size: response.headers['content-length'] || 0
                });
                
            } catch (error) {
                results.push({
                    ...asset,
                    status: error.response?.status || 0,
                    error: error.message
                });
            }
        }
        
        return results;
    }

    resolveUrl(url, baseUrl = null) {
        try {
            if (baseUrl) {
                return new URL(url, baseUrl).href;
            } else {
                return new URL(url, this.baseUrl).href;
            }
        } catch {
            return url;
        }
    }

    normalizeUrl(url) {
        try {
            const urlObj = new URL(url);
            return urlObj.pathname + urlObj.search;
        } catch {
            return url;
        }
    }

    isInternalUrl(url) {
        try {
            const urlObj = new URL(url);
            const baseUrlObj = new URL(this.baseUrl);
            
            return urlObj.hostname === baseUrlObj.hostname && 
                   urlObj.pathname.startsWith('/public/');
        } catch {
            return false;
        }
    }

    shouldSkip(url) {
        if (this.skipPattern) {
            const regex = new RegExp(this.skipPattern);
            return regex.test(url);
        }
        
        // Padrões padrão para ignorar
        const skipPatterns = [
            /logout/i,
            /download/i,
            /webhook/i,
            /callback/i,
            /submit/i,
            /action/i,
            /\.pdf$/i,
            /\.zip$/i,
            /\.exe$/i,
            /#/
        ];
        
        return skipPatterns.some(pattern => pattern.test(url));
    }

    async sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    async generateReports() {
        console.log('📊 Gerando relatórios...'.cyan);
        
        // Criar diretórios
        await fs.ensureDir('tests/links');
        
        // Relatório HTML
        const htmlReport = this.generateHtmlReport();
        await fs.writeFile('tests/links/report.html', htmlReport);
        
        // Relatório JSON
        const jsonReport = {
            summary: {
                totalPages: this.results.length,
                totalErrors: this.errors.length,
                totalLinks: this.results.reduce((sum, r) => sum + (r.links?.length || 0), 0),
                totalAssets: this.results.reduce((sum, r) => sum + (r.assets?.length || 0), 0),
                averageResponseTime: this.results.reduce((sum, r) => sum + r.responseTime, 0) / this.results.length,
                timestamp: new Date().toISOString()
            },
            results: this.results,
            errors: this.errors
        };
        
        await fs.writeFile('tests/links/report.json', JSON.stringify(jsonReport, null, 2));
        
        console.log('📄 Relatório HTML: tests/links/report.html'.green);
        console.log('📋 Relatório JSON: tests/links/report.json'.green);
    }

    generateHtmlReport() {
        const timestamp = new Date().toLocaleString();
        const totalPages = this.results.length;
        const totalErrors = this.errors.length;
        const totalLinks = this.results.reduce((sum, r) => sum + (r.links?.length || 0), 0);
        const totalAssets = this.results.reduce((sum, r) => sum + (r.assets?.length || 0), 0);
        const averageResponseTime = this.results.reduce((sum, r) => sum + r.responseTime, 0) / this.results.length;
        
        return `
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Links - Painel Smile PRO</title>
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
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .results table {
            width: 100%;
            border-collapse: collapse;
        }
        .results th, .results td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .results th {
            background: #f3f4f6;
            font-weight: 600;
            color: #374151;
        }
        .results tr:hover {
            background: #f9fafb;
        }
        .status-ok {
            color: #10b981;
            font-weight: bold;
        }
        .status-error {
            color: #ef4444;
            font-weight: bold;
        }
        .status-warning {
            color: #f59e0b;
            font-weight: bold;
        }
        .depth {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8em;
            font-weight: bold;
        }
        .depth-0 { background: #d1fae5; color: #065f46; }
        .depth-1 { background: #dbeafe; color: #1e40af; }
        .depth-2 { background: #fef3c7; color: #92400e; }
        .depth-3 { background: #fecaca; color: #991b1b; }
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
            <h1>🕷️ Relatório de Links - Painel Smile PRO</h1>
            <div class="subtitle">Relatório gerado em ${timestamp}</div>
        </div>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number success">${totalPages}</div>
                <div class="stat-label">Páginas Visitadas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number error">${totalErrors}</div>
                <div class="stat-label">Erros Encontrados</div>
            </div>
            <div class="stat-card">
                <div class="stat-number warning">${totalLinks}</div>
                <div class="stat-label">Links Encontrados</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">${totalAssets}</div>
                <div class="stat-label">Assets Verificados</div>
            </div>
        </div>
        
        <div class="filters">
            <div class="filter-group">
                <label for="status-filter">Status:</label>
                <select id="status-filter">
                    <option value="">Todos</option>
                    <option value="200">200 OK</option>
                    <option value="404">404 Not Found</option>
                    <option value="500">500 Server Error</option>
                </select>
                
                <label for="depth-filter">Profundidade:</label>
                <select id="depth-filter">
                    <option value="">Todas</option>
                    <option value="0">0 - Dashboard</option>
                    <option value="1">1 - Primeiro nível</option>
                    <option value="2">2 - Segundo nível</option>
                    <option value="3">3 - Terceiro nível</option>
                </select>
                
                <label for="search">Buscar:</label>
                <input type="text" id="search" placeholder="URL ou título...">
            </div>
        </div>
        
        <div class="results">
            <table>
                <thead>
                    <tr>
                        <th>URL</th>
                        <th>Título</th>
                        <th>Status</th>
                        <th>Tempo (ms)</th>
                        <th>Profundidade</th>
                        <th>Links</th>
                        <th>Assets</th>
                    </tr>
                </thead>
                <tbody>
                    ${this.results.map(result => `
                        <tr>
                            <td>
                                <a href="${result.fullUrl}" target="_blank">${result.url}</a>
                            </td>
                            <td>${result.title || '-'}</td>
                            <td class="status-${result.status >= 400 ? 'error' : result.status >= 300 ? 'warning' : 'ok'}">
                                ${result.status}
                            </td>
                            <td>${result.responseTime}</td>
                            <td>
                                <span class="depth depth-${result.depth}">${result.depth}</span>
                            </td>
                            <td>${result.links?.length || 0}</td>
                            <td>${result.assets?.length || 0}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
        
        <div class="footer">
            <p>Relatório gerado automaticamente pelo crawler de links</p>
        </div>
    </div>
    
    <script>
        // Filtros
        const statusFilter = document.getElementById('status-filter');
        const depthFilter = document.getElementById('depth-filter');
        const searchInput = document.getElementById('search');
        const tableBody = document.querySelector('tbody');
        
        function filterResults() {
            const status = statusFilter.value;
            const depth = depthFilter.value;
            const search = searchInput.value.toLowerCase();
            
            const rows = tableBody.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const url = cells[0].textContent.toLowerCase();
                const title = cells[1].textContent.toLowerCase();
                const statusCell = cells[2].textContent;
                const depthCell = cells[4].textContent;
                
                const statusMatch = !status || statusCell.includes(status);
                const depthMatch = !depth || depthCell.includes(depth);
                const searchMatch = !search || url.includes(search) || title.includes(search);
                
                if (statusMatch && depthMatch && searchMatch) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        statusFilter.addEventListener('change', filterResults);
        depthFilter.addEventListener('change', filterResults);
        searchInput.addEventListener('input', filterResults);
    </script>
</body>
</html>`;
    }
}

// Executar se chamado diretamente
if (require.main === module) {
    const crawler = new LinksCrawler();
    crawler.init().catch(console.error);
}

module.exports = LinksCrawler;
