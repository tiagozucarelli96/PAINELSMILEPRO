/**
 * Custom Modals - Sistema de alertas e confirmações customizados
 * Substitui os alert() e confirm() nativos do navegador
 */

// Função auxiliar para escape HTML
function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Modal customizado de alerta
function customAlert(mensagem, titulo = 'Aviso') {
    return new Promise((resolve) => {
        // Remover qualquer modal anterior
        const existing = document.querySelector('.custom-alert-overlay');
        if (existing) {
            existing.remove();
        }
        
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                <div class="custom-alert-body">${escapeHtml(mensagem)}</div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="this.closest('.custom-alert-overlay').remove(); if (window.resolveCustomAlert) { window.resolveCustomAlert(); }">OK</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
                if (window.resolveCustomAlert) {
                    window.resolveCustomAlert();
                }
            }
        });
        
        window.resolveCustomAlert = () => {
            overlay.remove();
            delete window.resolveCustomAlert;
            resolve();
        };
        
        // Foco no botão OK
        setTimeout(() => {
            const btn = overlay.querySelector('.custom-alert-btn-primary');
            if (btn) btn.focus();
        }, 100);
    });
}

// Modal customizado de confirmação
function customConfirm(mensagem, titulo = 'Confirmar') {
    return new Promise((resolve) => {
        // Remover qualquer modal anterior
        const existing = document.querySelector('.custom-alert-overlay');
        if (existing) {
            existing.remove();
        }
        
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                <div class="custom-alert-body">${escapeHtml(mensagem)}</div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-secondary" onclick="if (window.resolveCustomConfirm) { window.resolveCustomConfirm(false); }">Cancelar</button>
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="if (window.resolveCustomConfirm) { window.resolveCustomConfirm(true); }">Confirmar</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
                if (window.resolveCustomConfirm) {
                    window.resolveCustomConfirm(false);
                }
            }
        });
        
        window.resolveCustomConfirm = (resultado) => {
            overlay.remove();
            delete window.resolveCustomConfirm;
            resolve(resultado);
        };
        
        // Foco no botão Cancelar (mais seguro)
        setTimeout(() => {
            const btn = overlay.querySelector('.custom-alert-btn-secondary');
            if (btn) btn.focus();
        }, 100);
    });
}

// Sobrescrever alert() e confirm() globais (opcional, pode ser removido se não desejar)
if (typeof window.originalAlert === 'undefined') {
    window.originalAlert = window.alert;
    window.alert = function(mensagem) {
        return customAlert(mensagem, 'Aviso');
    };
}

if (typeof window.originalConfirm === 'undefined') {
    window.originalConfirm = window.confirm;
    window.confirm = function(mensagem) {
        // Não podemos substituir completamente confirm() porque ele é síncrono
        // Mas podemos fazer um log para informar o desenvolvedor
        console.warn('Use customConfirm() em vez de confirm() para melhor UX');
        return customConfirm(mensagem, 'Confirmar');
    };
}

