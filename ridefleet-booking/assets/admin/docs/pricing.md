# admin-pricing.css — Pricing Rules & Core Rules Pages

Loaded on: `ridefleet-pricing-rules`, `ridefleet-core-rules`

## Core Rules Layout
- `.rfb-core-rules-grid` — 2-column grid for core rule sections (max-width 1280px)

## Flat-Rate Table
- `.rfb-flat-rate-table` — stacked grid of flat-rate rule rows
- `.rfb-flat-rate-actions` — flex row of add/import buttons above the table
- `.rfb-flat-rate-head` — 5-column column header row (hidden on narrow screens)
- `.rfb-flat-rate-row` — individual rule row: bordered, rounded, light background
- `.rfb-flat-rate-row label`, `.rfb-zone-tools` — stacked label+input within row cells
- `.rfb-flat-rate-row label span` — field label in teal uppercase

## Zone Tools
- `.rfb-zone-tools` — grid layout for zone coordinate/polygon inputs
- `.rfb-zone-tools textarea` — taller textarea for polygon coordinates

## Responsive
- `1100px` — core-rules-grid, flat-rate-head, flat-rate-row all collapse to single column;
  flat-rate-head is hidden
