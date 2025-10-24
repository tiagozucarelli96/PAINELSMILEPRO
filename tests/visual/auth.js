// auth.js — Sistema de autenticação para testes visuais
const { chromium } = require('playwright');

class AuthManager {
    constructor(config) {
        this.config = config;
        this.sessionCookies = null;
        this.isAuthenticated = false;
    }

    async authenticate() {
        if (!this.config.auth.enabled) {
            console.log('🔓 Autenticação desabilitada');
            return true;
        }

        if (this.isAuthenticated && this.sessionCookies) {
            console.log('🔑 Usando sessão existente');
            return true;
        }

        console.log('🔐 Autenticando...');
        
        try {
            const browser = await chromium.launch({ 
                headless: true,
                args: this.config.browser.args
            });
            
            const context = await browser.newContext();
            const page = await context.newPage();
            
            // Navegar para página de login
            const loginUrl = `${this.config.baseUrl}${this.config.auth.loginUrl}`;
            await page.goto(loginUrl, { waitUntil: 'networkidle' });
            
            // Preencher formulário de login
            await page.fill('input[name="username"], input[name="email"], input[name="login"]', this.config.auth.username);
            await page.fill('input[name="password"], input[name="senha"]', this.config.auth.password);
            
            // Submeter formulário
            await page.click('button[type="submit"], input[type="submit"], .btn-login');
            
            // Aguardar redirecionamento
            await page.waitForLoadState('networkidle');
            
            // Verificar se login foi bem-sucedido
            const currentUrl = page.url();
            const isLoggedIn = !currentUrl.includes('login') && !currentUrl.includes('auth');
            
            if (isLoggedIn) {
                // Salvar cookies de sessão
                this.sessionCookies = await context.cookies();
                this.isAuthenticated = true;
                console.log('✅ Autenticação bem-sucedida');
            } else {
                console.log('❌ Falha na autenticação');
                return false;
            }
            
            await browser.close();
            return true;
            
        } catch (error) {
            console.log(`❌ Erro na autenticação: ${error.message}`);
            return false;
        }
    }

    async createAuthenticatedContext(browser) {
        if (!this.config.auth.enabled) {
            return await browser.newContext();
        }

        if (!this.isAuthenticated) {
            const success = await this.authenticate();
            if (!success) {
                throw new Error('Falha na autenticação');
            }
        }

        const context = await browser.newContext();
        
        if (this.sessionCookies) {
            await context.addCookies(this.sessionCookies);
        }
        
        return context;
    }

    async testAuthentication() {
        if (!this.config.auth.enabled) {
            return true;
        }

        try {
            const browser = await chromium.launch({ 
                headless: true,
                args: this.config.browser.args
            });
            
            const context = await this.createAuthenticatedContext(browser);
            const page = await context.newPage();
            
            // Testar acesso a página protegida
            const testUrl = `${this.config.baseUrl}/public/dashboard.php`;
            await page.goto(testUrl, { waitUntil: 'networkidle' });
            
            const currentUrl = page.url();
            const isAuthenticated = !currentUrl.includes('login') && !currentUrl.includes('auth');
            
            await browser.close();
            
            if (isAuthenticated) {
                console.log('✅ Sessão válida');
                return true;
            } else {
                console.log('❌ Sessão inválida');
                return false;
            }
            
        } catch (error) {
            console.log(`❌ Erro ao testar autenticação: ${error.message}`);
            return false;
        }
    }

    async refreshSession() {
        if (!this.config.auth.enabled) {
            return true;
        }

        console.log('🔄 Renovando sessão...');
        this.isAuthenticated = false;
        this.sessionCookies = null;
        
        return await this.authenticate();
    }

    getSessionInfo() {
        return {
            enabled: this.config.auth.enabled,
            authenticated: this.isAuthenticated,
            hasCookies: !!this.sessionCookies,
            cookieCount: this.sessionCookies ? this.sessionCookies.length : 0
        };
    }
}

module.exports = AuthManager;
