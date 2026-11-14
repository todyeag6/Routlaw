/**
 * ROUTLAW — Table Sort Module
 * Client-side sorting for data tables (progressive enhancement)
 * Falls back to server-side sort if JS disabled
 */
(function () {
  'use strict';

  function initTableSort() {
    const tables = document.querySelectorAll('.data-table');

    tables.forEach((table) => {
      const headers = table.querySelectorAll('th[role="columnheader"]');

      headers.forEach((th) => {
        const btn = th.querySelector('.sort-btn');
        if (!btn) return;

        const columnIndex = Array.from(th.parentElement.children).indexOf(th);
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        let sortDirection = 'none'; // 'none', 'ascending', 'descending'

        function getCellValue(row, index) {
          const cell = row.cells[index];
          if (!cell) return '';
          // Check for data-sort-value attribute first
          return cell.getAttribute('data-sort-value') || cell.textContent.trim();
        }

        function compareRows(a, b, direction) {
          const aVal = getCellValue(a, columnIndex);
          const bVal = getCellValue(b, columnIndex);

          // Try numeric comparison first
          const aNum = parseFloat(aVal.replace(/[^\d.-]/g, ''));
          const bNum = parseFloat(bVal.replace(/[^\d.-]/g, ''));
          const isNumeric = !isNaN(aNum) && !isNaN(bNum) && aVal !== '' && bVal !== '';

          let result = 0;
          if (isNumeric) {
            result = aNum - bNum;
          } else {
            result = aVal.localeCompare(bVal, undefined, { numeric: true, sensitivity: 'base' });
          }

          return direction === 'ascending' ? result : -result;
        }

        function sort(direction) {
          const rows = Array.from(tbody.rows);
          rows.sort((a, b) => compareRows(a, b, direction));

          // Re-append rows in sorted order
          rows.forEach((row) => tbody.appendChild(row));

          // Update ARIA
          headers.forEach((h) => h.setAttribute('aria-sort', 'none'));
          th.setAttribute('aria-sort', direction);

          // Update sort button icon
          const svg = btn.querySelector('svg');
          if (svg) {
            if (direction === 'descending') {
              svg.style.transform = 'rotate(180deg)';
            } else {
              svg.style.transform = 'rotate(0deg)';
            }
            svg.style.color = direction !== 'none' ? 'var(--color-accent)' : 'var(--color-text-muted)';
          }

          sortDirection = direction;
        }

        btn.addEventListener('click', (event) => {
          event.preventDefault();

          if (sortDirection === 'none' || sortDirection === 'descending') {
            sort('ascending');
          } else if (sortDirection === 'ascending') {
            sort('descending');
          }
        });

        // Keyboard support
        btn.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            btn.click();
          }
        });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTableSort);
  } else {
    initTableSort();
  }
})();