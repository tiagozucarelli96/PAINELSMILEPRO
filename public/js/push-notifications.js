// push-notifications.js — Gerenciamento de Web Push Notifications
class PushNotificationsManager {
    constructor() {
        this.registration = null;
        this.subscription = null;
        this.isUserInternal = false;
        this.userId = null;
    }
    
    /**
     * Inicializar sistema de push
     */
    async init(userId, isUserInternal) {
        this.userId = userId;
        this.isUserInternal = isUserInternal;
        
        // Se não for usuário interno, não inicializar push
        if (!isUserInternal) {
            return { required: false };
        }
        
        // Verificar se navegador suporta
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.warn('Navegador não suporta Web Push Notifications');
            return { required: true, supported: false };
        }
        
        // Verificar se já tem consentimento
        const hasConsent = await this.checkConsent();
        
        if (!hasConsent) {
            return { required: true, hasConsent: false };
        }
        
        // Registrar service worker e subscription
        try {
            await this.registerServiceWorker();
            await this.subscribe();
            return { required: true, hasConsent: true, subscribed: true };
        } catch (error) {
            console.error('Erro ao inicializar push:', error);
            return { required: true, hasConsent: true, error: error.message };
        }
    }
    
    /**
     * Verificar se usuário já tem consentimento no banco
     */
    async checkConsent() {
        try {
            const response = await fetch('push_check_consent.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    usuario_id: this.userId
                })
            });
            
            const data = await response.json();
            return data.hasConsent === true;
        } catch (error) {
            console.error('Erro ao verificar consentimento:', error);
            return false;
        }
    }
    
    /**
     * Registrar service worker
     */
    async registerServiceWorker() {
        try {
            this.registration = await navigator.serviceWorker.register('/service-worker.js', {
                scope: '/'
            });
            console.log('[Push] Service Worker registrado:', this.registration);
            return this.registration;
        } catch (error) {
            console.error('[Push] Erro ao registrar service worker:', error);
            throw error;
        }
    }
    
    /**
     * Solicitar permissão e criar subscription
     */
    async requestPermission() {
        try {
            const permission = await Notification.requestPermission();
            
            if (permission !== 'granted') {
                throw new Error('Permissão negada pelo usuário');
            }
            
            return permission;
        } catch (error) {
            console.error('[Push] Erro ao solicitar permissão:', error);
            throw error;
        }
    }
    
    /**
     * Criar subscription
     */
    async subscribe() {
        try {
            if (!this.registration) {
                await this.registerServiceWorker();
            }
            
            // Verificar subscription existente
            this.subscription = await this.registration.pushManager.getSubscription();
            
            if (this.subscription) {
                // Já tem subscription, verificar se está registrada no banco
                await this.registerSubscription(this.subscription);
                return this.subscription;
            }
            
            // Criar nova subscription
            const applicationServerKey = await this.getPublicKey();
            this.subscription = await this.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey
            });
            
            // Registrar no banco
            await this.registerSubscription(this.subscription);
            
            return this.subscription;
        } catch (error) {
            console.error('[Push] Erro ao criar subscription:', error);
            throw error;
        }
    }
    
    /**
     * Obter chave pública do servidor
     */
    async getPublicKey() {
        try {
            const response = await fetch('push_get_public_key.php');
            const data = await response.json();
            
            if (!data.publicKey) {
                throw new Error('Chave pública não encontrada');
            }
            
            // Converter base64 para Uint8Array
            return this.urlBase64ToUint8Array(data.publicKey);
        } catch (error) {
            console.error('[Push] Erro ao obter chave pública:', error);
            throw error;
        }
    }
    
    /**
     * Converter base64 URL para Uint8Array
     */
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/\-/g, '+')
            .replace(/_/g, '/');
        
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        
        return outputArray;
    }
    
    /**
     * Registrar subscription no banco de dados
     */
    async registerSubscription(subscription) {
        try {
            const response = await fetch('push_register_subscription.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    usuario_id: this.userId,
                    endpoint: subscription.endpoint,
                    keys: {
                        p256dh: this.arrayBufferToBase64(subscription.getKey('p256dh')),
                        auth: this.arrayBufferToBase64(subscription.getKey('auth'))
                    }
                })
            });
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Erro ao registrar subscription');
            }
            
            return data;
        } catch (error) {
            console.error('[Push] Erro ao registrar subscription:', error);
            throw error;
        }
    }
    
    /**
     * Converter ArrayBuffer para base64
     */
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }
    
    /**
     * Cancelar subscription
     */
    async unsubscribe() {
        try {
            if (this.subscription) {
                await this.subscription.unsubscribe();
                await this.unregisterSubscription();
                this.subscription = null;
            }
        } catch (error) {
            console.error('[Push] Erro ao cancelar subscription:', error);
            throw error;
        }
    }
    
    /**
     * Remover subscription do banco
     */
    async unregisterSubscription() {
        try {
            const response = await fetch('push_unregister_subscription.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    usuario_id: this.userId
                })
            });
            
            return await response.json();
        } catch (error) {
            console.error('[Push] Erro ao remover subscription:', error);
            throw error;
        }
    }
}

// Instância global
window.pushNotificationsManager = new PushNotificationsManager();
