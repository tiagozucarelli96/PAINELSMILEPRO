#!/usr/bin/env node

// demo.js — Demonstração do crawler de links
const fs = require('fs-extra');
const path = require('path');

class LinksCrawlerDemo {
    constructor() {
        this.baseUrl = process.env.BASE_URL || 'http://localhost';
    }

    async run() {
        console.log('🕷️ Demonstração do Crawler de Links Internos');
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
        console.log('  npm run links:install  # Instalar dependências');
        console.log('  npm run links:crawl   # Executar crawler');
    }

    async checkFileStructure() {
        console.log('\n📁 Verificando estrutura de arquivos...');
        
        const files = [
            'package.json',
            'crawler.js',
            'demo.js',
            'README.md'
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
        console.log('  Profundidade máxima: 3 níveis');
        console.log('  Rate limit: 3 req/s');
        console.log('  Timeout: 10s por requisição');
        console.log('  Máximo de tentativas: 2');
        console.log('  Domínio: Apenas /public/ (interno)');
    }

    showCommands() {
        console.log('\n📋 Comandos disponíveis:');
        console.log('  npm run links:install  # Instalar dependências');
        console.log('  npm run links:crawl    # Executar crawler completo');
        console.log('  npm run links:demo     # Esta demonstração');
        console.log('  npm run links:clean    # Limpar arquivos gerados');
    }

    showExamples() {
        console.log('\n💡 Exemplos de uso:');
        console.log('');
        console.log('# Crawler básico');
        console.log('npm run links:crawl');
        console.log('');
        console.log('# Crawler com URL inicial personalizada');
        console.log('npm run links:crawl -- --start=/public/usuarios.php');
        console.log('');
        console.log('# Crawler com profundidade personalizada');
        console.log('npm run links:crawl -- --max-depth=2');
        console.log('');
        console.log('# Crawler ignorando padrões específicos');
        console.log('npm run links:crawl -- --skip="logout|download"');
        console.log('');
        console.log('# Crawler com autenticação');
        console.log('export TEST_USERNAME=admin');
        console.log('export TEST_PASSWORD=admin123');
        console.log('npm run links:crawl');
    }

    showFeatures() {
        console.log('\n🎯 Funcionalidades:');
        console.log('✅ Descoberta automática de links internos');
        console.log('✅ Verificação de status HTTP (200, 404, 500)');
        console.log('✅ Medição de tempo de resposta (TTFB)');
        console.log('✅ Verificação de assets (CSS, JS, IMG)');
        console.log('✅ Detecção de redirecionamentos em loop');
        console.log('✅ Autenticação automática');
        console.log('✅ Rate limiting (3 req/s)');
        console.log('✅ Relatórios HTML e JSON');
        console.log('✅ Filtros e busca no relatório');
        console.log('✅ Código de saída baseado em erros');
    }

    showOutputs() {
        console.log('\n📊 Saídas geradas:');
        console.log('📄 report.html - Relatório HTML interativo');
        console.log('📋 report.json - Dados detalhados em JSON');
        console.log('📈 Estatísticas: páginas, links, assets, erros');
        console.log('🔍 Filtros: por status, profundidade, busca');
        console.log('📱 Responsivo: funciona em mobile e desktop');
    }
}

// Executar se chamado diretamente
if (require.main === module) {
    const demo = new LinksCrawlerDemo();
    demo.run().catch(console.error);
}

module.exports = LinksCrawlerDemo;
