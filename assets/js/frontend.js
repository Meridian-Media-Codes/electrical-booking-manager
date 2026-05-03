(function () {
	'use strict';

	const cache = {
		jobs: null,
		addons: {},
		slots: {},
		quotes: {},
		months: {},
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

	function config() {
		const data = window.ebmBooking || {};

		return {
			restUrl: data.restUrl || '/wp-json/ebm/v1/',
			nonce: data.nonce || '',
			preloadedJobs: Array.isArray(data.preloadedJobs) ? data.preloadedJobs : [],
			allowedPostcodePrefixes: Array.isArray(data.allowedPostcodePrefixes) && data.allowedPostcodePrefixes.length ? data.allowedPostcodePrefixes : ['FY'],
			homeUrl: data.homeUrl || '/',
			logoUrl: data.logoUrl || '',
			i18n: data.i18n || {},
		};
	}

	function t(key, fallback) {
		const i18n = config().i18n;
		return i18n && i18n[key] ? i18n[key] : fallback;
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

	function escapeHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
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

	function monthKey(date) {
		return `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
	}

	function isoFromDate(date) {
		return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
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

	function formatMonthTitle(date) {
		return date.toLocaleDateString('en-GB', {
			month: 'long',
			year: 'numeric',
		});
	}

	function formatDisplayDate(value) {
		const iso = ukToIso(value);
		const date = new Date(`${iso}T00:00:00`);

		if (Number.isNaN(date.getTime())) {
			return value || '';
		}

		return date.toLocaleDateString('en-GB', {
			day: '2-digit',
			month: '2-digit',
			year: 'numeric',
		});
	}

	function money(value) {
		return new Intl.NumberFormat('en-GB', {
			style: 'currency',
			currency: 'GBP',
		}).format(Number(value || 0));
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

	function apiUrl(path) {
		const base = config().restUrl.endsWith('/') ? config().restUrl : `${config().restUrl}/`;
		return base + path.replace(/^\//, '');
	}

	async function api(path, options = {}) {
		const response = await fetch(apiUrl(path), {
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config().nonce,
				...(options.headers || {}),
			},
			...options,
		});

		const body = await response.json().catch(() => null);

		if (!response.ok) {
			throw new Error(body && body.message ? body.message : 'Something went wrong.');
		}

		return body;
	}

	function makeState() {
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
		};
	}

	function clearMessage(app) {
		const box = qs('.ebm-live-message', app);

		if (box) {
			box.remove();
		}
	}

	function message(app, text, type = 'error') {
		clearMessage(app);

		const box = document.createElement('div');
		box.className = `ebm-live-message ebm-${type}`;
		box.textContent = text;
		app.prepend(box);
	}

	function createButton(text, className, type = 'button') {
		const button = document.createElement('button');
		button.type = type;
		button.className = className;
		button.textContent = text;
		return button;
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
		const section = document.createElement('section');
		section.className = 'ebm-step-screen';
		section.dataset.step = String(step);

		const heading = document.createElement('h2');
		heading.textContent = title;
		section.appendChild(heading);

		return section;
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

	function normalisePostcode(value) {
		return String(value || '').toUpperCase().replace(/\s+/g, '');
	}

	function postcodeAllowed(value) {
		const compact = normalisePostcode(value);

		return config().allowedPostcodePrefixes.some(function (prefix) {
			const clean = String(prefix || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
			return clean && compact.startsWith(clean);
		});
	}

	function allowedPostcodeLabel() {
		return config().allowedPostcodePrefixes.join(', ');
	}

	function cacheKey(prefix, data) {
		return `${prefix}:${JSON.stringify(data)}`;
	}

	function renderJobs(app, state, target, jobs) {
		target.innerHTML = '';

		if (!jobs.length) {
			target.innerHTML = '<div class="ebm-empty">No jobs are available yet.</div>';
			return;
		}

		jobs.forEach(function (job) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'ebm-job-card';
			button.dataset.jobId = job.id;
			button.innerHTML = `
				<span class="ebm-job-title">${escapeHtml(job.title || 'Job')}</span>
				<span class="ebm-job-meta">${durationLabel(job.duration_minutes || 0)}</span>
			`;

			button.addEventListener('click', async function () {
				state.jobId = Number(job.id);
				state.addons = {};
				state.date = '';
				state.time = '';
				state.slots = [];
				state.quote = null;
				state.voucherCode = '';

				cache.months = {};

				qsa('.ebm-job-card', target).forEach(function (card) {
					card.classList.remove('is-selected');
					card.setAttribute('aria-pressed', 'false');
				});

				button.classList.add('is-selected');
				button.setAttribute('aria-pressed', 'true');

				clearMessage(app);
				await loadAddons(app, state);
				preloadInitialAvailability(state);
				goToStep(app, state, 2);
			});

			target.appendChild(button);
		});
	}

	async function loadJobs(app, state, target) {
		const preloaded = config().preloadedJobs;

		if (cache.jobs && cache.jobs.length) {
			renderJobs(app, state, target, cache.jobs);
			return;
		}

		if (preloaded.length) {
			cache.jobs = preloaded;
			renderJobs(app, state, target, cache.jobs);

			api('jobs')
				.then(function (response) {
					const jobs = Array.isArray(response) ? response : (response.jobs || []);
					if (jobs.length) {
						cache.jobs = jobs;
					}
				})
				.catch(function () {});

			return;
		}

		target.innerHTML = '<div class="ebm-loading">Loading jobs...</div>';

		try {
			const response = await api('jobs');
			const jobs = Array.isArray(response) ? response : (response.jobs || []);
			cache.jobs = jobs;
			renderJobs(app, state, target, jobs);
		} catch (error) {
			target.innerHTML = `<div class="ebm-error">${escapeHtml(error.message)}</div>`;
		}
	}

	async function loadAddons(app, state) {
		const target = qs('[data-ebm-addons]', app);

		if (!target || !state.jobId) {
			return;
		}

		const key = String(state.jobId);

		if (cache.addons[key]) {
			renderAddons(app, state, target, cache.addons[key]);
			return;
		}

		target.innerHTML = '<div class="ebm-loading">Loading add-ons...</div>';

		try {
			const response = await api(`addons?job_id=${encodeURIComponent(state.jobId)}`);
			const addons = Array.isArray(response) ? response : (response.addons || []);
			cache.addons[key] = addons;
			renderAddons(app, state, target, addons);
		} catch (error) {
			target.innerHTML = `<div class="ebm-error">${escapeHtml(error.message)}</div>`;
		}
	}

	function renderAddons(app, state, target, addons) {
		target.innerHTML = '';

		if (!addons.length) {
			target.innerHTML = '<div class="ebm-empty">No add-ons are needed for this job.</div>';
			return;
		}

		addons.forEach(function (addon) {
			const min = Number(addon.min_qty || 0);
			const max = Number(addon.max_qty || 10);

			const card = document.createElement('div');
			card.className = 'ebm-addon-card';
			card.innerHTML = `
				<div class="ebm-addon-inner">
					<div>
						<span class="ebm-addon-title">${escapeHtml(addon.title || 'Add-on')}</span>
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

				cache.months = {};
				cache.slots = {};
				cache.quotes = {};

				preloadInitialAvailability(state);
			});

			target.appendChild(card);
		});
	}

	async function loadSlots(app, state) {
		const target = qs('[data-ebm-slots]', app);

		if (!target || !state.jobId || !state.date) {
			return;
		}

		const key = cacheKey('slots', {
			job_id: state.jobId,
			date: ukToIso(state.date),
			addons: state.addons,
		});

		if (cache.slots[key]) {
			state.slots = cache.slots[key];
			renderSlots(app, state, target, cache.slots[key]);
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
			cache.slots[key] = slots;
			state.slots = slots;
			renderSlots(app, state, target, slots);
		} catch (error) {
			target.innerHTML = `<div class="ebm-error">${escapeHtml(error.message)}</div>`;
		}
	}

	function renderSlots(app, state, target, slots) {
		target.innerHTML = '';

		if (!slots.length) {
			target.innerHTML = '<div class="ebm-empty">No available times for this date. Please choose another date.</div>';
			return;
		}

		slots.forEach(function (slot) {
			const time = slot.time || slot.start || slot.label;

			if (!time) {
				return;
			}

			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'ebm-slot';
			button.textContent = time;

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

	function setCalendarCheckingState(popover, isChecking) {
		if (!popover) {
			return;
		}

		popover.classList.toggle('is-checking-availability', isChecking);
		popover.classList.toggle('is-awaiting-availability', isChecking);
		popover.classList.remove('is-availability-error');

		qsa('.ebm-cal-day', popover).forEach(function (button) {
			if (isChecking) {
				button.disabled = true;
				button.classList.add('is-pending-availability');
				button.setAttribute('aria-disabled', 'true');
				button.setAttribute('title', 'Checking dates');
			}
		});
	}

	function applyMonthAvailability(popover, unavailableDates) {
		const unavailable = Array.isArray(unavailableDates) ? unavailableDates : [];

		if (!popover) {
			return;
		}

		popover.classList.remove('is-checking-availability');
		popover.classList.remove('is-awaiting-availability');
		popover.classList.remove('is-availability-error');

		qsa('.ebm-cal-day', popover).forEach(function (button) {
			const date = button.dataset.date || '';

			if (!date) {
				return;
			}

			button.classList.remove('is-pending-availability');

			if (unavailable.includes(date)) {
				button.classList.add('is-unavailable');
				button.disabled = true;
				button.setAttribute('aria-disabled', 'true');
				button.setAttribute('title', 'No available times');
			} else {
				button.classList.remove('is-unavailable');
				button.disabled = false;
				button.removeAttribute('aria-disabled');
				button.removeAttribute('title');
			}
		});
	}

	function setCalendarAvailabilityError(popover) {
		if (!popover) {
			return;
		}

		popover.classList.remove('is-checking-availability');
		popover.classList.remove('is-awaiting-availability');
		popover.classList.add('is-availability-error');

		qsa('.ebm-cal-day', popover).forEach(function (button) {
			button.disabled = true;
			button.classList.add('is-pending-availability');
			button.setAttribute('aria-disabled', 'true');
			button.setAttribute('title', 'Dates could not be checked');
		});
	}

	async function fetchMonthAvailability(state, monthDate) {
		if (!state.jobId || !monthDate) {
			return null;
		}

		const key = cacheKey('month', {
			job_id: state.jobId,
			month: monthKey(monthDate),
			addons: state.addons,
		});

		if (cache.months[key]) {
			return cache.months[key];
		}

		const response = await api('month-availability', {
			method: 'POST',
			body: JSON.stringify({
				job_id: state.jobId,
				month: monthKey(monthDate),
				addons: state.addons,
			}),
		});

		cache.months[key] = response;

		return response;
	}

	async function loadMonthAvailability(app, state, monthDate, popover) {
		if (!state.jobId || !monthDate || !popover) {
			return;
		}

		const key = cacheKey('month', {
			job_id: state.jobId,
			month: monthKey(monthDate),
			addons: state.addons,
		});

		if (cache.months[key]) {
			applyMonthAvailability(popover, cache.months[key].unavailable_dates || []);
			preloadNextMonth(state, monthDate);
			return;
		}

		setCalendarCheckingState(popover, true);

		try {
			const response = await fetchMonthAvailability(state, monthDate);
			applyMonthAvailability(popover, response.unavailable_dates || []);
			preloadNextMonth(state, monthDate);
		} catch (error) {
			setCalendarAvailabilityError(popover);
		}
	}

	function preloadInitialAvailability(state) {
		if (!state.jobId) {
			return;
		}

		const current = new Date();
		const currentMonth = new Date(current.getFullYear(), current.getMonth(), 1);

		fetchMonthAvailability(state, currentMonth)
			.then(function () {
				preloadNextMonth(state, currentMonth);
			})
			.catch(function () {});
	}

	function preloadNextMonth(state, monthDate) {
		if (!state.jobId || !monthDate) {
			return;
		}

		const next = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 1);

		fetchMonthAvailability(state, next).catch(function () {});
	}

	async function loadQuote(state, bypassCache) {
		const key = cacheKey('quote', {
			job_id: state.jobId,
			addons: state.addons,
			voucher_code: state.voucherCode || '',
		});

		if (!bypassCache && cache.quotes[key]) {
			state.quote = cache.quotes[key];
			return cache.quotes[key];
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
		cache.quotes[key] = response;

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
		const output = qs('[data-ebm-voucher-message]', app);

		if (!input) {
			return;
		}

		state.voucherCode = input.value.trim().toUpperCase();

		if (!state.voucherCode) {
			if (output) {
				output.className = 'ebm-voucher-message ebm-error';
				output.textContent = 'Enter a voucher code.';
			}
			return;
		}

		if (output) {
			output.className = 'ebm-voucher-message ebm-loading';
			output.textContent = 'Checking voucher...';
		}

		try {
			await loadQuote(state, true);
			renderReview(app, state);

			if (output) {
				const amount = Number(state.quote.discount_amount || 0);

				if (amount > 0) {
					output.className = 'ebm-voucher-message ebm-success';
					output.textContent = `Voucher applied. You saved ${money(amount)}.`;
				} else {
					output.className = 'ebm-voucher-message ebm-error';
					output.textContent = 'This voucher did not change the total.';
				}
			}
		} catch (error) {
			state.voucherCode = '';
			state.quote = null;

			try {
				await loadQuote(state, true);
				renderReview(app, state);
			} catch (secondError) {}

			if (output) {
				output.className = 'ebm-voucher-message ebm-error';
				output.textContent = error.message;
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

	function valueOf(root, selector) {
		const element = qs(selector, root);
		return element ? element.value.trim() : '';
	}

	function dispatchChange(element) {
		element.dispatchEvent(new Event('input', { bubbles: true }));
		element.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function updateAddressPreview(root) {
		const line1 = valueOf(root, '[name="address_line_1"]');
		const line2 = valueOf(root, '[name="address_line_2"]');
		const town = valueOf(root, '[name="town"]');
		const county = valueOf(root, '[name="county"]');
		const postcode = valueOf(root, '[name="postcode"]');
		const preview = qs('[data-ebm-address-preview]', root);
		const parts = [line1, line2, town, county, postcode].filter(Boolean);

		if (!preview) {
			return;
		}

		if (!parts.length) {
			preview.innerHTML = '<strong>Service address preview</strong><span>Start typing your address above, or enter it manually.</span>';
			return;
		}

		preview.innerHTML = `<strong>Service address preview</strong><span>${escapeHtml(parts.join(', '))}</span>`;
	}

	function showAddressMessage(app, text, type) {
		const box = qs('[data-ebm-address-message]', app);

		if (!box) {
			return;
		}

		box.className = `ebm-address-message ebm-${type || 'error'}`;
		box.textContent = text;
	}

	function clearAddressMessage(app) {
		const box = qs('[data-ebm-address-message]', app);

		if (box) {
			box.className = 'ebm-address-message';
			box.textContent = '';
		}
	}

	function fillAddressFromComponents(app, components) {
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

		const fields = {
			'[name="address_line_1"]': line1Value || streetNumber || premise || '',
			'[name="address_line_2"]': route || '',
			'[name="town"]': townValue || '',
			'[name="county"]': county || '',
			'[name="postcode"]': postcode || '',
		};

		Object.keys(fields).forEach(function (selector) {
			const input = qs(selector, app);

			if (input) {
				input.value = fields[selector];
				dispatchChange(input);
			}
		});

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

		if (!mount || !window.google || !window.google.maps || !window.google.maps.importLibrary) {
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

				try {
					const place = prediction.toPlace();

					await place.fetchFields({
						fields: ['addressComponents', 'formattedAddress', 'displayName'],
					});

					fillAddressFromComponents(app, place.addressComponents || []);
				} catch (error) {
					showAddressMessage(app, 'The selected address could not be loaded. Please enter it manually.', 'error');
				}
			});
		} catch (error) {
			state.addressAutocompleteReady = false;
			showAddressMessage(app, 'Google address search could not load. Please enter the address manually.', 'error');
		}
	}

	function getCustomer(app) {
		const line1 = valueOf(app, '[name="address_line_1"]');
		const line2 = valueOf(app, '[name="address_line_2"]');
		const town = valueOf(app, '[name="town"]');
		const county = valueOf(app, '[name="county"]');
		const postcode = valueOf(app, '[name="postcode"]');
		const address = [line1, line2, town, county, postcode].filter(Boolean).join('\n');

		return {
			name: valueOf(app, '[name="name"]'),
			email: valueOf(app, '[name="email"]'),
			phone: valueOf(app, '[name="phone"]'),
			line_1: line1,
			line_2: line2,
			town: town,
			county: county,
			postcode: postcode,
			address: address,
			privacy: !!qs('[name="privacy"]', app)?.checked,
		};
	}

	function buildSuccessScreen(data) {
		const logo = config().logoUrl ? `
			<div class="ebm-success-logo-wrap">
				<img src="${escapeHtml(config().logoUrl)}" alt="" class="ebm-success-logo">
			</div>
		` : '';

		const meta = `
			<div class="ebm-success-meta">
				${data.date ? `<div class="ebm-success-meta-row"><span>Date</span><strong>${escapeHtml(formatDisplayDate(data.date))}</strong></div>` : ''}
				${data.time ? `<div class="ebm-success-meta-row"><span>Time</span><strong>${escapeHtml(data.time)}</strong></div>` : ''}
				${data.reference ? `<div class="ebm-success-meta-row"><span>Reference</span><strong>#${escapeHtml(data.reference)}</strong></div>` : ''}
			</div>
		`;

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

					<h2 class="ebm-success-title">${escapeHtml(data.title || t('booking_success', 'Booking successful'))}</h2>
					<p class="ebm-success-text">${escapeHtml(data.text || t('booking_success_text', 'Your booking has been received successfully.'))}</p>

					${meta}

					<div class="ebm-success-actions">
						<a class="ebm-btn" href="${escapeHtml(config().homeUrl)}">${escapeHtml(t('back_home', 'Back to home'))}</a>
						<button type="button" class="ebm-btn ebm-btn-secondary" data-ebm-book-again>${escapeHtml(t('make_another', 'Make another booking'))}</button>
					</div>
				</div>
			</div>
		`;
	}

	function showSuccess(app, data) {
		app.classList.add('ebm-has-success');
		app.innerHTML = buildSuccessScreen(data || {});

		const again = qs('[data-ebm-book-again]', app);

		if (again) {
			again.addEventListener('click', function () {
				window.location.href = window.location.origin + window.location.pathname;
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

		if (params.get('payment') === 'success') {
			showSuccess(app, {
				title: t('booking_success', 'Booking successful'),
				text: t('payment_success_text', 'Your payment was successful and your booking is confirmed.'),
			});

			return true;
		}

		if (params.get('payment') === 'cancelled') {
			message(app, 'Payment was cancelled. Your booking has not been confirmed.', 'error');

			if (window.history && window.history.replaceState) {
				const url = new URL(window.location.href);
				url.searchParams.delete('payment');
				url.searchParams.delete('ebm_booking');
				window.history.replaceState({}, document.title, url.toString());
			}
		}

		return false;
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

			cache.slots = {};
			cache.quotes = {};
			cache.months = {};

			if (response && response.checkout_url) {
				window.location.href = response.checkout_url;
				return;
			}

			showSuccess(app, {
				title: t('booking_success', 'Booking successful'),
				text: response.message || t('booking_success_text', 'Your booking has been received successfully.'),
				date: state.date,
				time: state.time,
				reference: response.booking_id || '',
			});
		} catch (error) {
			message(app, error.message, 'error');
		}
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
				button.className = 'ebm-cal-day is-pending-availability';
				button.textContent = String(date.getDate());
				button.dataset.date = isoFromDate(date);
				button.disabled = true;
				button.setAttribute('aria-disabled', 'true');
				button.setAttribute('title', 'Checking dates');

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

					if (button.disabled || button.classList.contains('is-unavailable') || button.classList.contains('is-pending-availability')) {
						return;
					}

					input.value = `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
					state.date = input.value;
					state.time = '';
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

			loadMonthAvailability(app, state, month, popover);
		}

		trigger.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();

			render();
			toggle(!popover.classList.contains('is-open'));
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

		popover.addEventListener('click', function (event) {
			event.stopPropagation();
		});

		document.addEventListener('click', function (event) {
			if (!wrap.contains(event.target)) {
				toggle(false);
			}
		});
	}

	function buildApp(app) {
		const state = makeState();
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
		addons.innerHTML += '<p>Prices are hidden until the final step.</p>';
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
		addons.append(addonList, addonActions);

		const dates = screen(3, 'Choose date and time');
		const dateLabel = document.createElement('label');
		dateLabel.textContent = 'Start date';

		const dateInput = document.createElement('input');
		dateInput.name = 'date';
		dateInput.type = 'text';

		dateLabel.appendChild(dateInput);

		const slotGrid = document.createElement('div');
		slotGrid.className = 'ebm-slot-grid';
		slotGrid.dataset.ebmSlots = '';

		const dateActions = document.createElement('div');
		dateActions.className = 'ebm-actions';

		const dateBack = createButton('Back', 'ebm-btn ebm-btn-secondary');

		dateBack.addEventListener('click', function () {
			goToStep(app, state, 2);
		});

		dateActions.appendChild(dateBack);
		dates.append(dateLabel, slotGrid, dateActions);

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

		qsa('[name="address_line_1"], [name="address_line_2"], [name="town"], [name="county"], [name="postcode"]', details).forEach(function (input) {
			input.addEventListener('input', function () {
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
				message(app, 'Please complete your contact details and service address before continuing.', 'error');
				return;
			}

			if (!postcodeAllowed(state.customer.postcode)) {
				message(app, `Sorry, bookings are only available for ${allowedPostcodeLabel()} postcodes.`, 'error');
				showAddressMessage(app, `Sorry, bookings are only available for ${allowedPostcodeLabel()} postcodes.`, 'error');
				return;
			}

			clearMessage(app);

			try {
				await loadQuote(state, true);
				renderReview(app, state);
				goToStep(app, state, 5);
			} catch (error) {
				message(app, error.message, 'error');
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

		qs('[data-ebm-apply-voucher]', voucherBox).addEventListener('click', function () {
			applyVoucher(app, state);
		});

		qs('[data-ebm-voucher-input]', voucherBox).addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				applyVoucher(app, state);
			}
		});

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
		qsa('[data-ebm-booking-app], #ebm-booking-app, .ebm-booking-form').forEach(function (app) {
			if (app.dataset.ebmInitialised === '1') {
				return;
			}

			app.dataset.ebmInitialised = '1';
			buildApp(app);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();