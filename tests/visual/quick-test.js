#!/usr/bin/env node

// quick-test.js — Teste rápido para verificar se o sistema está funcionando
const { chromium } = require('playwright');
const fs = require('fs-extra');
const path = require('path');

class QuickTest {
    constructor() {
        this.baseUrl = process.env.BASE_URL || 'http://localhost';
        this.testRoutes = [
            'index.php',
            'dashboard.php',
            'sistema_unificado.php',
            'verificacao_geral.php'
        ];
    }

    async run() {
        console.log('🧪 Executando teste rápido...');
        
        try {
            const browser = await chromium.launch({ 
                headless: true,
                args: ['--no-sandbox', '--disable-setuid-sandbox']
            });
            
            const context = await browser.newContext();
            const page = await context.newPage();
            
            let passed = 0;
            let failed = 0;
            
            for (const route of this.testRoutes) {
                try {
                    console.log(`🔍 Testando: ${route}`);
                    
                    const url = `${this.baseUrl}/public/${route}`;
                    await page.goto(url, { 
                        waitUntil: 'networkidle',
                        timeout: 10000 
                    });
                    
                    // Verificar se a página carregou corretamente
                    const title = await page.title();
                    const hasContent = await page.evaluate(() => document.body.textContent.length > 0);
                    
                    if (title && hasContent) {
                        console.log(`✅ ${route} - OK`);
                        passed++;
                    } else {
                        console.log(`❌ ${route} - Sem conteúdo`);
                        failed++;
                    }
                    
                } catch (error) {
                    console.log(`❌ ${route} - ERRO: ${error.message}`);
                    failed++;
                }
            }
            
            await browser.close();
            
            console.log(`\n📊 Resultados: ${passed} ✅ | ${failed} ❌`);
            
            if (failed === 0) {
                console.log('🎉 Teste rápido passou! O sistema está funcionando.');
                process.exit(0);
            } else {
                console.log('⚠️ Alguns testes falharam. Verifique a configuração.');
                process.exit(1);
            }
            
        } catch (error) {
            console.error(`❌ Erro fatal: ${error.message}`);
            process.exit(1);
        }
    }
}

// Executar se chamado diretamente
if (require.main === module) {
    const test = new QuickTest();
    test.run().catch(console.error);
}

module.exports = QuickTest;
