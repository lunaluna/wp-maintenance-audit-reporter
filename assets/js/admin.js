/**
 * WP Maintenance Audit Reporter — admin UI helpers.
 *
 * Shows a blocking overlay during long synchronous form posts (runner actions).
 *
 * @package WPMAR
 */
(function () {
	'use strict';

	const cfg =
		typeof window.wpmarAdminBusy === 'object' && window.wpmarAdminBusy !== null
			? window.wpmarAdminBusy
			: {};

	function busyCopy(actionValue) {
		switch (actionValue) {
			case 'dry_run':
				return cfg.dryRun || '';
			case 'full_run':
				return cfg.fullRun || '';
			case 'test_mail':
				return cfg.testMail || '';
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

		// Submit buttons marked disabled before navigation are omitted from POST. Mirror the clicked
		// action into a hidden field so PHP still receives `wpmar_admin_action` (dry_run/full_run/etc.).
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

	document.addEventListener('DOMContentLoaded', function () {
		document.addEventListener(
			'submit',
			function (e) {
				onSubmit(e);
			},
			true
		);

		if (
			typeof cfg.confirmSingle !== 'string' &&
			typeof cfg.confirmBulk !== 'string'
		) {
			return;
		}

		function elementFromEventTarget(t) {
			if (!t) {
				return null;
			}
			return t.nodeType === 1 ? t : t.parentElement;
		}

		function openDeleteConfirm(message, onDelete) {
			const root = document.getElementById('wpmar-delete-confirm');
			const msgEl = document.getElementById(
				'wpmar-delete-confirm-label'
			);
			const btnBack = document.getElementById(
				'wpmar-delete-confirm-back'
			);
			const btnDo = document.getElementById('wpmar-delete-confirm-do');
			const backdrop = root
				? root.querySelector('.wpmar-delete-confirm__backdrop')
				: null;

			if (
				!root ||
				!msgEl ||
				!btnBack ||
				!btnDo ||
				typeof message !== 'string'
			) {
				if (
					typeof onDelete === 'function' &&
					window.confirm(message || '')
				) {
					onDelete();
				}
				return;
			}

			msgEl.textContent = message;
			root.hidden = false;
			btnBack.focus();

			function cleanup() {
				btnBack.removeEventListener('click', onBack);
				btnDo.removeEventListener('click', onDo);
				if (backdrop) {
					backdrop.removeEventListener('click', onBack);
				}
				document.removeEventListener('keydown', onEscape);
			}

			function onBack() {
				root.hidden = true;
				cleanup();
			}

			function onDo() {
				root.hidden = true;
				cleanup();
				if (typeof onDelete === 'function') {
					onDelete();
				}
			}

			function onEscape(ev) {
				if (ev.key === 'Escape') {
					ev.preventDefault();
					onBack();
				}
			}

			btnBack.addEventListener('click', onBack);
			btnDo.addEventListener('click', onDo);
			document.addEventListener('keydown', onEscape);
			if (backdrop) {
				backdrop.addEventListener('click', onBack);
			}
		}

		document.addEventListener(
			'click',
			function (e) {
				const el = elementFromEventTarget(e.target);
				const link =
					el &&
					typeof el.closest === 'function' &&
					el.closest('a.wpmar-report-delete');
				if (!link || !link.getAttribute('href')) {
					return;
				}
				if (typeof cfg.confirmSingle !== 'string') {
					return;
				}
				e.preventDefault();
				openDeleteConfirm(cfg.confirmSingle, function () {
					window.location.href = link.href;
				});
			},
			true
		);

		const reportsForm = document.getElementById('wpmar-reports-form');
		if (!reportsForm) {
			return;
		}

		reportsForm.addEventListener('submit', function (e) {
			const actionTop = reportsForm.querySelector(
				'select[name="action"]'
			);
			const actionBottom = reportsForm.querySelector(
				'select[name="action2"]'
			);
			const wantsDelete =
				(actionTop && actionTop.value === 'delete') ||
				(actionBottom && actionBottom.value === 'delete');
			if (!wantsDelete) {
				return;
			}

			const n = reportsForm.querySelectorAll(
				'tbody .check-column input[type="checkbox"]:checked'
			).length;
			if (n < 1) {
				return;
			}

			let msg =
				typeof cfg.confirmBulk === 'string' ? cfg.confirmBulk : '';
			if (msg.indexOf('%d') !== -1) {
				msg = msg.split('%d').join(String(n));
			}
			if (!msg) {
				return;
			}

			e.preventDefault();
			openDeleteConfirm(msg, function () {
				reportsForm.submit();
			});
		});
	});
})();
