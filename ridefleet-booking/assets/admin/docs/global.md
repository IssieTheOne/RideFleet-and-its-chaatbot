# admin.css — Global Styles

Loaded on every RideFleet admin screen. Contains only components used across 2+ pages.

## CSS Variables
`.rfb-admin` — `--rfb-ink`, `--rfb-muted`, `--rfb-line`, `--rfb-panel`, `--rfb-accent`, `--rfb-gold`, `--rfb-blue`

## Layout
- `.rfb-admin` — max-width container
- `.rfb-page-title` — flex row: heading on left, primary action on right
- `.rfb-stat-grid` — 4-column responsive grid for stat cards
- `.rfb-settings-grid` — 2-column auto-fit grid for form sections
- `.rfb-charts-grid` — 2-column grid for chart panels (canvas sizing lives in admin-dashboard.css)

## Components
- `.rfb-stat` — KPI card with accent left-border strip
- `.rfb-panel` — white card with border, radius, subtle shadow
- `.rfb-card`, `.rfb-card-wide` — alternate card variant used in core-rules and settings

## Badges
- `.rfb-badge` — pill badge base
- `.rfb-badge-confirmed`, `.rfb-badge-paid`, `.rfb-badge-completed` — green
- `.rfb-badge-pending_payment`, `.rfb-badge-pending`, `.rfb-badge-unpaid`, `.rfb-badge-partially_paid` — amber
- `.rfb-badge-cancelled`, `.rfb-badge-failed`, `.rfb-badge-refunded` — red

## Typography
- `.rfb-kicker` — teal uppercase label above page titles
- `.rfb-eyebrow` — similar accent label inside cards

## Navigation
- `.rfb-tabs`, `.rfb-tabs a`, `.rfb-tabs a.is-active` — tab bar

## Forms
- `.rfb-settings-grid label`, inputs, selects, textareas — consistent form field styling
- `.rfb-checkbox` — flex checkbox + label
- `.rfb-inline-check` — inline checkbox variant
- `.rfb-field-grid`, `.rfb-mini-grid` — 2-column form field grids
- `.rfb-checklist` — disc list

## Utility
- `.rfb-filter-bar` — flex/grid filter bar with inputs and dropdowns
- `.rfb-filter-bar-labeled` — labeled variant with stacked label+input
- `.rfb-calendar-toolbar` — flex toolbar for calendar/availability date controls
- `.rfb-timeline` — stacked note/event list with accent left border
- `.rfb-pagination` — prev/next pagination row
- `.rfb-table-panel` — scrollable table wrapper
- `.rfb-endpoint-list` — wrapping flex list of code tags
- `.rfb-admin-map` — map embed container (div, not iframe)
- `.rfb-map-preview` — iframe map embed wrapper
- `.rfb-api-status-card` — API key status display box
- `.rfb-actions` — flex action button row

## Responsive breakpoints (global)
- `1100px` — stat-grid collapses to 2 columns
- `900px` — stat-grid stays 2 columns (ops-grid handled in dashboard)
- `782px` — page-title, pagination stack vertically; field-grid collapses
- `720px` — stat-grid collapses to 1 column
