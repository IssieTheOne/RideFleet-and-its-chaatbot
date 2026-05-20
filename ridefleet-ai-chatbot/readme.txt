=== RideFleet AI Chatbot ===
Contributors: ridefleet
Requires at least: 6.3
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

Customer-facing AI booking assistant for RideFleet Booking.

== Description ==
RideFleet AI Chatbot is a standalone companion plugin. It does not own pricing rules, geofences, dispatch settings, or schedules. It collects booking details conversationally, asks the core RideFleet Booking API for verified trip prices, submits confirmed reservations, and routes negotiated fares or booking edits into human approval workflows.

= Version 5-style operating model =

* Intent-first message classification before booking logic
* Explicit language switching and locked conversation language
* Google lookup only for confident location turns
* Human repair mode after repeated uncertainty
* Fare approval requests with original fare, proposed fare, and admin decision trail
* Change request status responses inside the chat
* Browser session continuity across refreshes
* Admin transcript metadata: language, intent, extracted fields, English summary

Security boundary:
* Pricing is fetched through GET /wp-json/taxi-booking/v1/calculate-price.
* Reservations are submitted through POST /wp-json/taxi-booking/v1/create-booking.
* No endpoint or service in this plugin can update core plugin settings, flat rates, geofences, or administrative configuration.

== Shortcode ==
[ridefleet_ai_chatbot]
