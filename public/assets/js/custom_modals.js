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

function normalizeAlertText(text) {
    return (text || '')
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();
}

function detectAlertSeverity(titulo, mensagem) {
    const t = normalizeAlertText(titulo);
    const m = normalizeAlertText(mensagem);

    const successRegex = /(sucesso|sucess|atualizado com sucesso|salvo com sucesso|copiado com sucesso|concluido com sucesso|realizado com sucesso)/;
    const errorRegex = /(erro|falha|nao foi possivel|invalido|excecao|problema|negado|expirad)/;

    const titleLooksSuccess = successRegex.test(t);
    const messageLooksSuccess = successRegex.test(m);
    const titleLooksError = errorRegex.test(t);
    const messageLooksError = errorRegex.test(m);

    if ((titleLooksSuccess || messageLooksSuccess) && !messageLooksError) {
        return 'success';
    }
    if (titleLooksError || messageLooksError) {
        return 'error';
    }
    return 'warning';
}

// Ícone do título (Sucesso = check verde, Erro = X vermelho, demais = Aviso)
function getAlertHeaderIcon(severity) {
    if (severity === 'success') return '<span class="custom-alert-header-icon custom-alert-icon-success">✓</span>';
    if (severity === 'error') return '<span class="custom-alert-header-icon custom-alert-icon-error">✕</span>';
    return '<span class="custom-alert-header-icon custom-alert-icon-warning">!</span>';
}

// Modal customizado de alerta
function customAlert(mensagem, titulo = 'Aviso') {
    return new Promise((resolve) => {
        // Remover qualquer modal anterior
        const existing = document.querySelector('.custom-alert-overlay');
        if (existing) {
            existing.remove();
        }
        const severity = detectAlertSeverity(titulo, mensagem);
        const icon = getAlertHeaderIcon(severity);
        let safeTitle = (titulo || 'Aviso').toString();
        if (severity === 'success' && normalizeAlertText(safeTitle) === 'erro') {
            safeTitle = 'Sucesso';
        }
        if (severity === 'error' && normalizeAlertText(safeTitle) === 'sucesso') {
            safeTitle = 'Erro';
        }
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header is-${severity}">${icon} ${escapeHtml(safeTitle)}</div>
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

// Modal customizado de prompt (input)
function customPrompt(mensagem, valorPadrao = '', titulo = 'Informação') {
    return new Promise((resolve) => {
        // Remover qualquer modal anterior
        const existing = document.querySelector('.custom-prompt-overlay');
        if (existing) {
            existing.remove();
        }
        
        const overlay = document.createElement('div');
        overlay.className = 'custom-prompt-overlay';
        const inputId = 'custom-prompt-input-' + Date.now();
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                <div class="custom-alert-body">
                    <div style="margin-bottom: 1rem;">${escapeHtml(mensagem)}</div>
                    <input type="text" id="${inputId}" class="custom-prompt-input" value="${escapeHtml(valorPadrao)}" placeholder="Digite aqui..." style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem;">
                </div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-secondary" onclick="if (window.resolveCustomPrompt) { window.resolveCustomPrompt(null); }">Cancelar</button>
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="
                        const input = document.getElementById('${inputId}');
                        if (window.resolveCustomPrompt) { window.resolveCustomPrompt(input ? input.value : null); }
                    ">OK</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
                if (window.resolveCustomPrompt) {
                    window.resolveCustomPrompt(null);
                }
            }
        });
        
        window.resolveCustomPrompt = (resultado) => {
            overlay.remove();
            delete window.resolveCustomPrompt;
            resolve(resultado);
        };
        
        // Foco no input
        setTimeout(() => {
            const input = document.getElementById(inputId);
            if (input) {
                input.focus();
                input.select();
                
                // Permitir Enter para confirmar
                input.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (window.resolveCustomPrompt) {
                            window.resolveCustomPrompt(input.value);
                        }
                    }
                });
                
                // Permitir Escape para cancelar
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        if (window.resolveCustomPrompt) {
                            window.resolveCustomPrompt(null);
                        }
                    }
                });
            }
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
        // confirm() precisa permanecer síncrono para não quebrar formulários antigos.
        return window.originalConfirm(mensagem);
    };
}

if (typeof window.originalPrompt === 'undefined') {
    window.originalPrompt = window.prompt;
    window.prompt = function(mensagem, valorPadrao) {
        // prompt() também precisa permanecer síncrono para compatibilidade.
        return window.originalPrompt(mensagem, valorPadrao || '');
    };
}
