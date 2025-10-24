#!/usr/bin/env node

// demo.js — Demonstração do sistema de validação visual
const fs = require('fs-extra');
const path = require('path');

class VisualTestDemo {
    constructor() {
        this.baseUrl = process.env.BASE_URL || 'http://localhost';
    }

    async run() {
        console.log('🎭 Demonstração do Sistema de Validação Visual');
        console.log('=' .repeat(50));
        
        // Verificar estrutura de arquivos
        await this.checkFileStructure();
        
        // Mostrar configurações
        this.showConfiguration();
        
        // Mostrar comandos disponíveis
        this.showCommands();
        
        // Mostrar exemplos de uso
        this.showExamples();
        
        console.log('\n🚀 Para começar:');
        console.log('  npm run visual:install  # Instalar dependências');
        console.log('  npm run visual:quick    # Teste rápido');
        console.log('  npm run visual:test     # Executar todos os testes');
    }

    async checkFileStructure() {
        console.log('\n📁 Verificando estrutura de arquivos...');
        
        const files = [
            'package.json',
            'config.js',
            'run-tests.js',
            'quick-test.js',
            'auth.js',
            'utils.js',
            'routes.txt',
            'README.md',
            'install.sh'
        ];
        
        let existing = 0;
        let missing = 0;
        
        for (const file of files) {
            const exists = await fs.pathExists(path.join(__dirname, file));
            if (exists) {
                console.log(`✅ ${file}`);
                existing++;
            } else {
                console.log(`❌ ${file}`);
                missing++;
            }
        }
        
        console.log(`\n📊 Arquivos: ${existing} ✅ | ${missing} ❌`);
        
        if (missing === 0) {
            console.log('🎉 Estrutura completa!');
        } else {
            console.log('⚠️ Alguns arquivos estão faltando.');
        }
    }

    showConfiguration() {
        console.log('\n⚙️ Configuração:');
        console.log(`  Base URL: ${this.baseUrl}`);
        console.log('  Viewports: Desktop (1440x900), Tablet (1024x768), Mobile (390x844)');
        console.log('  Timeout: 30s navegação, 1s network idle');
        console.log('  Screenshot: Full page, sem animações');
        console.log('  Comparação: Threshold 0.1, diff > 1%');
    }

    showCommands() {
        console.log('\n📋 Comandos disponíveis:');
        console.log('  npm run visual:install        # Instalar dependências');
        console.log('  npm run visual:quick          # Teste rápido');
        console.log('  npm run visual:test           # Executar todos os testes');
        console.log('  npm run visual:routes         # Executar com rotas específicas');
        console.log('  npm run visual:update-baseline # Atualizar baseline');
        console.log('  npm run visual:clean          # Limpar arquivos gerados');
    }

    showExamples() {
        console.log('\n💡 Exemplos de uso:');
        console.log('');
        console.log('# Teste rápido para verificar se está funcionando');
        console.log('npm run visual:quick');
        console.log('');
        console.log('# Executar todos os testes');
        console.log('npm run visual:test');
        console.log('');
        console.log('# Executar apenas rotas específicas');
        console.log('npm run visual:routes');
        console.log('');
        console.log('# Atualizar baseline após mudanças aprovadas');
        console.log('npm run visual:update-baseline');
        console.log('');
        console.log('# Limpar arquivos gerados');
        console.log('npm run visual:clean');
    }
}

// Executar se chamado diretamente
if (require.main === module) {
    const demo = new VisualTestDemo();
    demo.run().catch(console.error);
}

module.exports = VisualTestDemo;
