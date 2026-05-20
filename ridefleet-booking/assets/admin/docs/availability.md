# admin-availability.css — Availability Page

Loaded on: `ridefleet-availability`

## Classes

- `.rfb-availability-grid` — auto-fill grid of per-vehicle resource calendars (min 350px per column)
- `.rfb-resource-calendar` — individual vehicle calendar card (white, rounded, shadow)
- `.rfb-resource-calendar h3` — vehicle name heading with bottom border

Note: Calendar day cells inside availability reuse `.rfb-calendar-day` base styles from admin-calendar.css.
The availability page is listed after calendar in the enqueue order so calendar styles are available.

## Responsive
- `900px` — availability-grid min column drops to 250px
- `600px` — availability-grid collapses to single column
