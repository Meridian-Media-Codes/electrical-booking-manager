(function () {
	'use strict';

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
			calendarMonth: null,
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

	async function loadJobs(app, state, target) {
		target.innerHTML = '<div class="ebm-loading">Loading jobs...</div>';

		try {
			const jobs = await api('jobs');
			const list = Array.isArray(jobs) ? jobs : (jobs.jobs || []);

			if (!list.length) {
				target.innerHTML = '<div class="ebm-empty">No jobs are available yet.</div>';
				return;
			}

			target.innerHTML = '';

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
		} catch (error) {
			target.innerHTML = `<div class="ebm-error">${escapeHtml(error.message)}</div>`;
		}
	}

	async function loadAddons(app, state) {
		const target = qs('[data-ebm-addons]', app);

		if (!target) {
			return;
		}

		target.innerHTML = '<div class="ebm-loading">Loading add-ons...</div>';

		try {
			const response = await api(`addons?job_id=${encodeURIComponent(state.jobId)}`);
			const addons = Array.isArray(response) ? response : (response.addons || []);

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
				});

				target.appendChild(card);
			});
		} catch (error) {
			target.innerHTML = `<div class="ebm-error">${escapeHtml(error.message)}</div>`;
		}
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
			state.slots = slots;

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
		} catch (error) {
			target.innerHTML = `<div class="ebm-error">${escapeHtml(error.message)}</div>`;
		}
	}

	async function loadQuote(state) {
		const response = await api('quote', {
			method: 'POST',
			body: JSON.stringify({
				job_id: state.jobId,
				addons: state.addons,
			}),
		});

		state.quote = response;

		return response;
	}

	function renderReview(app, state) {
		const target = qs('[data-ebm-review]', app);

		if (!target) {
			return;
		}

		const quote = state.quote || {};

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
					customer: state.customer,
				}),
			});

			if (response && response.checkout_url) {
				window.location.href = response.checkout_url;
				return;
			}

			if (response && response.url) {
				window.location.href = response.url;
				return;
			}

			setMessage(app, 'Booking created successfully.', 'success');
		} catch (error) {
			setMessage(app, error.message, 'error');
		}
	}

	function getCustomer(app) {
		return {
			name: valueOf(app, '[name="name"]'),
			email: valueOf(app, '[name="email"]'),
			phone: valueOf(app, '[name="phone"]'),
			address: valueOf(app, '[name="address"]'),
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

				button.addEventListener('click', async function () {
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

			qs('[data-cal-prev]', popover).addEventListener('click', function () {
				state.calendarMonth = new Date(year, monthIndex - 1, 1);
				render();
			});

			qs('[data-cal-next]', popover).addEventListener('click', function () {
				state.calendarMonth = new Date(year, monthIndex + 1, 1);
				render();
			});
		}

		trigger.addEventListener('click', function () {
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
				<div class="ebm-field-full">
					<label>Service address</label>
					<textarea name="address" autocomplete="street-address"></textarea>
				</div>
			</div>
			<div class="ebm-privacy-row">
				<input id="ebm-privacy" type="checkbox" name="privacy">
				<label for="ebm-privacy">I accept the privacy policy.</label>
			</div>
		`;

		const detailsActions = document.createElement('div');
		detailsActions.className = 'ebm-actions';
		const detailsBack = createButton('Back', 'ebm-btn ebm-btn-secondary');
		const detailsNext = createButton('Continue', 'ebm-btn');

		detailsBack.addEventListener('click', function () {
			goToStep(app, state, 3);
		});

		detailsNext.addEventListener('click', async function () {
			state.customer = getCustomer(app);

			if (!state.customer.name || !state.customer.email || !state.customer.phone || !state.customer.address) {
				setMessage(app, 'Please complete your details before continuing.', 'error');
				return;
			}

			if (!state.customer.privacy) {
				setMessage(app, 'Please accept the privacy policy before continuing.', 'error');
				return;
			}

			clearMessage(app);

			try {
				await loadQuote(state);
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
		review.append(reviewBox, reviewActions);

		shell.append(jobs, addons, dates, details, review);
		app.appendChild(shell);

		buildCalendar(dateInput, app, state);
		loadJobs(app, state, jobList);
		renderStepState(app, state);
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