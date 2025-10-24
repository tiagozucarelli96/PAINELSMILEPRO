// config.js — Configurações para validação visual
module.exports = {
    // URL base do sistema
    baseUrl: process.env.BASE_URL || 'http://localhost',
    
    // Viewports para teste
    viewports: {
        desktop: { width: 1440, height: 900 },
        tablet: { width: 1024, height: 768 },
        mobile: { width: 390, height: 844 }
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
            '--single-process'
        ]
    },
    
    // Timeouts
    timeouts: {
        navigation: 30000,
        networkIdle: 1000,
        screenshot: 500
    },
    
    // Configurações de screenshot
    screenshot: {
        fullPage: true,
        animations: 'disabled',
        quality: 90
    },
    
    // Configurações de comparação
    comparison: {
        threshold: 0.1,
        diffThreshold: 1.0, // Porcentagem de diferença para falhar
        pixelmatch: {
            threshold: 0.1,
            alpha: 0.1,
            aa: false,
            diffColor: [255, 0, 0],
            diffColorAlt: [0, 255, 0]
        }
    },
    
    // Diretórios
    directories: {
        screens: 'tests/visual/screens',
        baseline: 'tests/visual/baseline',
        diff: 'tests/visual/diff',
        logs: 'tests/visual/logs',
        reports: 'tests/visual'
    },
    
    // Arquivos de configuração
    files: {
        routes: 'tests/visual/routes.txt',
        report: 'tests/visual/report.html',
        log: 'tests/visual/log.json'
    },
    
    // Configurações de autenticação
    auth: {
        enabled: false,
        loginUrl: '/login.php',
        username: process.env.TEST_USERNAME || 'admin',
        password: process.env.TEST_PASSWORD || 'admin',
        sessionCookie: 'PHPSESSID'
    },
    
    // Configurações de rede
    network: {
        waitForIdle: true,
        idleTime: 1000,
        maxRequests: 100
    },
    
    // Configurações de erro
    errorHandling: {
        maxConsoleErrors: 10,
        maxNetworkErrors: 10,
        maxPageErrors: 5
    },
    
    // Configurações de relatório
    report: {
        includeScreenshots: true,
        includeDiffs: true,
        includeLogs: true,
        includeMetrics: true,
        generateHtml: true,
        generateJson: true
    },
    
    // Configurações de performance
    performance: {
        collectMetrics: true,
        maxLoadTime: 10000, // 10 segundos
        slowThreshold: 3000  // 3 segundos
    },
    
    // Configurações de segurança
    security: {
        hideSensitiveData: true,
        maskSelectors: [
            '.password',
            '.token',
            '.api-key',
            '.secret'
        ],
        allowedMethods: ['GET'],
        blockedMethods: ['POST', 'PUT', 'DELETE', 'PATCH']
    },
    
    // Configurações de debug
    debug: {
        verbose: process.env.DEBUG === 'true',
        saveConsoleLogs: true,
        saveNetworkLogs: true,
        savePageLogs: true
    }
};
