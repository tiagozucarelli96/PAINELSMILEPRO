#!/usr/bin/env node

// ci.js — Integração com CI/CD para validação visual
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
        console.log('🔄 Executando validação visual em modo CI/CD...');
        
        try {
            // Verificar se o servidor está rodando
            await this.checkServer();
            
            // Executar testes
            await this.runTests();
            
            // Verificar resultados
            await this.checkResults();
            
            console.log('✅ Validação visual concluída com sucesso!');
            process.exit(0);
            
        } catch (error) {
            console.error(`❌ Falha na validação visual: ${error.message}`);
            process.exit(1);
        }
    }

    async checkServer() {
        console.log('🔍 Verificando servidor...');
        
        try {
            const response = await fetch(`${this.baseUrl}/public/index.php`);
            if (response.ok) {
                console.log('✅ Servidor está rodando');
            } else {
                throw new Error(`Servidor retornou status ${response.status}`);
            }
        } catch (error) {
            throw new Error(`Servidor não está acessível: ${error.message}`);
        }
    }

    async runTests() {
        console.log('🧪 Executando testes...');
        
        try {
            // Executar teste rápido primeiro
            console.log('📋 Executando teste rápido...');
            execSync('npm run visual:quick', { 
                stdio: this.verbose ? 'inherit' : 'pipe',
                cwd: __dirname 
            });
            
            // Executar testes completos
            console.log('📋 Executando testes completos...');
            execSync('npm run visual:test', { 
                stdio: this.verbose ? 'inherit' : 'pipe',
                cwd: __dirname 
            });
            
        } catch (error) {
            throw new Error(`Falha na execução dos testes: ${error.message}`);
        }
    }

    async checkResults() {
        console.log('📊 Verificando resultados...');
        
        const logPath = path.join(__dirname, 'log.json');
        
        if (!await fs.pathExists(logPath)) {
            throw new Error('Arquivo de log não encontrado');
        }
        
        const log = await fs.readJson(logPath);
        
        console.log(`📈 Total: ${log.summary.total}`);
        console.log(`✅ Passou: ${log.summary.passed}`);
        console.log(`❌ Falhou: ${log.summary.failed}`);
        
        if (log.summary.failed > 0) {
            console.log('\n❌ Falhas encontradas:');
            
            for (const error of log.errors) {
                console.log(`  - ${error.route}: ${error.error}`);
            }
            
            for (const diff of log.diffs) {
                console.log(`  - ${diff.route} (${diff.viewport}): ${diff.diffPercentage.toFixed(2)}% diferença`);
            }
            
            throw new Error(`${log.summary.failed} testes falharam`);
        }
        
        console.log('✅ Todos os testes passaram!');
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
            'log.json',
            'screens/',
            'diff/'
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
