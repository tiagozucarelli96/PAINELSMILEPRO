// service-worker.js — Service Worker para Web Push Notifications
const CACHE_NAME = 'painel-smile-v1';

// Instalar service worker
self.addEventListener('install', (event) => {
    console.log('[Service Worker] Instalando...');
    self.skipWaiting();
});

// Ativar service worker
self.addEventListener('activate', (event) => {
    console.log('[Service Worker] Ativando...');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('[Service Worker] Removendo cache antigo:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    return self.clients.claim();
});

// Receber notificações push
self.addEventListener('push', (event) => {
    console.log('[Service Worker] Push recebido:', event);
    
    let data = {
        title: 'Portal Grupo Smile',
        body: 'Você tem novas atualizações no sistema.',
        icon: '/favicon.ico',
        badge: '/favicon.ico',
        tag: 'painel-smile-notification',
        requireInteraction: false,
        data: {}
    };
    
    if (event.data) {
        try {
            const pushData = event.data.json();
            data = {
                ...data,
                ...pushData
            };
        } catch (e) {
            console.error('[Service Worker] Erro ao parsear dados do push:', e);
        }
    }
    
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: data.icon || '/favicon.ico',
            badge: data.badge || '/favicon.ico',
            tag: data.tag,
            requireInteraction: data.requireInteraction || false,
            data: data.data || {},
            actions: [
                {
                    action: 'open',
                    title: 'Abrir Painel'
                }
            ]
        })
    );
});

// Clique na notificação
self.addEventListener('notificationclick', (event) => {
    console.log('[Service Worker] Notificação clicada:', event);
    
    event.notification.close();
    
    const action = event.action || 'open';
    const urlToOpen = '/index.php?page=dashboard';
    
    event.waitUntil(
        clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        }).then((clientList) => {
            // Se já existe uma janela aberta, focar nela
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                if (client.url === urlToOpen && 'focus' in client) {
                    return client.focus();
                }
            }
            // Se não existe, abrir nova janela
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});

// Tratar erros
self.addEventListener('error', (event) => {
    console.error('[Service Worker] Erro:', event.error);
});

self.addEventListener('unhandledrejection', (event) => {
    console.error('[Service Worker] Promise rejeitada:', event.reason);
});
