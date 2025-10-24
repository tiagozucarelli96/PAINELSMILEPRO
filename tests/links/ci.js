#!/usr/bin/env node

// ci.js — Integração com CI/CD para crawler de links
const { execSync } = require('child_process');
const fs = require('fs-extra');
const path = require('path');

class CIIntegration {
    constructor() {
        this.baseUrl = process.env.BASE_URL || 'http://localhost';
        this.ciMode = process.env.CI === 'true';
        this.verbose = process.env.VERBOSE === 'true';
    }

    async run() {
        console.log('🔄 Executando crawler de links em modo CI/CD...');
        
        try {
            // Verificar se o servidor está rodando
            await this.checkServer();
            
            // Executar crawler
            await this.runCrawler();
            
            // Verificar resultados
            await this.checkResults();
            
            console.log('✅ Crawler de links concluído com sucesso!');
            process.exit(0);
            
        } catch (error) {
            console.error(`❌ Falha no crawler de links: ${error.message}`);
            process.exit(1);
        }
    }

    async checkServer() {
        console.log('🔍 Verificando servidor...');
        
        try {
            const response = await fetch(`${this.baseUrl}/public/dashboard.php`);
            if (response.ok) {
                console.log('✅ Servidor está rodando');
            } else {
                throw new Error(`Servidor retornou status ${response.status}`);
            }
        } catch (error) {
            throw new Error(`Servidor não está acessível: ${error.message}`);
        }
    }

    async runCrawler() {
        console.log('🕷️ Executando crawler...');
        
        try {
            execSync('npm run links:crawl', { 
                stdio: this.verbose ? 'inherit' : 'pipe',
                cwd: __dirname 
            });
            
        } catch (error) {
            throw new Error(`Falha na execução do crawler: ${error.message}`);
        }
    }

    async checkResults() {
        console.log('📊 Verificando resultados...');
        
        const reportPath = path.join(__dirname, 'report.json');
        
        if (!await fs.pathExists(reportPath)) {
            throw new Error('Arquivo de relatório não encontrado');
        }
        
        const report = await fs.readJson(reportPath);
        
        console.log(`📈 Total de páginas: ${report.summary.totalPages}`);
        console.log(`❌ Erros encontrados: ${report.summary.totalErrors}`);
        console.log(`🔗 Links encontrados: ${report.summary.totalLinks}`);
        console.log(`📦 Assets verificados: ${report.summary.totalAssets}`);
        
        if (report.summary.totalErrors > 0) {
            console.log('\n❌ Erros encontrados:');
            
            for (const error of report.errors) {
                console.log(`  - ${error.url}: ${error.error}`);
            }
            
            throw new Error(`${report.summary.totalErrors} erros encontrados`);
        }
        
        console.log('✅ Todos os links estão funcionando!');
    }

    async generateReport() {
        console.log('📊 Gerando relatório...');
        
        const reportPath = path.join(__dirname, 'report.html');
        
        if (await fs.pathExists(reportPath)) {
            console.log(`📋 Relatório gerado: ${reportPath}`);
        } else {
            console.log('⚠️ Relatório não encontrado');
        }
    }

    async uploadArtifacts() {
        if (!this.ciMode) {
            console.log('📤 Modo CI não detectado, pulando upload de artefatos');
            return;
        }
        
        console.log('📤 Upload de artefatos...');
        
        const artifacts = [
            'report.html',
            'report.json',
            'logs/'
        ];
        
        for (const artifact of artifacts) {
            const artifactPath = path.join(__dirname, artifact);
            if (await fs.pathExists(artifactPath)) {
                console.log(`📁 ${artifact} encontrado`);
            } else {
                console.log(`⚠️ ${artifact} não encontrado`);
            }
        }
    }
}

// Executar se chamado diretamente
if (require.main === module) {
    const ci = new CIIntegration();
    ci.run().catch(console.error);
}

module.exports = CIIntegration;
