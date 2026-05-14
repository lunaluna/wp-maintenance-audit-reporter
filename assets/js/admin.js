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
	});
})();
