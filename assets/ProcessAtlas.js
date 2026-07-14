(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		if (window.location.hash === '#AtlasAddEntry') {
			var panel = document.querySelector('#AtlasAddEntry');
			if (panel) window.requestAnimationFrame(function () { panel.scrollIntoView({ block: 'start' }); });
		}

		var keyword = document.querySelector('[data-atlas-keyword]');
		var keywordRequire = document.querySelector('[data-atlas-keyword-require]');
		if (keyword && keywordRequire) {
			var syncKeywordRequire = function () {
				var enabled = keyword.value.trim() !== '';
				keywordRequire.disabled = !enabled;
				if (!enabled) keywordRequire.checked = false;
			};
			keyword.addEventListener('input', syncKeywordRequire);
			syncKeywordRequire();
		}

		var batchForm = document.querySelector('.AtlasBatchForm');
		if (!batchForm) return;
		var job = document.querySelector('[data-atlas-job]');
		var progress = job.querySelector('[data-atlas-progress]');
		var progressLabel = job.querySelector('[data-atlas-progress-label]');
		var title = job.querySelector('[data-atlas-job-title]');
		var retryLabel = job.getAttribute('data-retry-label') || 'Connection interrupted — retrying…';
		var networkErrorLabel = job.getAttribute('data-network-error-label') || 'Connection failed after several attempts. The reindex job is still safe to resume.';
		var tryAgainLabel = job.getAttribute('data-try-again-label') || 'Try again';
		var retryAttempts = 0;
		var maxRetryAttempts = 5;

		var showJobError = function (data, preserveCancel) {
			job.classList.add('is-error');
			job.setAttribute('role', 'alert');
			job.setAttribute('aria-live', 'assertive');
			title.textContent = data.error;
			if (progress.isConnected) progress.remove();
			progressLabel.textContent = '';
			var cancel = job.querySelector('[name="action"][value="reindex_cancel"]');
			if (!preserveCancel && cancel && cancel.form) cancel.form.remove();
			if (data.detail) {
				var detail = document.createElement('code');
				detail.className = 'AtlasJob-errorDetail';
				detail.textContent = data.detail;
				title.parentNode.appendChild(detail);
			}
			var retry = document.createElement('a');
			retry.className = 'uk-button uk-button-primary AtlasJob-retry';
			retry.href = './?section=reindex';
			retry.textContent = tryAgainLabel;
			job.appendChild(retry);
		};

		var runBatch = function () {
			if (batchForm.dataset.running === '1') return;
			batchForm.dataset.running = '1';
			// An input named "action" can shadow HTMLFormElement.action.
			var batchUrl = batchForm.getAttribute('action') || window.location.href;
			window.fetch(batchUrl, {
				method: 'POST',
				body: new FormData(batchForm),
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			}).then(function (response) {
				if (response.status === 403) {
					window.location.reload();
					var expired = new Error('Session expired');
					expired.sessionExpired = true;
					throw expired;
				}
				if (!response.ok) throw new Error('Request failed');
				return response.json();
			}).then(function (data) {
				batchForm.dataset.running = '0';
				retryAttempts = 0;
				if (data.percent !== undefined) {
					progress.value = data.percent;
					progressLabel.textContent = data.percent + '%';
				}
				if (data.label) title.textContent = data.label;
				if (data.error) {
					showJobError(data);
					return;
				}
				if (data.done) {
					window.location.reload();
					return;
				}
				window.setTimeout(runBatch, 120);
			}).catch(function (error) {
				batchForm.dataset.running = '0';
				if (error && error.sessionExpired) return;
				retryAttempts += 1;
				if (retryAttempts >= maxRetryAttempts) {
					showJobError({ error: networkErrorLabel, detail: error && error.message ? error.message : '' }, true);
					return;
				}
				title.textContent = retryLabel;
				window.setTimeout(runBatch, Math.min(1500 * Math.pow(2, retryAttempts - 1), 12000));
			});
		};
		runBatch();
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!(form instanceof HTMLFormElement)) return;

		var confirmation = form.getAttribute('data-confirm');
		if (confirmation && !window.confirm(confirmation)) {
			event.preventDefault();
			return;
		}

		var busyLabel = form.getAttribute('data-busy-label');
		if (!busyLabel) return;
		form.classList.add('is-running');
		var button = form.querySelector('[type="submit"]');
		if (button) {
			button.disabled = true;
			button.value = busyLabel;
			button.textContent = busyLabel;
		}
	});

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.AtlasCopy');
		if (!button) return;
		var value = button.getAttribute('data-copy') || '';
		var copyLabel = button.getAttribute('data-copy-label') || 'Copy ref';
		var copiedLabel = button.getAttribute('data-copied-label') || 'Copied';
		var done = function () {
			button.classList.add('is-copied');
			button.setAttribute('aria-label', copiedLabel);
			window.setTimeout(function () {
				button.classList.remove('is-copied');
				button.setAttribute('aria-label', copyLabel);
			}, 1400);
		};

		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(value).then(done);
			return;
		}

		var input = document.createElement('textarea');
		input.value = value;
		input.setAttribute('readonly', '');
		input.style.position = 'fixed';
		input.style.opacity = '0';
		document.body.appendChild(input);
		input.select();
		if (document.execCommand('copy')) done();
		input.remove();
	});

	document.addEventListener('input', function (event) {
		if (!event.target.matches('[data-atlas-filter]')) return;
		var targetSelector = event.target.getAttribute('data-atlas-filter');
		var emptySelector = event.target.getAttribute('data-atlas-filter-empty');
		var container = targetSelector ? document.querySelector(targetSelector) : null;
		if (!container) return;
		var query = event.target.value.trim().toLowerCase();
		var items = container.querySelectorAll('[data-atlas-filter-item]');
		var visible = 0;
		items.forEach(function (item) {
			var value = (item.getAttribute('data-filter-value') || item.textContent).toLowerCase();
			var matches = !query || value.indexOf(query) !== -1;
			item.hidden = !matches;
			if (matches) visible += 1;
		});
		var empty = emptySelector ? document.querySelector(emptySelector) : null;
		if (empty) empty.hidden = visible !== 0;
	});
}());
