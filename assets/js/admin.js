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

	function bootAdminUi() {
		document.addEventListener(
			'submit',
			function (e) {
				onSubmit(e);
			},
			true
		);

		initReportsDeleteModal();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener('DOMContentLoaded', bootAdminUi);
	} else {
		bootAdminUi();
	}
})();
