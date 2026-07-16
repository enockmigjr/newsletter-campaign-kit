(function () {
	'use strict';
	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!(form instanceof HTMLFormElement) || !form.closest('.newsletter-campaign-kit-admin')) return;
		if (form.dataset.nckSubmitting === 'true') {
			event.preventDefault();
			return;
		}
		form.dataset.nckSubmitting = 'true';
		form.setAttribute('aria-busy', 'true');
		form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
			button.disabled = true;
			button.classList.add('is-busy');
		});
	});
	window.addEventListener('pageshow', function () {
		document.querySelectorAll('.newsletter-campaign-kit-admin form[data-nck-submitting="true"]').forEach(function (form) {
			delete form.dataset.nckSubmitting;
			form.removeAttribute('aria-busy');
			form.querySelectorAll('button, input[type="submit"]').forEach(function (button) {
				button.disabled = false;
				button.classList.remove('is-busy');
			});
		});
	});
}());
