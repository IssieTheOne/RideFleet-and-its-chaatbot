# admin-calendar.css — Calendar Page

Loaded on: `ridefleet-calendar`

## Classes

- `.rfb-calendar-weekdays` — 7-column header row with day labels
- `.rfb-calendar-weekdays span` — individual day label (uppercase, accent colour)
- `.rfb-calendar-grid` — 7-column grid of day cells
- `.rfb-calendar-day` — individual day cell (min-height, border-radius, flex column)
- `.rfb-calendar-day:hover` — lift effect on hover
- `.rfb-calendar-day.rfb-day-available` — green-tinted available day
- `.rfb-calendar-day.rfb-day-busy` — red-tinted busy day
- `.rfb-calendar-day strong` — date number display
- `.rfb-day-status` — small status pill inside day cell
- `.rfb-calendar-day a` — booking link chip inside day cell
- `.rfb-calendar-day a:hover` — hover state for booking chip

### Admin-scoped calendar polish (`.rfb-admin .rfb-calendar-*`)
- `.rfb-admin .rfb-calendar-weekdays` — refined weekday header grid
- `.rfb-admin .rfb-calendar-weekdays span` — styled weekday chips
- `.rfb-admin .rfb-calendar-grid` — refined day grid
- `.rfb-admin .rfb-calendar-day` — taller day cells with padding
- `.rfb-admin .rfb-calendar-day.has-bookings` — accent border for days with bookings
- `.rfb-admin .rfb-calendar-empty` — transparent empty day filler
- `.rfb-admin .rfb-calendar-day > strong` — date number with weekday abbreviation
- `.rfb-admin .rfb-calendar-booking` — booking chip inside day (teal background)
- `.rfb-admin .rfb-calendar-booking b`, `span` — booking title and time text
- `.rfb-admin .rfb-calendar-booking.is-pending_payment` — amber variant for unpaid

## Responsive
- `900px` — calendar collapses to single-column list; empty cells hidden
- `720px` — weekdays and grid collapse to 1 column (from base styles)
