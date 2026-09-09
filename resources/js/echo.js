/**
 * Thai Lottery WebSocket & Realtime Broadcaster Client
 * Integrates Laravel Echo / Reverb / Pusher client with automatic fallback and channel management.
 */

class EchoBridge {
    constructor() {
        this.listeners = new Map();
        this.isInitialized = false;
        this.client = null;
    }

    init() {
        if (this.isInitialized) return;

        // If window.Echo is loaded (e.g. via Pusher/Reverb)
        if (window.Echo) {
            this.client = window.Echo;
            this.isInitialized = true;
            this.bindGlobalChannels();
            return;
        }

        // Mock/Fallback event bus for environments without active WS server
        this.client = {
            private: (channel) => this.createChannelProxy('private', channel),
            channel: (channel) => this.createChannelProxy('public', channel),
        };
        this.isInitialized = true;
    }

    createChannelProxy(type, name) {
        return {
            listen: (event, callback) => {
                const key = `${name}:${event}`;
                if (!this.listeners.has(key)) {
                    this.listeners.set(key, []);
                }
                this.listeners.get(key).push(callback);
                return this;
            },
            stopListening: (event) => {
                const key = `${name}:${event}`;
                this.listeners.delete(key);
                return this;
            }
        };
    }

    // Trigger an event on our internal bus (used when polling or when mock WS fires)
    emit(channel, event, data) {
        const key = `${channel}:${event}`;
        const handlers = this.listeners.get(key);
        if (handlers && handlers.length > 0) {
            handlers.forEach(fn => fn(data));
        }
    }

    bindGlobalChannels() {
        const userIdMeta = document.querySelector('meta[name="user-id"]');
        const userId = userIdMeta ? userIdMeta.content : null;

        if (userId && this.client && typeof this.client.private === 'function') {
            this.client.private(`user.${userId}`)
                .listen('.wallet.balance.updated', (e) => {
                    document.dispatchEvent(new CustomEvent('lottery:wallet-updated', { detail: e }));
                })
                .listen('.bet.placed', (e) => {
                    document.dispatchEvent(new CustomEvent('lottery:bet-placed', { detail: e }));
                })
                .listen('.withdrawal.status.updated', (e) => {
                    document.dispatchEvent(new CustomEvent('lottery:withdrawal-updated', { detail: e }));
                })
                .listen('.deposit.status.updated', (e) => {
                    document.dispatchEvent(new CustomEvent('lottery:deposit-updated', { detail: e }));
                });
        }

        if (this.client && typeof this.client.channel === 'function') {
            this.client.channel('draws')
                .listen('.draw.result.published', (e) => {
                    document.dispatchEvent(new CustomEvent('lottery:draw-result-published', { detail: e }));
                })
                .listen('.draw.status.updated', (e) => {
                    document.dispatchEvent(new CustomEvent('lottery:draw-status-updated', { detail: e }));
                });
        }
    }
}

export const echo = new EchoBridge();
window.echoBridge = echo;

export default echo;
