# admin-dashboard.css — Dashboard Page

Loaded on: `ridefleet-booking` (slug for the Dashboard page)

## Classes

- `.rfb-hero` — dark gradient banner at top of dashboard with headline and description
- `.rfb-hero h1`, `.rfb-hero p` — hero typography
- `.rfb-panel canvas` — chart canvas sizing (full-width, fixed 260px height)
- `.rfb-charts-grid .rfb-panel` — min-height and overflow for chart panels
- `.rfb-ops-grid` — 2-column asymmetric layout: main content + sidebar
- `.rfb-mini-stats` — 4-column row of compact stat blocks
- `.rfb-mini-stats .rfb-stat` — padding override for compact variant
- `.rfb-progress` — horizontal progress bar with gradient fill
- `.rfb-progress span` — the filled portion of the progress bar
- `.rfb-activity-list`, `.rfb-route-list` — stacked list of activity/route items
- `.rfb-activity-list a`, `.rfb-route-list div` — individual list item with 2-col grid
- `.rfb-activity-list em` — accent-coloured status text within activity items
- `.rfb-ops-tabs-wrap`, `.rfb-ops-tabs` — tab container used in operational overview

## Responsive
- `900px` — ops-grid, mini-stats, charts-grid collapse to single column; stat-grid goes 2-col
