// utils.js — Utilitários para validação visual
const fs = require('fs-extra');
const path = require('path');
const { PNG } = require('pngjs');
const pixelmatch = require('pixelmatch');

class VisualTestUtils {
    constructor(config) {
        this.config = config;
    }

    // Criar diretórios necessários
    async ensureDirectories() {
        const dirs = [
            this.config.directories.screens,
            this.config.directories.baseline,
            this.config.directories.diff,
            this.config.directories.logs,
            this.config.directories.reports
        ];

        for (const dir of dirs) {
            await fs.ensureDir(dir);
            
            // Criar subdiretórios para viewports
            for (const viewport of Object.keys(this.config.viewports)) {
                await fs.ensureDir(path.join(dir, viewport));
            }
        }
    }

    // Gerar slug para arquivo
    generateSlug(route) {
        return route
            .replace('.php', '')
            .replace(/[^a-zA-Z0-9]/g, '_')
            .toLowerCase();
    }

    // Verificar se arquivo deve ser excluído
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

    // Comparar screenshots
    async compareScreenshots(currentPath, baselinePath, diffPath) {
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
                this.config.comparison.pixelmatch
            );
            
            const diffPercentage = (diffPixels / (width * height)) * 100;
            
            if (diffPercentage > this.config.comparison.diffThreshold) {
                // Salvar diff
                await fs.ensureDir(path.dirname(diffPath));
                await fs.writeFile(diffPath, PNG.sync.write(diff));
                
                return {
                    hasDiff: true,
                    diffPercentage,
                    diffPixels,
                    diffPath
                };
            }
            
            return {
                hasDiff: false,
                diffPercentage,
                diffPixels
            };
            
        } catch (error) {
            throw new Error(`Erro ao comparar screenshots: ${error.message}`);
        }
    }

    // Salvar log da página
    async savePageLog(route, viewport, data) {
        const slug = this.generateSlug(route);
        const logPath = path.join(
            this.config.directories.logs,
            viewport,
            `${slug}.log`
        );
        
        await fs.ensureDir(path.dirname(logPath));
        await fs.writeFile(logPath, JSON.stringify(data, null, 2));
    }

    // Salvar screenshot
    async saveScreenshot(page, route, viewport) {
        const slug = this.generateSlug(route);
        const screenshotPath = path.join(
            this.config.directories.screens,
            viewport,
            `${slug}.png`
        );
        
        await fs.ensureDir(path.dirname(screenshotPath));
        await page.screenshot({ 
            path: screenshotPath,
            fullPage: this.config.screenshot.fullPage,
            animations: this.config.screenshot.animations
        });
        
        return screenshotPath;
    }

    // Copiar para baseline
    async copyToBaseline(screenshotPath, route, viewport) {
        const slug = this.generateSlug(route);
        const baselinePath = path.join(
            this.config.directories.baseline,
            viewport,
            `${slug}.png`
        );
        
        await fs.ensureDir(path.dirname(baselinePath));
        await fs.copy(screenshotPath, baselinePath);
        
        return baselinePath;
    }

    // Verificar se baseline existe
    async baselineExists(route, viewport) {
        const slug = this.generateSlug(route);
        const baselinePath = path.join(
            this.config.directories.baseline,
            viewport,
            `${slug}.png`
        );
        
        return await fs.pathExists(baselinePath);
    }

    // Obter caminho do baseline
    getBaselinePath(route, viewport) {
        const slug = this.generateSlug(route);
        return path.join(
            this.config.directories.baseline,
            viewport,
            `${slug}.png`
        );
    }

    // Obter caminho do diff
    getDiffPath(route, viewport) {
        const slug = this.generateSlug(route);
        return path.join(
            this.config.directories.diff,
            viewport,
            `${slug}.png`
        );
    }

    // Limpar diretórios
    async cleanDirectories() {
        const dirs = [
            this.config.directories.screens,
            this.config.directories.diff
        ];

        for (const dir of dirs) {
            if (await fs.pathExists(dir)) {
                await fs.remove(dir);
            }
        }

        await this.ensureDirectories();
    }

    // Obter estatísticas de arquivos
    async getFileStats(directory) {
        if (!await fs.pathExists(directory)) {
            return { count: 0, size: 0 };
        }

        const files = await fs.readdir(directory, { withFileTypes: true });
        let count = 0;
        let size = 0;

        for (const file of files) {
            if (file.isFile()) {
                count++;
                const filePath = path.join(directory, file.name);
                const stats = await fs.stat(filePath);
                size += stats.size;
            }
        }

        return { count, size };
    }

    // Formatar tamanho de arquivo
    formatFileSize(bytes) {
        const sizes = ['B', 'KB', 'MB', 'GB'];
        if (bytes === 0) return '0 B';
        
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
    }

    // Formatar tempo
    formatTime(ms) {
        if (ms < 1000) return `${ms}ms`;
        if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
        return `${(ms / 60000).toFixed(1)}m`;
    }

    // Validar URL
    isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }

    // Aguardar com timeout
    async waitWithTimeout(promise, timeout) {
        return Promise.race([
            promise,
            new Promise((_, reject) => 
                setTimeout(() => reject(new Error('Timeout')), timeout)
            )
        ]);
    }

    // Retry com backoff
    async retryWithBackoff(fn, maxRetries = 3, baseDelay = 1000) {
        for (let i = 0; i < maxRetries; i++) {
            try {
                return await fn();
            } catch (error) {
                if (i === maxRetries - 1) throw error;
                
                const delay = baseDelay * Math.pow(2, i);
                console.log(`⏳ Tentativa ${i + 1} falhou, aguardando ${delay}ms...`);
                await new Promise(resolve => setTimeout(resolve, delay));
            }
        }
    }
}

module.exports = VisualTestUtils;
