/* global rfacAdmin */
(function () {
	'use strict';

	function testConnection(type, resultEl, statusEl) {
		resultEl.textContent = '';
		resultEl.className = 'rfac-test-result';
		statusEl.textContent = type === 'openrouter' ? 'Testing OpenRouter...' : 'Testing Core API...';
		statusEl.className = 'rfac-test-status rfac-test-status-loading';

		var body = new URLSearchParams({
			action: type === 'openrouter' ? 'rfac_test_openrouter' : 'rfac_test_core_api',
			_ajax_nonce: rfacAdmin.nonce
		});

		if (type === 'openrouter') {
			var keyInput = document.querySelector('[name="openrouter_key"]');
			if (keyInput) {
				body.set('key', keyInput.value);
			}
		}

		fetch(rfacAdmin.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success) {
					statusEl.textContent = '✓ ' + data.data;
					statusEl.className = 'rfac-test-status rfac-test-status-ok';
				} else {
					statusEl.textContent = '✗ ' + (data.data || 'Connection failed');
					statusEl.className = 'rfac-test-status rfac-test-status-error';
				}
			})
			.catch(function () {
				statusEl.textContent = '✗ Request failed';
				statusEl.className = 'rfac-test-status rfac-test-status-error';
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var orBtn = document.getElementById('rfac-test-openrouter');
		var orStatus = document.getElementById('rfac-test-openrouter-status');
		if (orBtn && orStatus) {
			orBtn.addEventListener('click', function () {
				testConnection('openrouter', orBtn, orStatus);
			});
		}

		var coreBtn = document.getElementById('rfac-test-core-api');
		var coreStatus = document.getElementById('rfac-test-core-api-status');
		if (coreBtn && coreStatus) {
			coreBtn.addEventListener('click', function () {
				testConnection('core_api', coreBtn, coreStatus);
			});
		}

		/* Model picker: select drives the hidden text field; "Custom" reveals it */
		var modelPicker = document.getElementById('rfac-model-picker');
		var modelValue = document.getElementById('rfac-model-value');
		if (modelPicker && modelValue) {
			function syncModelPicker() {
				if (modelPicker.value === '__custom__') {
					modelValue.style.display = '';
					modelValue.readOnly = false;
					if (!modelValue.value || modelPicker.querySelector('option[value="' + modelValue.value + '"]:not([value="__custom__"])')) {
						modelValue.value = '';
					}
					modelValue.focus();
				} else {
					modelValue.value = modelPicker.value;
					modelValue.style.display = 'none';
					modelValue.readOnly = true;
				}
			}
			// Initial sync: hide text field unless current value is custom
			if (modelPicker.value !== '__custom__') {
				modelValue.style.display = 'none';
				modelValue.readOnly = true;
			} else {
				modelValue.style.display = '';
				modelValue.readOnly = false;
			}
			modelPicker.addEventListener('change', syncModelPicker);
		}

		function bindModelPicker(pickerId, valueId) {
			var picker = document.getElementById(pickerId);
			var value  = document.getElementById(valueId);
			if (!picker || !value) return;
			function sync() {
				if (picker.value === '__custom__') {
					value.style.display = '';
					value.readOnly = false;
					if (picker.querySelector('option[value="' + value.value + '"]:not([value="__custom__"])')) {
						value.value = '';
					}
					value.focus();
				} else {
					value.value = picker.value;
					value.style.display = 'none';
					value.readOnly = true;
				}
			}
			if (picker.value !== '__custom__') { value.style.display='none'; value.readOnly=true; } else { value.style.display=''; value.readOnly=false; }
			picker.addEventListener('change', sync);
		}
		bindModelPicker('rfac-fast-picker',    'rfac-fast-value');
		bindModelPicker('rfac-quality-picker', 'rfac-quality-value');

		/* Company bio AI rewrite */
		var bio = document.getElementById('rfac-company-bio');
		var rewriteStatus = document.getElementById('rfac-rewrite-status');
		var rewriteButtons = document.querySelectorAll('[data-rfac-rewrite]');

		rewriteButtons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (!bio || !rewriteStatus) return;
				var text = bio.value.trim();
				if (!text) {
					rewriteStatus.textContent = '✗ Bio is empty — write something first.';
					rewriteStatus.className = 'rfac-test-status rfac-test-status-error';
					return;
				}

				var mode = btn.getAttribute('data-rfac-rewrite') || 'concise';
				rewriteButtons.forEach(function (b) { b.disabled = true; });
				rewriteStatus.textContent = 'Rewriting (' + mode + ')...';
				rewriteStatus.className = 'rfac-test-status rfac-test-status-loading';

				var body = new URLSearchParams({
					action: 'rfac_rewrite_bio',
					_ajax_nonce: rfacAdmin.nonce,
					mode: mode,
					text: text
				});

				fetch(rfacAdmin.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				})
					.then(function (r) { return r.json(); })
					.then(function (data) {
						if (data.success && data.data && data.data.rewritten) {
							var original = bio.value;
							bio.value = data.data.rewritten;
							rewriteStatus.innerHTML = '✓ Rewritten. <a href="#" id="rfac-rewrite-undo">Undo</a>';
							rewriteStatus.className = 'rfac-test-status rfac-test-status-ok';
							var undo = document.getElementById('rfac-rewrite-undo');
							if (undo) {
								undo.addEventListener('click', function (e) {
									e.preventDefault();
									bio.value = original;
									rewriteStatus.textContent = 'Restored original.';
									rewriteStatus.className = 'rfac-test-status';
								});
							}
						} else {
							rewriteStatus.textContent = '✗ ' + (data.data || 'Rewrite failed');
							rewriteStatus.className = 'rfac-test-status rfac-test-status-error';
						}
					})
					.catch(function () {
						rewriteStatus.textContent = '✗ Request failed';
						rewriteStatus.className = 'rfac-test-status rfac-test-status-error';
					})
					.finally(function () {
						rewriteButtons.forEach(function (b) { b.disabled = false; });
					});
			});
		});

		/* Live theme preview */
		var colorInputs = document.querySelectorAll('.rfac-color-grid input[type="color"]');
		var previewWidget = document.querySelector('.rfac-theme-preview');
		colorInputs.forEach(function (input) {
			input.addEventListener('input', function () {
				if (!previewWidget) return;
				var map = {
					theme_primary: '--rfac-primary',
					theme_primary_dark: '--rfac-primary-dark',
					theme_surface: '--rfac-surface',
					theme_text: '--rfac-text',
					theme_muted: '--rfac-muted'
				};
				var prop = map[input.name];
				if (prop) {
					previewWidget.style.setProperty(prop, input.value);
				}
			});
		});

		// ── FAQ builder ──────────────────────────────────────────────────────────
		var faqList = document.getElementById('rfac-faq-list');
		var faqAdd  = document.getElementById('rfac-faq-add');

		function faqRowHtml(idx) {
			return '<div class="rfac-faq-row" data-index="' + idx + '">'
				+ '<div class="rfac-faq-row__fields">'
				+ '<input type="text" name="faq_question[]" value="" placeholder="Question keyword(s), e.g. service area, payment methods" class="rfac-faq-row__question" />'
				+ '<textarea name="faq_answer[]" rows="2" placeholder="Answer the chatbot will give" class="rfac-faq-row__answer"></textarea>'
				+ '</div>'
				+ '<button type="button" class="rfac-faq-row__remove button" title="Remove">✕</button>'
				+ '</div>';
		}

		function updateFaqAddBtn() {
			if (!faqAdd || !faqList) return;
			faqAdd.disabled = faqList.querySelectorAll('.rfac-faq-row').length >= 10;
		}

		if (faqList) {
			faqList.addEventListener('click', function(e) {
				var btn = e.target.closest('.rfac-faq-row__remove');
				if (!btn) return;
				var row = btn.closest('.rfac-faq-row');
				if (row && faqList.querySelectorAll('.rfac-faq-row').length > 1) {
					row.remove();
					updateFaqAddBtn();
				}
			});
		}

		if (faqAdd) {
			faqAdd.addEventListener('click', function() {
				if (!faqList) return;
				var rows = faqList.querySelectorAll('.rfac-faq-row');
				if (rows.length >= 10) return;
				faqList.insertAdjacentHTML('beforeend', faqRowHtml(rows.length));
				updateFaqAddBtn();
			});
		}
	});
})();
