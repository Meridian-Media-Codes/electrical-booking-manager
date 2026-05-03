(function () {
	'use strict';

	const memoryCache = {
		jobs: null,
		addons: {},
		slots: {},
		quotes: {},
	};

	const appStates = new WeakMap();

	window.ebmGooglePlacesLoaded = function () {
		document.querySelectorAll('[data-ebm-booking-app], #ebm-booking-app, .ebm-booking-form').forEach(function (app) {
			const state = appStates.get(app);

			if (state) {
				initAddressAutocomplete(app, state);
			}
		});
	};

	function createInitialState() {
		return {
			step: 1,
			jobId: null,
			addons: {},
			date: '',
			time: '',
			slots: [],
			customer: {},
			quote: null,
			voucherCode: '',
			calendarMonth: null,
			addressAutocompleteReady: false,
			addressSelectedFromGoogle: false,
		};
	}

	function qs(selector, root = document) {
		return root.querySelector(selector);
	}

	function qsa(selector, root = document) {
		return Array.from(root.querySelectorAll(selector));
	}

	function pad(value) {
		return String(value).padStart(2, '0');
	}

	function ukToIso(value) {
		if (!value) {
			return '';
		}

		if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
			return value;
		}

		const parts = String(value).split('/');

		if (parts.length !== 3) {
			return value;
		}

		return `${parts[2]}-${parts[1]}-${parts[0]}`;
	}

	function formatMonthTitle(date) {
		return date.toLocaleDateString('en-GB', {
			month: 'long',
			year: 'numeric',
		});
	}

	function dispatchChange(element) {
		element.dispatchEvent(new Event('input', { bubbles: true }));
		element.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function getConfig() {
		const config = window.ebmBooking || {};

		return {
			restUrl: config.restUrl || '/wp-json/ebm/v1/',
			nonce: config.nonce || '',
			preloadedJobs: Array.isArray(config.preloadedJobs) ? config.preloadedJobs : [],
			cacheVersion: config.cacheVersion || '1',
			googlePlacesApiKey: config.googlePlacesApiKey || '',
			allowedPostcodePrefixes: Array.isArray(config.allowedPostcodePrefixes) && config.allowedPostcodePrefixes.length ? config.allowedPostcodePrefixes : ['FY'],
			homeUrl: config.homeUrl || '/',
			logoUrl: config.logoUrl || '',
			i18n: config.i18n || {},
		};
	}

	function apiUrl(path) {
		const config = getConfig();
		const base = config.restUrl.endsWith('/') ? config.restUrl : config.restUrl + '/';

		return base + path.replace(/^\//, '');
	}

	async function api(path, options = {}) {
		const config = getConfig();

		const response = await fetch(apiUrl(path), {
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
				...(options.headers || {}),
			},
			...options,
		});

		const body = await response.json().catch(() => null);

		if (!response.ok) {
			const message = body && body.message ? body.message : 'Something went wrong.';
			throw new Error(message);
		}

		return body;
	}

	function cacheKey(prefix, data) {
		return prefix + ':' + JSON.stringify(data);
	}

	function createButton(text, className, type = 'button') {
		const button = document.createElement('button');
		button.type = type;
		button.className = className;
		button.textContent = text;

		return button;
	}

	function setMessage(app, message, type = 'error') {
		let box = qs('.ebm-live-message', app);

		if (!box) {
			box = document.createElement('div');
			box.className = 'ebm-live-message';
			app.prepend(box);
		}

		box.className = `ebm-live-message ebm-${type}`;
		box.textContent = message;
	}

	function clearMessage(app) {
		const box = qs('.ebm-live-message', app);

		if (box) {
			box.remove();
		}
	}

	function stepHeader(app, state) {
		const header = document.createElement('div');
		header.className = 'ebm-step-header';
		header.setAttribute('aria-label', 'Booking steps');

		for (let i = 1; i <= 5; i++) {
			const pill = document.createElement('button');
			pill.type = 'button';
			pill.className = 'ebm-step-pill';
			pill.textContent = String(i);
			pill.dataset.step = String(i);

			pill.addEventListener('click', function () {
				if (canMoveToStep(state, i)) {
					goToStep(app, state, i);
				}
			});

			header.appendChild(pill);
		}

		return header;
	}

	function screen(step, title) {
		const div = document.createElement('section');
		div.className = 'ebm-step-screen';
		div.dataset.step = String(step);

		const h2 = document.createElement('h2');
		h2.textContent = title;
		div.appendChild(h2);

		return div;
	}

	function canMoveToStep(state, step) {
		if (step <= state.step) {
			return true;
		}

		if (step >= 2 && !state.jobId) {
			return false;
		}

		if (step >= 4 && (!state.date || !state.time)) {
			return false;
		}

		return true;
	}

	function goToStep(app, state, step) {
		state.step = step;
		renderStepState(app, state);

		const active = qs(`.ebm-step-screen[data-step="${step}"]`, app);

		if (active) {
			active.scrollIntoView({
				behavior: 'smooth',
				block: 'nearest',
			});
		}
	}

	function renderStepState(app, state) {
		qsa('.ebm-step-screen', app).forEach(function (section) {
			section.classList.toggle('is-active', Number(section.dataset.step) === state.step);
		});

		qsa('.ebm-step-pill', app).forEach(function (pill) {
			const step = Number(pill.dataset.step);
			pill.classList.toggle('is-active', step === state.step);
			pill.classList.toggle('is-done', step < state.step);
		});
	}

	function money(value) {
		const number = Number(value || 0);

		return new Intl.NumberFormat('en-GB', {
			style: 'currency',
			currency: 'GBP',
		}).format(number);
	}

	function formatDisplayDate(dateString) {
		if (!dateString) {
			return '';
		}

		const iso = ukToIso(dateString);
		const date = new Date(`${iso}T00:00:00`);

		if (Number.isNaN(date.getTime())) {
			return dateString;
		}

		return date.toLocaleDateString('en-GB', {
			day: '2-digit',
			month: '2-digit',
			year: 'numeric',
		});
	}

	function i18n(key, fallback) {
		const config = getConfig();
		return config.i18n && config.i18n[key] ? config.i18n[key] : fallback;
	}

	function buildSuccessScreen(data) {
		const config = getConfig();
		const title = data.title || i18n('booking_success', 'Booking successful');
		const text = data.text || i18n('booking_success_text', 'Your booking has been received successfully.');
		const date = data.date ? formatDisplayDate(data.date) : '';
		const time = data.time || '';
		const reference = data.reference || '';

		const logo = config.logoUrl ? `
			<div class="ebm-success-logo-wrap">
				<img src="${escapeHtml(config.logoUrl)}" alt="" class="ebm-success-logo">
			</div>
		` : '';

		let meta = '';

		if (date || time || reference) {
			meta += '<div class="ebm-success-meta">';

			if (date) {
				meta += `<div class="ebm-success-meta-row"><span>Date</span><strong>${escapeHtml(date)}</strong></div>`;
			}

			if (time) {
				meta += `<div class="ebm-success-meta-row"><span>Time</span><strong>${escapeHtml(time)}</strong></div>`;
			}

			if (reference) {
				meta += `<div class="ebm-success-meta-row"><span>Reference</span><strong>#${escapeHtml(reference)}</strong></div>`;
			}

			meta += '</div>';
		}

		return `
			<div class="ebm-booking-shell ebm-success-shell">
				<div class="ebm-success-screen">
					<div class="ebm-success-art" aria-hidden="true">
						<div class="ebm-success-calendar">
							<div class="ebm-success-calendar-top"></div>
							<div class="ebm-success-calendar-grid">
								<span></span><span></span><span></span><span></span>
								<span></span><span></span><span></span><span></span>
								<span></span><span></span><span></span><span></span>
							</div>
						</div>
						<div class="ebm-success-tick">
							<svg viewBox="0 0 52 52" aria-hidden="true">
								<circle cx="26" cy="26" r="26"></circle>
								<path d="M14 27.5l8 8L38 19.5"></path>
							</svg>
						</div>
					</div>

					${logo}

					<h2 class="ebm-success-title">${escapeHtml(title)}</h2>
					<p class="ebm-success-text">${escapeHtml(text)}</p>

					${meta}

					<div class="ebm-success-actions">
						<a class="ebm-btn" href="${escapeHtml(config.homeUrl)}">${escapeHtml(i18n('back_home', 'Back to home'))}</a>
						<button type="button" class="ebm-btn ebm-btn-secondary" data-ebm-book-again>${escapeHtml(i18n('make_another', 'Make another booking'))}</button>
					</div>
				</div>
			</div>
		`;
	}

	function showSuccessScreen(app, data) {
		app.classList.add('ebm-has-success');
		app.innerHTML = buildSuccessScreen(data || {});

		const againButton = qs('[data-ebm-book-again]', app);

		if (againButton) {
			againButton.addEventListener('click', function () {
				const cleanUrl = window.location.origin + window.location.pathname;
				window.location.href = cleanUrl;
			});
		}

		if (window.history && window.history.replaceState) {
			const url = new URL(window.location.href);
			url.searchParams.delete('payment');
			url.searchParams.delete('ebm_booking');
			window.history.replaceState({}, document.title, url.toString());
		}
	}

	function handleReturnState(app) {
		const params = new URLSearchParams(window.location.search);
		const payment = params.get('payment');

		if ('success' === payment) {
			showSuccessScreen(app, {
				title: i18n('booking_success', 'Booking successful'),
				text: i18n('payment_success_text', 'Your payment was successful and your booking is confirmed.'),
			});

			return true;
		}

		if ('cancelled' === payment) {
			setMessage(app, 'Payment was cancelled. Your booking has not been confirmed.', 'error');

			if (window.history && window.history.replaceState) {
				const url = new URL(window.location.href);
				url.searchParams.delete('payment');
				url.searchParams.delete('ebm_booking');
				window.history.replaceState({}, document.title, url.toString());
			}
		}

		return false;
	}

	function durationLabel(minutes) {
		const total = Number(minutes || 0);

		if (total >= 1440 && total % 1440 === 0) {
			const days = total / 1440;
			return `${days} ${days === 1 ? 'Day' : 'Days'}`;
		}

		if (total >= 60 && total % 60 === 0) {
			const hours = total / 60;
			return `${hours} ${hours === 1 ? 'Hour' : 'Hours'}`;
		}

		return `${total} Minutes`;
	}

	function normalisePostcode(postcode) {
		return String(postcode || '').toUpperCase().replace(/\s+/g, '');
	}

	function postcodeAllowed(postcode) {
		const compact = normalisePostcode(postcode);
		const config = getConfig();

		return config.allowedPostcodePrefixes.some(function (prefix) {
			const cleanPrefix = String(prefix || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
			return cleanPrefix && compact.startsWith(cleanPrefix);
		});
	}

	function allowedPostcodeLabel() {
		return getConfig().allowedPostcodePrefixes.join(', ');
	}

	function renderJobs(app, state, target, list) {
		target.innerHTML = '';

		if (!list.length) {
			target.innerHTML = '<div class="ebm-empty">No jobs are available yet.</div>';
			return;
		}

		list.forEach(function (job) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'ebm-job-card';
			button.dataset.jobId = job.id;
			button.innerHTML = `
				<span class="ebm-job-title">${escapeHtml(job.title || job.name || 'Job')}</span>
				<span class="ebm-job-meta">${durationLabel(job.duration_minutes || job.duration || 0)}</span>
			`;

			button.addEventListener('click', async function () {
				state.jobId = Number(job.id);
				state.addons = {};
				state.date = '';
				state.time = '';
				state.slots = [];
				state.quote = null;
				state.voucherCode = '';

				qsa('.ebm-job-card', target).forEach(function (card) {
					card.classList.remove('is-selected');
					card.setAttribute('aria-pressed', 'false');
				});

				button.classList.add('is-selected');
				button.setAttribute('aria-pressed', 'true');

				clearMessage(app);
				await loadAddons(app, state);
				goToStep(app, state, 2);
			});

			target.appendChild(button);
		});
	}

	async function loadJobs(app, state, target) {
		const config = getConfig();

		if (memoryCache.jobs && memoryCache.jobs.length) {
			renderJobs(app, state, target, memoryCache.jobs);
			return;
		}

		if (config.preloadedJobs.length) {
			memoryCache.jobs = config.preloadedJobs;
			renderJobs(app, state, target, memoryCache.jobs);

			api('jobs')
				.then(function (jobs) {
					const list = Array.isArray(jobs) ? jobs : (jobs.jobs || []);
					if (list.length) {
						memoryCache.jobs = list;
					}
				})
				.catch(function () {});

			return;
		}

		target.innerHTML = '<div class="ebm-loading">Loading jobs...</div>';

		try {
			const jobs = await api('jobs');
			const list = Array.isArray(jobs) ? jobs : (jobs.jobs || []);
			memoryCache.jobs = list;
			renderJobs(app, state, target, list);
		} catch (error) {
			target.innerHTML = `<div class="ebm-error">${escapeHtml(error.message)}</div>`;
		}
	}

	async function loadAddons(app, state) {
		const target = qs('[data-ebm-addons]', app);

		if (!target) {
			return;
		}

		const key = String(state.jobId);

		if (memoryCache.addons[key]) {
			renderAddons(app, state, target, memoryCache.addons[key]);
			return;
		}

		target.innerHTML = '<div class="ebm-loading">Loading add-ons...</div>';

		try {
			const response = await api(`addons?job_id=${encodeURIComponent(state.jobId)}`);
			const addons = Array.isArray(response) ? response : (response.addons || []);
			memoryCache.addons[key] = addons;
			renderAddons(app, state, target, addons);
		} catch (error) {
			target.innerHTML = `<div class="ebm-error">${escapeHtml(error.message)}</div>`;
		}
	}

	function renderAddons(app, state, target, addons) {
		if (!addons.length) {
			target.innerHTML = '<div class="ebm-empty">No add-ons are needed for this job.</div>';
			return;
		}

		target.innerHTML = '';

		addons.forEach(function (addon) {
			const min = Number(addon.min_qty || 0);
			const max = Number(addon.max_qty || 10);

			const card = document.createElement('div');
			card.className = 'ebm-addon-card';
			card.innerHTML = `
				<div class="ebm-addon-inner">
					<div>
						<span class="ebm-addon-title">${escapeHtml(addon.title || addon.name || 'Add-on')}</span>
						${addon.description ? `<div class="ebm-addon-description">${escapeHtml(addon.description)}</div>` : ''}
						<span class="ebm-addon-meta">${durationLabel(addon.extra_duration_minutes || 0)} per item</span>
					</div>
					<div class="ebm-addon-qty">
						<label>
							Qty
							<input type="number" min="${min}" max="${max}" value="${min}" data-addon-id="${addon.id}">
						</label>
					</div>
				</div>
			`;

			const input = qs('input', card);

			input.addEventListener('change', function () {
				let value = Number(input.value || 0);
				value = Math.max(min, Math.min(max, value));
				input.value = String(value);

				if (value > 0) {
					state.addons[addon.id] = value;
					card.classList.add('is-selected');
				} else {
					delete state.addons[addon.id];
					card.classList.remove('is-selected');
				}

				state.date = '';
				state.time = '';
				state.slots = [];
				state.quote = null;
				state.voucherCode = '';
			});

			target.appendChild(card);
		});
	}

	async function loadSlots(app, state) {
		const target = qs('[data-ebm-slots]', app);

		if (!target) {
			return;
		}

		if (!state.jobId || !state.date) {
			target.innerHTML = '';
			return;
		}

		const key = cacheKey('slots', {
			job_id: state.jobId,
			date: ukToIso(state.date),
			addons: state.addons,
		});

		if (memoryCache.slots[key]) {
			state.slots = memoryCache.slots[key];
			renderSlots(app, state, target, memoryCache.slots[key]);
			return;
		}

		target.innerHTML = '<div class="ebm-loading">Checking availability...</div>';

		try {
			const response = await api('slots', {
				method: 'POST',
				body: JSON.stringify({
					job_id: state.jobId,
					date: ukToIso(state.date),
					addons: state.addons,
				}),
			});

			const slots = Array.isArray(response) ? response : (response.slots || []);
			memoryCache.slots[key] = slots;
			state.slots = slots;

			renderSlots(app, state, target, slots);
		} catch (error) {
			target.innerHTML = `<div class="ebm-error">${escapeHtml(error.message)}</div>`;
		}
	}

	function renderSlots(app, state, target, slots) {
		if (!slots.length) {
			target.innerHTML = '<div class="ebm-empty">No available times for this date. Please choose another date.</div>';
			return;
		}

		target.innerHTML = '';

		slots.forEach(function (slot) {
			const time = slot.time || slot.start || slot.label;

			if (!time) {
				return;
			}

			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'ebm-slot';
			button.textContent = time;
			button.dataset.time = time;

			button.addEventListener('click', function () {
				state.time = time;

				qsa('.ebm-slot', target).forEach(function (item) {
					item.classList.remove('is-selected');
					item.setAttribute('aria-pressed', 'false');
				});

				button.classList.add('is-selected');
				button.setAttribute('aria-pressed', 'true');

				clearMessage(app);
				goToStep(app, state, 4);
			});

			target.appendChild(button);
		});
	}

	async function loadQuote(state, bypassCache) {
		const key = cacheKey('quote', {
			job_id: state.jobId,
			addons: state.addons,
			voucher_code: state.voucherCode || '',
		});

		if (!bypassCache && memoryCache.quotes[key]) {
			state.quote = memoryCache.quotes[key];
			return memoryCache.quotes[key];
		}

		const response = await api('quote', {
			method: 'POST',
			body: JSON.stringify({
				job_id: state.jobId,
				addons: state.addons,
				voucher_code: state.voucherCode || '',
			}),
		});

		state.quote = response;
		memoryCache.quotes[key] = response;

		return response;
	}

	function renderReview(app, state) {
		const target = qs('[data-ebm-review]', app);

		if (!target) {
			return;
		}

		const quote = state.quote || {};
		const discountAmount = Number(quote.discount_amount || 0);
		const voucherCode = quote.voucher_code || state.voucherCode || '';

		target.innerHTML = `
			<div class="ebm-summary-card">
				<div class="ebm-summary-row">
					<span>Date</span>
					<strong>${escapeHtml(state.date || '')}</strong>
				</div>
				<div class="ebm-summary-row">
					<span>Time</span>
					<strong>${escapeHtml(state.time || '')}</strong>
				</div>
				${discountAmount > 0 ? `
					<div class="ebm-summary-row">
						<span>Voucher ${escapeHtml(voucherCode)}</span>
						<strong>-${money(discountAmount)}</strong>
					</div>
				` : ''}
				<div class="ebm-summary-row ebm-total-row">
					<span>Total</span>
					<strong>${money(quote.total || quote.total_amount || 0)}</strong>
				</div>
				<div class="ebm-summary-row">
					<span>Deposit due now</span>
					<strong>${money(quote.deposit || quote.deposit_amount || 0)}</strong>
				</div>
			</div>
		`;
	}

	async function applyVoucher(app, state) {
		const input = qs('[data-ebm-voucher-input]', app);
		const message = qs('[data-ebm-voucher-message]', app);

		if (!input) {
			return;
		}

		state.voucherCode = input.value.trim().toUpperCase();

		if (!state.voucherCode) {
			if (message) {
				message.className = 'ebm-voucher-message ebm-error';
				message.textContent = 'Enter a voucher code.';
			}
			return;
		}

		if (message) {
			message.className = 'ebm-voucher-message ebm-loading';
			message.textContent = 'Checking voucher...';
		}

		try {
			await loadQuote(state, true);
			renderReview(app, state);

			if (message) {
				const discountAmount = Number(state.quote.discount_amount || 0);

				if (discountAmount > 0) {
					message.className = 'ebm-voucher-message ebm-success';
					message.textContent = `Voucher applied. You saved ${money(discountAmount)}.`;
				} else {
					message.className = 'ebm-voucher-message ebm-error';
					message.textContent = 'This voucher did not change the total.';
				}
			}
		} catch (error) {
			state.voucherCode = '';
			state.quote = null;
			await loadQuote(state, true);
			renderReview(app, state);

			if (message) {
				message.className = 'ebm-voucher-message ebm-error';
				message.textContent = error.message;
			}
		}
	}

	function findAddressComponent(components, type) {
		if (!Array.isArray(components)) {
			return null;
		}

		return components.find(function (component) {
			return Array.isArray(component.types) && component.types.includes(type);
		}) || null;
	}

	function componentLong(components, type) {
		const component = findAddressComponent(components, type);
		return component ? component.longText || component.long_name || '' : '';
	}

	function componentShort(components, type) {
		const component = findAddressComponent(components, type);
		return component ? component.shortText || component.short_name || component.longText || component.long_name || '' : '';
	}

	function showAddressMessage(app, message, type) {
		const box = qs('[data-ebm-address-message]', app);

		if (!box) {
			return;
		}

		box.className = `ebm-address-message ebm-${type || 'error'}`;
		box.textContent = message;
	}

	function clearAddressMessage(app) {
		const box = qs('[data-ebm-address-message]', app);

		if (box) {
			box.className = 'ebm-address-message';
			box.textContent = '';
		}
	}

	function updateAddressPreview(root) {
		const line1 = valueOf(root, '[name="address_line_1"]');
		const line2 = valueOf(root, '[name="address_line_2"]');
		const town = valueOf(root, '[name="town"]');
		const county = valueOf(root, '[name="county"]');
		const postcode = valueOf(root, '[name="postcode"]');

		const parts = [line1, line2, town, county, postcode].filter(Boolean);
		const preview = qs('[data-ebm-address-preview]', root);

		if (!preview) {
			return;
		}

		if (!parts.length) {
			preview.innerHTML = '<strong>Service address preview</strong><span>Start typing your address above, or enter it manually.</span>';
			return;
		}

		preview.innerHTML = `<strong>Service address preview</strong><span>${escapeHtml(parts.join(', '))}</span>`;
	}

	function fillAddressFromComponents(app, state, components) {
		const streetNumber = componentLong(components, 'street_number');
		const route = componentLong(components, 'route');
		const premise = componentLong(components, 'premise');
		const subpremise = componentLong(components, 'subpremise');
		const postalTown = componentLong(components, 'postal_town');
		const locality = componentLong(components, 'locality');
		const county = componentLong(components, 'administrative_area_level_2') || componentLong(components, 'administrative_area_level_1');
		const postcode = componentShort(components, 'postal_code') || componentLong(components, 'postal_code');

		const line1Value = [subpremise, premise || streetNumber].filter(Boolean).join(', ');
		const townValue = postalTown || locality;

		const line1 = qs('[name="address_line_1"]', app);
		const line2 = qs('[name="address_line_2"]', app);
		const town = qs('[name="town"]', app);
		const countyInput = qs('[name="county"]', app);
		const postcodeInput = qs('[name="postcode"]', app);

		if (line1) {
			line1.value = line1Value || streetNumber || premise || '';
			dispatchChange(line1);
		}

		if (line2) {
			line2.value = route || '';
			dispatchChange(line2);
		}

		if (town) {
			town.value = townValue || '';
			dispatchChange(town);
		}

		if (countyInput) {
			countyInput.value = county || '';
			dispatchChange(countyInput);
		}

		if (postcodeInput) {
			postcodeInput.value = postcode || '';
			dispatchChange(postcodeInput);
		}

		state.addressSelectedFromGoogle = true;
		updateAddressPreview(app);

		if (!postcode) {
			showAddressMessage(app, 'Please enter the postcode for this address.', 'error');
			return;
		}

		if (!postcodeAllowed(postcode)) {
			showAddressMessage(app, `Sorry, we only accept bookings for ${allowedPostcodeLabel()} postcodes.`, 'error');
			return;
		}

		showAddressMessage(app, 'Address accepted. Please check the details before continuing.', 'success');
	}

	async function initAddressAutocomplete(app, state) {
		if (state.addressAutocompleteReady) {
			return;
		}

		const mount = qs('[data-ebm-place-autocomplete-mount]', app);

		if (!mount) {
			return;
		}

		if (!window.google || !window.google.maps || !window.google.maps.importLibrary) {
			return;
		}

		state.addressAutocompleteReady = true;
		mount.innerHTML = '';

		try {
			const placesLibrary = await window.google.maps.importLibrary('places');
			const PlaceAutocompleteElement = placesLibrary.PlaceAutocompleteElement || window.google.maps.places.PlaceAutocompleteElement;

			if (!PlaceAutocompleteElement) {
				state.addressAutocompleteReady = false;
				showAddressMessage(app, 'Google address search could not load. Please enter the address manually.', 'error');
				return;
			}

			const autocomplete = new PlaceAutocompleteElement({});

			autocomplete.setAttribute('aria-label', 'Address search');
			autocomplete.setAttribute('placeholder', 'Start typing the service address');

			if ('includedRegionCodes' in autocomplete) {
				autocomplete.includedRegionCodes = ['gb'];
			}

			if ('requestedLanguage' in autocomplete) {
				autocomplete.requestedLanguage = 'en-GB';
			}

			if ('locationBias' in autocomplete) {
				autocomplete.locationBias = {
					center: {
						lat: 53.8175,
						lng: -3.0357,
					},
					radius: 30000,
				};
			}

			mount.appendChild(autocomplete);

			autocomplete.addEventListener('gmp-select', async function (event) {
				const prediction = event.placePrediction;

				if (!prediction || !prediction.toPlace) {
					showAddressMessage(app, 'Please choose an address from the list.', 'error');
					return;
				}

				const place = prediction.toPlace();

				try {
					await place.fetchFields({
						fields: ['addressComponents', 'formattedAddress', 'displayName'],
					});

					const components = place.addressComponents || [];
					fillAddressFromComponents(app, state, components);
				} catch (error) {
					showAddressMessage(app, 'The selected address could not be loaded. Please enter it manually.', 'error');
				}
			});
		} catch (error) {
			state.addressAutocompleteReady = false;
			showAddressMessage(app, 'Google address search could not load. Please enter the address manually.', 'error');
		}
	}

	async function submitBooking(app, state) {
		clearMessage(app);

		try {
			const response = await api('bookings', {
				method: 'POST',
				body: JSON.stringify({
					job_id: state.jobId,
					addons: state.addons,
					date: ukToIso(state.date),
					time: state.time,
					voucher_code: state.voucherCode || '',
					customer: state.customer,
				}),
			});

			memoryCache.slots = {};
			memoryCache.quotes = {};

			if (response && response.checkout_url) {
				window.location.href = response.checkout_url;
				return;
			}

			showSuccessScreen(app, {
				title: i18n('booking_success', 'Booking successful'),
				text: response.message || i18n('booking_success_text', 'Your booking has been received successfully.'),
				date: state.date,
				time: state.time,
				reference: response.booking_id || '',
			});
		} catch (error) {
			setMessage(app, error.message, 'error');
		}
	}

	function getCustomer(app) {
		const line1 = valueOf(app, '[name="address_line_1"]');
		const line2 = valueOf(app, '[name="address_line_2"]');
		const town = valueOf(app, '[name="town"]');
		const county = valueOf(app, '[name="county"]');
		const postcode = valueOf(app, '[name="postcode"]');

		const addressParts = [line1, line2, town, county, postcode].filter(Boolean);

		return {
			name: valueOf(app, '[name="name"]'),
			email: valueOf(app, '[name="email"]'),
			phone: valueOf(app, '[name="phone"]'),
			postcode: postcode,
			line_1: line1,
			line_2: line2,
			town: town,
			county: county,
			address: addressParts.join('\n'),
			privacy: !!qs('[name="privacy"]', app)?.checked,
		};
	}

	function valueOf(root, selector) {
		const element = qs(selector, root);
		return element ? element.value.trim() : '';
	}

	function escapeHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function buildCalendar(input, app, state) {
		const wrap = document.createElement('div');
		wrap.className = 'ebm-date-wrap';

		const inputWrap = document.createElement('div');
		inputWrap.className = 'ebm-date-input-wrap';

		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(inputWrap);
		inputWrap.appendChild(input);

		input.type = 'text';
		input.placeholder = 'dd/mm/yyyy';
		input.autocomplete = 'off';

		const trigger = document.createElement('button');
		trigger.type = 'button';
		trigger.className = 'ebm-date-trigger';
		trigger.setAttribute('aria-label', 'Open calendar');
		trigger.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1.5A2.5 2.5 0 0 1 22 6.5v12A2.5 2.5 0 0 1 19.5 21h-15A2.5 2.5 0 0 1 2 18.5v-12A2.5 2.5 0 0 1 4.5 4H6V3a1 1 0 0 1 1-1Zm12.5 8h-15v8.5a.5.5 0 0 0 .5.5h14a.5.5 0 0 0 .5-.5V10ZM5 6a.5.5 0 0 0-.5.5V8h15V6.5A.5.5 0 0 0 19 6H5Z"/></svg>';
		inputWrap.appendChild(trigger);

		const popover = document.createElement('div');
		popover.className = 'ebm-calendar-popover';
		wrap.appendChild(popover);

		const selected = parseInputDate(input.value) || new Date();
		state.calendarMonth = new Date(selected.getFullYear(), selected.getMonth(), 1);

		function toggle(open) {
			popover.classList.toggle('is-open', open);
		}

		function render() {
			const month = state.calendarMonth || new Date();
			const year = month.getFullYear();
			const monthIndex = month.getMonth();
			const today = new Date();
			const selectedDate = parseInputDate(input.value);

			const first = new Date(year, monthIndex, 1);
			const start = new Date(first);
			const mondayOffset = (first.getDay() + 6) % 7;
			start.setDate(first.getDate() - mondayOffset);

			popover.innerHTML = '';

			const head = document.createElement('div');
			head.className = 'ebm-cal-head';
			head.innerHTML = `
				<div class="ebm-cal-title">${formatMonthTitle(month)}</div>
				<div class="ebm-cal-nav">
					<button type="button" data-cal-prev aria-label="Previous month">‹</button>
					<button type="button" data-cal-next aria-label="Next month">›</button>
				</div>
			`;
			popover.appendChild(head);

			const weekdays = document.createElement('div');
			weekdays.className = 'ebm-cal-weekdays';

			['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].forEach(function (day) {
				const span = document.createElement('span');
				span.textContent = day;
				weekdays.appendChild(span);
			});

			popover.appendChild(weekdays);

			const days = document.createElement('div');
			days.className = 'ebm-cal-days';

			for (let i = 0; i < 42; i++) {
				const date = new Date(start);
				date.setDate(start.getDate() + i);

				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'ebm-cal-day';
				button.textContent = String(date.getDate());

				if (date.getMonth() !== monthIndex) {
					button.classList.add('is-muted');
				}

				if (sameDate(date, today)) {
					button.classList.add('is-today');
				}

				if (selectedDate && sameDate(date, selectedDate)) {
					button.classList.add('is-selected');
				}

				button.addEventListener('click', async function (event) {
					event.preventDefault();
					event.stopPropagation();

					input.value = `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
					state.date = input.value;
					state.time = '';
					dispatchChange(input);
					toggle(false);
					await loadSlots(app, state);
				});

				days.appendChild(button);
			}

			popover.appendChild(days);

			qs('[data-cal-prev]', popover).addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();

				state.calendarMonth = new Date(year, monthIndex - 1, 1);
				render();
				toggle(true);
			});

			qs('[data-cal-next]', popover).addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();

				state.calendarMonth = new Date(year, monthIndex + 1, 1);
				render();
				toggle(true);
			});
		}

		trigger.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();

			render();
			toggle(!popover.classList.contains('is-open'));
		});

		popover.addEventListener('click', function (event) {
			event.stopPropagation();
		});

		input.addEventListener('focus', function () {
			render();
			toggle(true);
		});

		input.addEventListener('change', async function () {
			state.date = input.value;
			state.time = '';
			await loadSlots(app, state);
		});

		document.addEventListener('click', function (event) {
			if (!wrap.contains(event.target)) {
				toggle(false);
			}
		});
	}

	function parseInputDate(value) {
		if (!value) {
			return null;
		}

		const iso = ukToIso(value);
		const parts = iso.split('-');

		if (parts.length !== 3) {
			return null;
		}

		const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));

		return Number.isNaN(date.getTime()) ? null : date;
	}

	function sameDate(a, b) {
		return a.getFullYear() === b.getFullYear()
			&& a.getMonth() === b.getMonth()
			&& a.getDate() === b.getDate();
	}

	function buildFreshApp(app) {
		const state = createInitialState();
		appStates.set(app, state);

		if (handleReturnState(app)) {
			return;
		}

		app.innerHTML = '';

		const shell = document.createElement('div');
		shell.className = 'ebm-booking-shell';

		shell.appendChild(stepHeader(app, state));

		const jobs = screen(1, 'Choose the job');
		const jobList = document.createElement('div');
		jobList.className = 'ebm-job-list';
		jobList.dataset.ebmJobs = '';
		jobs.appendChild(jobList);

		const addons = screen(2, 'Choose add-ons');
		const addonText = document.createElement('p');
		addonText.textContent = 'Prices are hidden until the final step.';
		const addonList = document.createElement('div');
		addonList.className = 'ebm-addon-list';
		addonList.dataset.ebmAddons = '';
		const addonActions = document.createElement('div');
		addonActions.className = 'ebm-actions';
		const addonBack = createButton('Back', 'ebm-btn ebm-btn-secondary');
		const addonNext = createButton('Continue', 'ebm-btn');

		addonBack.addEventListener('click', function () {
			goToStep(app, state, 1);
		});

		addonNext.addEventListener('click', function () {
			goToStep(app, state, 3);
		});

		addonActions.append(addonBack, addonNext);
		addons.append(addonText, addonList, addonActions);

		const dates = screen(3, 'Choose date and time');
		const dateLabel = document.createElement('label');
		dateLabel.textContent = 'Start date';
		const dateInput = document.createElement('input');
		dateInput.type = 'text';
		dateInput.name = 'date';
		dateLabel.appendChild(dateInput);
		const slots = document.createElement('div');
		slots.className = 'ebm-slot-grid';
		slots.dataset.ebmSlots = '';
		const dateActions = document.createElement('div');
		dateActions.className = 'ebm-actions';
		const dateBack = createButton('Back', 'ebm-btn ebm-btn-secondary');

		dateBack.addEventListener('click', function () {
			goToStep(app, state, 2);
		});

		dateActions.appendChild(dateBack);
		dates.append(dateLabel, slots, dateActions);

		const details = screen(4, 'Your details');
		details.innerHTML += `
			<div class="ebm-details-grid">
				<div>
					<label>Name</label>
					<input type="text" name="name" autocomplete="name">
				</div>
				<div>
					<label>Email</label>
					<input type="email" name="email" autocomplete="email">
				</div>
				<div>
					<label>Phone</label>
					<input type="tel" name="phone" autocomplete="tel">
				</div>

				<div class="ebm-field-full ebm-address-helper">
					<label>Address search</label>
					<div class="ebm-place-autocomplete-mount" data-ebm-place-autocomplete-mount></div>
					<div class="ebm-address-message" data-ebm-address-message></div>
					<p class="ebm-address-hint">Choose an address from the list. We only accept bookings for ${escapeHtml(allowedPostcodeLabel())} postcodes.</p>
				</div>

				<div>
					<label>House or building</label>
					<input type="text" name="address_line_1" autocomplete="address-line1" placeholder="23">
				</div>

				<div class="ebm-field-full">
					<label>Street address</label>
					<input type="text" name="address_line_2" autocomplete="address-line2" placeholder="Market Street">
				</div>

				<div>
					<label>Town or city</label>
					<input type="text" name="town" autocomplete="address-level2" placeholder="Blackpool">
				</div>

				<div>
					<label>County</label>
					<input type="text" name="county" autocomplete="address-level1" placeholder="Lancashire">
				</div>

				<div>
					<label>Postcode</label>
					<input type="text" name="postcode" autocomplete="postal-code" placeholder="FY1 1AA">
				</div>
			</div>

			<div class="ebm-address-preview" data-ebm-address-preview>
				<strong>Service address preview</strong>
				<span>Start typing your address above, or enter it manually.</span>
			</div>

			<div class="ebm-privacy-row">
				<input id="ebm-privacy" type="checkbox" name="privacy">
				<label for="ebm-privacy">I accept the privacy policy.</label>
			</div>
		`;

		const addressInputs = qsa('[name="address_line_1"], [name="address_line_2"], [name="town"], [name="county"], [name="postcode"]', details);

		addressInputs.forEach(function (input) {
			input.addEventListener('input', function () {
				state.addressSelectedFromGoogle = false;
				updateAddressPreview(app);
				clearAddressMessage(app);
			});

			input.addEventListener('change', function () {
				updateAddressPreview(app);
			});
		});

		const detailsActions = document.createElement('div');
		detailsActions.className = 'ebm-actions';
		const detailsBack = createButton('Back', 'ebm-btn ebm-btn-secondary');
		const detailsNext = createButton('Continue', 'ebm-btn');

		detailsBack.addEventListener('click', function () {
			goToStep(app, state, 3);
		});

		detailsNext.addEventListener('click', async function () {
			state.customer = getCustomer(app);

			if (!state.customer.name || !state.customer.email || !state.customer.phone || !state.customer.postcode || !state.customer.line_1 || !state.customer.line_2 || !state.customer.town) {
				setMessage(app, 'Please complete your contact details and service address before continuing.', 'error');
				return;
			}

			if (!postcodeAllowed(state.customer.postcode)) {
				setMessage(app, `Sorry, bookings are only available for ${allowedPostcodeLabel()} postcodes.`, 'error');
				showAddressMessage(app, `Sorry, bookings are only available for ${allowedPostcodeLabel()} postcodes.`, 'error');
				return;
			}

			clearMessage(app);

			try {
				await loadQuote(state, true);
				renderReview(app, state);
				goToStep(app, state, 5);
			} catch (error) {
				setMessage(app, error.message, 'error');
			}
		});

		detailsActions.append(detailsBack, detailsNext);
		details.appendChild(detailsActions);

		const review = screen(5, 'Confirm and pay deposit');

		const reviewBox = document.createElement('div');
		reviewBox.dataset.ebmReview = '';

		const voucherBox = document.createElement('div');
		voucherBox.className = 'ebm-voucher-box';
		voucherBox.innerHTML = `
			<label for="ebm-voucher-code">Voucher code</label>
			<div class="ebm-voucher-row">
				<input id="ebm-voucher-code" type="text" data-ebm-voucher-input placeholder="Enter voucher code">
				<button type="button" class="ebm-btn ebm-btn-secondary" data-ebm-apply-voucher>Apply</button>
			</div>
			<div class="ebm-voucher-message" data-ebm-voucher-message></div>
		`;

		const reviewActions = document.createElement('div');
		reviewActions.className = 'ebm-actions';
		const reviewBack = createButton('Back', 'ebm-btn ebm-btn-secondary');
		const reviewSubmit = createButton('Confirm booking and pay deposit', 'ebm-btn');

		reviewBack.addEventListener('click', function () {
			goToStep(app, state, 4);
		});

		reviewSubmit.addEventListener('click', function () {
			submitBooking(app, state);
		});

		qs('[data-ebm-apply-voucher]', voucherBox).addEventListener('click', function () {
			applyVoucher(app, state);
		});

		qs('[data-ebm-voucher-input]', voucherBox).addEventListener('keydown', function (event) {
			if ('Enter' === event.key) {
				event.preventDefault();
				applyVoucher(app, state);
			}
		});

		reviewActions.append(reviewBack, reviewSubmit);
		review.append(reviewBox, voucherBox, reviewActions);

		shell.append(jobs, addons, dates, details, review);
		app.appendChild(shell);

		buildCalendar(dateInput, app, state);
		loadJobs(app, state, jobList);
		renderStepState(app, state);
		initAddressAutocomplete(app, state);
	}

	function init() {
		const apps = qsa('[data-ebm-booking-app], #ebm-booking-app, .ebm-booking-form');

		if (!apps.length) {
			return;
		}

		apps.forEach(function (app) {
			if (app.dataset.ebmInitialised === '1') {
				return;
			}

			app.dataset.ebmInitialised = '1';
			buildFreshApp(app);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();