/**
 * ROUTLAW — Main Application Module
 * Progressive enhancement: all features degrade gracefully without JS
 */
import './nav-drawer.js';
import './table-sort.js';
import './toast.js';
import './status-transition.js';
import './extraction-preview.js';

// Global error handler for unhandled promise rejections
window.addEventListener('unhandledrejection', (event) => {
  console.error('Unhandled rejection:', event.reason);
  // Don't spam toasts for programming errors
});

// Keyboard shortcuts
document.addEventListener('keydown', (event) => {
  // ESC closes modals, drawers, dropdowns
  if (event.key === 'Escape') {
    // Close modals
    document.querySelectorAll('.modal-overlay[open]').forEach((overlay) => {
      overlay.removeAttribute('open');
    });
    // Close nav drawer
    const drawer = document.querySelector('.nav-drawer[open]');
    if (drawer) {
      drawer.removeAttribute('open');
      const overlay = document.querySelector('.nav-drawer-overlay[visible]');
      if (overlay) overlay.removeAttribute('visible');
    }
    // Close dropdowns
    document.querySelectorAll('.dropdown[open]').forEach((dd) => dd.removeAttribute('open'));
  }
});

// Initialize tooltips, dropdowns, etc. (progressive enhancement)
document.addEventListener('DOMContentLoaded', () => {
  // Dropdowns: click outside to close
  document.addEventListener('click', (event) => {
    const dropdown = event.target.closest('.dropdown');
    document.querySelectorAll('.dropdown[open]').forEach((dd) => {
      if (dd !== dropdown) dd.removeAttribute('open');
    });
  });

  // Tab activation
  document.querySelectorAll('.tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      const tabs = tab.closest('.tabs');
      const panelId = tab.getAttribute('aria-controls');
      tabs.querySelectorAll('.tab').forEach((t) => t.setAttribute('aria-selected', 'false'));
      tab.setAttribute('aria-selected', 'true');
      tabs.parentElement.querySelectorAll('.tab-panel').forEach((p) => p.hidden = true);
      const panel = document.getElementById(panelId);
      if (panel) panel.hidden = false;
    });
  });

  // Toast auto-dismiss (success only)
  document.querySelectorAll('.toast-success').forEach((toast) => {
    setTimeout(() => toast.remove(), 4000);
  });
});

// Export for debugging
window.routlaw = {
  version: '1.0.0'
};