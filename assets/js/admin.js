/**
 * WP Maintenance Audit Reporter — admin UI helpers.
 *
 * Blocking overlay during synchronous posts from the settings form (runner actions).
 *
 * @package WPMAR
 */
(function () {
	'use strict';

	function busyStrings() {
		if (
			typeof window.wpmarAdminBusy === 'object' &&
			window.wpmarAdminBusy !== null
		) {
			return window.wpmarAdminBusy;
		}

		return {};
	}

	function busyCopy(actionValue) {
		const cfg = busyStrings();

		switch (actionValue) {
			case 'dry_run':
				return cfg.dryRun || '';
			case 'full_run':
				return cfg.fullRun || '';
			default:
				return '';
		}
	}

	function onSubmit(evt) {
		const form = evt.target;
		if (
			form.id !== 'wpmar-settings-form' ||
			form.nodeName !== 'FORM'
		) {
			return;
		}

		const overlay = document.getElementById('wpmar-busy-overlay');
		const messageEl = document.getElementById('wpmar-busy-message');
		const btn =
			evt.target === form && evt.submitter ? evt.submitter : null;

		const actionBtn =
			btn &&
			btn.getAttribute &&
			btn.getAttribute('name') === 'wpmar_admin_action' &&
			btn.type === 'submit'
				? btn
				: null;

		const actionValue = actionBtn ? String(actionBtn.value || '') : '';
		const copy = busyCopy(actionValue);

		if (!overlay || !messageEl || !copy) {
			return;
		}

		let carry = form.querySelector(
			'input[type="hidden"][name="wpmar_admin_action"][data-wpmar-busy-carry="1"]'
		);
		if (!carry) {
			carry = document.createElement('input');
			carry.type = 'hidden';
			carry.name = 'wpmar_admin_action';
			carry.setAttribute('data-wpmar-busy-carry', '1');
			form.appendChild(carry);
		}
		carry.value = actionValue;

		messageEl.textContent = copy;
		overlay.hidden = false;
		overlay.setAttribute('aria-hidden', 'false');
		overlay.setAttribute('aria-busy', 'true');
		form.setAttribute('aria-busy', 'true');

		form.querySelectorAll('button[type="submit"]').forEach(function (b) {
			b.disabled = true;
		});
	}

	/**
	 * Report list row delete → confirm overlay (jQuery delegated: avoids native click target quirks).
	 */
	function initReportsDeleteModal() {
		if (typeof window.jQuery !== 'function') {
			return;
		}

		const $ = window.jQuery;
		const modal = document.getElementById('wpmar-delete-report-modal');
		const form = document.getElementById('wpmar-reports-form');

		if (!modal || !form) {
			return;
		}

		const bodyEl = modal.querySelector('[data-wpmar-delete-modal-body]');
		const btnCancel = modal.querySelector('[data-wpmar-delete-cancel]');
		const btnConfirm = modal.querySelector('[data-wpmar-delete-confirm]');

		let pendingRowUrl = '';
		let trapHandler = null;

		function removeTrap() {
			if (trapHandler) {
				document.removeEventListener('keydown', trapHandler, true);
				trapHandler = null;
			}
		}

		function closeModal() {
			removeTrap();
			pendingRowUrl = '';
			modal.hidden = true;
			modal.setAttribute('aria-hidden', 'true');
			if (bodyEl) {
				bodyEl.textContent = '';
			}
		}

		function openRowModal(url, message) {
			removeTrap();
			pendingRowUrl = url;
			if (bodyEl && message) {
				bodyEl.textContent = message;
			}

			modal.hidden = false;
			modal.setAttribute('aria-hidden', 'false');

			trapHandler = function (ke) {
				if (ke.key === 'Escape') {
					ke.preventDefault();
					closeModal();
				}
			};
			document.addEventListener('keydown', trapHandler, true);

			if (btnCancel) {
				btnCancel.focus();
			}
		}

		$(form).on('click', 'a.wpmar-report-delete-trigger', function (e) {
			e.preventDefault();

			const href = this.getAttribute('href');
			const msg =
				this.getAttribute('data-wpmar-delete-message') || '';

			if (href) {
				openRowModal(href, msg);
			}
		});

		if (btnCancel) {
			btnCancel.addEventListener('click', closeModal);
		}

		if (btnConfirm) {
			btnConfirm.addEventListener('click', function () {
				if (!pendingRowUrl) {
					closeModal();

					return;
				}

				window.location.href = pendingRowUrl;
			});
		}

		modal.addEventListener('click', function (evt) {
			if (evt.target === modal) {
				closeModal();
			}
		});
	}

	/**
	 * Polls the job-status REST endpoint and renders progress / download links.
	 *
	 * Activated when the settings (or network) screen prints a panel marked with
	 * [data-wpmar-job-poll]; reads the job id and REST config from wpmarAdminBusy.
	 */
	function initJobPoller() {
		const panel = document.querySelector('[data-wpmar-job-poll]');
		if (!panel) {
			return;
		}

		const cfg = busyStrings();
		const jobId = panel.getAttribute('data-wpmar-job-id') || '';
		const base = cfg.restBase || '';

		if (!jobId || !base) {
			return;
		}

		const mode = panel.getAttribute('data-wpmar-job-mode') || 'full';
		const messageEl = panel.querySelector('[data-wpmar-job-message]');
		const spinnerEl = panel.querySelector('[data-wpmar-job-spinner]');
		const linksEl = panel.querySelector('[data-wpmar-job-links]');
		const previewEl = panel.querySelector('[data-wpmar-job-preview]');
		const flashEl = document.querySelector('[data-wpmar-job-flash]');

		function setFlash(text, isError) {
			if (!flashEl || !text) {
				return;
			}
			flashEl.className = isError
				? 'notice notice-error'
				: 'notice notice-success';
			const p = flashEl.querySelector('p');
			if (p) {
				p.textContent = text;
			}
		}

		const POLL_MS = 2500;
		let timer = null;
		let stopped = false;

		function setMessage(text) {
			if (messageEl && text) {
				messageEl.textContent = text;
			}
		}

		function stopSpinner() {
			if (spinnerEl) {
				spinnerEl.hidden = true;
				spinnerEl.style.display = 'none';
			}
		}

		function stop() {
			stopped = true;
			if (timer) {
				window.clearTimeout(timer);
				timer = null;
			}
		}

		function addLink(href, label) {
			if (!linksEl || !href || !label) {
				return;
			}
			const li = document.createElement('li');
			const a = document.createElement('a');
			a.className = 'button';
			a.href = href;
			a.textContent = label;
			li.appendChild(a);
			linksEl.appendChild(li);
		}

		function renderDone(data) {
			stopSpinner();
			const result = data && data.result ? data.result : null;
			const isDry = mode === 'dry' || !!(result && result.dry_brevity);

			if (isDry) {
				// Dry run: show the brevity summary, no download links.
				setMessage(cfg.pollDoneDry || cfg.pollDone || '');
				setFlash(cfg.flashDoneDry || cfg.flashDone || '', false);
				if (previewEl && result && result.dry_brevity) {
					previewEl.textContent = result.dry_brevity;
					previewEl.hidden = false;
				}
				return;
			}

			setMessage(cfg.pollDone || '');
			setFlash(cfg.flashDone || '', false);
			if (!result) {
				return;
			}
			if (result.report_url) {
				addLink(result.report_url, cfg.linkReport || result.report_url);
			}
			const dl = result.downloads || {};
			if (dl.md) {
				addLink(dl.md, cfg.linkMd || 'Markdown');
			}
			if (dl.pdf) {
				addLink(dl.pdf, cfg.linkPdf || 'PDF');
			}
			if (dl.client_md) {
				addLink(dl.client_md, cfg.linkClient || 'Markdown');
			}
			if (linksEl) {
				linksEl.hidden = false;
			}
		}

		function renderFailed(data) {
			stopSpinner();
			const detail = data && data.error ? ' ' + data.error : '';
			setMessage((cfg.pollFailed || '') + detail);
			setFlash((cfg.pollFailed || '') + detail, true);
			panel.classList.add('wpmar-job-panel--failed');
		}

		function tick() {
			if (stopped) {
				return;
			}

			window
				.fetch(base + encodeURIComponent(jobId), {
					method: 'GET',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': cfg.restNonce || '',
						Accept: 'application/json'
					}
				})
				.then(function (res) {
					return res.json();
				})
				.then(function (data) {
					const status = data && data.status ? data.status : '';

					if (status === 'queued') {
						setMessage(cfg.pollQueued || '');
					} else if (status === 'running') {
						setMessage(cfg.pollRunning || '');
					} else if (status === 'done') {
						renderDone(data);
						stop();
						return;
					} else if (status === 'failed') {
						renderFailed(data);
						stop();
						return;
					}

					timer = window.setTimeout(tick, POLL_MS);
				})
				.catch(function () {
					// Transient network/REST error: keep polling rather than giving up.
					setMessage(cfg.pollError || '');
					timer = window.setTimeout(tick, POLL_MS);
				});
		}

		tick();
	}

	function bootAdminUi() {
		document.addEventListener(
			'submit',
			function (e) {
				onSubmit(e);
			},
			true
		);

		initReportsDeleteModal();
		initJobPoller();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener('DOMContentLoaded', bootAdminUi);
	} else {
		bootAdminUi();
	}
})();
