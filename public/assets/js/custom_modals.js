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
function customConfirm(mensagem, titulo = 'Confirmar', options = {}) {
    return new Promise((resolve) => {
        // Remover qualquer modal anterior
        const existing = document.querySelector('.custom-alert-overlay');
        if (existing) {
            existing.remove();
        }

        const confirmLabel = (options && options.confirmLabel ? String(options.confirmLabel) : 'Confirmar');
        const cancelLabel = (options && options.cancelLabel ? String(options.cancelLabel) : 'Cancelar');
        
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                <div class="custom-alert-body">${escapeHtml(mensagem)}</div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-secondary" onclick="if (window.resolveCustomConfirm) { window.resolveCustomConfirm(false); }">${escapeHtml(cancelLabel)}</button>
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="if (window.resolveCustomConfirm) { window.resolveCustomConfirm(true); }">${escapeHtml(confirmLabel)}</button>
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
function customPrompt(mensagem, valorPadrao = '', titulo = 'Informação', options = {}) {
    return new Promise((resolve) => {
        // Remover qualquer modal anterior
        const existing = document.querySelector('.custom-prompt-overlay');
        if (existing) {
            existing.remove();
        }

        const inputType = (options && options.inputType ? String(options.inputType) : 'text');
        const placeholder = (options && options.placeholder ? String(options.placeholder) : 'Digite aqui...');
        const confirmLabel = (options && options.confirmLabel ? String(options.confirmLabel) : 'OK');
        const cancelLabel = (options && options.cancelLabel ? String(options.cancelLabel) : 'Cancelar');
        
        const overlay = document.createElement('div');
        overlay.className = 'custom-prompt-overlay';
        const inputId = 'custom-prompt-input-' + Date.now();
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                <div class="custom-alert-body">
                    <div style="margin-bottom: 1rem;">${escapeHtml(mensagem)}</div>
                    <input type="${escapeHtml(inputType)}" id="${inputId}" class="custom-prompt-input" value="${escapeHtml(valorPadrao)}" placeholder="${escapeHtml(placeholder)}" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem;">
                </div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-secondary" onclick="if (window.resolveCustomPrompt) { window.resolveCustomPrompt(null); }">${escapeHtml(cancelLabel)}</button>
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="
                        const input = document.getElementById('${inputId}');
                        if (window.resolveCustomPrompt) { window.resolveCustomPrompt(input ? input.value : null); }
                    ">${escapeHtml(confirmLabel)}</button>
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

// Detecta se a mensagem de prompt sugere senha
function isPasswordPromptMessage(mensagem) {
    const text = normalizeAlertText(mensagem);
    return /(senha|password|passcode|credencial)/.test(text);
}

function getEventTargetFromContext(ctxEvent) {
    if (!ctxEvent) return null;
    let target = ctxEvent.currentTarget || ctxEvent.target || null;
    if (target && target.nodeType !== 1 && target.parentElement) {
        target = target.parentElement;
    }
    return target || null;
}

function pickReplayTarget(target, eventType) {
    if (!target || !target.closest) return target;
    if (eventType === 'submit') {
        if (target.tagName === 'FORM') return target;
        const form = target.closest('form');
        return form || target;
    }
    const clickable = target.closest('button, a, input[type="button"], input[type="submit"], [role="button"]');
    return clickable || target;
}

function replayInteraction(target, eventType) {
    const safeType = String(eventType || '').toLowerCase();
    if (!target) return;

    if (safeType === 'submit') {
        const form = target.tagName === 'FORM' ? target : (target.closest ? target.closest('form') : null);
        if (!form) return;
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else if (typeof form.submit === 'function') {
            form.submit();
        }
        return;
    }

    if (typeof target.click === 'function') {
        target.click();
        return;
    }

    if (target.dispatchEvent) {
        target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }
}

const customDialogState = {
    lastInteraction: null,
    confirmBypass: new WeakMap(),
    promptReplay: new WeakMap(),
};

function rememberInteraction(event) {
    customDialogState.lastInteraction = {
        event: event || null,
        type: String((event && event.type) || ''),
        target: getEventTargetFromContext(event),
        ts: Date.now(),
    };
}

function getInteractionContext() {
    const now = Date.now();
    const windowEvent = (typeof window !== 'undefined' && window.event) ? window.event : null;
    if (windowEvent && typeof windowEvent === 'object') {
        return {
            event: windowEvent,
            type: String(windowEvent.type || ''),
            target: getEventTargetFromContext(windowEvent),
            ts: now,
        };
    }
    const last = customDialogState.lastInteraction;
    if (last && (now - Number(last.ts || 0) <= 2000) && last.target) {
        return last;
    }
    return null;
}

function consumeConfirmBypass(target, message) {
    if (!target) return false;
    const current = customDialogState.confirmBypass.get(target);
    if (!current) return false;
    customDialogState.confirmBypass.delete(target);
    return current.message === String(message || '') && Number(current.expires || 0) >= Date.now();
}

function setConfirmBypass(target, message) {
    if (!target) return;
    customDialogState.confirmBypass.set(target, {
        message: String(message || ''),
        expires: Date.now() + 5000,
    });
}

function consumePromptReplay(target, message) {
    if (!target) return { has: false, value: null };
    const current = customDialogState.promptReplay.get(target);
    if (!current) return { has: false, value: null };
    customDialogState.promptReplay.delete(target);
    if (current.message !== String(message || '') || Number(current.expires || 0) < Date.now()) {
        return { has: false, value: null };
    }
    return { has: true, value: current.value };
}

function setPromptReplay(target, message, value) {
    if (!target) return;
    customDialogState.promptReplay.set(target, {
        message: String(message || ''),
        value: value,
        expires: Date.now() + 5000,
    });
}

if (typeof document !== 'undefined') {
    ['click', 'submit', 'keydown'].forEach((eventType) => {
        document.addEventListener(eventType, rememberInteraction, true);
    });
}

// Sobrescrever alert(), confirm() e prompt() globais para usar padrão visual do sistema
if (typeof window.originalAlert === 'undefined') {
    window.originalAlert = window.alert;
    window.alert = function(mensagem) {
        return customAlert(mensagem, 'Aviso');
    };
}

if (typeof window.originalConfirm === 'undefined') {
    window.originalConfirm = window.confirm;
    window.confirm = function(mensagem) {
        const message = String(mensagem || '');
        const ctx = getInteractionContext();
        let target = ctx ? pickReplayTarget(ctx.target, ctx.type) : null;
        if (!target && typeof document !== 'undefined' && document.activeElement) {
            target = pickReplayTarget(document.activeElement, 'click');
        }

        if (target && consumeConfirmBypass(target, message)) {
            return true;
        }

        if (ctx && ctx.event && typeof ctx.event.preventDefault === 'function') {
            ctx.event.preventDefault();
            if (typeof ctx.event.stopPropagation === 'function') {
                ctx.event.stopPropagation();
            }
            if (typeof ctx.event.stopImmediatePropagation === 'function') {
                ctx.event.stopImmediatePropagation();
            }
        }

        customConfirm(message, 'Confirmar').then((confirmed) => {
            if (!confirmed || !target) return;
            setConfirmBypass(target, message);
            replayInteraction(target, ctx ? ctx.type : '');
        });

        return false;
    };
}

if (typeof window.originalPrompt === 'undefined') {
    window.originalPrompt = window.prompt;
    window.prompt = function(mensagem, valorPadrao) {
        const message = String(mensagem || '');
        const defaultValue = valorPadrao === undefined || valorPadrao === null ? '' : String(valorPadrao);
        const ctx = getInteractionContext();
        let target = ctx ? pickReplayTarget(ctx.target, ctx.type) : null;
        if (!target && typeof document !== 'undefined' && document.activeElement) {
            target = pickReplayTarget(document.activeElement, 'click');
        }

        if (target) {
            const replay = consumePromptReplay(target, message);
            if (replay.has) {
                return replay.value;
            }
        }

        if (ctx && ctx.event && typeof ctx.event.preventDefault === 'function') {
            ctx.event.preventDefault();
            if (typeof ctx.event.stopPropagation === 'function') {
                ctx.event.stopPropagation();
            }
            if (typeof ctx.event.stopImmediatePropagation === 'function') {
                ctx.event.stopImmediatePropagation();
            }
        }

        const promptOptions = isPasswordPromptMessage(message)
            ? { inputType: 'password', placeholder: 'Digite sua senha' }
            : { inputType: 'text' };

        customPrompt(message, defaultValue, 'Informação', promptOptions).then((value) => {
            if (value === null || !target) return;
            setPromptReplay(target, message, value);
            replayInteraction(target, ctx ? ctx.type : '');
        });

        return null;
    };
}
