(function () {
	'use strict';

	function ready(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}
		callback();
	}

	function mapsReady(callback, attempts) {
		attempts = attempts || 0;
		if (window.google && window.google.maps) {
			Promise.all([
				window.google.maps.importLibrary ? window.google.maps.importLibrary('maps') : Promise.resolve({ Map: window.google.maps.Map }),
				window.google.maps.importLibrary ? window.google.maps.importLibrary('marker').catch(function () { return {}; }) : Promise.resolve({ Marker: window.google.maps.Marker }),
				window.google.maps.importLibrary ? window.google.maps.importLibrary('places').catch(function () { return {}; }) : Promise.resolve({ Autocomplete: window.google.maps.places && window.google.maps.places.Autocomplete })
			]).then(function (libraries) {
				var mapsLibrary = libraries[0] || {};
				var markerLibrary = libraries[1] || {};
				var placesLibrary = libraries[2] || {};
				var maps = window.google.maps;
				maps.Map = mapsLibrary.Map || maps.Map;
				maps.Marker = markerLibrary.Marker || maps.Marker;
				maps.Polyline = mapsLibrary.Polyline || maps.Polyline;
				maps.Polygon = mapsLibrary.Polygon || maps.Polygon;
				maps.LatLngBounds = mapsLibrary.LatLngBounds || maps.LatLngBounds;
				maps.places = maps.places || {};
				maps.places.Autocomplete = placesLibrary.Autocomplete || maps.places.Autocomplete;
				if (maps.Map) {
					callback(maps);
				}
			});
			return;
		}
		if (attempts < 40) {
			window.setTimeout(function () {
				mapsReady(callback, attempts + 1);
			}, 250);
		}
	}

	function initPlaceSearch(maps) {
		document.querySelectorAll('[data-rfb-place-search]').forEach(function (input) {
			if (!maps.places || !maps.places.Autocomplete) {
				return;
			}
			var autocomplete = new maps.places.Autocomplete(input, {
				fields: ['formatted_address', 'geometry', 'name']
			});
			autocomplete.addListener('place_changed', function () {
				var place = autocomplete.getPlace();
				var location = place && place.geometry && place.geometry.location;
				if (!location) {
					return;
				}
				if (place.formatted_address) {
					input.value = place.formatted_address;
				}
				var latField = document.querySelector('[name="' + input.dataset.rfbTargetLat + '"]');
				var lngField = document.querySelector('[name="' + input.dataset.rfbTargetLng + '"]');
				if (latField) {
					latField.value = location.lat().toFixed(6);
					latField.dispatchEvent(new Event('change', { bubbles: true }));
				}
				if (lngField) {
					lngField.value = location.lng().toFixed(6);
					lngField.dispatchEvent(new Event('change', { bubbles: true }));
				}
				var zoneTarget = input.dataset.rfbTargetZone;
				if (zoneTarget) {
					var zoneField = document.querySelector('[name="' + zoneTarget + '"]');
					if (zoneField) {
						zoneField.value = JSON.stringify({
							center: { lat: Number(location.lat().toFixed(6)), lng: Number(location.lng().toFixed(6)) },
							radius: Number(input.dataset.rfbDefaultRadius || 5)
						}, null, 2);
					}
				}
				input.dispatchEvent(new Event('rfb:place-selected', { bubbles: true }));
			});
		});
	}

	function initFlatRateRows(maps) {
		document.querySelectorAll('[data-rfb-add-flat-rate]').forEach(function (button) {
			button.addEventListener('click', function () {
				var table = document.querySelector('[data-rfb-flat-rate-table]');
				var template = document.querySelector('[data-rfb-flat-rate-template]');
				if (!table || !template) {
					return;
				}
				var index = Number(table.dataset.nextIndex || table.querySelectorAll('.rfb-flat-rate-row').length);
				var html = template.innerHTML.replace(/__INDEX__/g, String(index));
				var wrapper = document.createElement('div');
				wrapper.innerHTML = html.trim();
				var row = wrapper.firstElementChild;
				table.appendChild(row);
				table.dataset.nextIndex = String(index + 1);
				initPlaceSearch(maps);
			});
		});
		document.addEventListener('click', function (event) {
			var remove = event.target.closest('[data-rfb-remove-flat-rate]');
			if (remove) {
				remove.closest('.rfb-flat-rate-row')?.remove();
			}
		});
	}

	function initRouteBuilders(maps) {
		document.querySelectorAll('[data-rfb-route-builder]').forEach(function (builder) {
			var mapEl = builder.querySelector('[data-rfb-route-map]');
			if (!mapEl) {
				return;
			}

			var originInput = builder.querySelector('[name="rfb_origin"], [name="pickupAddress"]');
			var destinationInput = builder.querySelector('[name="rfb_destination"], [name="dropoffAddress"]');
			var originLat = builder.querySelector('[name="rfb_origin_lat"], [name="manual_pickup_lat"]');
			var originLng = builder.querySelector('[name="rfb_origin_lng"], [name="manual_pickup_lng"]');
			var destinationLat = builder.querySelector('[name="rfb_destination_lat"], [name="manual_dropoff_lat"]');
			var destinationLng = builder.querySelector('[name="rfb_destination_lng"], [name="manual_dropoff_lng"]');
			var radiusInput = builder.querySelector('[name="rfb_match_radius"]');
			var center = { lat: 50.8503, lng: 4.3517 };
			var routePolyline = null;
			var originCircle = null;
			var destinationCircle = null;
			var updatingFromCircle = false;
			var map = new maps.Map(mapEl, {
				center: center,
				zoom: 8,
				mapTypeControl: false,
				streetViewControl: false
			});

			function coords(latField, lngField) {
				var lat = Number(latField && latField.value);
				var lng = Number(lngField && lngField.value);
				return Number.isFinite(lat) && Number.isFinite(lng) && lat && lng ? { lat: lat, lng: lng } : null;
			}

			function getRadiusMeters() {
				return (parseFloat(radiusInput && radiusInput.value) || 15) * 1000;
			}

			function makeCircle(position, radiusMeters) {
				return new maps.Circle({
					center: position,
					radius: radiusMeters,
					map: map,
					strokeColor: '#0f766e',
					strokeOpacity: 0.75,
					strokeWeight: 2,
					fillColor: '#0f766e',
					fillOpacity: 0.10,
					editable: true,
					zIndex: 1
				});
			}

			function syncRadiusFromCircle(sourceCircle, otherCircle) {
				if (updatingFromCircle) { return; }
				updatingFromCircle = true;
				var km = (sourceCircle.getRadius() / 1000).toFixed(1);
				if (radiusInput) { radiusInput.value = km; }
				if (otherCircle) { otherCircle.setRadius(sourceCircle.getRadius()); }
				updatingFromCircle = false;
			}

			function updateCircles() {
				if (originCircle) { originCircle.setMap(null); originCircle = null; }
				if (destinationCircle) { destinationCircle.setMap(null); destinationCircle = null; }
				var rm = getRadiusMeters();
				var oCoords = coords(originLat, originLng);
				var dCoords = coords(destinationLat, destinationLng);
				if (oCoords) {
					originCircle = makeCircle(oCoords, rm);
					originCircle.addListener('radius_changed', function () { syncRadiusFromCircle(originCircle, destinationCircle); });
				}
				if (dCoords) {
					destinationCircle = makeCircle(dCoords, rm);
					destinationCircle.addListener('radius_changed', function () { syncRadiusFromCircle(destinationCircle, originCircle); });
				}
			}

			function drawPath(points, viewport) {
				if (routePolyline) {
					routePolyline.setMap(null);
				}
				if (!points.length) {
					return;
				}
				routePolyline = new maps.Polyline({
					path: points,
					map: map,
					strokeColor: '#0f766e',
					strokeOpacity: 0.95,
					strokeWeight: 5
				});
				if (viewport) {
					map.fitBounds(viewport);
					return;
				}
				var bounds = new maps.LatLngBounds();
				points.forEach(function (point) {
					bounds.extend(point);
				});
				map.fitBounds(bounds);
			}

			async function updatePreview() {
				var origin = coords(originLat, originLng) || (originInput && originInput.value ? originInput.value : null);
				var destination = coords(destinationLat, destinationLng) || (destinationInput && destinationInput.value ? destinationInput.value : null);
				updateCircles();
				if (!origin || !destination) {
					return;
				}
				if (!maps.importLibrary) {
					return;
				}
				try {
					var library = await maps.importLibrary('routes');
					var response = await library.Route.computeRoutes({
						origin: origin,
						destination: destination,
						travelMode: maps.TravelMode && maps.TravelMode.DRIVING ? maps.TravelMode.DRIVING : 'DRIVING',
						routingPreference: 'TRAFFIC_UNAWARE',
						fields: ['distanceMeters', 'durationMillis', 'path', 'viewport']
					});
					var route = response.routes && response.routes[0];
					if (!route || !route.distanceMeters || !route.durationMillis) {
						return;
					}
					var distance = builder.querySelector('[name="rfb_sample_distance"], [name="distance"]');
					var duration = builder.querySelector('[name="rfb_sample_duration"], [name="durationMinutes"]');
					if (distance) {
						distance.value = (route.distanceMeters / 1000).toFixed(2);
					}
					if (duration) {
						duration.value = Math.round(route.durationMillis / 60000);
					}
					drawPath((route.path || []).map(function (point) {
						var lat = typeof point.lat === 'function' ? point.lat() : point.lat;
						var lng = typeof point.lng === 'function' ? point.lng() : point.lng;
						return Number.isFinite(lat) && Number.isFinite(lng) ? { lat: lat, lng: lng } : null;
					}).filter(Boolean), route.viewport);
				} catch (error) {}
			}

			['change', 'rfb:place-selected'].forEach(function (eventName) {
				[originInput, destinationInput, originLat, originLng, destinationLat, destinationLng].forEach(function (field) {
					if (field) {
						field.addEventListener(eventName, updatePreview);
					}
				});
			});

			if (radiusInput) {
				radiusInput.addEventListener('input', function () {
					var rm = getRadiusMeters();
					if (originCircle) { originCircle.setRadius(rm); }
					if (destinationCircle) { destinationCircle.setRadius(rm); }
				});
			}

			updatePreview();
		});
	}

	function initPolygonHelpers(maps) {
		document.querySelectorAll('[data-rfb-polygon-helper]').forEach(function (helper) {
			var mapEl = helper.querySelector('[data-rfb-click-map]');
			var output = helper.querySelector('[data-rfb-polygon-output]');
			var clear = helper.querySelector('[data-rfb-clear-polygon]');
			if (!mapEl || !output) {
				return;
			}
			var center = {
				lat: Number(mapEl.dataset.lat || 50.8503),
				lng: Number(mapEl.dataset.lng || 4.3517)
			};
			var points = [];
			var markers = [];
			var polygon = null;
			var map = new maps.Map(mapEl, {
				center: center,
				zoom: 11,
				mapTypeControl: false,
				streetViewControl: false
			});

			function redraw() {
				if (polygon) {
					polygon.setMap(null);
				}
				polygon = new maps.Polygon({
					paths: points,
					map: map,
					strokeColor: '#0f766e',
					strokeOpacity: 0.9,
					strokeWeight: 2,
					fillColor: '#0f766e',
					fillOpacity: 0.16
				});
				output.value = JSON.stringify({ type: 'Polygon', coordinates: [points.map(function (point) {
					return { lat: Number(point.lat.toFixed(6)), lng: Number(point.lng.toFixed(6)) };
				})] }, null, 2);
			}

			function marker(point, index) {
				if (maps.Marker) {
					markers.push(new maps.Marker({
						position: point,
						map: map,
						label: String(index + 1)
					}));
				}
			}

			function loadExistingPolygon() {
				if (!output.value) {
					return;
				}
				try {
					var data = JSON.parse(output.value);
					var raw = data.coordinates && data.coordinates[0] ? data.coordinates[0] : data.points || data.polygon || [];
					points = raw.map(function (point) {
						if (Array.isArray(point)) {
							return { lat: Number(point[1]), lng: Number(point[0]) };
						}
						return { lat: Number(point.lat), lng: Number(point.lng) };
					}).filter(function (point) {
						return Number.isFinite(point.lat) && Number.isFinite(point.lng);
					});
					points.forEach(marker);
					if (points.length) {
						redraw();
						var bounds = new maps.LatLngBounds();
						points.forEach(function (point) {
							bounds.extend(point);
						});
						map.fitBounds(bounds);
					}
				} catch (error) {}
			}

			map.addListener('click', function (event) {
				var point = { lat: event.latLng.lat(), lng: event.latLng.lng() };
				points.push(point);
				marker(point, points.length - 1);
				redraw();
			});

			if (clear) {
				clear.addEventListener('click', function () {
					points = [];
					markers.forEach(function (marker) {
						marker.setMap(null);
					});
					markers = [];
					if (polygon) {
						polygon.setMap(null);
					}
					output.value = '';
				});
			}
			loadExistingPolygon();
		});
	}

	ready(function () {
		if (window.RideFleetAdmin && window.RideFleetAdmin.screen && window.RideFleetAdmin.screen.indexOf('ridefleet-settings') !== -1) {
			console.info('[RideFleet] Dispatcher API settings', {
				enabled: Boolean(window.RideFleetAdmin.dispatcherApiEnabled),
				hasKey: Boolean(window.RideFleetAdmin.dispatcherApiHasKey),
				baseUrl: window.RideFleetAdmin.dispatcherBaseUrl || ''
			});
		}
		mapsReady(function (maps) {
			initPlaceSearch(maps);
			initPolygonHelpers(maps);
			initRouteBuilders(maps);
			initFlatRateRows(maps);
		});
	});
}());
