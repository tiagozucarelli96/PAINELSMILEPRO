        // Exibir avisos de sucesso/erro vindos da URL (ex.: redirect com ?success= ou ?error=)
        (function() {
            var params = new URLSearchParams(window.location.search);
            var success = params.get('success');
            var error = params.get('error');
            if (typeof customAlert === 'function') {
                if (success) {
                    customAlert(decodeURIComponent(success), 'Sucesso');
                } else if (error) {
                    var decodedError = decodeURIComponent(error);
                    var looksSuccess = /sucesso|sucess|atualizado|salvo/i.test(decodedError);
                    customAlert(decodedError, looksSuccess ? 'Sucesso' : 'Erro');
                }
            }
        })();

        // Push notifications para qualquer usuário logado (por usuário, sem bloqueio de navegação)
        (function () {
            const sidebarUnifiedConfig = window.sidebarUnifiedConfig || {};
            var userId = Number(sidebarUnifiedConfig.pushUserId || 0);
            if (!userId || !window.pushNotificationsManager) return;

            async function initPushForUser() {
                try {
                    var manager = window.pushNotificationsManager;
                    var state = await manager.init(userId, true);
                    if (state && state.subscribed) {
                        return;
                    }

                    if (!('Notification' in window)) {
                        return;
                    }

                    if (Notification.permission === 'granted') {
                        await manager.registerServiceWorker();
                        await manager.subscribe();
                        return;
                    }

                    // Se ainda não decidiu (default), solicitar novamente para evitar casos
                    // em que a primeira tentativa não foi exibida ao usuário.
                    if (Notification.permission === 'default') {
                        await manager.requestPermission();
                        if (Notification.permission === 'granted') {
                            await manager.registerServiceWorker();
                            await manager.subscribe();
                        }
                    }
                } catch (err) {
                    console.warn('Push init falhou:', err && err.message ? err.message : err);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPushForUser);
            } else {
                initPushForUser();
            }
        })();
        
        // Função para alternar sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            
            if (!sidebar || !mainContent) {
                console.error('Sidebar ou mainContent não encontrados');
                return;
            }

            const isMobile = window.matchMedia('(max-width: 768px)').matches;

            // Controle dedicado para mobile: usa classe .open
            if (isMobile) {
                const desiredState = typeof arguments[0] === 'boolean' ? arguments[0] : !sidebar.classList.contains('open');
                if (desiredState) {
                    sidebar.classList.add('open');
                    document.body.classList.add('sidebar-mobile-open');
                    if (sidebarOverlay) {
                        sidebarOverlay.classList.add('open');
                    }
                } else {
                    sidebar.classList.remove('open');
                    document.body.classList.remove('sidebar-mobile-open');
                    if (sidebarOverlay) {
                        sidebarOverlay.classList.remove('open');
                    }
                }
                return;
            }
            
            const isCollapsed = sidebar.classList.contains('collapsed');
            
            if (isCollapsed) {
                // Mostrar sidebar
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
                mainContent.style.marginLeft = '280px';
                mainContent.style.width = 'calc(100% - 280px)';
            } else {
                // Esconder sidebar
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                mainContent.style.marginLeft = '0';
                mainContent.style.width = '100%';
            }

            document.body.classList.remove('sidebar-mobile-open');
            if (sidebarOverlay) {
                sidebarOverlay.classList.remove('open');
            }

            // Forçar recalculo de layouts dependentes de largura (ex: FullCalendar).
            setTimeout(() => window.dispatchEvent(new Event('resize')), 150);
        }

        function syncSidebarByViewport() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            if (!sidebar || !mainContent) return;

            const isMobile = window.matchMedia('(max-width: 768px)').matches;
            if (typeof window.__lastSidebarMobileMode === 'undefined') {
                window.__lastSidebarMobileMode = null;
            }
            if (window.__lastSidebarMobileMode === isMobile && arguments[0] !== true) {
                return;
            }
            window.__lastSidebarMobileMode = isMobile;

            if (isMobile) {
                // Garante estado inicial fechado no mobile
                sidebar.classList.remove('collapsed');
                sidebar.classList.remove('open');
                mainContent.classList.remove('expanded');
                mainContent.style.marginLeft = '0';
                mainContent.style.width = '100%';
                document.body.classList.remove('sidebar-mobile-open');
                if (sidebarOverlay) {
                    sidebarOverlay.classList.remove('open');
                }
                return;
            }

            // Em desktop, restaura comportamento padrão
            sidebar.classList.remove('open');
            document.body.classList.remove('sidebar-mobile-open');
            if (sidebarOverlay) {
                sidebarOverlay.classList.remove('open');
            }
            if (sidebar.classList.contains('collapsed')) {
                mainContent.classList.add('expanded');
                mainContent.style.marginLeft = '0';
                mainContent.style.width = '100%';
            } else {
                mainContent.classList.remove('expanded');
                mainContent.style.marginLeft = '280px';
                mainContent.style.width = 'calc(100% - 280px)';
            }
        }
        
        // Função para voltar
        function goBack() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = 'index.php?page=dashboard';
            }
        }
        
        // Carregar conteúdo da página atual
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarUnifiedConfig = window.sidebarUnifiedConfig || {};
            const currentPage = String(sidebarUnifiedConfig.currentPage || "");
            syncSidebarByViewport();

            // Em mobile, fecha o menu ao tocar em uma opção da sidebar.
            document.querySelectorAll('#sidebar .nav-item').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.matchMedia('(max-width: 768px)').matches) {
                        toggleSidebar(false);
                    }
                });
            });
            
            // Se for dashboard, logistico, configurações, cadastros, financeiro ou administrativo, 
            // o conteúdo já foi renderizado via PHP, não fazer nada
            // Para agenda, o conteúdo também já foi renderizado via PHP após sidebar_unified.php
            // NOTA: Comercial usa comercial_landing.php via index.php, não precisa renderizar aqui
            // NOTA: Páginas que usam includeSidebar/endSidebar já renderizam o conteúdo, não carregar via AJAX
            const pagesWithOwnRender = [
                'dashboard', 'logistico', 'configuracoes', 'cadastros', 'financeiro', 'administrativo', 
                'agenda', 'agenda_eventos', 'demandas', 'demandas_quadro',
                'comercial', 'comercial_degust_inscritos', 'comercial_degust_inscricoes', 'comercial_degustacao_editar',
                'comercial_degust_public', 'comercial_pagamento', 'comercial_realizar_degustacao',
                'pagamento_degustacao', 'testar_identificacao_pagamento',
                // Vendas (páginas com JS próprio — não carregar via AJAX)
                'vendas_pre_contratos', 'vendas_administracao', 'vendas_lancamento_presencial',
                'vendas_kanban', 'vendas_links_publicos',
                // Eventos (formulários reutilizáveis)
                'formularios_eventos',
                // Eventos (módulo com includeSidebar — não carregar via AJAX)
                'eventos', 'eventos_reuniao_final', 'eventos_rascunhos', 'eventos_calendario', 'eventos_galeria', 'eventos_fornecedores',
                // Minha conta e gestão de documentos (includeSidebar)
                'pessoal', 'pessoal_modulo', 'minha_conta', 'contabilidade_holerite_individual', 'administrativo_gestao_documentos', 'administrativo_juridico', 'administrativo_avisos',
                // Contabilidade (páginas com modal/JS próprio)
                'contabilidade_admin_guias'
            ];

            const isLogistica = currentPage === 'logistica' || currentPage.startsWith('logistica_');
            const isEventos = currentPage === 'eventos' || currentPage.startsWith('eventos_');

            if (!pagesWithOwnRender.includes(currentPage) && !isLogistica && !isEventos) {
                // Para outras páginas, carregar via AJAX
                loadPageContent(currentPage);
            }

            // Auto-sync Google Calendar em background (com webhook + fallback)
            startGoogleCalendarAutoSync();
        });

        window.addEventListener('resize', syncSidebarByViewport);

        function startGoogleCalendarAutoSync() {
            const syncUrl = 'google_calendar_auto_sync_ping.php';
            const run = () => {
                fetch(syncUrl, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                }).catch(() => {});
            };
            run();
            setInterval(run, 300000);
        }
        
        const DASHBOARD_AVISOS_API = 'avisos_dashboard_api.php';
        const DASHBOARD_LOGISTICA_ALERTAS_API = 'dashboard_logistica_alertas.php';

        function formatarDataAvisoDashboard(dataTexto) {
            if (!dataTexto) return '';
            const data = new Date(dataTexto);
            if (Number.isNaN(data.getTime())) return '';
            return data.toLocaleString('pt-BR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        async function abrirAvisoDashboard(botao) {
            const avisoId = Number(botao?.dataset?.avisoId || 0);
            if (!avisoId) return;

            const modal = document.getElementById('dashboardAvisoModal');
            const titleEl = document.getElementById('dashboardAvisoModalTitle');
            const metaEl = document.getElementById('dashboardAvisoModalMeta');
            const bodyEl = document.getElementById('dashboardAvisoModalBody');
            if (!modal || !titleEl || !metaEl || !bodyEl) return;

            titleEl.textContent = 'Aviso';
            metaEl.textContent = '';
            bodyEl.innerHTML = '<div class="dashboard-aviso-modal-loading">Carregando aviso...</div>';
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');

            try {
                const response = await fetch(`${DASHBOARD_AVISOS_API}?action=detalhes&id=${avisoId}`, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                const payload = await response.json();

                if (!response.ok || !payload.success || !payload.data) {
                    throw new Error(payload.error || 'Aviso indisponível');
                }

                const aviso = payload.data;
                titleEl.textContent = aviso.assunto || 'Aviso';
                const meta = [];
                if (aviso.criador_nome) meta.push(`Por ${aviso.criador_nome}`);
                if (aviso.criado_em) meta.push(`Publicado em ${formatarDataAvisoDashboard(aviso.criado_em)}`);
                if (aviso.expira_em) meta.push(`Expira em ${formatarDataAvisoDashboard(aviso.expira_em)}`);
                metaEl.textContent = meta.join(' • ');
                bodyEl.innerHTML = aviso.conteudo_html || '<p>Sem conteúdo.</p>';

                if (String(botao.dataset.avisoUnico || '') === '1') {
                    botao.remove();
                    atualizarContagemAvisosDashboard();
                }
            } catch (error) {
                titleEl.textContent = 'Aviso';
                metaEl.textContent = '';
                bodyEl.innerHTML = `<div class="dashboard-aviso-modal-loading">${escapeHtml(error.message || 'Não foi possível carregar o aviso.')}</div>`;
            }
        }

        function fecharAvisoDashboard() {
            const modal = document.getElementById('dashboardAvisoModal');
            if (!modal) return;
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }

        function atualizarContagemAvisosDashboard() {
            const lista = document.querySelector('#dashboard-avisos-section .avisos-list');
            const badge = document.getElementById('dashboard-avisos-count');
            if (!lista || !badge) return;

            const total = lista.querySelectorAll('.aviso-item-btn').length;
            badge.textContent = `${total} avisos`;

            if (total === 0) {
                lista.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">📣</div>
                        <p>Nenhum aviso ativo para você</p>
                    </div>
                `;
            }
        }

        document.addEventListener('click', function(e) {
            const modal = document.getElementById('dashboardAvisoModal');
            if (modal && e.target === modal) {
                fecharAvisoDashboard();
            }
        });
        
        // ============================================
        // SISTEMA GLOBAL DE NOTIFICAÇÕES (Dashboard)
        // ============================================
        let dashboardNotificacoes = [];
        let dashboardNotificacoesNaoLidas = 0;
        const DASHBOARD_API_BASE = 'demandas_trello_api.php';

        // Carregar notificações ao entrar na dashboard
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarUnifiedConfig = window.sidebarUnifiedConfig || {};
            if (String(sidebarUnifiedConfig.currentPage || "") !== "dashboard") {
                return;
            }
            carregarDashboardLogisticaAlertas();
            carregarDashboardNotificacoes();
            // Polling a cada 30 segundos
            setInterval(carregarDashboardNotificacoes, 30000);
            setInterval(carregarDashboardLogisticaAlertas, 120000);
        });

        async function carregarDashboardLogisticaAlertas() {
            const wrapper = document.getElementById('dashboard-logistica-alertas-wrapper');
            if (!wrapper) return;

            try {
                const response = await fetch(DASHBOARD_LOGISTICA_ALERTAS_API, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'text/html' }
                });

                if (!response.ok) {
                    wrapper.hidden = true;
                    return;
                }

                const html = (await response.text()).trim();
                if (!html) {
                    wrapper.hidden = true;
                    wrapper.innerHTML = '';
                    return;
                }

                wrapper.hidden = false;
                wrapper.innerHTML = html;
            } catch (error) {
                console.error('Erro ao carregar alertas logísticos:', error);
                wrapper.hidden = true;
            }
        }

        async function carregarDashboardNotificacoes() {
            try {
                const response = await fetch(DASHBOARD_API_BASE + '?action=notificacoes', {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                
                if (!response.ok) {
                    console.warn('Erro ao carregar notificações:', response.status);
                    return;
                }
                
                const data = await response.json();
                
                if (data.success) {
                    dashboardNotificacoes = data.data || [];
                    dashboardNotificacoesNaoLidas = data.nao_lidas || 0;
                    
                    // Atualizar contador
                    const countEl = document.getElementById('dashboard-notificacoes-count');
                    if (countEl) {
                        if (dashboardNotificacoesNaoLidas > 0) {
                            countEl.textContent = dashboardNotificacoesNaoLidas > 99 ? '99+' : dashboardNotificacoesNaoLidas;
                            countEl.style.display = 'block';
                            countEl.style.visibility = 'visible';
                        } else {
                            countEl.style.display = 'none';
                            countEl.style.visibility = 'hidden';
                        }
                    }
                    
                    // Renderizar se o dropdown estiver aberto
                    const dropdown = document.getElementById('dashboard-notificacoes-dropdown');
                    if (dropdown && dropdown.classList.contains('open')) {
                        renderizarDashboardNotificacoes();
                    }
                }
            } catch (error) {
                console.error('Erro ao carregar notificações:', error);
            }
        }
        
        function toggleDashboardNotificacoes(event) {
            event.stopPropagation();
            const dropdown = document.getElementById('dashboard-notificacoes-dropdown');
            if (!dropdown) return;
            
            const isOpen = dropdown.classList.contains('open');
            
            if (isOpen) {
                dropdown.classList.remove('open');
            } else {
                dropdown.classList.add('open');
                renderizarDashboardNotificacoes();
            }
        }
        
        function renderizarDashboardNotificacoes() {
            const container = document.getElementById('dashboard-notificacoes-content');
            if (!container) return;
            
            if (dashboardNotificacoes.length === 0) {
                container.innerHTML = '<div class="dashboard-notificacoes-empty"><div style="font-size: 2rem; margin-bottom: 0.5rem;">🔔</div><div>Nenhuma notificação</div></div>';
                return;
            }
            
            // Agrupar por data
            const hoje = new Date();
            hoje.setHours(0,0,0,0);
            
            const grupos = {
                hoje: [],
                ontem: [],
                semana: [],
                antigas: []
            };
            
            dashboardNotificacoes.forEach(notif => {
                const dataNotif = new Date(notif.data_notificacao || notif.criada_em || notif.criado_em || notif.created_at);
                const diffMs = hoje - dataNotif;
                const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
                
                if (diffDays === 0) grupos.hoje.push(notif);
                else if (diffDays === 1) grupos.ontem.push(notif);
                else if (diffDays <= 7) grupos.semana.push(notif);
                else grupos.antigas.push(notif);
            });
            
            let html = '';
            
            if (grupos.hoje.length > 0) {
                html += '<div style="padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #f9fafb; border-bottom: 1px solid #e5e7eb;">Hoje</div>';
                html += grupos.hoje.map(n => renderizarDashboardNotificacaoItem(n)).join('');
            }
            
            if (grupos.ontem.length > 0) {
                html += '<div style="padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #f9fafb; border-bottom: 1px solid #e5e7eb; margin-top: 0.5rem;">Ontem</div>';
                html += grupos.ontem.map(n => renderizarDashboardNotificacaoItem(n)).join('');
            }
            
            if (grupos.semana.length > 0) {
                html += '<div style="padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #f9fafb; border-bottom: 1px solid #e5e7eb; margin-top: 0.5rem;">Esta semana</div>';
                html += grupos.semana.map(n => renderizarDashboardNotificacaoItem(n)).join('');
            }
            
            if (grupos.antigas.length > 0) {
                html += '<div style="padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #f9fafb; border-bottom: 1px solid #e5e7eb; margin-top: 0.5rem;">Mais antigas</div>';
                html += grupos.antigas.map(n => renderizarDashboardNotificacaoItem(n)).join('');
            }
            
            container.innerHTML = html;
        }
        
        function renderizarDashboardNotificacaoItem(notif) {
            const dataNotif = new Date(notif.data_notificacao || notif.criada_em || notif.criado_em || notif.created_at);
            const tempoRelativo = getTempoRelativoDashboard(dataNotif);
            const naoLida = !notif.lida;
            
            // Determinar URL de destino baseado no tipo de notificação
            let urlDestino = '#';
            let tipoNotificacao = notif.tipo || 'demanda';
            
            if (tipoNotificacao.includes('demanda') || tipoNotificacao.includes('card') || tipoNotificacao.includes('menção') || notif.referencia_id) {
                // Notificação de demanda - ir para a página de demandas
                urlDestino = 'index.php?page=demandas';
                // Se tiver referencia_id (card_id), pode adicionar parâmetro para destacar o card
                if (notif.referencia_id || notif.card_id) {
                    urlDestino += '#card-' + (notif.referencia_id || notif.card_id);
                }
            } else if (tipoNotificacao.includes('agenda')) {
                urlDestino = 'index.php?page=agenda';
            } else if (tipoNotificacao.includes('comercial')) {
                urlDestino = 'index.php?page=comercial';
            }
            if (notif.url_destino && String(notif.url_destino).trim() !== '') {
                urlDestino = String(notif.url_destino).trim();
            }
            // Preparado para expansão futura - outros tipos podem ser adicionados aqui
            
            return `
                <div class="dashboard-notificacao-item ${naoLida ? 'nao-lida' : ''}" 
                     onclick="navegarParaNotificacao('${urlDestino}', ${notif.id})">
                    <div class="dashboard-notificacao-titulo">${escapeHtml(notif.titulo || 'Notificação')}</div>
                    ${notif.mensagem && notif.mensagem !== notif.titulo ? `<div class="dashboard-notificacao-trecho">${escapeHtml(notif.mensagem.substring(0, 100))}${notif.mensagem.length > 100 ? '...' : ''}</div>` : ''}
                    <div class="dashboard-notificacao-meta">
                        <span>${tempoRelativo}</span>
                        ${notif.autor_nome ? `<span>${escapeHtml(notif.autor_nome)}</span>` : ''}
                    </div>
                </div>
            `;
        }
        
        function getTempoRelativoDashboard(data) {
            const agora = new Date();
            const diffMs = agora - data;
            const diffSeg = Math.floor(diffMs / 1000);
            const diffMin = Math.floor(diffSeg / 60);
            const diffHora = Math.floor(diffMin / 60);
            const diffDia = Math.floor(diffHora / 24);
            
            if (diffSeg < 60) return 'Agora';
            if (diffMin < 60) return diffMin + 'm atrás';
            if (diffHora < 24) return diffHora + 'h atrás';
            if (diffDia === 1) return 'Ontem';
            if (diffDia < 7) return diffDia + 'd atrás';
            return data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        async function navegarParaNotificacao(url, notificacaoId) {
            // Marcar como lida
            try {
                await fetch(DASHBOARD_API_BASE + '?action=marcar_notificacao&id=' + notificacaoId, {
                    method: 'POST',
                    credentials: 'same-origin'
                });
            } catch (error) {
                console.error('Erro ao marcar notificação como lida:', error);
            }
            
            // Fechar dropdown
            const dropdown = document.getElementById('dashboard-notificacoes-dropdown');
            if (dropdown) {
                dropdown.classList.remove('open');
            }
            
            // Navegar
            if (url && url !== '#') {
                window.location.href = url;
            }
        }
        
        async function marcarTodasDashboardNotificacoesLidas() {
            try {
                const naoLidas = dashboardNotificacoes.filter(n => !n.lida);
                for (const notif of naoLidas) {
                    try {
                        await fetch(DASHBOARD_API_BASE + '?action=marcar_notificacao&id=' + notif.id, {
                            method: 'POST',
                            credentials: 'same-origin'
                        });
                    } catch (error) {
                        console.error('Erro ao marcar notificação:', error);
                    }
                }
                
                // Recarregar notificações
                await carregarDashboardNotificacoes();
                renderizarDashboardNotificacoes();
            } catch (error) {
                console.error('Erro ao marcar todas como lidas:', error);
            }
        }
        
        // Fechar dropdown ao clicar fora
        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('dashboard-notificacoes-dropdown');
            const badge = document.querySelector('.dashboard-notificacoes-badge');
            
            if (dropdown && badge && !dropdown.contains(e.target) && !badge.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });
        
        // Função para carregar conteúdo das páginas
        function loadPageContent(page) {
            const pageContent = document.getElementById('pageContent');
            if (!pageContent) return;
            
            // Mostrar loading
            pageContent.innerHTML = '<div style="text-align: center; padding: 50px; color: #64748b;"><div style="font-size: 24px; margin-bottom: 20px;">⏳</div><div>Carregando...</div></div>';
            
            // Carregar página via AJAX
            fetch('index.php?page=' + page)
                .then(response => response.text())
                .then(html => {
                    // Extrair apenas o conteúdo da página (sem sidebar duplicada)
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const content = doc.querySelector('#pageContent') || doc.body;
                    
                    if (content) {
                        pageContent.innerHTML = content.innerHTML;
                    } else {
                        pageContent.innerHTML = html;
                    }
                })
                .catch(error => {
                    console.error('Erro ao carregar página:', error);
                    pageContent.innerHTML = '<div style="text-align: center; padding: 50px; color: #dc2626;"><div style="font-size: 24px; margin-bottom: 20px;">❌</div><div>Erro ao carregar página</div></div>';
                });
        }
