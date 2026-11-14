/**
 * ROUTLAW — Navigation Drawer Module
 * Mobile hamburger menu with focus trap, ARIA, ESC close
 */
(function () {
  'use strict';

  const DRAWER_SELECTOR = '.nav-drawer';
  const TOGGLE_SELECTOR = '.nav-toggle';
  const OVERLAY_SELECTOR = '.nav-drawer-overlay';
  const CLOSE_SELECTOR = '.nav-drawer-close';

  function initDrawer() {
    const drawer = document.querySelector(DRAWER_SELECTOR);
    const toggle = document.querySelector(TOGGLE_SELECTOR);
    const overlay = document.querySelector(OVERLAY_SELECTOR);
    const closeBtn = document.querySelector(CLOSE_SELECTOR);

    if (!drawer || !toggle || !overlay) return;

    let lastFocusedElement = null;

    function openDrawer() {
      lastFocusedElement = document.activeElement;
      drawer.setAttribute('open', '');
      overlay.setAttribute('visible', '');
      toggle.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';

      // Focus first focusable element in drawer
      const focusable = drawer.querySelector('a, button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (focusable) focusable.focus();

      // Trap focus
      document.addEventListener('keydown', handleKeydown);
    }

    function closeDrawer() {
      drawer.removeAttribute('open');
      overlay.removeAttribute('visible');
      toggle.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';

      if (lastFocusedElement) {
        lastFocusedElement.focus();
        lastFocusedElement = null;
      }

      document.removeEventListener('keydown', handleKeydown);
    }

    function handleKeydown(event) {
      if (event.key === 'Tab') {
        const focusableElements = drawer.querySelectorAll(
          'a[href], button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (event.shiftKey && document.activeElement === firstElement) {
          event.preventDefault();
          lastElement.focus();
        } else if (!event.shiftKey && document.activeElement === lastElement) {
          event.preventDefault();
          firstElement.focus();
        }
      } else if (event.key === 'Escape') {
        closeDrawer();
      }
    }

    // Toggle button
    toggle.addEventListener('click', () => {
      if (drawer.hasAttribute('open')) {
        closeDrawer();
      } else {
        openDrawer();
      }
    });

    // Close button
    if (closeBtn) {
      closeBtn.addEventListener('click', closeDrawer);
    }

    // Overlay click
    overlay.addEventListener('click', closeDrawer);

    // Nav links close drawer on click (mobile)
    drawer.querySelectorAll('.nav-link').forEach((link) => {
      link.addEventListener('click', () => {
        if (window.innerWidth < 769) {
          closeDrawer();
        }
      });
    });

    // Handle resize
    window.addEventListener('resize', () => {
      if (window.innerWidth >= 769 && drawer.hasAttribute('open')) {
        closeDrawer();
      }
    });
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDrawer);
  } else {
    initDrawer();
  }
})();