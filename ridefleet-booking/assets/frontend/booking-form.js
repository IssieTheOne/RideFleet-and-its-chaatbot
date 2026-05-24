(function () {
	'use strict';

	const config = window.RideFleetBooking || {};
	const forms = document.querySelectorAll('.rfb-booking-form');

	if (!forms.length || !config.restUrl) {
		return;
	}

	const money = (amount, currency) => {
		try {
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(amount);
		} catch (error) {
			return `${currency || ''} ${Number(amount || 0).toFixed(2)}`.trim();
		}
	};

	const text = (value) => String(value || '').trim();

	const api = async (path, options = {}) => {
		const response = await fetch(`${config.restUrl}${path}`, {
			...options,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || '',
				...(options.headers || {}),
			},
		});

		if (!response.ok) {
			let details = {};
			try {
				details = await response.json();
			} catch (error) {}
			const requestError = new Error(details.message || `Request failed with status ${response.status}`);
			requestError.details = details;
			requestError.status = response.status;
			throw requestError;
		}

		return response.json();
	};

	class BookingForm {
		constructor(root) {
			this.root = root;
			this.fields = {};
			this.state = {
				sessionId: this.sessionId(),
				distance: 0,
				durationMinutes: 0,
				currency: 'USD',
				distanceUnit: 'km',
				vehicles: [],
				extras: [],
				selectedVehicleId: 0,
				manualDispatch: false,
				selectedExtras: new Map(),
				serviceAreaMap: null,
				placeCoords: {},
			};

			root.querySelectorAll('[data-rfb-field]').forEach((field) => {
				this.fields[field.dataset.rfbField] = field;
				if ((field.dataset.rfbField === 'pickupAddress' || field.dataset.rfbField === 'dropoffAddress') && field.value) {
					field.dataset.rfbPlaceSelected = '1';
				}
			});

			this.mapEl = root.querySelector('[data-rfb-map]');
			this.summaryEl = root.querySelector('[data-rfb-route-summary]');
			this.floatingRouteEl = root.querySelector('[data-rfb-floating-route]');
			this.manualRouteEl = root.querySelector('[data-rfb-manual-route]');
			this.vehiclesEl = root.querySelector('[data-rfb-vehicles]');
			this.extrasEl = root.querySelector('[data-rfb-extras]');
			this.totalEl = root.querySelector('[data-rfb-total]');
			this.distanceEl = root.querySelector('[data-rfb-distance]');
			this.durationEl = root.querySelector('[data-rfb-duration]');
			this.messageEl = root.querySelector('[data-rfb-message]');
			this.confirmationEl = root.querySelector('[data-rfb-confirmation]');
			this.confirmationTextEl = root.querySelector('[data-rfb-confirmation-text]');
			this.submitEl = root.querySelector('[data-rfb-submit]');
			this.searchEl = root.querySelector('[data-rfb-search]');
			this.applyCouponEl = root.querySelector('[data-rfb-apply-coupon]');
			this.globalBackEl = root.querySelector('[data-rfb-global-back]');
			this.flowSteps = root.querySelectorAll('[data-rfb-flow-step]');
			this.stepDots = root.querySelectorAll('[data-rfb-step-dot]');
			this.dateStripEl = root.querySelector('[data-rfb-date-strip]');
			this.timeGridEl = root.querySelector('[data-rfb-time-grid]');
			this.dateModalEl = root.querySelector('[data-rfb-date-modal]');
			this.calendarMonthsEl = root.querySelector('[data-rfb-calendar-months]');
			this.calendarTitleEl = root.querySelector('[data-rfb-calendar-title]');
			this.calendarCursor = new Date();
			this.reviewEls = {};
			root.querySelectorAll('[data-rfb-review]').forEach((element) => {
				this.reviewEls[element.dataset.rfbReview] = element;
			});
			this.routeRequestId = 0;

			this.bind();
			this.setDefaultPhonePrefix();
			this.renderDateTimePickers();
			this.load();
			window.setTimeout(() => {
				if (this.fields.pickupAddress?.value && this.fields.dropoffAddress?.value) {
					this.calculateRoute();
				}
			}, 500);
		}

		sessionId() {
			const key = 'rfb_session_id';
			const existing = window.sessionStorage ? window.sessionStorage.getItem(key) : '';
			if (existing) {
				return existing;
			}

			const next = `rfb-${Date.now()}-${Math.random().toString(16).slice(2)}`;
			if (window.sessionStorage) {
				window.sessionStorage.setItem(key, next);
			}

			return next;
		}

		bind() {
			['passengers', 'luggage'].forEach((key) => {
				this.fields[key]?.addEventListener('change', async () => {
					await this.loadVehicles();
					this.quote();
				});
			});

			['pickupAddress', 'dropoffAddress'].forEach((key) => {
				this.fields[key]?.addEventListener('input', () => {
					this.fields[key].dataset.rfbPlaceSelected = '';
					delete this.state.placeCoords[key];
					this.state.distance = 0;
					this.state.durationMinutes = 0;
					this.state.quote = null;
					this.totalEl.textContent = '--';
					this.message('', '');
				});
				this.fields[key]?.addEventListener('change', () => this.calculateRoute());
			});
			this.fields.couponCode?.addEventListener('change', () => this.quote());
			this.fields.customerPhone?.addEventListener('input', () => {
				this.fields.customerPhone.value = this.fields.customerPhone.value.replace(/[^\d\s]/g, '');
			});
			this.applyCouponEl?.addEventListener('click', async () => {
				await this.quote(true);
			});
			this.submitEl?.addEventListener('click', () => this.submit());
			this.searchEl?.addEventListener('click', async () => {
				if (!this.fields.pickupAddress?.value || !this.fields.dropoffAddress?.value) {
					this.message('Please choose both pickup and drop-off from the location dropdown list.', 'error');
					return;
				}
				if (this.state.googleMapsConfigured && (!this.fields.pickupAddress.dataset.rfbPlaceSelected || !this.fields.dropoffAddress.dataset.rfbPlaceSelected)) {
					this.message('Please select both locations from the dropdown list so we can price the route accurately.', 'error');
					return;
				}
				const routed = await this.calculateRoute();
				if (routed || this.state.distance > 0) {
					this.go('time');
				} else {
					this.message('Please choose pickup and drop-off from the dropdown list so the route can be priced correctly.', 'error');
				}
			});
			this.root.querySelectorAll('[data-rfb-next]').forEach((button) => {
				button.addEventListener('click', () => {
					const target = button.dataset.rfbNext;
					if (target === 'review' && !this.validate(this.payload())) {
						return;
					}
					this.go(target);
				});
			});
			this.root.querySelectorAll('[data-rfb-back]').forEach((button) => {
				button.addEventListener('click', () => this.go(button.dataset.rfbBack));
			});
			this.globalBackEl?.addEventListener('click', () => {
				const previous = this.previousStep(this.root.dataset.currentStep || 'route');
				if (previous) {
					this.go(previous);
				}
			});
		}

		go(step) {
			this.root.classList.toggle('is-reviewing', step === 'review');
			this.root.dataset.currentStep = step;
			const steps = ['route', 'time', 'ride', 'details', 'review'];
			const activeIndex = steps.indexOf(step);
			const progress = activeIndex <= 0 ? 0 : Math.round((activeIndex / (steps.length - 1)) * 100);
			this.root.querySelector('[data-rfb-stepper]')?.style.setProperty('--rfb-progress', `${progress}%`);
			this.flowSteps.forEach((element) => element.classList.toggle('is-active', element.dataset.rfbFlowStep === step));
			this.stepDots.forEach((element) => {
				const dotIndex = steps.indexOf(element.dataset.rfbStepDot);
				element.classList.toggle('is-active', dotIndex === activeIndex);
				element.classList.toggle('is-complete', dotIndex < activeIndex);
			});
			if (this.globalBackEl) {
				this.globalBackEl.hidden = step === 'route';
				this.globalBackEl.closest('.rfb-stepper')?.classList.toggle('rfb-stepper-no-back', step === 'route');
			}
			if (step === 'review') {
				this.renderReview();
			}
		}

		previousStep(step) {
			const steps = ['route', 'time', 'ride', 'details', 'review'];
			const index = steps.indexOf(step);
			return index > 0 ? steps[index - 1] : '';
		}

		renderDateTimePickers() {
			if (this.dateStripEl) {
				const today = new Date();
				this.dateStripEl.innerHTML = Array.from({ length: 6 }).map((_, index) => {
					const date = new Date(today);
					date.setDate(today.getDate() + index);
					const value = this.dateValue(date);
					const label = index === 0 ? 'Today' : date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' });
					return `<button type="button" data-rfb-date="${value}" class="${index === 0 ? 'is-active' : ''}">${label}</button>`;
				}).join('');

				this.dateStripEl.querySelectorAll('button').forEach((button) => {
					button.addEventListener('click', () => {
						this.dateStripEl.querySelectorAll('button').forEach((item) => item.classList.remove('is-active'));
						button.classList.add('is-active');
						this.fields.pickupDate.value = button.dataset.rfbDate;
						this.setDefaultTimeForDate();
						this.renderTimeSlots();
						this.quote();
					});
				});

				if (this.fields.pickupDate && !this.fields.pickupDate.value) {
					this.fields.pickupDate.value = this.dateValue(today);
				}
				this.fields.pickupDate?.addEventListener('click', (event) => {
					event.preventDefault();
					this.openCalendar();
				});
				this.fields.pickupDate?.addEventListener('focus', () => this.openCalendar());
				this.fields.pickupDate?.addEventListener('change', () => {
					this.setDefaultTimeForDate();
					this.syncDateStrip();
					this.renderTimeSlots();
					this.quote();
				});
				this.root.querySelector('[data-rfb-calendar-close]')?.addEventListener('click', () => this.closeCalendar());
				this.root.querySelector('[data-rfb-calendar-prev]')?.addEventListener('click', () => this.shiftCalendar(-1));
				this.root.querySelector('[data-rfb-calendar-next]')?.addEventListener('click', () => this.shiftCalendar(1));
				this.renderCalendar();
			}

			if (this.timeGridEl) {
				if (this.fields.pickupTime && !this.fields.pickupTime.value) {
					this.setDefaultTimeForDate();
				}
				this.fields.pickupTime?.addEventListener('change', () => {
					this.renderTimeSlots();
					this.quote();
				});
				this.renderTimeSlots();
			}
		}

		renderTimeSlots() {
			if (!this.timeGridEl) {
				return;
			}

			const slots = this.availableTimeSlots();
			const current = this.fields.pickupTime?.value || '';
			this.timeGridEl.innerHTML = slots.map((slot, index) => {
				const value = slot === 'Now' ? this.currentTimeValue() : slot;
				const active = current === value || (!current && index === 0);
				return `<button type="button" data-rfb-time="${slot}" class="${active ? 'is-active' : ''}">${slot}</button>`;
			}).join('');

			this.timeGridEl.querySelectorAll('button').forEach((button) => {
				button.addEventListener('click', () => {
					this.timeGridEl.querySelectorAll('button').forEach((item) => item.classList.remove('is-active'));
					button.classList.add('is-active');
					this.fields.pickupTime.value = button.dataset.rfbTime === 'Now' ? this.currentTimeValue() : button.dataset.rfbTime;
					this.quote();
				});
			});
		}

		availableTimeSlots() {
			const baseSlots = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '22:00'];
			if (!this.selectedDateIsToday()) {
				return baseSlots;
			}

			const nowValue = this.currentTimeValue();
			const nextSlot = this.roundedFutureTimeValue(15);
			const futureSlots = baseSlots.filter((slot) => slot > nowValue);
			const slots = ['Now'];
			if (nextSlot > nowValue && !futureSlots.includes(nextSlot)) {
				slots.push(nextSlot);
			}
			return slots.concat(futureSlots);
		}

		setDefaultTimeForDate() {
			if (!this.fields.pickupTime) {
				return;
			}

			this.fields.pickupTime.value = this.selectedDateIsToday() ? this.currentTimeValue() : '08:00';
		}

		selectedDateIsToday() {
			return (this.fields.pickupDate?.value || '') === this.dateValue(new Date());
		}

		currentTimeValue() {
			const now = new Date();
			return now.toTimeString().slice(0, 5);
		}

		setDefaultPhonePrefix() {
			const field = this.fields.customerPhonePrefix;
			if (!field || field.value !== '+1') {
				return;
			}

			const language = (navigator.language || '').toLowerCase();
			const timezone = (Intl.DateTimeFormat().resolvedOptions().timeZone || '').toLowerCase();
			const map = [
				{ test: () => language.includes('be') || timezone.includes('brussels'), value: '+32' },
				{ test: () => language.includes('nl') || timezone.includes('amsterdam'), value: '+31' },
				{ test: () => language.includes('fr') || timezone.includes('paris'), value: '+33' },
				{ test: () => language.includes('gb') || timezone.includes('london'), value: '+44' },
				{ test: () => language.includes('de') || timezone.includes('berlin'), value: '+49' },
				{ test: () => language.includes('ma') || timezone.includes('casablanca'), value: '+212' },
			];
			const match = map.find((item) => item.test());
			if (match) {
				field.value = match.value;
			}
		}

		roundedFutureTimeValue(minutes) {
			const now = new Date();
			now.setMinutes(Math.ceil(now.getMinutes() / minutes) * minutes, 0, 0);
			return now.toTimeString().slice(0, 5);
		}

		syncDateStrip() {
			if (!this.dateStripEl || !this.fields.pickupDate) {
				return;
			}
			this.dateStripEl.querySelectorAll('button').forEach((item) => {
				item.classList.toggle('is-active', item.dataset.rfbDate === this.fields.pickupDate.value);
			});
		}

		openCalendar() {
			if (!this.dateModalEl) {
				return;
			}
			this.dateModalEl.hidden = false;
			this.renderCalendar();
		}

		closeCalendar() {
			if (this.dateModalEl) {
				this.dateModalEl.hidden = true;
			}
		}

		shiftCalendar(months) {
			this.calendarCursor.setMonth(this.calendarCursor.getMonth() + months);
			this.renderCalendar();
		}

		renderCalendar() {
			if (!this.calendarMonthsEl) {
				return;
			}

			const today = new Date();
			today.setHours(0, 0, 0, 0);
			const max = new Date(today);
			max.setDate(max.getDate() + 90);
			const first = new Date(this.calendarCursor.getFullYear(), this.calendarCursor.getMonth(), 1);
			const second = new Date(first.getFullYear(), first.getMonth() + 1, 1);
			this.calendarTitleEl.textContent = `${first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })} - ${second.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}`;
			this.calendarMonthsEl.innerHTML = [first, second].map((month) => this.monthHtml(month, today, max)).join('');
			this.calendarMonthsEl.querySelectorAll('[data-rfb-calendar-date]').forEach((button) => {
				button.addEventListener('click', () => {
					this.fields.pickupDate.value = button.dataset.rfbCalendarDate;
					this.dateStripEl?.querySelectorAll('button').forEach((item) => item.classList.toggle('is-active', item.dataset.rfbDate === button.dataset.rfbCalendarDate));
					this.setDefaultTimeForDate();
					this.renderTimeSlots();
					this.quote();
					this.closeCalendar();
				});
			});
		}

		monthHtml(month, today, max) {
			const label = month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
			const startDay = new Date(month.getFullYear(), month.getMonth(), 1).getDay();
			const daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
			const blanks = Array.from({ length: startDay }).map(() => '<span></span>').join('');
			const selected = this.fields.pickupDate?.value || '';
			const days = Array.from({ length: daysInMonth }).map((_, index) => {
				const date = new Date(month.getFullYear(), month.getMonth(), index + 1);
				const value = this.dateValue(date);
				const disabled = date < today || date > max;
				return `<button type="button" data-rfb-calendar-date="${value}" class="${value === selected ? 'is-active' : ''}" ${disabled ? 'disabled' : ''}>${index + 1}</button>`;
			}).join('');
			return `<div class="rfb-calendar-month"><strong>${label}</strong><div class="rfb-calendar-days"><b>Sun</b><b>Mon</b><b>Tue</b><b>Wed</b><b>Thu</b><b>Fri</b><b>Sat</b>${blanks}${days}</div></div>`;
		}

		dateValue(date) {
			const year = date.getFullYear();
			const month = String(date.getMonth() + 1).padStart(2, '0');
			const day = String(date.getDate()).padStart(2, '0');
			return `${year}-${month}-${day}`;
		}

		async load() {
			try {
				const settings = await api('/settings/public');
				this.state.currency = settings.currency || 'USD';
				this.state.distanceUnit = settings.distanceUnit || 'km';
				this.state.googleMapsConfigured = !!settings.googleMapsConfigured;
				this.state.googleMapsMapId = settings.googleMapsMapId || undefined;
				this.state.serviceAreaMap = settings.serviceAreaMap || null;
				if (this.state.googleMapsConfigured) {
					this.initMapsWhenReady();
				} else {
					this.enableManualRoute('Google Maps API key is not configured yet. Enter distance manually for testing.');
				}
				const loads = [this.loadVehicles()];
				if (this.root.dataset.extrasEnabled === 'yes') {
					loads.push(this.loadExtras());
				}
				await Promise.all(loads);
				this.quote();
			} catch (error) {
				this.message('Could not load booking settings yet.', 'error');
			}
		}

		async loadVehicles() {
			const passengers = Number(this.fields.passengers?.value || 1);
			const luggage = Number(this.fields.luggage?.value || 0);
			const vehicles = await api(`/vehicles?passengers=${passengers}&luggage=${luggage}`);
			this.state.vehicles = vehicles;
			this.state.selectedVehicleId = vehicles.length ? Number(vehicles[0].id) : 0;
			this.renderVehicles();
		}

		async loadExtras() {
			const extras = await api('/extras');
			this.state.extras = extras;
			this.renderExtras();
		}

		renderVehicles() {
			if (!this.vehiclesEl) {
				return;
			}

			if (!this.state.vehicles.length) {
				this.state.manualDispatch = true;
				this.state.selectedVehicleId = 0;
				this.vehiclesEl.hidden = false;
				this.vehiclesEl.innerHTML = '<div class="rfb-auto-vehicle rfb-auto-vehicle-dispatch"><strong>Dispatch will assign a vehicle</strong><small>No standard vehicle matches this count — our dispatch team will select the right vehicle for your trip and may adjust the final fare.</small></div>';
				return;
			}

			const vehicle = this.state.vehicles[0];
			this.state.manualDispatch = false;
			this.state.selectedVehicleId = Number(vehicle.id);
			this.vehiclesEl.hidden = true;
			this.vehiclesEl.innerHTML = '';
		}

		renderExtras() {
			if (!this.extrasEl) {
				return;
			}

			if (!this.state.extras.length) {
				this.extrasEl.innerHTML = '<p>No extras configured yet.</p>';
				return;
			}

			this.extrasEl.innerHTML = this.state.extras.map((extra) => `
				<label class="rfb-option rfb-extra-option">
					<span>
						<strong>${extra.name}</strong>
						<small>${money(extra.price, this.state.currency)}</small>
					</span>
					<input type="checkbox" value="${extra.id}">
				</label>
			`).join('');

			this.extrasEl.querySelectorAll('input[type="checkbox"]').forEach((input) => {
				input.addEventListener('change', () => {
					const id = Number(input.value);
					if (input.checked) {
						this.state.selectedExtras.set(id, 1);
						input.closest('.rfb-option')?.classList.add('rfb-option-selected');
					} else {
						this.state.selectedExtras.delete(id);
						input.closest('.rfb-option')?.classList.remove('rfb-option-selected');
					}
					this.quote();
				});
			});
		}

		initMapsWhenReady() {
			let attempts = 0;
			const wait = () => {
				attempts += 1;
				if (window.google && window.google.maps && this.mapEl) {
					this.initMaps();
					return;
				}

				if (attempts < 40) {
					window.setTimeout(wait, 250);
				}
			};
			wait();
		}

		async initMaps() {
			if (this.map) {
				this.refreshMapLayout();
				return;
			}

			this.map = new window.google.maps.Map(this.mapEl, {
				center: this.mapDefaultCenter(),
				zoom: this.mapDefaultZoom(),
				mapId: this.state.googleMapsMapId,
			});
			this.observeMapLayout();
			this.refreshMapLayout();

			const places = window.google.maps.importLibrary ? await window.google.maps.importLibrary('places').catch(() => null) : null;
			if (places && (places.PlaceAutocompleteElement || places.BasicPlaceAutocompleteElement)) {
				this.initPlaceAutocompleteWidgets(places);
			} else {
				this.initLegacyAutocomplete();
			}

			if (this.fields.pickupAddress?.value && this.fields.dropoffAddress?.value) {
				this.calculateRoute();
			}
		}

		initPlaceAutocompleteWidgets(places) {
			const ElementClass = places.PlaceAutocompleteElement || places.BasicPlaceAutocompleteElement;
			['pickupAddress', 'dropoffAddress'].forEach((key) => {
				const field = this.fields[key];
				if (!field || field.value) {
					return;
				}

				const fieldPlaceholder = field.getAttribute('placeholder') || 'Location';
				const widget = new ElementClass({});
				widget.className = 'rfb-place-autocomplete';
				widget.setAttribute('aria-label', fieldPlaceholder);
				widget.setAttribute('placeholder', fieldPlaceholder); // preserve the original placeholder
				field.classList.add('rfb-input-is-backed');
				field.closest('.rfb-location-field')?.classList.add('rfb-location-field-modern');
				field.insertAdjacentElement('afterend', widget);

				widget.addEventListener('gmp-select', async (event) => {
					await this.applyPlaceSelection(event, key);
				});
			});
		}

		async applyPlaceSelection(event, key) {
			const prediction = event.placePrediction || event.detail?.placePrediction;
			const place = prediction && prediction.toPlace ? prediction.toPlace() : event.place || event.detail?.place;
			if (!place || !this.fields[key]) {
				return;
			}

			if (place.fetchFields) {
				await place.fetchFields({ fields: ['displayName', 'formattedAddress', 'location'] });
			}

			this.fields[key].value = place.formattedAddress || place.displayName || '';
			this.fields[key].dataset.rfbPlaceSelected = '1';
			this.storePlaceCoords(key, place.location);
			this.message('', '');
			this.calculateRoute();
		}

		initLegacyAutocomplete() {
			['pickupAddress', 'dropoffAddress'].forEach((key) => {
				if (!this.fields[key] || !window.google?.maps?.places?.Autocomplete) {
					return;
				}

				// Save the placeholder before Google's library potentially clears it.
				const savedPlaceholder = this.fields[key].getAttribute('placeholder') || '';
				const autocomplete = new window.google.maps.places.Autocomplete(this.fields[key], {
					fields: ['formatted_address', 'geometry', 'name'],
				});
				// Restore placeholder immediately after init (Google clears it on some versions).
				if (savedPlaceholder && !this.fields[key].getAttribute('placeholder')) {
					this.fields[key].setAttribute('placeholder', savedPlaceholder);
				}
				autocomplete.addListener('place_changed', () => {
					const place = autocomplete.getPlace();
					if (place && place.formatted_address) {
						this.fields[key].value = place.formatted_address;
						this.fields[key].dataset.rfbPlaceSelected = '1';
						this.storePlaceCoords(key, place.geometry?.location);
						this.message('', '');
					}
					this.calculateRoute();
				});
			});
		}

		storePlaceCoords(key, location) {
			const lat = typeof location?.lat === 'function' ? location.lat() : location?.lat;
			const lng = typeof location?.lng === 'function' ? location.lng() : location?.lng;
			if (Number.isFinite(Number(lat)) && Number.isFinite(Number(lng))) {
				this.state.placeCoords[key] = { lat: Number(lat), lng: Number(lng) };
			}
		}

		async calculateRoute() {
			const origin = this.fields.pickupAddress?.value.trim();
			const destination = this.fields.dropoffAddress?.value.trim();
			const requestId = ++this.routeRequestId;

			if (!origin || !destination) {
				this.clearRouteVisual();
				return false;
			}

			const cached = this.cachedRoute(origin, destination);
			if (cached) {
				this.applyRouteFacts(origin, destination, cached.distance, cached.durationMinutes, requestId);
				await this.drawRouteForCurrentAddresses(origin, destination, requestId);
				return true;
			}

			const serverCached = await this.serverCachedRoute(origin, destination);
			if (serverCached) {
				this.applyRouteFacts(origin, destination, serverCached.distance, serverCached.durationMinutes, requestId);
				this.storeRoute(origin, destination, serverCached.distance, serverCached.durationMinutes);
				await this.drawRouteForCurrentAddresses(origin, destination, requestId);
				return true;
			}

			return this.drawRouteForCurrentAddresses(origin, destination, requestId);
		}

		async drawRouteForCurrentAddresses(origin, destination, requestId) {
			if (!this.isCurrentRoute(origin, destination, requestId)) {
				return false;
			}

			const maps = window.google?.maps;
			if (!maps) {
				this.enableManualRoute('Google Maps is not ready yet. Please choose suggestions from the location dropdown list and try again.');
				return false;
			}

			if (maps.importLibrary) {
				try {
					await this.calculateRouteWithRoutesApi(origin, destination, requestId);
					return true;
				} catch (error) {}
			}

			this.clearRouteVisual();
			this.enableManualRoute('Route could not be calculated. Please choose pickup and drop-off from the dropdown list.');
			return false;
		}

		async calculateRouteWithRoutesApi(origin, destination, requestId) {
			const maps = window.google?.maps;
			if (!maps?.importLibrary) {
				throw new Error('Google Maps Routes library is not ready yet.');
			}

			const { Route } = await maps.importLibrary('routes');
			if (!Route || !Route.computeRoutes) {
				throw new Error('Routes library unavailable');
			}

			const response = await Route.computeRoutes({
				origin,
				destination,
				travelMode: maps.TravelMode?.DRIVING || 'DRIVING',
				routingPreference: 'TRAFFIC_UNAWARE',
				fields: ['distanceMeters', 'durationMillis', 'path', 'viewport'],
			});
			const route = response.routes && response.routes[0];
			if (!route || !route.distanceMeters || !route.durationMillis) {
				throw new Error('No route returned');
			}
			if (!this.isCurrentRoute(origin, destination, requestId)) {
				return;
			}

			const distanceKm = route.distanceMeters / 1000;
			const durationMinutes = route.durationMillis / 60000;
			const distance = this.state.distanceUnit === 'mi' ? distanceKm * 0.621371 : distanceKm;
			this.drawRoutePath(route.path || [], route.viewport);
			this.applyRouteFacts(origin, destination, distance, durationMinutes, requestId);
			this.storeRoute(origin, destination, distance, durationMinutes);
			this.storeServerRoute(origin, destination, distance, durationMinutes, '');
		}

		drawRoutePath(path, viewport) {
			const maps = window.google?.maps;
			if (!this.map || !maps) {
				return;
			}

			if (this.routePolyline) {
				this.routePolyline.setMap(null);
			}

			const points = (path || []).map((point) => {
				const lat = typeof point.lat === 'function' ? point.lat() : point.lat;
				const lng = typeof point.lng === 'function' ? point.lng() : point.lng;
				return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
			}).filter(Boolean);

			if (!points.length) {
				return;
			}

			this.routePolyline = new maps.Polyline({
				path: points,
				map: this.map,
				strokeColor: '#0f766e',
				strokeOpacity: 0.95,
				strokeWeight: 6,
			});

			if (viewport) {
				this.map.fitBounds(viewport);
				return;
			}

			const bounds = new maps.LatLngBounds();
			points.forEach((point) => bounds.extend(point));
			this.map.fitBounds(bounds);
		}

		applyRouteFacts(origin, destination, distance, durationMinutes, requestId) {
			if (!this.isCurrentRoute(origin, destination, requestId)) {
				return;
			}
			this.state.distance = distance;
			this.state.durationMinutes = durationMinutes;
			this.setRouteFacts(distance, durationMinutes);
			this.quote();
		}

		isCurrentRoute(origin, destination, requestId) {
			return requestId === this.routeRequestId
				&& this.fields.pickupAddress?.value.trim() === origin
				&& this.fields.dropoffAddress?.value.trim() === destination;
		}

		ensureMap() {
			const maps = window.google?.maps;
			if (!this.map && maps && this.mapEl) {
				this.map = new maps.Map(this.mapEl, {
					center: this.mapDefaultCenter(),
					zoom: this.mapDefaultZoom(),
					mapId: this.state.googleMapsMapId,
				});
				this.observeMapLayout();
			}
			this.refreshMapLayout();
		}

		mapDefaultCenter() {
			const center = this.state.serviceAreaMap?.center;
			if (center && Number.isFinite(Number(center.lat)) && Number.isFinite(Number(center.lng))) {
				return { lat: Number(center.lat), lng: Number(center.lng) };
			}
			return { lat: 50.8503, lng: 4.3517 };
		}

		mapDefaultZoom() {
			return this.state.serviceAreaMap?.center ? 11 : 10;
		}

		latLng(point) {
			if (!point || !Number.isFinite(Number(point.lat)) || !Number.isFinite(Number(point.lng))) {
				return null;
			}
			return { lat: Number(point.lat), lng: Number(point.lng) };
		}

		clearRouteVisual() {
			if (this.routePolyline) {
				this.routePolyline.setMap(null);
				this.routePolyline = null;
			}
		}

		observeMapLayout() {
			if (this.mapResizeObserver || !this.mapEl || !window.ResizeObserver) {
				return;
			}
			this.mapResizeObserver = new ResizeObserver(() => this.refreshMapLayout());
			this.mapResizeObserver.observe(this.mapEl);
		}

		refreshMapLayout() {
			const maps = window.google?.maps;
			if (!this.map || !maps?.event) {
				return;
			}
			window.setTimeout(() => maps.event.trigger(this.map, 'resize'), 80);
		}

		async quote(showCouponMessage = false) {
			try {
				const quote = await api('/quote', {
					method: 'POST',
					body: JSON.stringify(this.payload()),
				});
				this.state.quote = quote;
				if (quote.blocked || quote.code === 'outside_service_area') {
					this.state.quote = null;
					this.totalEl.textContent = '--';
					this.message(quote.message || 'This ride is outside our service area.', 'error');
					this.renderReview();
					return;
				}
				this.totalEl.textContent = money(quote.total, quote.currency);
				if (quote.requiresApproval && quote.approvalMessage) {
					this.message(`${quote.approvalMessage} This estimate is not final. Dispatch will confirm the final fare when they contact you.`, 'warning');
				} else if (!showCouponMessage) {
					this.message('', '');
				}
				if (showCouponMessage) {
					if (text(this.fields.couponCode?.value) && quote.coupon && quote.coupon.valid) {
						this.message(quote.coupon.message || 'Promo code applied.', 'success');
					} else if (text(this.fields.couponCode?.value)) {
						this.message(quote.coupon?.message || 'This promo code is not valid for this ride.', 'error');
					} else {
						this.message('Enter a promo code first.', 'error');
					}
				}
				this.renderReview();
			} catch (error) {
				this.totalEl.textContent = '--';
				if (error.details?.code === 'outside_service_area') {
					this.message(error.details.message || 'This ride is outside our service area.', 'error');
				} else if (showCouponMessage) {
					this.message('Could not check this promo code yet.', 'error');
				}
			}
		}

		payload() {
			return {
				sessionId: this.state.sessionId,
				serviceType: 'distance',
				transferType: 'one_way',
				pickupAddress: this.fields.pickupAddress?.value || '',
				dropoffAddress: this.fields.dropoffAddress?.value || '',
				pickup_lat: this.state.placeCoords.pickupAddress?.lat,
				pickup_lng: this.state.placeCoords.pickupAddress?.lng,
				dropoff_lat: this.state.placeCoords.dropoffAddress?.lat,
				dropoff_lng: this.state.placeCoords.dropoffAddress?.lng,
				pickupDate: this.fields.pickupDate?.value || '',
				pickupTime: this.fields.pickupTime?.value || '',
				distance: this.state.distance,
				durationMinutes: this.state.durationMinutes,
				passengers: Number(this.fields.passengers?.value || 1),
				luggage: Number(this.fields.luggage?.value || 0),
				vehicleId: this.state.selectedVehicleId,
				routeId: Number(this.root.dataset.routeId || 0),
				extras: Array.from(this.state.selectedExtras.entries()).map(([id, quantity]) => ({ id, quantity })),
				couponCode: this.fields.couponCode?.value || '',
				customerFirstName: this.fields.customerFirstName?.value || '',
				customerLastName: this.fields.customerLastName?.value || '',
				customerEmail: this.fields.customerEmail?.value || '',
				customerPhone: this.normalizedPhone(),
				note: this.fields.note?.value || '',
			};
		}

		normalizedPhone() {
			const phone = text(this.fields.customerPhone?.value || '');
			if (!phone || phone.startsWith('+')) {
				return phone;
			}
			const prefix = this.fields.customerPhonePrefix?.value || '';
			return `${prefix} ${phone}`.trim();
		}

		validate(payload) {
			const required = ['pickupAddress', 'dropoffAddress', 'pickupDate', 'pickupTime', 'customerFirstName', 'customerLastName', 'customerEmail', 'customerPhone'];
			const missing = required.filter((key) => !String(payload[key] || '').trim());

			if (missing.length) {
				this.message('Please complete all required fields.', 'error');
				return false;
			}

			const phoneDigits = text(this.fields.customerPhone?.value || '').replace(/\D/g, '');
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(payload.customerEmail || ''))) {
				this.message('Please enter a valid email address.', 'error');
				return false;
			}

			if (phoneDigits.length < 7 || phoneDigits.length > 15) {
				this.message('Please enter a valid phone number using numbers only.', 'error');
				return false;
			}

			if (!payload.vehicleId && !this.state.manualDispatch) {
				this.message('Please enter passenger and luggage counts to check available vehicles.', 'error');
				return false;
			}

			if (!payload.distance) {
				this.message('Please choose pickup and drop-off from the dropdown list so the route can be priced.', 'error');
				return false;
			}

			if (!this.state.quote) {
				this.message('Please get a valid quote before creating the booking.', 'error');
				return false;
			}

			return true;
		}

		renderReview() {
			if (!this.reviewEls.pickup) {
				return;
			}

			const payload = this.payload();
			const extras = this.state.extras
				.filter((item) => this.state.selectedExtras.has(Number(item.id)))
				.map((item) => item.name);
			const customer = [payload.customerFirstName, payload.customerLastName].filter(Boolean).join(' ');
			const when = [payload.pickupDate, payload.pickupTime].filter(Boolean).join(' at ');

			this.reviewEls.pickup.textContent = payload.pickupAddress || '--';
			this.reviewEls.dropoff.textContent = payload.dropoffAddress || '--';
			this.reviewEls.when.textContent = when || '--';
			this.reviewEls.passengers.textContent = String(payload.passengers || 1);
			this.reviewEls.luggage.textContent = String(payload.luggage || 0);
			this.reviewEls.extras.textContent = extras.length ? extras.join(', ') : 'No extras';
			this.reviewEls.customer.textContent = customer || payload.customerEmail || '--';
			this.reviewEls.phone.textContent = payload.customerPhone || '--';
			if (this.reviewEls.total) {
				const total = this.state.quote ? money(this.state.quote.total, this.state.quote.currency) : '--';
				const distance = this.distanceEl?.textContent || '--';
				const duration = this.durationEl?.textContent || '--';
				this.reviewEls.total.textContent = `${total} - ${distance} - ${duration}`;
			}
		}

		async submit() {
			const payload = this.payload();
			if (!this.validate(payload)) {
				return;
			}

			this.submitEl.disabled = true;
			this.message('Creating your booking...', '');

			try {
				const response = await api('/bookings', {
					method: 'POST',
					body: JSON.stringify(payload),
				});

				if (response.checkoutUrl) {
				this.message(`Booking ${response.booking.bookingNumber} created. Redirecting to checkout...`, 'success');
					window.location.href = response.checkoutUrl;
					return;
				}

				this.showConfirmation(response.booking.bookingNumber, payload);
			} catch (error) {
				if (error.details?.code === 'outside_service_area') {
					this.message(error.details.message || 'This ride is outside our service area.', 'error');
				} else {
					this.message(error.details?.message || 'Could not create the booking. Please try again.', 'error');
				}
			} finally {
				this.submitEl.disabled = false;
			}
		}

		showConfirmation(bookingNumber, payload = {}) {
			this.flowSteps.forEach((element) => element.classList.remove('is-active'));
			this.stepDots.forEach((element) => element.classList.remove('is-active'));
			this.root.classList.add('is-complete');
			this.root.classList.remove('is-reviewing');
			if (this.confirmationEl) {
				this.confirmationEl.hidden = false;
			}
			if (this.confirmationTextEl) {
				this.confirmationTextEl.textContent = `Booking ${bookingNumber} confirmed. We will contact you if anything needs to be confirmed.`;
			}

			// ── Add to Calendar ───────────────────────────────────────────────────────
			if (this.confirmationEl && payload.pickupDate && payload.pickupTime) {
				// Build a Date from the local pickup date + time (user-selected, no UTC offset).
				const [year, month, day] = payload.pickupDate.split('-').map(Number);
				const [hour, minute]     = payload.pickupTime.split(':').map(Number);
				const startDt = new Date(year, month - 1, day, hour, minute, 0);
				const endDt   = new Date(startDt.getTime() + 60 * 60 * 1000); // estimate 1 h

				const pad2 = (n) => String(n).padStart(2, '0');
				// Format as local YYYYMMDDTHHMMSS (no Z — local time event, not UTC).
				const fmtLocal = (d) =>
					`${d.getFullYear()}${pad2(d.getMonth()+1)}${pad2(d.getDate())}T` +
					`${pad2(d.getHours())}${pad2(d.getMinutes())}00`;

				const route   = [payload.pickupAddress, payload.dropoffAddress].filter(Boolean).join(' → ');
				const summary = `Taxi ride: ${route}`;
				const desc    = `Booking reference: ${bookingNumber}`;

				// Google Calendar link
				const gcUrl = 'https://calendar.google.com/calendar/render?' + new URLSearchParams({
					action: 'TEMPLATE',
					text: summary,
					dates: `${fmtLocal(startDt)}/${fmtLocal(endDt)}`,
					details: desc,
					location: payload.pickupAddress || '',
				}).toString();

				// ICS blob for Apple Calendar / Outlook / any calendar app
				const icsLines = [
					'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//RideFleet//Booking//EN',
					'BEGIN:VEVENT',
					`DTSTART:${fmtLocal(startDt)}`,
					`DTEND:${fmtLocal(endDt)}`,
					`SUMMARY:${summary}`,
					`DESCRIPTION:${desc}`,
					`LOCATION:${payload.pickupAddress || ''}`,
					`UID:${bookingNumber}@ridefleet`,
					'END:VEVENT', 'END:VCALENDAR',
				].join('\r\n');
				const icsUrl = URL.createObjectURL(new Blob([icsLines], { type: 'text/calendar;charset=utf-8' }));

				const calSection = document.createElement('div');
				calSection.className = 'rfb-cal-section';
				calSection.innerHTML =
					'<p class="rfb-cal-label">Add to calendar</p>' +
					'<div class="rfb-cal-links">' +
					`<a href="${gcUrl}" target="_blank" rel="noopener noreferrer" class="rfb-cal-btn rfb-cal-google">` +
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 9h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
					' Google Calendar</a>' +
					`<a href="${icsUrl}" download="booking-${bookingNumber}.ics" class="rfb-cal-btn rfb-cal-apple">` +
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 9h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="8" cy="14" r="1" fill="currentColor"/><circle cx="12" cy="14" r="1" fill="currentColor"/><circle cx="16" cy="14" r="1" fill="currentColor"/></svg>' +
					' Apple / Other</a>' +
					'</div>';
				this.confirmationEl.appendChild(calSection);
			}

			this.message('', '');
		}

		summary(text) {
			if (this.summaryEl) {
				this.summaryEl.textContent = text;
			}
			if (this.floatingRouteEl) {
				this.floatingRouteEl.textContent = text;
			}
		}

		setRouteFacts(distance, durationMinutes) {
			const distanceText = `${Number(distance || 0).toFixed(1)} ${this.state.distanceUnit}`;
			const durationText = `${Math.round(Number(durationMinutes || 0))} min`;
			this.summary(`${distanceText} - ${durationText}`);
			if (this.distanceEl) {
				this.distanceEl.textContent = distanceText;
			}
			if (this.durationEl) {
				this.durationEl.textContent = durationText;
			}
		}

		cacheKey(origin, destination) {
			return `rfb_route_${this.state.distanceUnit}_${origin.trim().toLowerCase()}_${destination.trim().toLowerCase()}`;
		}

		cachedRoute(origin, destination) {
			try {
				const raw = window.localStorage?.getItem(this.cacheKey(origin, destination));
				if (!raw) {
					return null;
				}
				const cached = JSON.parse(raw);
				if (!cached.expires || cached.expires < Date.now()) {
					window.localStorage.removeItem(this.cacheKey(origin, destination));
					return null;
				}
				return cached;
			} catch (error) {
				return null;
			}
		}

		storeRoute(origin, destination, distance, durationMinutes) {
			try {
				window.localStorage?.setItem(
					this.cacheKey(origin, destination),
					JSON.stringify({
						distance,
						durationMinutes,
						expires: Date.now() + 1000 * 60 * 60 * 24,
					})
				);
			} catch (error) {}
		}

		async serverCachedRoute(origin, destination) {
			try {
				const cached = await api(`/route-cache?origin=${encodeURIComponent(origin)}&destination=${encodeURIComponent(destination)}`);
				return cached.cached ? cached : null;
			} catch (error) {
				return null;
			}
		}

		storeServerRoute(origin, destination, distance, durationMinutes, polyline) {
			api('/route-cache', {
				method: 'POST',
				body: JSON.stringify({
					origin,
					destination,
					distance,
					durationSeconds: Math.round(durationMinutes * 60),
					polyline,
				}),
			}).catch(() => {});
		}

		message(text, type) {
			if (!this.messageEl) {
				return;
			}

			this.messageEl.textContent = text;
			this.messageEl.className = `rfb-message ${type ? `rfb-message-${type}` : ''}`;
		}

		enableManualRoute(text) {
			this.summary(text);
			if (this.manualRouteEl) {
				this.manualRouteEl.hidden = true;
			}
			if (this.mapEl && !this.state.googleMapsConfigured) {
				this.mapEl.classList.add('rfb-map-disabled');
				this.mapEl.textContent = 'Google Maps API key required';
			}
		}
	}

	forms.forEach((form) => new BookingForm(form));
}());

