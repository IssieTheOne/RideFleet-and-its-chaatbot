(function () {
	'use strict';

	// Debug logger: available only when explicitly enabled in widget settings.
	// Filter by "[RideFleet Chat]" in the console to isolate these entries.
	function rfacLog(label, data) {
		if (typeof console === 'undefined') { return; }
		if (!(window.RideFleetAIChatbot && window.RideFleetAIChatbot.debug)) { return; }
		var prefix = '%c[RideFleet Chat]';
		var style  = 'color:#0f766e;font-weight:700;';
		if (data !== undefined) {
			if (console.groupCollapsed) {
				console.groupCollapsed(prefix + ' ' + label, style);
				console.log(data);
				console.groupEnd();
			} else {
				console.log('[RideFleet Chat] ' + label, data);
			}
		} else {
			console.log(prefix + ' ' + label, style);
		}
	}

	function getStoredSessionId() {
		try {
			return window.localStorage ? String(window.localStorage.getItem('rfacSessionId') || '') : '';
		} catch (error) {
			return '';
		}
	}

	function setStoredSessionId(value) {
		window.rfacSessionId = value || '';
		try {
			if (window.localStorage) {
				if (value) {
					window.localStorage.setItem('rfacSessionId', value);
				} else {
					window.localStorage.removeItem('rfacSessionId');
				}
			}
		} catch (error) {}
	}

	function getSessionId() {
		if (!window.rfacSessionId) {
			window.rfacSessionId = getStoredSessionId();
		}
		return window.rfacSessionId || '';
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function formatBotText(text) {
		return escapeHtml(text)
			.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
			.replace(/\n/g, '<br>');
	}

	function appendMessage(container, text, role) {
		var item = document.createElement('div');
		item.className = 'rfac-message rfac-message-' + role;
		if (role === 'bot') {
			item.innerHTML = formatBotText(text);
		} else {
			item.textContent = text;
		}
		container.appendChild(item);
		container.scrollTop = container.scrollHeight;
	}

	function appendInfoCard(container, payload) {
		if (!payload || !payload.data) {
			return;
		}

		var data = payload.data;
		var collected = data.collected || {};
		var quote = data.quote || {};
		var booking = data.booking || {};
		// Show the quote card only at the final confirmation step and after booking —
		// the bot message itself already includes the price at confirm_price so no need to repeat it.
		var shouldRender = ['confirm_booking_details', 'complete', 'change_pending'].indexOf(payload.state) !== -1;
		if (!shouldRender) {
			return;
		}

		var card = document.createElement('div');
		card.className = 'rfac-info-card';
		var titles = {
			complete: 'Booking confirmed',
			change_pending: 'Change request pending'
		};
		var title = titles[payload.state] || 'Trip quote';
		var html = '<div class="rfac-info-title">' + escapeHtml(title) + '</div>';
		if (collected.pickup_address || collected.dropoff_address) {
			html += '<div class="rfac-route-line"><span>Pickup</span><strong>' + escapeHtml(collected.pickup_address || '—') + '</strong></div>';
			html += '<div class="rfac-route-line"><span>Drop-off</span><strong>' + escapeHtml(collected.dropoff_address || '—') + '</strong></div>';
		}
		if (collected.pickup_time) {
			html += '<div class="rfac-route-line"><span>Pickup time</span><strong>' + escapeHtml(formatPickupTime(collected.pickup_time)) + '</strong></div>';
		}
		if (collected.passengers || collected.luggage != null || collected.vehicle_name) {
			html += '<div class="rfac-route-line"><span>Passengers</span><strong>' + escapeHtml(String(collected.passengers || 1)) + '</strong></div>';
			html += '<div class="rfac-route-line"><span>Luggage</span><strong>' + escapeHtml(String(collected.luggage == null ? 0 : collected.luggage)) + '</strong></div>';
			if (collected.vehicle_name) {
				html += '<div class="rfac-route-line"><span>Vehicle</span><strong>' + escapeHtml(collected.vehicle_name) + '</strong></div>';
			}
		}
		if (collected.extras && collected.extras.length) {
			html += '<div class="rfac-route-line"><span>Extras</span><strong>' + escapeHtml(collected.extras.map(function (e) { return e.name || ('#' + e.id); }).join(', ')) + '</strong></div>';
		}
		if (collected.coupon_code) {
			html += '<div class="rfac-route-line"><span>Coupon</span><strong>' + escapeHtml(collected.coupon_code) + ' <em style="opacity:.7;font-weight:500;">(applied at dispatch)</em></strong></div>';
		}
		if (quote.final_price) {
			var pricingBadge = '';
			if (quote.pricing_type === 'flat_rate') {
				pricingBadge = ' <span class="rfac-badge rfac-badge--flat">🔒 Fixed</span>';
			} else if (quote.pricing_type === 'metered') {
				pricingBadge = ' <span class="rfac-badge rfac-badge--metered">~ Estimate</span>';
			}
			html += '<div class="rfac-price-line"><span>Verified fare' + pricingBadge + '</span><strong>' + escapeHtml(quote.currency || 'USD') + ' ' + Number(quote.final_price).toFixed(2) + '</strong></div>';
			if (quote.distance_km && quote.duration_minutes) {
				var dur = quote.duration_minutes;
				var h = Math.floor(dur / 60);
				var m = dur % 60;
				var durStr = h > 0 ? (h + 'h ' + String(m).padStart(2, '0') + 'min') : (m + 'min');
				html += '<div class="rfac-route-line rfac-route-line--muted"><span>Travel estimate</span><strong>' + durStr + ' · ' + Number(quote.distance_km).toFixed(0) + ' km</strong></div>';
			}
			// Addons breakdown
			var addons = quote.addons || {};
			if (addons.vehicle_adjustment > 0) {
				html += '<div class="rfac-route-line rfac-route-line--muted"><span>Vehicle surcharge</span><strong>+' + escapeHtml(quote.currency || 'USD') + ' ' + Number(addons.vehicle_adjustment).toFixed(2) + '</strong></div>';
			}
			if (addons.extras_total > 0) {
				html += '<div class="rfac-route-line rfac-route-line--muted"><span>Extras</span><strong>+' + escapeHtml(quote.currency || 'USD') + ' ' + Number(addons.extras_total).toFixed(2) + '</strong></div>';
			}
		}
		if (quote.requires_approval || (quote.service_area && (quote.service_area.pickup_allowed === false || quote.service_area.dropoff_allowed === false))) {
			var sa = quote.service_area || {};
			var which = (sa.pickup_allowed === false && sa.dropoff_allowed === false) ? 'both endpoints' : (sa.pickup_allowed === false ? 'pickup' : (sa.dropoff_allowed === false ? 'drop-off' : 'route'));
			html += '<div class="rfac-warning-line">⚠ ' + escapeHtml(which) + ' outside standard service area — dispatch will approve manually.</div>';
		}
		if (booking.id) {
			html += '<div class="rfac-route-line rfac-copy-row" data-rfac-copy="' + escapeHtml(booking.id) + '" title="Tap to copy booking reference">' +
				'<span>Booking ID</span>' +
				'<strong class="rfac-copy-value">' + escapeHtml(booking.id) + ' <span class="rfac-copy-icon">⎘</span></strong>' +
				'</div>';
		}
		if (booking.changeRequestId) {
			html += '<div class="rfac-route-line"><span>Request</span><strong>#' + escapeHtml(String(booking.changeRequestId)) + ' awaiting admin approval</strong></div>';
		}
		if (booking.proposedPrice) {
			html += '<div class="rfac-route-line"><span>Proposed fare</span><strong>' + escapeHtml(quote.currency || 'USD') + ' ' + Number(booking.proposedPrice).toFixed(2) + '</strong></div>';
		}
		card.innerHTML = html;

		// Tap-to-copy booking reference
		var copyRow = card.querySelector('[data-rfac-copy]');
		if (copyRow) {
			copyRow.style.cursor = 'pointer';
			copyRow.addEventListener('click', function () {
				var text = copyRow.getAttribute('data-rfac-copy') || '';
				var icon = copyRow.querySelector('.rfac-copy-icon');
				var copied = false;
				function showCopied() {
					if (copied) { return; }
					copied = true;
					if (icon) { icon.textContent = '✓'; }
					copyRow.classList.add('rfac-copy-row--done');
					setTimeout(function () {
						if (icon) { icon.textContent = '⎘'; }
						copyRow.classList.remove('rfac-copy-row--done');
						copied = false;
					}, 2000);
				}
				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText(text).then(showCopied).catch(function () {
						// Fallback for clipboard API failure
						try { document.execCommand('copy'); showCopied(); } catch (e) {}
					});
				} else {
					// Legacy fallback
					var ta = document.createElement('textarea');
					ta.value = text;
					ta.style.position = 'fixed';
					ta.style.opacity = '0';
					document.body.appendChild(ta);
					ta.focus();
					ta.select();
					try { document.execCommand('copy'); showCopied(); } catch (e) {}
					document.body.removeChild(ta);
				}
			});
		}

		// For the booking-confirmed state, append "Add to calendar" buttons inside the card.
		if (payload.state === 'complete') {
			var calBtns = buildCalendarButtons(collected, booking);
			if (calBtns) {
				card.appendChild(calBtns);
			}
		}
		container.appendChild(card);
		container.scrollTop = container.scrollHeight;
	}

	function appendActionCard(container, payload, closeWidget, sendText) {
		if (!payload || !payload.data || payload.data.ui_action !== 'offer_close') {
			return;
		}

		var card = document.createElement('div');
		card.className = 'rfac-action-card';
		card.innerHTML = '<div class="rfac-info-title">What next?</div><div class="rfac-action-row"></div>';

		var row = card.querySelector('.rfac-action-row');
		var closeButton = document.createElement('button');
		closeButton.type = 'button';
		closeButton.className = 'rfac-action-button';
		closeButton.textContent = 'Close chat';
		closeButton.addEventListener('click', closeWidget);

		var collected = (payload.data && payload.data.collected) || {};
		var hasRoute = collected.pickup_address && collected.dropoff_address;

		var returnButton = document.createElement('button');
		returnButton.type = 'button';
		returnButton.className = 'rfac-action-button rfac-action-button-primary';
		returnButton.textContent = 'Book return trip';
		returnButton.style.display = hasRoute ? '' : 'none';
		returnButton.addEventListener('click', function () {
			sendText('I would like to book the return trip');
			card.remove();
		});

		var newButton = document.createElement('button');
		newButton.type = 'button';
		newButton.className = 'rfac-action-button';
		newButton.textContent = 'New booking';
		newButton.addEventListener('click', function () {
			sendText('new booking');
			card.remove();
		});

		row.appendChild(closeButton);
		if (hasRoute) { row.appendChild(returnButton); }
		row.appendChild(newButton);
		container.appendChild(card);
		container.scrollTop = container.scrollHeight;
	}

	function appendPlaceCard(container, predictions, onSelect) {
		var existing = container.querySelector('[data-rfac-place-card-active="1"]');
		if (existing) {
			existing.remove();
		}

		if (!predictions || !predictions.length) {
			return;
		}

		var card = document.createElement('div');
		card.className = 'rfac-place-card';
		card.setAttribute('data-rfac-place-card-active', '1');
		card.innerHTML = '<div class="rfac-place-title">Suggestions</div>';

		var slice = predictions.slice(0, 5);
		slice.forEach(function (pred) {
			var main = pred.main_text || pred.description || pred.structured_formatting && pred.structured_formatting.main_text || '';
			var secondary = pred.secondary_text || pred.structured_formatting && pred.structured_formatting.secondary_text || '';
			var full = pred.description || main;
			var placeMeta = {
				place_id: pred.place_id || '',
				description: pred.description || full || main,
				main_text: main,
				secondary_text: secondary,
				types: pred.types || []
			};

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'rfac-place-option';
			btn.innerHTML = '<strong>' + escapeHtml(main) + '</strong>' + (secondary ? '<span>' + escapeHtml(secondary) + '</span>' : '');
			btn.addEventListener('click', function () {
				card.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
				onSelect(full || main, placeMeta);
			});
			card.appendChild(btn);
		});

		container.appendChild(card);
		container.scrollTop = container.scrollHeight;
	}

	var rfacCurrentState = 'greeting';

	function rfacMessagesEl() {
		return document.querySelector('[data-rfac-messages]') || document.getElementById('rfac-messages');
	}

	function rfacScrollToBottom() {
		var m = rfacMessagesEl();
		if (m) { m.scrollTop = m.scrollHeight; }
	}

	function showTypingIndicator() {
		hideTypingIndicator();
		var msgs = rfacMessagesEl();
		if (!msgs) return;
		var d = document.createElement('div');
		d.className = 'rfac-message rfac-message-bot rfac-typing-indicator';
		d.id = 'rfac-typing';
		d.innerHTML = '<span></span><span></span><span></span>';
		msgs.appendChild(d);
		rfacScrollToBottom();
	}
	function hideTypingIndicator() {
		var el = document.getElementById('rfac-typing');
		if (el) el.remove();
	}

	function scheduleIdleNudge(state) {
		clearTimeout(window._rfacIdleTimer);
		// Only nudge when the widget is open but the user hasn't started typing an address yet.
		// Any state past capture_pickup means a real booking is underway — never interrupt it.
		var abandonedStates = ['greeting', 'capture_pickup'];
		if (abandonedStates.indexOf(state) === -1) return;
		window._rfacIdleTimer = setTimeout(function() {
			if (abandonedStates.indexOf(rfacCurrentState) === -1) return;
			var nudges = [
				'Still looking for a taxi? Just drop your pickup address and I\'ll get you a price in seconds.',
				'Need a ride? Send me your pickup location and I\'ll find you a fare straight away.',
				'I\'m here whenever you\'re ready — just share where you\'d like to be picked up.'
			];
			var msgs = rfacMessagesEl();
			if (!msgs) return;
			var item = document.createElement('div');
			item.className = 'rfac-message rfac-message-bot';
			item.textContent = nudges[Math.floor(Math.random() * nudges.length)];
			msgs.appendChild(item);
			rfacScrollToBottom();
		}, 180000);
	}

	/**
	 * Build the "Add to calendar" section used inside the booking-confirmed info card.
	 * Returns a DOM element ready to append, or null if pickup_time is absent/unparseable.
	 */
	function buildCalendarButtons(collected, booking) {
		collected = collected || {};
		booking   = booking   || {};
		var pickup     = String(collected.pickup_address  || booking.pickup_address  || '');
		var dropoff    = String(collected.dropoff_address || booking.dropoff_address || '');
		var pickupTime = String(collected.pickup_time     || booking.pickup_time     || '');
		var bookingId  = String(booking.id || booking.booking_id || '');
		if (!pickup || !pickupTime) return null;
		var dt;
		try {
			dt = new Date(pickupTime.replace(' ', 'T'));
			if (isNaN(dt.getTime())) return null;
		} catch (e) { return null; }
		var end = new Date(dt.getTime() + 3600000);

		function fmtGcal(d) { return d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z'; }
		function fmtIcs(d)  { return d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z'; }

		var title   = 'Taxi: ' + pickup + (dropoff ? ' → ' + dropoff : '');
		var details = 'RideFleet booking' + (bookingId ? ' #' + bookingId : '') + '.';

		// Google Calendar deep-link
		var gcalUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
			+ '&text='     + encodeURIComponent(title)
			+ '&dates='    + encodeURIComponent(fmtGcal(dt) + '/' + fmtGcal(end))
			+ '&details='  + encodeURIComponent(details)
			+ '&location=' + encodeURIComponent(pickup);

		// Apple / iCal — generate .ics blob
		var icsLines = [
			'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//RideFleet//Chatbot//EN',
			'BEGIN:VEVENT',
			'UID:rfac-' + bookingId + '-' + Date.now() + '@ridefleet',
			'DTSTAMP:'  + fmtIcs(new Date()),
			'DTSTART:'  + fmtIcs(dt),
			'DTEND:'    + fmtIcs(end),
			'SUMMARY:'  + title.replace(/,/g, '\\,'),
			'DESCRIPTION:' + details.replace(/,/g, '\\,'),
			'LOCATION:' + pickup.replace(/,/g, '\\,'),
			'END:VEVENT', 'END:VCALENDAR'
		].join('\r\n');
		var blob   = new Blob([icsLines], {type: 'text/calendar;charset=utf-8'});
		var icsUrl = URL.createObjectURL(blob);

		var section = document.createElement('div');
		section.className = 'rfac-calendar-section';

		var label = document.createElement('div');
		label.className = 'rfac-calendar-section__label';
		label.textContent = 'Add to calendar';
		section.appendChild(label);

		var row = document.createElement('div');
		row.className = 'rfac-calendar-section__row';

		var gcal = document.createElement('a');
		gcal.href = gcalUrl;
		gcal.target = '_blank';
		gcal.rel = 'noopener noreferrer';
		gcal.className = 'rfac-calendar-link rfac-calendar-link--google';
		// Google Calendar — multi-colour G logo
		gcal.innerHTML = '<svg width="16" height="16" viewBox="0 0 48 48" aria-hidden="true" style="flex-shrink:0">'
			+ '<path fill="#4285F4" d="M47.5 24.5c0-1.5-.1-3-.4-4.5H24v8.5h13.2C36.5 32 34.5 35 31.2 37v6h8.7C44.7 38.5 47.5 32 47.5 24.5z"/>'
			+ '<path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-8.7-6c-2.1 1.4-4.8 2.3-7.2 2.3-5.5 0-10.2-3.7-11.8-8.7H3.2v6.2C7.2 42.5 15 48 24 48z"/>'
			+ '<path fill="#FBBC05" d="M12.2 29.8A12 12 0 0 1 12 28c0-.6.1-1.2.2-1.8v-6.2H3.2A24 24 0 0 0 0 28c0 3.9.9 7.5 2.5 10.7l9.7-8.9z"/>'
			+ '<path fill="#EA4335" d="M24 9.5c3.1 0 5.9 1.1 8.1 3.2l6.4-6.4C34.8 2.5 29.7 0 24 0 15 0 7.2 5.5 3.2 13.3l9 6.2C13.8 13.2 18.5 9.5 24 9.5z"/>'
			+ '</svg> Google Calendar';

		var ical = document.createElement('a');
		ical.href = icsUrl;
		ical.download = 'ride-' + (bookingId || 'booking') + '.ics';
		ical.className = 'rfac-calendar-link rfac-calendar-link--apple';
		// Apple Calendar — monochrome calendar icon
		ical.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" style="flex-shrink:0">'
			+ '<rect x="3" y="4" width="18" height="17" rx="2" ry="2" fill="none" stroke="currentColor" stroke-width="2"/>'
			+ '<line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2"/>'
			+ '<line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2"/>'
			+ '<line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>'
			+ '</svg> Apple Calendar';

		row.appendChild(gcal);
		row.appendChild(ical);
		section.appendChild(row);
		return section;
	}

	function maybeShowPaymentLink(data) {
		if (!data) return;
		var payUrl = (data.payment_url) || '';
		if (!payUrl) return;

		var msgs = rfacMessagesEl();
		if (!msgs) return;

		var ex = document.getElementById('rfac-payment-link');
		if (ex) ex.remove();

		var wrap = document.createElement('div');
		wrap.id = 'rfac-payment-link';
		wrap.style.cssText = 'display:flex;margin:6px 12px 4px;';

		var btn = document.createElement('a');
		btn.href = payUrl;
		btn.target = '_blank';
		btn.rel = 'noopener noreferrer';
		btn.className = 'rfac-calendar-link';
		btn.style.cssText = 'background:#0f766e;color:#fff;border-radius:8px;padding:8px 16px;font-weight:700;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;';
		btn.textContent = '💳 Pay now';

		wrap.appendChild(btn);
		msgs.appendChild(wrap);
		rfacScrollToBottom();
	}

	function debounce(fn, ms) {
		var timer;
		var debounced = function () {
			var args = arguments;
			var ctx = this;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(ctx, args);
			}, ms);
		};
		debounced.cancel = function () { clearTimeout(timer); };
		return debounced;
	}

	var PLACE_STATES = ['capture_pickup', 'confirm_pickup_city', 'capture_dropoff', 'confirm_dropoff_city'];

	var CONVERSATIONAL_RE = /^(hi+|hey+|hello|howdy|yo|sup|greetings|good\s+(morning|afternoon|evening|night)|how|what|where|when|why|who|whose|which|do|does|did|are|am|is|was|were|can|could|will|would|should|may|might|i'?m|i\s+am|me|my|thanks|thank|cheers|yes|yeah|yep|ok|okay|sure|fine|no|nope|maybe|help|sorry|please|cancel|stop|wait|bye|goodbye|test|testing|ping)\b/i;

	/**
	 * Formats a stored "YYYY-MM-DD HH:MM:SS" pickup time into a readable string.
	 * e.g. "2026-05-23 21:00:00" → "Saturday, May 23 2026 at 9:00 PM"
	 */
	function formatPickupTime(raw) {
		if (!raw) { return ''; }
		// Replace space with T so Date() parses it correctly in all browsers.
		var d = new Date(String(raw).replace(' ', 'T'));
		if (isNaN(d.getTime())) { return raw; }
		try {
			return d.toLocaleString(undefined, {
				weekday: 'long', year: 'numeric', month: 'long',
				day: 'numeric', hour: 'numeric', minute: '2-digit'
			});
		} catch (e) {
			return raw;
		}
	}

	function looksLikeAddress(text) {
		var t = String(text || '').trim();
		if (t.length < 4) {
			return false;
		}
		if (t.indexOf('?') !== -1) {
			return false;
		}
		if (CONVERSATIONAL_RE.test(t)) {
			return false;
		}
		// Address-ish signals: a digit, a comma, "street/avenue/road/airport/station/hotel" keyword, or a capitalized word that is not the first word.
		var hasDigit = /\d/.test(t);
		var hasComma = t.indexOf(',') !== -1;
		var hasPlaceKeyword = /\b(street|st\.?|avenue|ave\.?|road|rd\.?|boulevard|blvd\.?|lane|ln\.?|drive|dr\.?|way|square|sq\.?|plaza|airport|station|gare|terminal|hotel|hospital|university|park|center|centre|mall|cathedral|church|museum)\b/i.test(t);
		var capitalProperNoun = /\b[A-Z][a-z]{2,}\b/.test(t);
		return hasDigit || hasComma || hasPlaceKeyword || capitalProperNoun;
	}

	function init(widget) {
		var config = window.RideFleetAIChatbot || {};
		var endpoint = config.endpoint || widget.getAttribute('data-rfac-endpoint');
		var placesEndpoint = config.placesEndpoint || widget.getAttribute('data-rfac-places-endpoint') || '';
		var placeDetailsEndpoint = config.placeDetailsEndpoint || widget.getAttribute('data-rfac-place-details-endpoint') || '';
		var nonce = config.nonce || widget.getAttribute('data-rfac-nonce') || '';
		var bubble = widget.querySelector('.rfac-bubble');
		var windowEl = widget.querySelector('.rfac-window');
		var close = widget.querySelector('.rfac-close');
		var reset = widget.querySelector('[data-rfac-reset]');
		var form = widget.querySelector('[data-rfac-form]');
		var input = widget.querySelector('[data-rfac-input]');
		var messages = widget.querySelector('[data-rfac-messages]');
		var typing = widget.querySelector('[data-rfac-typing]');
		var typingLabel = widget.querySelector('[data-rfac-typing-label]');
		var sendBtn = form ? form.querySelector('[type="submit"]') : null;
		var progress = widget.querySelectorAll('.rfac-progress .rfac-progress-step');
		var currentState = 'capture_pickup';
		var lastPayload  = null; // holds the most recent server response for use inside setState
		var closeTimer;
		var requestInFlight = false;
		var rfacExtrasCache = null; // cached extras from booking API
		var rfacPendingExtras = []; // client-side multi-select for extras
		var fetchInFlight = false; // true while an autocomplete fetch is in-flight
		var autocompleteFetchAbort = null; // AbortController for the current autocomplete XHR
		// Geographic bias: store confirmed pickup coordinates so dropoff search is biased toward them.
		var pickupCoords = null; // { lat, lng } — set when pickup place is confirmed

		function generateMsgId() {
			try {
				return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, function(c) {
					return (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16);
				});
			} catch(e) {
				return Date.now().toString(36) + Math.random().toString(36).slice(2);
			}
		}

		function openWidget() {
			clearTimeout(closeTimer);
			windowEl.hidden = false;
			windowEl.style.display = '';
			windowEl.classList.remove('is-closed', 'is-closing');
			windowEl.classList.add('is-entering');
			bubble.hidden = true;
			bubble.style.display = 'none';
			setTimeout(function () {
				windowEl.classList.remove('is-entering');
			}, 260);
			rfacLog('Widget opened', { state: currentState, session_id: getSessionId() || '(new)' });
			input.focus();
		}

		function closeWidget() {
			windowEl.classList.remove('is-entering');
			windowEl.classList.add('is-closing');
			bubble.hidden = false;
			bubble.style.display = '';
			closeTimer = setTimeout(function () {
				windowEl.hidden = true;
				windowEl.style.display = 'none';
				windowEl.classList.remove('is-closing');
				windowEl.classList.add('is-closed');
			}, 180);
		}

		function trapFocus(event) {
			if (windowEl.hidden || event.key !== 'Tab') {
				return;
			}
			var focusable = windowEl.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
			if (!focusable.length) return;
			var first = focusable[0];
			var last = focusable[focusable.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		}

		document.addEventListener('keydown', function (event) {
			if (windowEl.hidden) return;
			if (event.key === 'Escape') {
				event.preventDefault();
				closeWidget();
				bubble.focus();
			} else {
				trapFocus(event);
			}
		});

		bubble.addEventListener('click', function (event) {
			event.preventDefault();
			openWidget();
		});

		close.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			closeWidget();
		});

		if (reset) {
			reset.addEventListener('click', function (event) {
				event.preventDefault();
				if (!window.confirm('Start a new conversation? Your current chat will be cleared.')) {
					return;
				}
				setStoredSessionId('');
				while (messages.firstChild) {
					messages.removeChild(messages.firstChild);
				}
				var defaultGreeting = messages.getAttribute('data-rfac-default-greeting') || 'Hi! How can I help?';
				// Override greeting based on browser language
				var browserLang = (navigator.language || navigator.userLanguage || 'en').substring(0, 2).toLowerCase();
				if (browserLang === 'fr' && defaultGreeting) {
					defaultGreeting = 'Bonjour ! Je peux réserver votre taxi. Où dois-je vous prendre en charge ?';
				} else if (browserLang === 'nl' && defaultGreeting) {
					defaultGreeting = 'Hallo! Ik kan uw taxi boeken. Waar moeten we u ophalen?';
				}
				var greeting = defaultGreeting;
				var item = document.createElement('div');
				item.className = 'rfac-message rfac-message-bot';
				item.textContent = greeting;
				messages.appendChild(item);
				typing.hidden = true;
				input.value = '';
				input.disabled = false;
				pickupCoords = null; // reset geographic bias for new conversation
				setState('capture_pickup');
				input.focus();
			});
		}

		function appendDateTimeHelper(container, avail) {
			var old = container.querySelector('[data-rfac-dt-helper]');
			if (old) { old.remove(); }

			// ── Availability constraints (from booking plugin, read-only here) ──
			var av              = avail || {};
			var minAdvHours     = typeof av.min_advance_hours === 'number' ? av.min_advance_hours : 2;
			var maxDays         = typeof av.max_booking_days  === 'number' ? av.max_booking_days  : 90;
			var bhEnabled       = !!av.business_hours_enabled;
			var businessHours   = av.business_hours  || {};
			var blockedRanges   = av.blocked_ranges  || [];

			var now         = new Date();
			var earliestMs  = now.getTime() + minAdvHours * 3600000;
			var earliestDt  = new Date(earliestMs);
			var pad         = function(n) { return String(n).padStart(2, '0'); };
			var fmtDate     = function(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); };
			var todayStr    = fmtDate(now.getFullYear(), now.getMonth() + 1, now.getDate());
			var earliestDay = fmtDate(earliestDt.getFullYear(), earliestDt.getMonth() + 1, earliestDt.getDate());
			var latestDt    = new Date(now.getTime() + maxDays * 86400000);
			var latestStr   = fmtDate(latestDt.getFullYear(), latestDt.getMonth() + 1, latestDt.getDate());

			// ── Date constraint helpers ─────────────────────────────
			function isBlocked(ds) {
				for (var i = 0; i < blockedRanges.length; i++) {
					if (ds >= blockedRanges[i].start && ds <= blockedRanges[i].end) { return true; }
				}
				return false;
			}
			function isClosedDay(ds) {
				if (!bhEnabled) { return false; }
				var p = ds.split('-'), d = new Date(+p[0], +p[1]-1, +p[2]);
				var h = businessHours[d.getDay()];
				return !h || !h.enabled;
			}
			function isDayDisabled(ds) {
				return ds < earliestDay || ds > latestStr || isBlocked(ds) || isClosedDay(ds);
			}
			function getHoursForDate(ds) {
				if (!bhEnabled) { return { openMins: 6*60, closeMins: 22*60 }; }
				var p = ds.split('-'), d = new Date(+p[0], +p[1]-1, +p[2]);
				var h = businessHours[d.getDay()];
				if (!h || !h.enabled) { return null; }
				var mins = function(t) { var s=String(t||'').split(':'); return (+s[0]||0)*60+(+s[1]||0); };
				return { openMins: mins(h.open), closeMins: mins(h.close) };
			}

			// ── Widget scaffold ─────────────────────────────────────
			var helper = document.createElement('div');
			helper.setAttribute('data-rfac-dt-helper', '1');
			helper.className = 'rfac-dt-helper';

			// Date summary strip (shown after a day is picked)
			var summaryEl = document.createElement('div');
			summaryEl.className = 'rfac-dt-summary';
			summaryEl.style.display = 'none';
			helper.appendChild(summaryEl);

			var selectedDate = earliestDay;
			var calYear  = earliestDt.getFullYear();
			var calMonth = earliestDt.getMonth();

			var MONTHS = ['January','February','March','April','May','June',
			              'July','August','September','October','November','December'];
			var DOW    = ['Mo','Tu','We','Th','Fr','Sa','Su'];
			var DAYS   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

			function updateSummary() {
				if (!selectedDate) { summaryEl.style.display = 'none'; return; }
				var p = selectedDate.split('-'), d = new Date(+p[0], +p[1]-1, +p[2]);
				summaryEl.textContent = '📅 ' + DAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ' ' + p[0];
				summaryEl.style.display = 'block';
			}

			// ── Time row ────────────────────────────────────────────
			var timeRowEl = null;

			function renderTimeRow() {
				if (timeRowEl) { timeRowEl.remove(); timeRowEl = null; }
				if (!selectedDate) { return; }
				var hours = getHoursForDate(selectedDate);
				if (!hours) { return; }

				var startMins;
				if (selectedDate === earliestDay) {
					var minOnDay  = earliestDt.getHours() * 60 + earliestDt.getMinutes();
					startMins = Math.max(hours.openMins, Math.ceil(minOnDay / 30) * 30);
				} else {
					startMins = Math.ceil(hours.openMins / 30) * 30;
				}

				var slots = [];
				for (var m = startMins; m <= hours.closeMins && slots.length < 24; m += 30) {
					var hh = Math.floor(m / 60), mm = m % 60;
					slots.push({ label: (hh % 12 || 12) + ':' + pad(mm) + (hh < 12 ? ' AM' : ' PM'), value: pad(hh) + ':' + pad(mm) });
				}
				if (!slots.length) { return; }

				timeRowEl = document.createElement('div');
				timeRowEl.className = 'rfac-dt-row rfac-time-row';
				var lbl = document.createElement('span');
				lbl.className = 'rfac-dt-section-label';
				lbl.textContent = 'Pick a time';
				timeRowEl.appendChild(lbl);

				slots.forEach(function(slot) {
					var btn = document.createElement('button');
					btn.type = 'button'; btn.className = 'rfac-dt-chip';
					btn.textContent = slot.label;
					btn.addEventListener('click', function() {
						var val = selectedDate + 'T' + slot.value;
						input.value = val;
						rfacLog('⏰ Datetime selected', { datetime: val });
						sendText(val);
					});
					timeRowEl.appendChild(btn);
				});
				helper.appendChild(timeRowEl);
				container.scrollTop = container.scrollHeight;
			}

			// ── Calendar ────────────────────────────────────────────
			var calEl = null;

			function renderCalendar() {
				if (calEl) { calEl.remove(); }
				calEl = document.createElement('div');
				calEl.className = 'rfac-cal';

				var header = document.createElement('div');
				header.className = 'rfac-cal-header';

				var isEarliestMo = (calYear === earliestDt.getFullYear() && calMonth === earliestDt.getMonth());
				var prevBtn = document.createElement('button');
				prevBtn.type = 'button'; prevBtn.className = 'rfac-cal-nav';
				prevBtn.innerHTML = '&#8249;'; prevBtn.disabled = isEarliestMo;
				prevBtn.addEventListener('click', function() {
					calMonth--; if (calMonth < 0) { calMonth = 11; calYear--; }
					renderCalendar();
				});

				var titleEl = document.createElement('span');
				titleEl.className = 'rfac-cal-title';
				titleEl.textContent = MONTHS[calMonth] + ' ' + calYear;

				var nextMonFirst = fmtDate(calMonth === 11 ? calYear+1 : calYear, (calMonth+1)%12+1, 1);
				var nextBtn = document.createElement('button');
				nextBtn.type = 'button'; nextBtn.className = 'rfac-cal-nav';
				nextBtn.innerHTML = '&#8250;'; nextBtn.disabled = nextMonFirst > latestStr;
				nextBtn.addEventListener('click', function() {
					calMonth++; if (calMonth > 11) { calMonth = 0; calYear++; }
					renderCalendar();
				});

				header.appendChild(prevBtn); header.appendChild(titleEl); header.appendChild(nextBtn);
				calEl.appendChild(header);

				var dowRow = document.createElement('div');
				dowRow.className = 'rfac-cal-dow';
				DOW.forEach(function(d) { var c = document.createElement('span'); c.textContent = d; dowRow.appendChild(c); });
				calEl.appendChild(dowRow);

				var daysGrid = document.createElement('div');
				daysGrid.className = 'rfac-cal-days';
				var firstDow = new Date(calYear, calMonth, 1).getDay();
				var offset   = (firstDow + 6) % 7;
				var daysInMo = new Date(calYear, calMonth + 1, 0).getDate();

				for (var i = 0; i < offset; i++) {
					var emp = document.createElement('span'); emp.className = 'rfac-cal-empty'; daysGrid.appendChild(emp);
				}
				for (var day = 1; day <= daysInMo; day++) {
					(function(d) {
						var ds       = fmtDate(calYear, calMonth + 1, d);
						var disabled = isDayDisabled(ds);
						var blocked  = isBlocked(ds);
						var closed   = isClosedDay(ds);
						var cell     = document.createElement('button');
						cell.type = 'button'; cell.textContent = d;
						cell.className = 'rfac-cal-day' +
							(ds < earliestDay || ds > latestStr ? ' is-past' : '') +
							(blocked   ? ' is-blocked'  : '') +
							(closed    ? ' is-closed'   : '') +
							(ds === todayStr     ? ' is-today'    : '') +
							(ds === selectedDate ? ' is-selected' : '');
						cell.disabled = disabled;
						if (blocked) { cell.title = 'Not available'; }
						if (!disabled) {
							cell.addEventListener('click', function() {
								selectedDate = ds;
								updateSummary(); renderCalendar(); renderTimeRow();
							});
						}
						daysGrid.appendChild(cell);
					})(day);
				}
				calEl.appendChild(daysGrid);
				helper.insertBefore(calEl, summaryEl.nextSibling || null);
			}

			// ── ASAP chip ────────────────────────────────────────────
			var asapRow = document.createElement('div');
			asapRow.className = 'rfac-dt-row rfac-asap-row';
			var asapBtn = document.createElement('button');
			asapBtn.type = 'button';
			asapBtn.className = 'rfac-dt-chip rfac-asap-chip';
			// label() is scoped inside appendQuickReplies — use a local resolver here
			var _dtLang = (lastPayload && lastPayload.data && lastPayload.data.collected && lastPayload.data.collected.language) || (window.rfacData && window.rfacData.lang) || 'en';
			asapBtn.textContent = '🚕 ' + (_dtLang === 'nl' ? 'Zo snel mogelijk' : (_dtLang === 'fr' ? 'Dès que possible' : 'As soon as possible'));
			asapBtn.addEventListener('click', function() { rfacLog('⚡ ASAP'); sendText('asap'); });
			asapRow.appendChild(asapBtn);
			helper.appendChild(asapRow);

			renderCalendar();
			updateSummary();
			renderTimeRow();

			container.appendChild(helper);
			container.scrollTop = container.scrollHeight;
		}

		function setState(state) {
			var prevState = currentState;
			currentState = state || currentState;
			if (prevState !== currentState) {
				rfacLog('State → ' + currentState, { from: prevState, to: currentState });
				// When leaving a place-capture state, always restore the send button and kill any pending fetch.
				if (PLACE_STATES.indexOf(prevState) !== -1 && PLACE_STATES.indexOf(currentState) === -1) {
					fetchInFlight = false;
					setSendBlocked(false);
				}
			}
			// Block the send button immediately on entering any address-capture state.
			// The user must select from Google Places autocomplete — free-text send bypasses
			// geocoding and will cause the quote API to fail with "missing coordinates".
			if (PLACE_STATES.indexOf(currentState) !== -1) {
				setSendBlocked(true);
			}
			var phoneMode = currentState === 'capture_phone';
			var emailMode = currentState === 'capture_email';
			var timeMode = currentState === 'capture_pickup_time';

			if (phoneMode) {
				input.type = 'tel';
				input.placeholder = 'e.g. +1 802 555 0100';
			} else if (emailMode) {
				input.type = 'email';
				input.placeholder = 'your@email.com (or type skip)';
			} else if (timeMode) {
				input.type = 'datetime-local';
				input.placeholder = '';
				input.min = new Date(Date.now() + 5 * 60000).toISOString().slice(0, 16);
				// Inject date/time shortcut chips below the message list
				appendDateTimeHelper(messages, lastPayload && lastPayload.data && lastPayload.data.availability);
			} else {
				// Remove the datetime helper when leaving time-capture state
				var helper = messages.querySelector('[data-rfac-dt-helper]');
				if (helper) { helper.remove(); }
				input.type = 'text';
				input.removeAttribute('min');
				input.removeAttribute('max');
				input.placeholder = 'Type your message...';
			}
			if (!emailMode) {
				// Ensure email autocomplete doesn't linger on other states
				input.removeAttribute('autocomplete');
			} else {
				input.setAttribute('autocomplete', 'email');
			}

			var stepMap = {
				capture_pickup: 0, confirm_pickup_city: 0,
				capture_dropoff: 0, confirm_dropoff_city: 0,
				capture_flight_number: 0, capture_passengers: 0, capture_luggage: 0,
				confirm_price: 1, confirm_long_trip: 1, capture_extras: 1,
				capture_coupon: 1, dispatch_pending_quote: 1,
				capture_name: 2, capture_phone: 2,
				capture_pickup_time: 2, confirm_booking_details: 2,
				complete: 3, change_pending: 3
			};
			var step = stepMap[currentState] !== undefined ? stepMap[currentState] : 3;
			progress.forEach(function (item, index) {
				item.classList.toggle('is-active', index <= step);
			});
		}

		function appendQuickReplies(container, state, payload) {
			// Always clear popular destinations container first to prevent stale chips
			var popDestContainerTop = document.getElementById('rfac-popular-destinations');
			if (popDestContainerTop) { popDestContainerTop.innerHTML = ''; }

			var replies = [];
			var chips = null;
			var collected = payload && payload.data && payload.data.collected || {};
			var rfacLang = collected.language || (window.rfacData && window.rfacData.lang) || 'en';
			function label(en, nl, fr) {
				return rfacLang === 'nl' ? nl : (rfacLang === 'fr' ? fr : en);
			}

			// Location disambiguation chips from server
			var candidates = payload && payload.data && payload.data.location_candidates;
			if (candidates && candidates.length && (state === 'confirm_pickup_city' || state === 'confirm_dropoff_city')) {
				chips = candidates.map(function(c) {
					return { label: c.length > 30 ? c.substring(0, 28) + '…' : c, value: c };
				}).filter(function(r) { return r.value !== ''; });
			}

			if (chips !== null) {
				replies = chips;
			} else if (state === 'capture_flight_number') {
				replies = [
					{ label: label('Skip', 'Overslaan', 'Passer'), value: 'skip' }
				];
			} else if (state === 'dispatch_pending_quote') {
				replies = [
					{ label: label('Yes, submit', 'Ja, indienen', 'Oui, soumettre'), value: 'yes', primary: true },
					{ label: label('No, try again', 'Nee, opnieuw', 'Non, recommencer'), value: 'no' }
				];
			} else if (state === 'confirm_long_trip') {
				replies = [
					{ label: label('Yes, book the taxi', 'Ja, taxi boeken', 'Oui, réserver le taxi'), value: 'yes', primary: true },
					{ label: label('Change destination', 'Bestemming wijzigen', 'Changer la destination'), value: 'change route' }
				];
			} else if (state === 'vehicle_unavailable') {
				replies = [
					{ label: label('Request multiple taxis', 'Meerdere taxis aanvragen', 'Demander plusieurs taxis'), value: 'request multiple taxis' },
					{ label: label('Change passenger count', 'Aantal passagiers wijzigen', 'Changer le nombre de passagers'), value: 'change route' }
				];
			} else if (state === 'capture_pickup' || state === 'capture_dropoff') {
				var popularDests = payload && payload.data && payload.data.popular_destinations;
				var popDestContainer = document.getElementById('rfac-popular-destinations');
				if (popDestContainer) { popDestContainer.innerHTML = ''; }
				if (popularDests && popularDests.length) {
					// Support both plain strings (legacy) and structured {label, lat, lng} objects.
					replies = popularDests.slice(0, 6).map(function(d) {
						var lbl = (typeof d === 'object' && d !== null) ? (d.label || '') : String(d);
						lbl = lbl.length > 28 ? lbl.substring(0, 26) + '…' : lbl;
						var chip = { label: lbl, value: lbl };
						// Embed coords so chip click goes through sendPlaceSelection path.
						if (typeof d === 'object' && d !== null && typeof d.lat === 'number') {
							chip._placeMeta = { description: d.label, main_text: d.label, lat: d.lat, lng: d.lng };
						}
						return chip;
					});
				}
			} else if (state === 'capture_extras') {
				// Extras are loaded from the server response and cached locally.
				var extrasList = payload && payload.data && payload.data.extras_list;
				if (extrasList && extrasList.length) {
					rfacExtrasCache = extrasList;
				}
				var extrasToShow = rfacExtrasCache || [];
				if (!extrasToShow.length) {
					// No extras configured — skip straight through
					replies = [{ label: label('No extras, continue →', 'Geen extras, verder →', 'Sans extras, continuer →'), value: 'no extras', primary: true }];
				} else {
					// ── Multi-select extras: tap to toggle, one round-trip when done ──
					var alreadySelected = (payload && payload.data && payload.data.collected && payload.data.collected.extras)
						? (payload.data.collected.extras || []).map(function(e) { return e.name || ''; })
						: [];
					// Initialise pending selection from server state on first render.
					rfacPendingExtras = alreadySelected.slice();

					var extrasRow = document.createElement('div');
					extrasRow.className = 'rfac-quick-replies rfac-extras-multiselect';
					extrasRow.setAttribute('data-rfac-quick-replies', '1');

					var doneLbl = label('Done, continue →', 'Klaar, verder →', 'Terminer, continuer →');
					var skipLbl = label('No extras, continue →', 'Geen extras, verder →', 'Sans extras, continuer →');

					function extrasLbl(e, sel) {
						var base = (sel ? '✓ ' : '') + e.name + (e.price > 0 ? ' (+' + Number(e.price).toFixed(2) + ')' : '');
						return base.length > 32 ? base.substring(0, 30) + '…' : base;
					}

					var doneBtn = document.createElement('button');
					doneBtn.type = 'button';
					doneBtn.setAttribute('data-rfac-extras-done', '1');
					doneBtn.className = 'rfac-quick-reply' + (rfacPendingExtras.length > 0 ? ' is-primary' : '');
					doneBtn.textContent = rfacPendingExtras.length > 0 ? doneLbl : skipLbl;

					extrasToShow.forEach(function(e) {
						var toggleBtn = document.createElement('button');
						toggleBtn.type = 'button';
						var isSel = rfacPendingExtras.indexOf(e.name) !== -1;
						toggleBtn.className = 'rfac-quick-reply rfac-extra-toggle' + (isSel ? ' is-selected' : '');
						toggleBtn.textContent = extrasLbl(e, isSel);
						toggleBtn.addEventListener('click', function() {
							var idx = rfacPendingExtras.indexOf(e.name);
							if (idx !== -1) {
								rfacPendingExtras.splice(idx, 1);
								toggleBtn.classList.remove('is-selected');
							} else {
								rfacPendingExtras.push(e.name);
								toggleBtn.classList.add('is-selected');
							}
							var nowSel = rfacPendingExtras.indexOf(e.name) !== -1;
							toggleBtn.textContent = extrasLbl(e, nowSel);
							// Update done button
							doneBtn.textContent = rfacPendingExtras.length > 0 ? doneLbl : skipLbl;
							doneBtn.className = 'rfac-quick-reply' + (rfacPendingExtras.length > 0 ? ' is-primary' : '');
						});
						extrasRow.appendChild(toggleBtn);
					});

					doneBtn.addEventListener('click', function() {
						extrasRow.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
						extrasRow.remove();
						var msg = rfacPendingExtras.length > 0
							? rfacPendingExtras.join(', ') + ', done'
							: 'no extras';
						rfacPendingExtras = [];
						sendText(msg);
					});
					extrasRow.appendChild(doneBtn);

					container.appendChild(extrasRow);
					container.scrollTop = container.scrollHeight;
					return; // row already appended — skip the generic replies loop below
				}
			} else if (state === 'capture_email') {
				replies = [
					{ label: label('Skip →', 'Overslaan →', 'Passer →'), value: 'skip' }
				];
			} else if (state === 'capture_passengers') {
				// Combined passengers + bags picker — one submit sends both values together
				// so the backend can parse "3 passengers, 1 bag" in a single turn.
				var paxPickerWrap = document.createElement('div');
				paxPickerWrap.setAttribute('data-rfac-quick-replies', '1');
				paxPickerWrap.className = 'rfac-pax-picker-wrap';

				var selectedPax  = 1;
				var selectedBags = 0;

				function makePaxRow(rowLabel, opts, initVal, onPick) {
					var row = document.createElement('div');
					row.className = 'rfac-pax-picker-row';
					var lbl = document.createElement('span');
					lbl.className = 'rfac-pax-picker-label';
					lbl.textContent = rowLabel;
					row.appendChild(lbl);
					var grp = document.createElement('div');
					grp.className = 'rfac-pax-picker-grp';
					opts.forEach(function (opt) {
						var btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'rfac-pax-picker-btn' + (opt.val === initVal ? ' is-selected' : '');
						btn.textContent = opt.txt;
						btn.addEventListener('click', function () {
							grp.querySelectorAll('.rfac-pax-picker-btn').forEach(function (b) { b.classList.remove('is-selected'); });
							btn.classList.add('is-selected');
							onPick(opt.val);
						});
						grp.appendChild(btn);
					});
					row.appendChild(grp);
					return row;
				}

				var paxOpts = [1,2,3,4,5,6].map(function (n) { return { txt: String(n), val: n }; });
				paxOpts.push({ txt: '7+', val: 7 });
				paxPickerWrap.appendChild(makePaxRow(
					label('Passengers', 'Passagiers', 'Passagers'),
					paxOpts, 1,
					function (v) { selectedPax = v; }
				));

				var bagOpts = [
					{ txt: label('0', '0', '0'), val: 0 },
					{ txt: '1', val: 1 },
					{ txt: '2', val: 2 },
					{ txt: '3', val: 3 },
					{ txt: '4+', val: 4 }
				];
				paxPickerWrap.appendChild(makePaxRow(
					label('Large bags', 'Grote koffers', 'Gros bagages'),
					bagOpts, 0,
					function (v) { selectedBags = v; }
				));

				var paxConfirmRow = document.createElement('div');
				paxConfirmRow.className = 'rfac-confirm-submit-row';
				var paxConfirmBtn = document.createElement('button');
				paxConfirmBtn.type = 'button';
				paxConfirmBtn.className = 'rfac-quick-reply is-primary rfac-quick-reply--full';
				paxConfirmBtn.textContent = label('Continue →', 'Verder →', 'Continuer →');
				paxConfirmBtn.addEventListener('click', function () {
					paxPickerWrap.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
					paxPickerWrap.remove();
					var paxWord  = selectedPax  === 1 ? 'passenger' : 'passengers';
					var bagWord  = selectedBags === 1 ? 'bag'       : 'bags';
					sendText(selectedPax + ' ' + paxWord + ', ' + selectedBags + ' ' + bagWord);
				});
				paxConfirmRow.appendChild(paxConfirmBtn);
				paxPickerWrap.appendChild(paxConfirmRow);

				container.appendChild(paxPickerWrap);
				container.scrollTop = container.scrollHeight;
				return;
			} else if (state === 'capture_luggage') {
				// Legacy fallback for old sessions that reach luggage separately
				var lugRow = document.createElement('div');
				lugRow.className = 'rfac-quick-replies';
				lugRow.setAttribute('data-rfac-quick-replies', '1');
				[0,1,2,3,4,5,6,7,8].forEach(function(n) {
					var btn = document.createElement('button');
					btn.type = 'button'; btn.className = 'rfac-quick-reply';
					btn.textContent = n === 0 ? label('None', 'Geen', 'Aucun') : String(n);
					btn.addEventListener('click', function() {
						lugRow.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
						lugRow.remove(); sendText(String(n));
					});
					lugRow.appendChild(btn);
				});
				container.appendChild(lugRow);
				container.scrollTop = container.scrollHeight;
				return;
			} else if (state === 'capture_coupon') {
				replies = [
					{ label: label('Skip →', 'Overslaan →', 'Passer →'), value: 'skip' }
				];
			} else if (state === 'confirm_cancellation') {
				replies = [
					{ label: label('Yes, cancel it', 'Ja, annuleer', 'Oui, annuler'), value: 'yes', primary: true },
					{ label: label('No, keep it', 'Nee, behouden', 'Non, conserver'), value: 'no' }
				];
			} else if (state === 'confirm_price') {
				var couponApplied = payload && payload.data && payload.data.quote && payload.data.quote.coupon && payload.data.quote.coupon.status === 'applied';

				// Custom two-row layout: action chips on top, full-width confirm button below.
				// Mirrors the confirm_booking_details pattern so every button has a direct
				// click handler — avoids the generic replies row where buttons can silently
				// fall through if the generic path is blocked.
				var priceWrap = document.createElement('div');
				priceWrap.setAttribute('data-rfac-quick-replies', '1');
				priceWrap.className = 'rfac-confirm-details-wrap';

				var priceEditRow = document.createElement('div');
				priceEditRow.className = 'rfac-quick-replies rfac-confirm-edit-row';

				var priceEditChips = [
					{ lbl: label('✎ Edit pickup', '✎ Ophaaladres wijzigen', '✎ Modifier le départ'), val: 'change pickup' },
					{ lbl: label('✎ Edit drop-off', '✎ Afleveradres wijzigen', '✎ Modifier la destination'), val: 'change dropoff' },
					{ lbl: label('Change details', 'Alles wijzigen', 'Tout modifier'), val: 'I want to change something' }
				];
				if (!couponApplied) {
					priceEditChips.push({ lbl: label('🏷️ Promo code', '🏷️ Promotiecode', '🏷️ Code promo'), val: 'apply promo code' });
				}

				priceEditChips.forEach(function (chip) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'rfac-quick-reply';
					btn.textContent = chip.lbl;
					btn.addEventListener('click', function () {
						priceWrap.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
						priceWrap.remove();
						sendText(chip.val);
					});
					priceEditRow.appendChild(btn);
				});
				priceWrap.appendChild(priceEditRow);

				var priceSubmitRow = document.createElement('div');
				priceSubmitRow.className = 'rfac-quick-replies rfac-confirm-submit-row';
				var confirmBtn = document.createElement('button');
				confirmBtn.type = 'button';
				confirmBtn.className = 'rfac-quick-reply is-primary rfac-quick-reply--full';
				confirmBtn.textContent = label('Confirm booking', 'Boeking bevestigen', 'Confirmer la réservation');
				confirmBtn.addEventListener('click', function () {
					priceWrap.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
					priceWrap.remove();
					sendText('yes, confirm');
				});
				priceSubmitRow.appendChild(confirmBtn);
				priceWrap.appendChild(priceSubmitRow);

				container.appendChild(priceWrap);
				container.scrollTop = container.scrollHeight;
				return;
			} else if (state === 'confirm_booking_details') {
				var hasTerms = payload && payload.data && payload.data.terms_url;
				var submitLabel = hasTerms
					? label('Accept T&C & Submit', 'Akkoord & indienen', 'Accepter CGV & envoyer')
					: label('Submit request', 'Aanvraag indienen', 'Envoyer la demande');

				// Custom two-row layout: edit chips on top, full-width submit below
				var detailsWrap = document.createElement('div');
				detailsWrap.setAttribute('data-rfac-quick-replies', '1');
				detailsWrap.className = 'rfac-confirm-details-wrap';

				var editRow = document.createElement('div');
				editRow.className = 'rfac-quick-replies rfac-confirm-edit-row';
				[
					{ lbl: label('✎ Edit pickup', '✎ Ophaaladres', '✎ Modifier le départ'), val: 'change pickup' },
					{ lbl: label('✎ Edit drop-off', '✎ Afleveradres', '✎ Modifier la destination'), val: 'change dropoff' },
					{ lbl: label('✎ Edit time', '✎ Tijd', '✎ Modifier l\'heure'), val: 'change time' }
				].forEach(function(r) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'rfac-quick-reply';
					btn.textContent = r.lbl;
					btn.addEventListener('click', function() {
						detailsWrap.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
						detailsWrap.remove();
						sendText(r.val);
					});
					editRow.appendChild(btn);
				});
				detailsWrap.appendChild(editRow);

				var submitRow = document.createElement('div');
				submitRow.className = 'rfac-quick-replies rfac-confirm-submit-row';
				var submitBtn2 = document.createElement('button');
				submitBtn2.type = 'button';
				submitBtn2.className = 'rfac-quick-reply is-primary rfac-quick-reply--full';
				submitBtn2.textContent = submitLabel;
				submitBtn2.addEventListener('click', function() {
					detailsWrap.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
					detailsWrap.remove();
					sendText('yes');
				});
				submitRow.appendChild(submitBtn2);
				detailsWrap.appendChild(submitRow);

				container.appendChild(detailsWrap);
				container.scrollTop = container.scrollHeight;
				return;
			} else if (state === 'complete') {
				// Nice post-booking UX: positive actions first, subtle cancel hint below — no alarming red button
				var completedCollected = payload && payload.data && payload.data.collected || {};
				var hasCompleteRoute = completedCollected.pickup_address && completedCollected.dropoff_address;
				var completeWrap = document.createElement('div');
				completeWrap.setAttribute('data-rfac-quick-replies', '1');
				completeWrap.className = 'rfac-complete-wrap';

				var completeActionsRow = document.createElement('div');
				completeActionsRow.className = 'rfac-quick-replies';

				if (hasCompleteRoute) {
					var returnTripBtn = document.createElement('button');
					returnTripBtn.type = 'button';
					returnTripBtn.className = 'rfac-quick-reply';
					returnTripBtn.textContent = label('📍 Book return trip', '📍 Retourrit boeken', '📍 Réserver le retour');
					returnTripBtn.addEventListener('click', function() {
						completeWrap.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
						completeWrap.remove();
						sendText('I would like to book the return trip');
					});
					completeActionsRow.appendChild(returnTripBtn);
				}

				var newBookingBtn2 = document.createElement('button');
				newBookingBtn2.type = 'button';
				newBookingBtn2.className = 'rfac-quick-reply';
				newBookingBtn2.textContent = label('🚕 New booking', '🚕 Nieuwe boeking', '🚕 Nouvelle réservation');
				newBookingBtn2.addEventListener('click', function() {
					completeWrap.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
					completeWrap.remove();
					sendText('new booking');
				});
				completeActionsRow.appendChild(newBookingBtn2);

				var cancelUrl = payload && payload.data && payload.data.cancellation_page_url;
				if (cancelUrl) {
					var cancelPolicyLink = document.createElement('a');
					cancelPolicyLink.href = cancelUrl;
					cancelPolicyLink.target = '_blank';
					cancelPolicyLink.rel = 'noopener noreferrer';
					cancelPolicyLink.className = 'rfac-quick-reply';
					cancelPolicyLink.textContent = label('Cancellation Policy ↗', 'Annuleringsbeleid ↗', 'Politique d\'annulation ↗');
					completeActionsRow.appendChild(cancelPolicyLink);
				}
				completeWrap.appendChild(completeActionsRow);

				// Subtle text hint — no red button cluttering a success moment
				var cancelHint = document.createElement('p');
				cancelHint.className = 'rfac-cancel-hint';
				cancelHint.textContent = label(
					'Need to cancel? Just type “cancel my booking”.',
					'Annuleren? Typ gewoon “boeking annuleren”.',
					'Besoin d’annuler ? Tapez «annuler ma réservation».'
				);
				completeWrap.appendChild(cancelHint);

				container.appendChild(completeWrap);
				container.scrollTop = container.scrollHeight;
				return;
			} else {
				var msg = String(payload && payload.message || '').trim();
				if (/\?\s*$/.test(msg) && /^(do|does|did|is|are|was|were|will|would|can|could|should|shall|may|might|have|has|had)\s/i.test(msg)) {
					replies = [
						{ label: label('Yes', 'Ja', 'Oui'), value: 'yes', primary: true },
						{ label: label('No', 'Nee', 'Non'), value: 'no' }
					];
				}
			}

			if (!replies.length) {
				return;
			}

			var row = document.createElement('div');
			row.className = 'rfac-quick-replies';
			row.setAttribute('data-rfac-quick-replies', '1');
			replies.forEach(function (r) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'rfac-quick-reply' + (r.primary ? ' is-primary' : '');
				btn.textContent = r.label;
				btn.addEventListener('click', function () {
					row.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
					row.remove();
					// Preset location chips with embedded coords: use sendPlaceSelection so
					// coords are forwarded to the server (geographic bias + no geocode round-trip).
					if (r._placeMeta) {
						sendPlaceSelection(r.value, r._placeMeta);
					} else {
						sendText(r.value);
					}
				});
				row.appendChild(btn);
			});
			container.appendChild(row);
			container.scrollTop = container.scrollHeight;
		}

		function clearQuickReplies() {
			var existing = messages.querySelectorAll('[data-rfac-quick-replies="1"]');
			existing.forEach(function (n) { n.remove(); });
		}

		function setSendBlocked(blocked) {
			if (!sendBtn) { return; }
			sendBtn.disabled = blocked;
			sendBtn.style.opacity = blocked ? '0.35' : '';
			sendBtn.title = blocked ? 'Select a suggestion from the list first' : '';
		}

		var fetchPlaces = debounce(function (query) {
			if (!placesEndpoint || query.length < 3) {
				return;
			}
			rfacLog('📍 Autocomplete fetch', { query: query, state: currentState });

			// Abort any previous in-flight autocomplete request before starting a new one.
			// Prevents stale XHR results from overwriting the UI after the user has already
			// made a selection or typed something new.
			if (autocompleteFetchAbort) {
				autocompleteFetchAbort.abort();
			}
			autocompleteFetchAbort = window.AbortController ? new AbortController() : null;

			fetchInFlight = true;
			setSendBlocked(true); // block send immediately while fetch is in-flight

			// Build URL with geographic bias:
			// - For dropoff: bias toward confirmed pickup coords (most relevant)
			// - For pickup: bias is handled server-side using the home region fallback
			var biasParams = '';
			if (currentState === 'capture_dropoff' && pickupCoords) {
				biasParams = '&lat=' + pickupCoords.lat + '&lng=' + pickupCoords.lng;
			}

			fetch(placesEndpoint + '?input=' + encodeURIComponent(query) + '&session_id=' + encodeURIComponent(getSessionId()) + biasParams, {
				headers: { 'X-WP-Nonce': nonce },
				signal: autocompleteFetchAbort ? autocompleteFetchAbort.signal : undefined
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					autocompleteFetchAbort = null;
					fetchInFlight = false;
					var count = (data && data.predictions) ? data.predictions.length : 0;
					rfacLog('📍 Autocomplete results', { query: query, count: count, state: currentState });
					if (count > 0) {
						setSendBlocked(true);
						appendPlaceCard(messages, data.predictions, function (selected, placeMeta) {
							// Kill the debounce timer AND abort any in-flight fetch so that no
							// stale results can re-appear after this selection is committed.
							fetchPlaces.cancel();
							if (autocompleteFetchAbort) {
								autocompleteFetchAbort.abort();
								autocompleteFetchAbort = null;
							}
							fetchInFlight = false;

							var card = messages.querySelector('[data-rfac-place-card-active="1"]');
							if (card) {
								card.remove();
							}
							setSendBlocked(false);
							rfacLog('✅ Place selected', { text: selected, place_id: (placeMeta || {}).place_id, state: currentState });
							input.value = '';
							sendPlaceSelection(selected, placeMeta || {});
						});
					} else {
						// No autocomplete results — keep send blocked so geocoding is not skipped.
						// A subtle inline nudge tells the user to try a more specific address.
						setSendBlocked(true);
						var noResultCard = messages.querySelector('[data-rfac-no-result]');
						if (!noResultCard) {
							noResultCard = document.createElement('div');
							noResultCard.className = 'rfac-place-card rfac-place-card--empty';
							noResultCard.setAttribute('data-rfac-no-result', '1');
							noResultCard.textContent = 'No address suggestions found — try adding a city or postal code.';
							messages.appendChild(noResultCard);
							rfacScrollToBottom();
							// Auto-dismiss after 4 s
							setTimeout(function () { if (noResultCard.parentNode) { noResultCard.remove(); } }, 4000);
						}
					}
				})
				.catch(function (err) {
					// AbortError = intentional cancellation (new query or selection made) — discard silently.
					if (err && err.name === 'AbortError') {
						return;
					}
					autocompleteFetchAbort = null;
					fetchInFlight = false;
					rfacLog('✖ Autocomplete error', { query: query, error: String(err) });
					setSendBlocked(false);
				});
		}, 420);

		function storePickupCoordsIfNeeded(place) {
			// When the user just confirmed a pickup, save its coordinates so dropoff
			// autocomplete can send them to the Places API for geographic bias.
			if (currentState === 'capture_pickup' || currentState === 'greeting') {
				if (place && typeof place.lat === 'number' && typeof place.lng === 'number') {
					pickupCoords = { lat: place.lat, lng: place.lng };
					rfacLog('📍 Pickup coords stored for dropoff bias', pickupCoords);
				}
			}
		}

		function sendPlaceSelection(selected, placeMeta) {
			if (!placeDetailsEndpoint || !placeMeta.place_id) {
				rfacLog('📍 Place sent (no details lookup)', { text: selected, meta: placeMeta });
				storePickupCoordsIfNeeded(placeMeta);
				sendText(selected, { source: 'place_autocomplete', place: placeMeta || {} });
				return;
			}

			fetch(placeDetailsEndpoint + '?place_id=' + encodeURIComponent(placeMeta.place_id) + '&session_id=' + encodeURIComponent(getSessionId()), {
				headers: { 'X-WP-Nonce': nonce }
			})
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (data) {
					var detailed = placeMeta;
					if (data && data.success && data.place) {
						detailed = Object.assign({}, placeMeta, data.place);
					}
					rfacLog('📍 Place sent (with details)', { text: selected, place_id: detailed.place_id, lat: detailed.lat, lng: detailed.lng });
					storePickupCoordsIfNeeded(detailed);
					sendText(selected, { source: 'place_autocomplete', place: detailed });
				})
				.catch(function (err) {
					rfacLog('✖ Place details error — sending without coords', { text: selected, error: String(err) });
					sendText(selected, { source: 'place_autocomplete', place: placeMeta || {} });
				});
		}

		input.addEventListener('input', function () {
			if (PLACE_STATES.indexOf(currentState) === -1) {
				return;
			}
			var val = input.value.trim();
			// In a place-capture state we know the user is typing an address.
			// Use a lighter check: ≥3 chars + not a pure conversational keyword.
			// looksLikeAddress() is too strict — it misses plain city names like
			// "new york" (lowercase, no digit, no comma, no keyword).
			var tooShort = val.length < 3;
			var isConversational = CONVERSATIONAL_RE.test(val) && val.split(/\s+/).length <= 2;
			if (tooShort || isConversational) {
				var old = messages.querySelector('[data-rfac-place-card-active="1"]');
				if (old) {
					old.remove();
					setSendBlocked(false);
				}
				return;
			}
			fetchPlaces(val);
		});

		function sendText(value, metadata) {
			var text = arguments.length ? String(value).trim() : input.value.trim();
			if (!text) {
				return;
			}
			// Convert datetime-local format (2026-05-22T14:30) to a readable string the server can parse.
			if (currentState === 'capture_pickup_time' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(text)) {
				// Format: "May 22 2026 at 2:30 PM" — unambiguous and matches normalize_pickup_time patterns.
				try {
					var dtParts = text.split('T');
					var d = new Date(dtParts[0] + 'T' + dtParts[1]);
					if (!isNaN(d)) {
						var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
						var h = d.getHours(), m = d.getMinutes();
						var ampm = h < 12 ? 'AM' : 'PM';
						var h12 = (h % 12) || 12;
						text = months[d.getMonth()] + ' ' + d.getDate() + ' ' + d.getFullYear() + ' at ' + h12 + ':' + String(m).padStart(2, '0') + ' ' + ampm;
					}
				} catch(e) {}
				// Remove the dt helper once time is being sent
				var dtHelper = messages.querySelector('[data-rfac-dt-helper]');
				if (dtHelper) { dtHelper.remove(); }
			}
			// Prevent duplicate sends (double-click, quick-reply race, etc.)
			if (requestInFlight) {
				rfacLog('⚡ Blocked duplicate send', { text: text, state: currentState });
				return;
			}
			requestInFlight = true;
			clearTimeout(window._rfacIdleTimer);

			var activeCard = messages.querySelector('[data-rfac-place-card-active="1"]');
			if (activeCard) {
				activeCard.remove();
			}
			clearQuickReplies();

			appendMessage(messages, text, 'user');
			rfacLog('→ Sending', { text: text, state: currentState, session_id: getSessionId() || '(new)' });
			input.value = '';
			input.disabled = true;

			if (typingLabel) {
				typingLabel.textContent = currentState === 'capture_dropoff' ? 'Checking route' : 'Typing';
			}
			typing.hidden = false;

			if (!endpoint) {
				appendMessage(messages, 'The chat endpoint is not configured on this page.', 'bot');
				requestInFlight = false;
				input.disabled = false;
				typing.hidden = true;
				return;
			}

			var controller = window.AbortController ? new AbortController() : null;
			var timeout = window.setTimeout(function () {
				if (controller) {
					controller.abort();
				}
			}, 30000);

			fetch(endpoint, {
				method: 'POST',
				signal: controller ? controller.signal : undefined,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: JSON.stringify({
					session_id: getSessionId(),
					message: text,
					client_locale: (navigator.language || navigator.userLanguage || '').slice(0, 5),
					client_message_id: generateMsgId(),
					prefill_contact: rfacPrefillContact || undefined,
					metadata: metadata || undefined
				})
			})
				.then(function (response) {
					return response.json().then(function (payload) {
						if (!response.ok) {
							throw new Error(payload.message || 'Chat request failed.');
						}
						return payload;
					});
				})
				.then(function (payload) {
					hideTypingIndicator();
					// Clear prefill after first successful send
					rfacPrefillContact = null;
					rfacLog('← Response', {
						state: payload.state,
						message: payload.message,
						session_id: payload.session_id,
						collected: payload.data && payload.data.collected,
						quote: payload.data && payload.data.quote,
						booking: payload.data && payload.data.booking
					});
					if (payload.session_id) {
						setStoredSessionId(payload.session_id);
					}
					rfacCurrentState = payload.state || 'greeting';
					scheduleIdleNudge(rfacCurrentState);
					if (payload.state === 'complete') {
						// Calendar buttons are now embedded inside the info card (see appendInfoCard).
						maybeShowPaymentLink(payload.data);
					}
					appendMessage(messages, payload.message || 'I can help you book a taxi ride.', 'bot');
					// Show route summary once both locations are known
					var d = payload.data || {};
					var summaryEl = document.getElementById('rfac-route-summary');
					if (summaryEl) {
						var pickup = (d.collected && d.collected.pickup_address) || '';
						var dropoff = (d.collected && d.collected.dropoff_address) || '';
						if (pickup && dropoff) {
							summaryEl.textContent = pickup + ' → ' + dropoff;
							summaryEl.hidden = false;
						}
					}
					// Save contact details for returning customer shortcut
					if (payload.state === 'complete' && payload.data && payload.data.collected) {
						var savedData = { name: payload.data.collected.customer_name || '', phone: payload.data.collected.customer_phone || '' };
						if (savedData.name && savedData.phone) {
							try { localStorage.setItem('rfac_saved_contact', JSON.stringify(savedData)); } catch(e) {}
						}
					}
					lastPayload = payload;
					appendInfoCard(messages, payload);
					appendActionCard(messages, payload, closeWidget, sendText);
					setState(payload.state);
					appendQuickReplies(messages, payload.state, payload);
					if (payload.data && payload.data.ui_action === 'close_chat') {
						window.setTimeout(function () {
							closeWidget();
						}, 700);
					}
					if (payload.state === 'halted') {
						setStoredSessionId('');
					}
				})
				.catch(function (error) {
					hideTypingIndicator();
					rfacLog('✖ Error', { name: error.name, message: error.message, state: currentState });
					var msg = error.name === 'AbortError'
						? 'That took too long. Please try again or choose a more specific place from the suggestions.'
						: (error.message || 'The chat service is unavailable right now. Please try again in a moment.');
					appendMessage(messages, msg, 'bot');
				})
				.finally(function () {
					requestInFlight = false;
					window.clearTimeout(timeout);
					input.disabled = false;
					typing.hidden = true;
					input.focus();
				});
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			// Block free-text submit while autocomplete suggestions are visible OR while a
			// fetch is still in-flight — prevents raw address strings racing ahead of suggestions.
			if (PLACE_STATES.indexOf(currentState) !== -1) {
				var activeCard = messages.querySelector('[data-rfac-place-card-active="1"]');
				if (activeCard) {
					rfacLog('⛔ Submit blocked — autocomplete suggestion must be selected first', { state: currentState });
					input.focus();
					return;
				}
				if (fetchInFlight) {
					rfacLog('⛔ Submit blocked — autocomplete fetch still in progress', { state: currentState });
					input.focus();
					return;
				}
			}
			sendText();
		});

		setState(currentState);

		// Returning customer shortcut
		// "Yes" stores contact as structured prefill metadata (not as chat text)
		// so the bot never tries to parse a name as a pickup address.
		var rfacPrefillContact = null;
		try {
			var savedContact = JSON.parse(localStorage.getItem('rfac_saved_contact') || 'null');
			if (savedContact && savedContact.name && savedContact.phone) {
				var returnMsg = 'Welcome back, ' + savedContact.name + '! Use the same contact details (' + savedContact.phone + ') for this booking?';
				setTimeout(function() {
					appendMessage(messages, returnMsg, 'bot');
					var chipRow = document.createElement('div');
					chipRow.className = 'rfac-chips';
					var yesChip = document.createElement('button');
					yesChip.type = 'button';
					yesChip.className = 'rfac-chip rfac-chip--primary';
					yesChip.textContent = 'Yes, use saved';
					yesChip.addEventListener('click', function() {
						chipRow.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
						chipRow.remove();
						// Start a fresh booking session and store contact as metadata for the next real user message.
						setStoredSessionId('');
						rfacPrefillContact = { name: savedContact.name, phone: savedContact.phone };
						setState('capture_pickup');
						rfacLog('Returning customer prefill queued', rfacPrefillContact);
						appendMessage(messages, 'Got it. I\'ll use ' + savedContact.name + ' and ' + savedContact.phone + ' for this booking. Where should we pick you up?', 'bot');
						input.placeholder = 'Pickup address, airport, hotel, station...';
						input.focus();
						try { localStorage.removeItem('rfac_prefill_name'); localStorage.removeItem('rfac_prefill_phone'); } catch(e) {}
					});
					var noChip = document.createElement('button');
					noChip.type = 'button';
					noChip.className = 'rfac-chip';
					noChip.textContent = 'Use different details';
					noChip.addEventListener('click', function() {
						chipRow.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
						chipRow.remove();
						rfacPrefillContact = null;
						setStoredSessionId('');
						setState('capture_pickup');
						try { localStorage.removeItem('rfac_saved_contact'); localStorage.removeItem('rfac_prefill_name'); localStorage.removeItem('rfac_prefill_phone'); } catch(e) {}
						appendMessage(messages, 'No problem. We\'ll use different contact details for this booking. Where should we pick you up?', 'bot');
						input.placeholder = 'Pickup address, airport, hotel, station...';
						input.focus();
					});
					chipRow.appendChild(yesChip);
					chipRow.appendChild(noChip);
					messages.appendChild(chipRow);
					messages.scrollTop = messages.scrollHeight;
				}, 600);
			}
		} catch(e) {}
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-rfac-widget]').forEach(init);
	});
})();
