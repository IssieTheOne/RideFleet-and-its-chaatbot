# admin-bookings.css — Bookings List, Detail & New Booking

Loaded on: `ridefleet-bookings` (covers list view, detail view, and manual booking form)

## Bookings List
- `.rfb-bookings-table` — borderless widefat table override
- `.rfb-bookings-table th` — uppercase muted column headers
- `.rfb-bookings-table td` — padded cells with vertical-middle alignment
- `.rfb-bookings-table td span` — secondary muted text beneath cell content
- `.rfb-dispatch-list` — auto-fit grid of dispatch cards
- `.rfb-dispatch-card` — individual dispatch card layout
- `.rfb-dispatch-card header` — flex header: booking number + badges
- `.rfb-dispatch-badges` — flex row of status badges
- `.rfb-dispatch-actions` — 4-col inline grid: status selects + action buttons
- `.rfb-pay-form` — inline-flex wrapper around Pay Now submit button

## Booking Detail
- `.rfb-booking-hero` — dark 4-column hero card: pickup / destination / total / actions
- `.rfb-booking-hero span`, `strong`, `p` — hero typography
- `.rfb-booking-hero .rfb-badge` — badge override inside dark hero
- `.rfb-workflow` — 4-step stepper row
- `.rfb-workflow div` — individual step box
- `.rfb-workflow strong` — numbered circle
- `.rfb-workflow .is-active strong` — active step circle (accent fill)
- `.rfb-booking-detail-page` — max-width and page-level overrides
- `.rfb-booking-detail-page .rfb-booking-hero` — detail-specific hero gradient and column sizing
- `.rfb-booking-detail-page .rfb-workflow` — detail stepper overrides
- `.rfb-booking-detail-page .rfb-workflow .is-active` — active step border/background
- `.rfb-booking-detail-grid` — 3-column responsive content grid
- `.rfb-detail-card` — base card with large border-radius
- `.rfb-detail-card h2`, `p` — card typography
- `.rfb-detail-card-route` — ride details card
- `.rfb-detail-card-dispatch` — dispatch workflow card; stacked forms with separator
- `.rfb-detail-card-dispatch select`, `input` — tall rounded inputs
- `.rfb-detail-card-customer` — customer card with subtle gradient background
- `.rfb-detail-card-notes` — notes card spanning 2 columns; timeline styling
- `.rfb-invoice-panel` — collapsible <details> invoice section
- `.rfb-invoice-panel summary` — flex header with status pill
- `.rfb-invoice-panel details[open] summary` — open state styling
- `.rfb-invoice-panel form` — padded form inside details
- `.rfb-invoice-panel input`, `textarea` — rounded inputs inside invoice form
- `.rfb-change-request-panel` — full-width amber-tinted change request section

## Manual Booking Form
- `.rfb-manual-booking > .rfb-settings-grid` — 2-column step grid
- `.rfb-manual-step` — individual step card (bordered, rounded)
- `.rfb-route-builder` — 2-column route + map layout
- `.rfb-route-preview` — map preview container with border
- `.rfb-route-preview .rfb-admin-map` — taller map inside route preview
- `.rfb-extra-checklist` — bordered grid of extra option checkboxes
- `.rfb-extra-checklist label` — individual extra row (flex, space-between)
- `.rfb-map-drawing` — dashed bordered container for polygon drawing UI

## Responsive
- `1200px` — hero collapses to 2 columns; detail grid collapses to 2 columns
- `1100px` — route-builder collapses to 1 column; dispatch-actions collapses to 1 column
- `782px` — hero, workflow, detail grid all go single column; dispatch-actions go single column
