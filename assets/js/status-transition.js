/**
 * ROUTLAW — Carrier Status Transition Module
 * Modal-driven state transitions with valid next-states from backend
 */
(function () {
  'use strict';

  function initStatusTransition() {
    // Delegate click on transition triggers
    document.addEventListener('click', (event) => {
      const trigger = event.target.closest('[data-carrier-transition]');
      if (!trigger) return;

      event.preventDefault();
      openTransitionModal(trigger);
    });
  }

  async function openTransitionModal(trigger) {
    const carrierId = trigger.getAttribute('data-carrier-id');
    const currentStatus = trigger.getAttribute('data-current-status');
    const companyId = trigger.getAttribute('data-company-id');
    const actorRoleId = trigger.getAttribute('data-actor-role-id');

    if (!carrierId || !currentStatus || !companyId || !actorRoleId) {
      console.error('Missing transition data attributes');
      return;
    }

    try {
      // Fetch valid next states from backend
      const response = await fetch(`/carriers/transition-options.php?carrier_id=${encodeURIComponent(carrierId)}&company_id=${encodeURIComponent(companyId)}&current_status=${encodeURIComponent(currentStatus)}`, {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const data = await response.json();
      if (!data.validTransitions || data.validTransitions.length === 0) {
        window.routlawToast?.warning('No valid transitions available for current state');
        return;
      }

      showTransitionModal(carrierId, currentStatus, companyId, actorRoleId, data.validTransitions);
    } catch (error) {
      console.error('Failed to load transition options:', error);
      window.routlawToast?.error('Failed to load transition options');
    }
  }

  function showTransitionModal(carrierId, currentStatus, companyId, actorRoleId, validTransitions) {
    // Remove any existing modal
    const existingOverlay = document.querySelector('.modal-overlay[data-carrier-modal]');
    if (existingOverlay) existingOverlay.remove();

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.setAttribute('data-carrier-modal', '');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'transition-modal-title');

    const statusLabels = {
      'new': 'New',
      'needs_documents': 'Needs Documents',
      'under_review': 'Under Review',
      'active': 'Active',
      'inactive': 'Inactive',
      'rejected': 'Rejected',
      'archived': 'Archived'
    };

    const formatStatus = (status) => statusLabels[status] || status;

    overlay.innerHTML = `
      <div class="modal" role="document">
        <div class="modal-header">
          <h2 id="transition-modal-title" class="modal-title">Transition Carrier Status</h2>
          <button class="modal-close" type="button" aria-label="Close">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>
        <div class="modal-body">
          <p style="color: var(--color-text-secondary); margin-bottom: var(--space-4);">
            Current status: <strong>${escapeHtml(formatStatus(currentStatus))}</strong>
          </p>
          <div class="form-field">
            <label for="transition-new-status" class="form-label">New Status <span class="required-indicator">*</span></label>
            <select id="transition-new-status" class="form-select" required>
              <option value="">Select new status…</option>
              ${validTransitions.map(s => `<option value="${escapeHtml(s)}">${escapeHtml(formatStatus(s))}</option>`).join('')}
            </select>
          </div>
          <div class="form-field">
            <label for="transition-reason" class="form-label">Reason (optional)</label>
            <textarea id="transition-reason" class="form-textarea" rows="3" placeholder="Enter reason for transition…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
          <button type="button" class="btn btn-primary modal-submit" disabled>Save Transition</button>
        </div>
      </div>
    `;

    document.body.appendChild(overlay);

    // Focus management
    const modal = overlay.querySelector('.modal');
    const closeBtn = overlay.querySelector('.modal-close');
    const cancelBtn = overlay.querySelector('.modal-cancel');
    const submitBtn = overlay.querySelector('.modal-submit');
    const select = overlay.querySelector('#transition-new-status');
    const reason = overlay.querySelector('#transition-reason');

    // Focus first focusable element
    select.focus();

    // Enable submit when status selected
    select.addEventListener('change', () => {
      submitBtn.disabled = !select.value;
    });

    function closeModal() {
      overlay.style.animation = 'fade-out 150ms ease forwards';
      modal.style.animation = 'scale-out 150ms ease forwards';
      overlay.addEventListener('animationend', () => overlay.remove(), { once: true });
    }

    // Close handlers
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeModal();
    });

    // Submit handler
    submitBtn.addEventListener('click', async () => {
      const newStatus = select.value;
      const reasonText = reason.value.trim();

      if (!newStatus) return;

      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving…';

      try {
        const formData = new FormData();
        formData.append('carrier_id', carrierId);
        formData.append('new_status', newStatus);
        formData.append('company_id', companyId);
        formData.append('actor_role_id', actorRoleId);
        if (reasonText) formData.append('reason', reasonText);

        const response = await fetch('/carriers/transition.php', {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });

        const result = await response.json();

        if (response.ok && result.success) {
          window.routlawToast?.success('Carrier status updated successfully');
          closeModal();
          // Reload page to reflect changes
          setTimeout(() => window.location.reload(), 500);
        } else {
          throw new Error(result.message || 'Transition failed');
        }
      } catch (error) {
        console.error('Transition failed:', error);
        window.routlawToast?.error(error.message || 'Failed to update status');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Save Transition';
      }
    });

    // Keyboard: Enter on select submits if valid
    select.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !submitBtn.disabled) {
        submitBtn.click();
      }
    });
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Initialize
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStatusTransition);
  } else {
    initStatusTransition();
  }
})();