/**
 * Thai Lottery Toast Notification Manager
 * Handles responsive, stacked floating alerts for async actions, websocket events, and validation feedback.
 */

class NotificationManager {
    constructor() {
        this.container = null;
        this.ensureContainer();
    }

    ensureContainer() {
        let el = document.getElementById('toast-container');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toast-container';
            el.className = 'fixed top-5 right-5 z-50 flex flex-col space-y-3 max-w-sm w-full pointer-events-none px-4 sm:px-0';
            document.body.appendChild(el);
        }
        this.container = el;
    }

    show(type, title, message = '', duration = 4000) {
        this.ensureContainer();

        const toast = document.createElement('div');
        toast.className = `pointer-events-auto transform transition-all duration-300 ease-out translate-y-2 opacity-0 flex items-start p-4 rounded-xl shadow-xl border backdrop-blur-md ${this.getStyle(type)}`;

        const iconSvg = this.getIcon(type);

        toast.innerHTML = `
            <div class="flex-shrink-0 mr-3 mt-0.5">
                ${iconSvg}
            </div>
            <div class="flex-1">
                <h4 class="text-sm font-bold text-white tracking-wide">${this.escapeHtml(title)}</h4>
                ${message ? `<p class="text-xs text-slate-300 mt-1 leading-relaxed">${this.escapeHtml(message)}</p>` : ''}
            </div>
            <button type="button" class="flex-shrink-0 ml-3 text-slate-400 hover:text-white focus:outline-none close-toast">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        `;

        this.container.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            toast.classList.remove('translate-y-2', 'opacity-0');
            toast.classList.add('translate-y-0', 'opacity-100');
        });

        // Close on click
        toast.querySelector('.close-toast').addEventListener('click', () => {
            this.dismiss(toast);
        });

        // Auto dismiss
        if (duration > 0) {
            setTimeout(() => {
                this.dismiss(toast);
            }, duration);
        }
    }

    dismiss(toast) {
        toast.classList.add('opacity-0', 'translate-x-full');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }

    success(title, message = '', duration = 4000) {
        this.show('success', title, message, duration);
    }

    error(title, message = '', duration = 5000) {
        this.show('error', title, message, duration);
    }

    warning(title, message = '', duration = 4500) {
        this.show('warning', title, message, duration);
    }

    info(title, message = '', duration = 4000) {
        this.show('info', title, message, duration);
    }

    getStyle(type) {
        switch (type) {
            case 'success':
                return 'bg-emerald-900/90 border-emerald-500/50 text-emerald-200';
            case 'error':
                return 'bg-rose-900/90 border-rose-500/50 text-rose-200';
            case 'warning':
                return 'bg-amber-900/90 border-amber-500/50 text-amber-200';
            case 'info':
            default:
                return 'bg-slate-900/90 border-amber-500/30 text-amber-200';
        }
    }

    getIcon(type) {
        switch (type) {
            case 'success':
                return '<svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
            case 'error':
                return '<svg class="w-5 h-5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
            case 'warning':
                return '<svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>';
            case 'info':
            default:
                return '<svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
        }
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

export const notifications = new NotificationManager();
window.notifications = notifications;

export default notifications;
