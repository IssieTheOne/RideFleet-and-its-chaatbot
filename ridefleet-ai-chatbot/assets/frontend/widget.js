(function () {
	'use strict';

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
		var shouldRender = ['confirm_price', 'complete', 'change_pending', 'price_negotiation_pending'].indexOf(payload.state) !== -1;
		if (!shouldRender) {
			return;
		}

		var card = document.createElement('div');
		card.className = 'rfac-info-card';
		var titles = {
			complete: 'Booking confirmed',
			price_negotiation_pending: 'Fare approval pending',
			change_pending: 'Change request pending'
		};
		var title = titles[payload.state] || 'Trip quote';
		var html = '<div class="rfac-info-title">' + escapeHtml(title) + '</div>';
		if (collected.pickup_address || collected.dropoff_address) {
			html += '<div class="rfac-route-line"><span>Pickup</span><strong>' + escapeHtml(collected.pickup_address || '—') + '</strong></div>';
			html += '<div class="rfac-route-line"><span>Drop-off</span><strong>' + escapeHtml(collected.dropoff_address || '—') + '</strong></div>';
		}
		if (collected.pickup_time) {
			html += '<div class="rfac-route-line"><span>Pickup time</span><strong>' + escapeHtml(collected.pickup_time) + '</strong></div>';
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
			html += '<div class="rfac-price-line"><span>Verified fare</span><strong>' + escapeHtml(quote.currency || 'USD') + ' ' + Number(quote.final_price).toFixed(2) + '</strong></div>';
		}
		if (quote.requires_approval || (quote.service_area && (quote.service_area.pickup_allowed === false || quote.service_area.dropoff_allowed === false))) {
			var sa = quote.service_area || {};
			var which = (sa.pickup_allowed === false && sa.dropoff_allowed === false) ? 'both endpoints' : (sa.pickup_allowed === false ? 'pickup' : (sa.dropoff_allowed === false ? 'drop-off' : 'route'));
			html += '<div class="rfac-warning-line">⚠ ' + escapeHtml(which) + ' outside standard service area — dispatch will approve manually.</div>';
		}
		if (booking.id) {
			html += '<div class="rfac-route-line"><span>Booking ID</span><strong>' + escapeHtml(booking.id) + '</strong></div>';
		}
		if (booking.changeRequestId) {
			html += '<div class="rfac-route-line"><span>Request</span><strong>#' + escapeHtml(String(booking.changeRequestId)) + ' awaiting admin approval</strong></div>';
		}
		if (booking.proposedPrice) {
			html += '<div class="rfac-route-line"><span>Proposed fare</span><strong>' + escapeHtml(quote.currency || 'USD') + ' ' + Number(booking.proposedPrice).toFixed(2) + '</strong></div>';
		}
		card.innerHTML = html;
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

		var newButton = document.createElement('button');
		newButton.type = 'button';
		newButton.className = 'rfac-action-button rfac-action-button-primary';
		newButton.textContent = 'New booking';
		newButton.addEventListener('click', function () {
			sendText('new booking');
			card.remove();
		});

		row.appendChild(closeButton);
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

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'rfac-place-option';
			btn.innerHTML = '<strong>' + escapeHtml(main) + '</strong>' + (secondary ? '<span>' + escapeHtml(secondary) + '</span>' : '');
			btn.addEventListener('click', function () {
				onSelect(full || main);
			});
			card.appendChild(btn);
		});

		container.appendChild(card);
		container.scrollTop = container.scrollHeight;
	}

	function debounce(fn, ms) {
		var timer;
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(ctx, args);
			}, ms);
		};
	}

	var PLACE_STATES = ['capture_pickup', 'confirm_pickup_city', 'capture_dropoff', 'confirm_dropoff_city'];

	var CONVERSATIONAL_RE = /^(hi+|hey+|hello|howdy|yo|sup|greetings|good\s+(morning|afternoon|evening|night)|how|what|where|when|why|who|whose|which|do|does|did|are|am|is|was|were|can|could|will|would|should|may|might|i'?m|i\s+am|me|my|thanks|thank|cheers|yes|yeah|yep|ok|okay|sure|fine|no|nope|maybe|help|sorry|please|cancel|stop|wait|bye|goodbye|test|testing|ping)\b/i;

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
		var nonce = config.nonce || widget.getAttribute('data-rfac-nonce') || '';
		var bubble = widget.querySelector('.rfac-bubble');
		var windowEl = widget.querySelector('.rfac-window');
		var close = widget.querySelector('.rfac-close');
		var reset = widget.querySelector('[data-rfac-reset]');
		var form = widget.querySelector('[data-rfac-form]');
		var input = widget.querySelector('[data-rfac-input]');
		var phonePrefix = widget.querySelector('[data-rfac-phone-prefix]');
		var messages = widget.querySelector('[data-rfac-messages]');
		var typing = widget.querySelector('[data-rfac-typing]');
		var typingLabel = widget.querySelector('[data-rfac-typing-label]');
		var progress = widget.querySelectorAll('.rfac-progress .rfac-progress-step');
		var currentState = 'capture_pickup';
		var closeTimer;

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
				var greeting = messages.getAttribute('data-rfac-default-greeting') || 'Hi! How can I help?';
				var item = document.createElement('div');
				item.className = 'rfac-message rfac-message-bot';
				item.textContent = greeting;
				messages.appendChild(item);
				typing.hidden = true;
				input.value = '';
				input.disabled = false;
				if (phonePrefix) {
					phonePrefix.disabled = false;
					phonePrefix.hidden = true;
				}
				setState('capture_pickup');
				input.focus();
			});
		}

		function setState(state) {
			currentState = state || currentState;
			var phoneMode = currentState === 'capture_phone' || currentState === 'capture_negotiation_phone';
			var timeMode = currentState === 'capture_pickup_time' || currentState === 'capture_negotiation_time';
			phonePrefix.hidden = !phoneMode;

			if (phoneMode) {
				input.type = 'tel';
				input.placeholder = 'Phone number...';
			} else if (timeMode) {
				input.type = 'datetime-local';
				input.placeholder = '';
				input.min = new Date(Date.now() + 5 * 60000).toISOString().slice(0, 16);
			} else if (currentState === 'capture_passengers' || currentState === 'capture_luggage') {
				input.type = 'number';
				input.min = currentState === 'capture_passengers' ? '1' : '0';
				input.max = '20';
				input.placeholder = currentState === 'capture_passengers' ? 'Number of passengers' : 'Number of bags';
			} else {
				input.type = 'text';
				input.removeAttribute('min');
				input.removeAttribute('max');
				input.placeholder = 'Type your message...';
			}

			var stepMap = {
				capture_pickup: 0, confirm_pickup_city: 0,
				capture_dropoff: 0, confirm_dropoff_city: 0,
				confirm_price: 1, capture_price_offer: 1,
				capture_passengers: 1, capture_luggage: 1,
				capture_vehicle: 1, capture_extras: 1,
				quote_refresh_requested: 1,
				capture_name: 2, capture_phone: 2,
				capture_pickup_time: 2, capture_negotiation_name: 2,
				capture_negotiation_phone: 2, capture_negotiation_time: 2,
				complete: 3, change_pending: 3, price_negotiation_pending: 3
			};
			var step = stepMap[currentState] !== undefined ? stepMap[currentState] : 3;
			progress.forEach(function (item, index) {
				item.classList.toggle('is-active', index <= step);
			});
		}

		function appendQuickReplies(container, state, payload) {
			var replies = [];

			if (state === 'confirm_price') {
				replies = [
					{ label: 'Confirm booking', value: 'yes, confirm', primary: true },
					{ label: 'Negotiate price', value: 'I would like to propose a lower fare' },
					{ label: 'Change details', value: 'I want to change something' }
				];
			} else if (state === 'capture_passengers') {
				replies = [1, 2, 3, 4, 5, 6, 7, 8].map(function (n) {
					return { label: String(n), value: String(n) };
				});
			} else if (state === 'capture_luggage') {
				replies = [0, 1, 2, 3, 4, 5].map(function (n) {
					return { label: String(n), value: String(n) };
				});
			} else if (state === 'capture_extras') {
				replies = [{ label: 'No extras, continue', value: 'no extras' }];
			} else if (state === 'complete') {
				replies = [
					{ label: 'Book another ride', value: 'new booking', primary: true },
					{ label: 'Modify this booking', value: 'I want to change my booking' }
				];
			} else {
				var msg = String(payload && payload.message || '').trim();
				if (/\?\s*$/.test(msg) && /^(do|does|did|is|are|was|were|will|would|can|could|should|shall|may|might|have|has|had)\s/i.test(msg)) {
					replies = [
						{ label: 'Yes', value: 'yes', primary: true },
						{ label: 'No', value: 'no' }
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
					row.remove();
					sendText(r.value);
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

		var fetchPlaces = debounce(function (query) {
			if (!placesEndpoint || query.length < 3) {
				return;
			}
			fetch(placesEndpoint + '?input=' + encodeURIComponent(query), {
				headers: { 'X-WP-Nonce': nonce }
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data && data.predictions && data.predictions.length) {
						appendPlaceCard(messages, data.predictions, function (selected) {
							var card = messages.querySelector('[data-rfac-place-card-active="1"]');
							if (card) {
								card.remove();
							}
							input.value = '';
							sendText(selected);
						});
					}
				})
				.catch(function () {});
		}, 420);

		input.addEventListener('input', function () {
			if (PLACE_STATES.indexOf(currentState) === -1) {
				return;
			}
			var val = input.value.trim();
			if (!looksLikeAddress(val)) {
				var old = messages.querySelector('[data-rfac-place-card-active="1"]');
				if (old) {
					old.remove();
				}
				return;
			}
			fetchPlaces(val);
		});

		function sendText(value) {
			var text = arguments.length ? String(value).trim() : input.value.trim();
			if (!text) {
				return;
			}

			var activeCard = messages.querySelector('[data-rfac-place-card-active="1"]');
			if (activeCard) {
				activeCard.remove();
			}
			clearQuickReplies();

			if ((currentState === 'capture_phone' || currentState === 'capture_negotiation_phone') && phonePrefix && !/^\+/.test(text)) {
				text = phonePrefix.value + ' ' + text.replace(/^0+/, '');
			}

			appendMessage(messages, text, 'user');
			input.value = '';
			input.disabled = true;
			if (phonePrefix) {
				phonePrefix.disabled = true;
			}

			if (typingLabel) {
				typingLabel.textContent = currentState === 'capture_dropoff' ? 'Checking route' : 'Typing';
			}
			typing.hidden = false;

			if (!endpoint) {
				appendMessage(messages, 'The chat endpoint is not configured on this page.', 'bot');
				input.disabled = false;
				if (phonePrefix) {
					phonePrefix.disabled = false;
				}
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
					client_locale: (navigator.language || navigator.userLanguage || '').slice(0, 5)
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
					if (payload.session_id) {
						setStoredSessionId(payload.session_id);
					}
					appendMessage(messages, payload.message || 'I can help you book a taxi ride.', 'bot');
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
					var msg = error.name === 'AbortError'
						? 'That took too long. Please try again or choose a more specific place from the suggestions.'
						: (error.message || 'The chat service is unavailable right now. Please try again in a moment.');
					appendMessage(messages, msg, 'bot');
				})
				.finally(function () {
					window.clearTimeout(timeout);
					input.disabled = false;
					if (phonePrefix) {
						phonePrefix.disabled = false;
					}
					typing.hidden = true;
					input.focus();
				});
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			sendText();
		});

		setState(currentState);
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-rfac-widget]').forEach(init);
	});
})();
