/**
 * ROUTLAW — Load Extraction Preview Module
 * Renders per-field confidence + missing list for extraction preview
 * BR-005: never fabricates — shows exactly what was extracted
 */
(function () {
  'use strict';

  function initExtractionPreview() {
    const form = document.getElementById('extraction-form');
    if (!form) return;

    const rawTextarea = form.querySelector('[name="raw_text"]');
    const previewContainer = document.getElementById('extraction-preview');
    const submitBtn = form.querySelector('[type="submit"]');
    let debounceTimer = null;

    if (!rawTextarea || !previewContainer) return;

    // Debounced preview on input
    rawTextarea.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        const text = rawTextarea.value.trim();
        if (text.length >= 10) {
          generatePreview(text);
        } else {
          clearPreview();
        }
      }, 300);
    });

    // Form submit
    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const text = rawTextarea.value.trim();
      if (!text) {
        window.routlawToast?.error('Please paste load text to extract');
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Creating Load…';

      try {
        const formData = new FormData(form);
        const response = await fetch(form.action || '/loads/extract.php', {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });

        const result = await response.json();

        if (response.ok && result.success) {
          window.routlawToast?.success('Load created successfully');
          setTimeout(() => {
            if (result.load_id) {
              window.location.href = `/loads/show.php?id=${result.load_id}`;
            } else {
              window.location.reload();
            }
          }, 500);
        } else {
          throw new Error(result.message || 'Failed to create load');
        }
      } catch (error) {
        console.error('Extraction failed:', error);
        window.routlawToast?.error(error.message || 'Failed to create load');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Load';
      }
    });
  }

  async function generatePreview(text) {
    const previewContainer = document.getElementById('extraction-preview');
    if (!previewContainer) return;

    previewContainer.innerHTML = '<div class="preview-loading">Analyzing…</div>';

    try {
      const response = await fetch('/loads/extract-preview.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'raw_text=' + encodeURIComponent(text)
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const extraction = await response.json();
      renderPreview(extraction);
    } catch (error) {
      console.error('Preview failed:', error);
      previewContainer.innerHTML = `
        <div class="alert alert-danger">
          <div class="alert-content">
            <div class="alert-title">Preview Failed</div>
            <div class="alert-message">${escapeHtml(error.message)}</div>
          </div>
        </div>
      `;
    }
  }

  function renderPreview(extraction) {
    const previewContainer = document.getElementById('extraction-preview');
    if (!previewContainer) return;

    const fields = extraction.fields || {};
    const missing = extraction.missing || [];
    const overallConfidence = extraction.overall_confidence || 'low';
    const reviewRequired = extraction.review_required === 1;
    const version = extraction.extraction_version || '1.0';

    const fieldLabels = {
      origin_city: 'Pickup City',
      origin_state: 'Pickup State',
      dest_city: 'Delivery City',
      dest_state: 'Delivery State',
      commodity: 'Commodity',
      weight_lbs: 'Weight (lbs)',
      posted_rate: 'Posted Rate'
    };

    const confidenceLabels = {
      high: 'High',
      medium: 'Medium',
      low: 'Low',
      none: 'Not Found'
    };

    const confidenceColors = {
      high: 'var(--color-success)',
      medium: 'var(--color-warning)',
      low: 'var(--color-danger)',
      none: 'var(--color-text-muted)'
    };

    let html = '';

    // Overall confidence banner
    const confidenceClass = overallConfidence === 'high' ? 'success' : overallConfidence === 'medium' ? 'warning' : 'danger';
    html += `
      <div class="alert alert-${confidenceClass}" style="margin-bottom: var(--space-4);">
        <div class="alert-content">
          <div class="alert-title">
            Extraction Confidence: <strong>${escapeHtml(confidenceLabels[overallConfidence])}</strong>
            ${reviewRequired ? '<span class="badge badge-warning" style="margin-left: var(--space-2); font-size: var(--text-xs);">Review Required</span>' : ''}
          </div>
          <div class="alert-message">
            Schema version: ${escapeHtml(version)} • ${missing.length > 0 ? `${missing.length} required field(s) missing` : 'All required fields present'}
          </div>
        </div>
      </div>
    `;

    // Field grid
    html += '<div class="extraction-fields" style="display: grid; gap: var(--space-3);">';

    const fieldOrder = ['origin_city', 'origin_state', 'dest_city', 'dest_state', 'commodity', 'weight_lbs', 'posted_rate'];

    fieldOrder.forEach((field) => {
      const spec = fields[field] || { value: null, confidence: 'none', provenance: 'none' };
      const isRequired = ['origin_city', 'origin_state', 'dest_city', 'dest_state', 'commodity', 'weight_lbs'].includes(field);
      const isMissing = missing.includes(field);
      const value = spec.value !== null && spec.value !== '' ? spec.value : '—';
      const confidence = spec.confidence || 'none';
      const provenance = spec.provenance || 'none';

      html += `
        <div class="extraction-field card" style="padding: var(--space-4); border-left: 4px solid ${confidenceColors[confidence]};">
          <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3);">
            <div>
              <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-1);">
                <span class="form-label" style="margin: 0;">${escapeHtml(fieldLabels[field] || field)}</span>
                ${isRequired ? '<span class="required-indicator" style="margin: 0;">*</span>' : ''}
                ${isMissing ? '<span class="badge badge-danger" style="font-size: 10px;">Missing</span>' : ''}
              </div>
              <div style="font-family: var(--font-mono); font-size: var(--text-base); color: var(--color-text-primary);">
                ${escapeHtml(String(value))}
              </div>
            </div>
            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: var(--space-1); min-width: 120px;">
              <span class="badge" style="background-color: ${confidenceColors[confidence]}26; color: ${confidenceColors[confidence]}; font-size: var(--text-xs);">
                ${escapeHtml(confidenceLabels[confidence])}
              </span>
              <span style="font-size: var(--text-xs); color: var(--color-text-muted);">
                Source: ${escapeHtml(provenance)}
              </span>
            </div>
          </div>
        </div>
      `;
    });

    html += '</div>';

    // Missing fields summary
    if (missing.length > 0) {
      html += `
        <div class="alert alert-warning" style="margin-top: var(--space-4);">
          <div class="alert-content">
            <div class="alert-title">Missing Required Fields</div>
            <div class="alert-message">
              The following required fields were not found in the source text and must be provided manually:
              <ul style="margin: var(--space-2) 0 0 var(--space-4);">
                ${missing.map(m => `<li>${escapeHtml(fieldLabels[m] || m)}</li>`).join('')}
              </ul>
            </div>
          </div>
        </div>
      `;
    }

    previewContainer.innerHTML = html;
  }

  function clearPreview() {
    const previewContainer = document.getElementById('extraction-preview');
    if (previewContainer) {
      previewContainer.innerHTML = '';
    }
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Initialize
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initExtractionPreview);
  } else {
    initExtractionPreview();
  }
})();