// example-config.js — Exemplo de configuração personalizada
module.exports = {
    // URL base do sistema
    baseUrl: 'http://localhost:8080', // Exemplo com porta específica
    
    // Viewports personalizados
    viewports: {
        desktop: { width: 1920, height: 1080 }, // Full HD
        laptop: { width: 1366, height: 768 },   // Laptop comum
        tablet: { width: 768, height: 1024 },   // iPad
        mobile: { width: 375, height: 667 }     // iPhone
    },
    
    // Configurações do navegador
    browser: {
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--no-first-run',
            '--no-zygote',
            '--single-process',
            '--disable-web-security', // Para testes locais
            '--disable-features=VizDisplayCompositor'
        ]
    },
    
    // Timeouts personalizados
    timeouts: {
        navigation: 60000,    // 1 minuto
        networkIdle: 2000,    // 2 segundos
        screenshot: 1000      // 1 segundo
    },
    
    // Configurações de screenshot
    screenshot: {
        fullPage: true,
        animations: 'disabled',
        quality: 100,         // Máxima qualidade
        type: 'png'           // Formato PNG
    },
    
    // Configurações de comparação
    comparison: {
        threshold: 0.05,      // Mais sensível
        diffThreshold: 0.5,    // 0.5% de diferença para falhar
        pixelmatch: {
            threshold: 0.05,
            alpha: 0.1,
            aa: false,
            diffColor: [255, 0, 0],      // Vermelho para diferenças
            diffColorAlt: [0, 255, 0]    // Verde para diferenças alternativas
        }
    },
    
    // Diretórios personalizados
    directories: {
        screens: 'tests/visual/screenshots',
        baseline: 'tests/visual/baseline',
        diff: 'tests/visual/differences',
        logs: 'tests/visual/logs',
        reports: 'tests/visual/reports'
    },
    
    // Arquivos de configuração
    files: {
        routes: 'tests/visual/custom-routes.txt',
        report: 'tests/visual/custom-report.html',
        log: 'tests/visual/custom-log.json'
    },
    
    // Configurações de autenticação
    auth: {
        enabled: true,
        loginUrl: '/login.php',
        username: 'admin',
        password: 'admin123',
        sessionCookie: 'PHPSESSID',
        loginForm: {
            usernameField: 'input[name="username"]',
            passwordField: 'input[name="password"]',
            submitButton: 'button[type="submit"]'
        }
    },
    
    // Configurações de rede
    network: {
        waitForIdle: true,
        idleTime: 2000,
        maxRequests: 200,
        blockResources: ['image', 'font', 'media'], // Bloquear recursos pesados
        interceptRequests: true
    },
    
    // Configurações de erro
    errorHandling: {
        maxConsoleErrors: 20,
        maxNetworkErrors: 20,
        maxPageErrors: 10,
        ignoreErrors: [
            'ResizeObserver loop limit exceeded',
            'Non-Error promise rejection captured'
        ]
    },
    
    // Configurações de relatório
    report: {
        includeScreenshots: true,
        includeDiffs: true,
        includeLogs: true,
        includeMetrics: true,
        generateHtml: true,
        generateJson: true,
        generatePdf: false,
        includePerformance: true
    },
    
    // Configurações de performance
    performance: {
        collectMetrics: true,
        maxLoadTime: 15000,    // 15 segundos
        slowThreshold: 5000,   // 5 segundos
        collectMemory: true,
        collectCpu: true
    },
    
    // Configurações de segurança
    security: {
        hideSensitiveData: true,
        maskSelectors: [
            '.password',
            '.token',
            '.api-key',
            '.secret',
            '.senha',
            '.chave'
        ],
        allowedMethods: ['GET'],
        blockedMethods: ['POST', 'PUT', 'DELETE', 'PATCH'],
        sanitizeUrls: true
    },
    
    // Configurações de debug
    debug: {
        verbose: true,
        saveConsoleLogs: true,
        saveNetworkLogs: true,
        savePageLogs: true,
        saveScreenshots: true,
        saveVideos: false
    },
    
    // Configurações de retry
    retry: {
        maxRetries: 3,
        baseDelay: 1000,
        maxDelay: 10000,
        retryOnFailure: true
    },
    
    // Configurações de paralelização
    parallel: {
        enabled: true,
        maxConcurrent: 3,
        timeout: 300000 // 5 minutos
    }
};
