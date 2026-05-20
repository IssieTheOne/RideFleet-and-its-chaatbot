# admin-settings.css — Settings Page

Loaded on: `ridefleet-settings`

## Classes

### Page Shell
- `.rfb-settings-shell` — outer grid wrapper for the settings page
- `.rfb-settings-headline` — single-column header area

### Hero Card
- `.rfb-settings-hero-card` — gradient card at top of settings with plugin summary
- `.rfb-settings-hero-card h2`, `p` — hero typography

### Layout
- `.rfb-settings-pills` — flex wrapping row of feature/status pills
- `.rfb-settings-stack` — 2-column grid of setting section cards (min 320px per column)
- `.rfb-settings-section` — individual setting section (display:grid, gap)
- `.rfb-settings-section-head` — section title + description row
- `.rfb-settings-section-head h2`, `p` — section heading typography
- `.rfb-settings-inline-actions` — flex row for inline save/test buttons
- `.rfb-settings-inline-actions .button` — removes WP default button margin

### Setup & Diagnostics Tabs
- `.rfb-setup-list` — grid of setup checklist items
- `.rfb-setup-list div` — individual item: icon column + text, red background when incomplete
- `.rfb-setup-list div.is-done` — green background when complete
- `.rfb-setup-list strong` — status text (red/green)
- `.rfb-diagnostic-row` — flex row for each diagnostic check result
- `.rfb-diagnostic-row:last-child` — removes bottom border from final row
- `.rfb-danger-action` — top-margin wrapper around destructive action buttons

## Responsive
- `1000px` — settings-stack collapses to single column
