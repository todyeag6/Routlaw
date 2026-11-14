---
version: alpha
name: ROUTLAW
description: "Ultra-minimal dark-mode freight operations platform. Developer-tool precision. Purple accent drives every interaction."
colors:
  primary: "#0D0D0D"
  bg-elevated: "#1A1A1A"
  bg-hover: "#222222"
  border: "#2A2A2A"
  border-focus: "#6E56CF"
  text-primary: "#FFFFFF"
  text-secondary: "#B3B3B3"
  text-muted: "#8A8A8A"
  accent: "#7C5CFF"
  accent-hover: "#9B7EFF"
  accent-press: "#6A4CFF"
  accent-subtle: "rgba(124,92,255,0.15)"
  success: "#10B981"
  success-subtle: "rgba(16,185,129,0.15)"
  warning: "#F59E0B"
  warning-subtle: "rgba(245,158,11,0.15)"
  danger: "#EF4444"
  danger-subtle: "rgba(239,68,68,0.15)"
  focus-ring: "#7C5CFF"
typography:
  font-family:
    fontFamily: '"Geist", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0px"
  font-mono:
    fontFamily: '"Geist Mono", ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace'
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0px"
  xs:
    fontFamily: '"Geist", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: "12px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0px"
  sm:
    fontFamily: '"Geist", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0px"
  base:
    fontFamily: '"Geist", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0px"
  lg:
    fontFamily: '"Geist", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: "20px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0px"
  xl:
    fontFamily: '"Geist", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: "24px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0px"
  2xl:
    fontFamily: '"Geist", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: "32px"
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: "-0.02em"
  3xl:
    fontFamily: '"Geist", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: "40px"
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: "-0.02em"
  label:
    fontFamily: '"Geist", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: "12px"
    fontWeight: 500
    lineHeight: 1.5
    letterSpacing: "0.02em"
spacing:
  1: "4px"
  2: "8px"
  3: "12px"
  4: "16px"
  5: "20px"
  6: "24px"
  8: "32px"
  10: "40px"
  12: "48px"
  16: "64px"
rounded:
  none: "0px"
  sm: "4px"
  md: "8px"
  lg: "12px"
  xl: "16px"
  full: "9999px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: "{spacing.2} {spacing.4}"
    typography: "{typography.sm}"
  button-primary-hover:
    backgroundColor: "{colors.accent-hover}"
    textColor: "{colors.text-primary}"
  button-primary-press:
    backgroundColor: "{colors.accent-press}"
    textColor: "{colors.text-primary}"
  button-primary-focus:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.text-primary}"
  button-secondary:
    backgroundColor: "{colors.bg-elevated}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: "{spacing.2} {spacing.4}"
    typography: "{typography.sm}"
  button-secondary-hover:
    backgroundColor: "{colors.bg-hover}"
    textColor: "{colors.text-primary}"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.text-secondary}"
    rounded: "{rounded.md}"
    padding: "{spacing.2} {spacing.3}"
    typography: "{typography.sm}"
  button-ghost-hover:
    backgroundColor: "{colors.bg-hover}"
    textColor: "{colors.text-primary}"
  button-danger:
    backgroundColor: "{colors.danger}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: "{spacing.2} {spacing.4}"
    typography: "{typography.sm}"
  button-danger-hover:
    backgroundColor: "#DC2626"
    textColor: "{colors.text-primary}"
  input:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: "{spacing.2} {spacing.3}"
    typography: "{typography.sm}"
  input-hover:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.text-primary}"
  input-focus:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.text-primary}"
  input-error:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.text-primary}"
  label:
    textColor: "{colors.text-secondary}"
    typography: "{typography.label}"
  select:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: "{spacing.2} {spacing.3}"
    typography: "{typography.sm}"
  table:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.text-primary}"
    typography: "{typography.sm}"
  th:
    textColor: "{colors.text-muted}"
    backgroundColor: "{colors.primary}"
    typography: "{typography.xs}"
  td:
    textColor: "{colors.text-primary}"
    backgroundColor: "{colors.primary}"
    typography: "{typography.sm}"
  tr-hover:
    backgroundColor: "{colors.bg-hover}"
  badge:
    backgroundColor: "{colors.bg-elevated}"
    textColor: "{colors.text-muted}"
    rounded: "{rounded.full}"
    padding: "{spacing.1} {spacing.2}"
    typography: "{typography.xs}"
  badge-new:
    backgroundColor: "{colors.bg-elevated}"
    textColor: "{colors.text-muted}"
  badge-needs_documents:
    backgroundColor: "{colors.warning-subtle}"
    textColor: "{colors.warning}"
  badge-under_review:
    backgroundColor: "{colors.accent-subtle}"
    textColor: "{colors.accent}"
  badge-active:
    backgroundColor: "{colors.success-subtle}"
    textColor: "{colors.success}"
  badge-inactive:
    backgroundColor: "{colors.bg-elevated}"
    textColor: "{colors.text-muted}"
  badge-rejected:
    backgroundColor: "{colors.danger-subtle}"
    textColor: "{colors.danger}"
  badge-archived:
    backgroundColor: "{colors.bg-elevated}"
    textColor: "{colors.text-muted}"
  badge-possible_duplicate:
    backgroundColor: "{colors.warning-subtle}"
    textColor: "{colors.warning}"
  badge-reviewed_distinct:
    backgroundColor: "{colors.success-subtle}"
    textColor: "{colors.success}"
  badge-confirmed_duplicate:
    backgroundColor: "{colors.danger-subtle}"
    textColor: "{colors.danger}"
  card:
    backgroundColor: "{colors.bg-elevated}"
    rounded: "{rounded.lg}"
    padding: "{spacing.6}"
  modal:
    backgroundColor: "{colors.bg-elevated}"
    rounded: "{rounded.xl}"
    padding: "{spacing.6}"
  toast:
    backgroundColor: "{colors.bg-elevated}"
    rounded: "{rounded.md}"
    padding: "{spacing.3} {spacing.4}"
    typography: "{typography.sm}"
  toast-success:
    backgroundColor: "{colors.bg-elevated}"
  toast-error:
    backgroundColor: "{colors.bg-elevated}"
  toast-warning:
    backgroundColor: "{colors.bg-elevated}"
  nav-link:
    textColor: "{colors.text-secondary}"
    rounded: "{rounded.md}"
    padding: "{spacing.2} {spacing.3}"
    typography: "{typography.sm}"
  nav-link-hover:
    textColor: "{colors.text-primary}"
    backgroundColor: "{colors.bg-hover}"
  nav-link-active:
    textColor: "{colors.accent}"
    backgroundColor: "{colors.accent-subtle}"
    typography: "{typography.sm}"
  page-title:
    textColor: "{colors.text-primary}"
    typography: "{typography.2xl}"
  section-title:
    textColor: "{colors.text-primary}"
    typography: "{typography.lg}"
  form-field: {}
  form-hint:
    textColor: "{colors.text-muted}"
    typography: "{typography.xs}"
  form-error:
    textColor: "{colors.danger}"
    typography: "{typography.xs}"
  required-indicator:
    textColor: "{colors.danger}"
  kbd:
    backgroundColor: "{colors.bg-hover}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.sm}"
    padding: "1px {spacing.1}"
    typography: "{typography.xs}"
  code:
    backgroundColor: "{colors.bg-hover}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.sm}"
    padding: "1px {spacing.1}"
    typography: "{typography.xs}"
  link:
    textColor: "{colors.accent}"
    typography: "{typography.sm}"
  link-hover:
    textColor: "{colors.accent}"
  link-visited:
    textColor: "#A890E0"
---

## Overview

ROUTLAW is a regulated freight/carrier operations platform. The visual language communicates precision, trust, and operational clarity. Dark-mode native (matching the terminal/IDE environments our users live in). A single purple accent (`#6E56CF`) drives all interactive state — no decorative color.

## Colors

- **Primary (#0D0D0D):** Near-black void. Reduces eye strain during long dispatch sessions.
- **Elevated (#1A1A1A):** Cards, modals, dropdowns — one step up from void.
- **Border (#2A2A2A):** Hairline divisions. Subtle structure without weight.
- **Text Primary (#FFFFFF):** High-contrast reading. Meets WCAG AAA on void.
- **Text Secondary (#B3B3B3):** Labels, secondary content. 7:1 on void.
- **Text Muted (#8A8A8A):** Placeholders, disabled, meta. 4.5:1 minimum.
- **Accent (#6E56CF):** The only brand color. Buttons, links, focus rings, active states. 5.2:1 on void (WCAG AA large text, AAA normal).
- **Success/Warning/Danger:** Semantic only. Never decorative. Subtle variants for badges/backgrounds.

## Typography

**Geist** (geometric, compressed tracking) for UI — technical precision, excellent at small sizes. **Geist Mono** for codes, IDs, rates, DOT/MC numbers — tabular figures, unambiguous glyphs.

Scale is modular (4px base). Weights: 400/500/600/700 only. No light/medium confusion.

## Layout

8px base spacing unit. 4px/8px/12px/16px/24px/32px/48px/64px rhythm. Cards: 16px radius, 1px border. Modals: 16px radius, elevated shadow, backdrop blur.

## Components

**Buttons:** Three variants — primary (accent fill), secondary (border), ghost (text-only). All 36px minimum height (WCAG 2.5.8). Focus: 2px ring + 2px offset (WCAG 2.4.11).

**Inputs:** 40px minimum height. Border transitions to accent on focus. Error state: danger border + danger-subtle ring.

**Tables:** Sortable headers (cursor: pointer). Sticky header on scroll. Row hover = elevated bg. Mobile: horizontal scroll with sticky first column.

**Badges:** Pill shape, 20px minimum height. Status colors map 1:1 to DB ENUMs (carrier status, lead dup_status, load review_required).

**Modals:** Centered, max 480w, max 85vh, scrollable content. Focus trap. ESC closes.

**Toasts:** Bottom-right stack. Semantic left border. Auto-dismiss 4s (success), persistent (error).

**Navigation:** Mobile drawer (hamburger ≥44px). Desktop inline. Active state = accent text + subtle bg.

## Do's and Don'ts

**Do:**
- Use `button-primary` for the single high-emphasis action per view.
- Use `badge-*` that exactly matches the DB enum value.
- Keep interactive targets ≥44×44px on mobile.
- Set `data-theme` via external `theme-init.js` before paint.

**Don't:**
- Add decorative colors (gradients, second accents, illustrative palettes).
- Use `unsafe-inline` styles/scripts — CSP forbids it.
- Nest component variants (e.g., `button-primary.hover`); use sibling keys.
- Hardcode hex values in CSS — reference `{colors.*}` tokens.
- Ship `100vh` — use `100svh` / `100dvh`.
- Omit `sizes` when using `srcset` with `w` descriptors.