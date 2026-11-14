/**
 * ROUTLAW — Toast Notification Module
 * Non-blocking, accessible, auto-dismiss for success
 */
(function () {
  'use strict';

  let container = null;

  function ensureContainer() {
    if (container) return container;

    container = document.createElement('div');
    container.className = 'toast-container';
    container.setAttribute('role', 'region');
    container.setAttribute('aria-label', 'Notifications');
    container.setAttribute('aria-live', 'polite');
    document.body.appendChild(container);
    return container;
  }

  function createToast(message, type = 'info', options = {}) {
    const { title, duration = type === 'success' ? 4000 : 0, action } = options;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');

    let html = '';
    if (title) {
      html += `<div class="toast-title">${escapeHtml(title)}</div>`;
    }
    html += `<div class="toast-message">${escapeHtml(message)}</div>`;

    if (action) {
      html += `<button class="toast-action btn btn-ghost" type="button">${escapeHtml(action.label)}</button>`;
    }

    html += `<button class="toast-close" type="button" aria-label="Dismiss">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
    </button>`;

    toast.innerHTML = html;

    // Close button
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', () => dismissToast(toast));

    // Action button
    if (action && action.onClick) {
      const actionBtn = toast.querySelector('.toast-action');
      actionBtn.addEventListener('click', () => {
        action.onClick();
        dismissToast(toast);
      });
    }

    // Auto-dismiss for success
    if (duration > 0) {
      setTimeout(() => dismissToast(toast), duration);
    }

    // Add to container
    const c = ensureContainer();
    c.appendChild(toast);

    // Trigger animation
    requestAnimationFrame(() => {
      toast.style.animation = 'toast-in 200ms ease';
    });

    return toast;
  }

  function dismissToast(toast) {
    if (!toast.parentElement) return;

    toast.style.animation = 'toast-out 150ms ease forwards';
    toast.addEventListener('animationend', () => {
      if (toast.parentElement) {
        toast.remove();
      }
      // Remove container if empty
      if (container && container.children.length === 0) {
        container.remove();
        container = null;
      }
    });
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Global API
  window.routlawToast = {
    success: (message, options) => createToast(message, 'success', options),
    error: (message, options) => createToast(message, 'error', options),
    warning: (message, options) => createToast(message, 'warning', options),
    info: (message, options) => createToast(message, 'info', options)
  };

  // CSS for toast-out animation (injected once)
  if (!document.getElementById('toast-animations')) {
    const style = document.createElement('style');
    style.id = 'toast-animations';
    style.textContent = `
      @keyframes toast-out {
        from { opacity: 1; transform: translateX(0); }
        to { opacity: 0; transform: translateX(100%); }
      }
    `;
    document.head.appendChild(style);
  }
})();