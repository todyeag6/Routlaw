/**
 * ROUTLAW — Theme Initialization Script
 * CSP-safe: external classic script in <head> (no defer, no module)
 * Runs before paint to prevent flash of incorrect theme
 * Reads localStorage, sets data-theme on <html>
 */
(function () {
  'use strict';

  const THEME_KEY = 'routlaw-theme';
  const html = document.documentElement;

  // Get stored theme or default to 'dark'
  function getStoredTheme() {
    try {
      return localStorage.getItem(THEME_KEY) || 'dark';
    } catch (e) {
      return 'dark';
    }
  }

  // Apply theme immediately
  function applyTheme(theme) {
    html.setAttribute('data-theme', theme);
  }

  // Initialize
  applyTheme(getStoredTheme());

  // Listen for storage changes (other tabs)
  window.addEventListener('storage', function (e) {
    if (e.key === THEME_KEY && e.newValue) {
      applyTheme(e.newValue);
    }
  });

  // Expose API for other scripts
  window.routlawTheme = {
    get: getStoredTheme,
    set: function (theme) {
      try {
        localStorage.setItem(THEME_KEY, theme);
      } catch (e) {
        // Ignore quota/privacy errors
      }
      applyTheme(theme);
    },
    toggle: function () {
      const current = getStoredTheme();
      const next = current === 'dark' ? 'light' : 'dark';
      window.routlawTheme.set(next);
      return next;
    }
  };
})();