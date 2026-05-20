# RideFleet

Two WordPress plugins that together power a complete taxi booking experience.

| Folder | What it does |
| --- | --- |
| [`ridefleet-booking/`](./ridefleet-booking) | The core booking plugin — pricing engine, geofenced service area, vehicles, extras, dispatcher, admin UI. |
| [`ridefleet-ai-chatbot/`](./ridefleet-ai-chatbot) | Customer-facing AI booking assistant. Talks to the core plugin's REST API only — never bypasses pricing or dispatch. |

## How they fit together

```
┌──────────────────────────┐         ┌────────────────────────────┐
│   ridefleet-ai-chatbot   │ ──REST▶ │      ridefleet-booking     │
│   (customer chat UX)     │         │   (pricing • dispatch • DB)│
└──────────────────────────┘         └────────────────────────────┘
            ▲                                       │
            └──────────── WP-Cron / Hooks ──────────┘
```

The chatbot is a thin, guard-railed front-end. The booking plugin owns the truth
(prices, geofences, vehicles, bookings). The chatbot only calls two
allow-listed endpoints:

- `GET /wp-json/taxi-booking/v1/calculate-price`
- `POST /wp-json/taxi-booking/v1/create-booking`

## Install

Drop both folders into `wp-content/plugins/` and activate them in the
WordPress admin. The booking plugin must be active and reachable for the
chatbot to function.

## Repository layout

This repo is rooted at `wp-content/plugins/`. The `.gitignore` excludes
every other WordPress plugin so only the two RideFleet folders are tracked.
