(function () {
	'use strict';

	function setBusy(form, busy) {
		form.dataset.nckSubmitting = busy ? 'true' : 'false';
		form.toggleAttribute('aria-busy', busy);
		form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(button) {
			button.disabled = busy;
			button.classList.toggle('is-busy', busy);
		});
	}

	function toast(message, success) {
		document.querySelectorAll('[data-nck-toast]').forEach(function(item) {
			item.remove();
		});
		const notice = document.createElement('div');
		notice.className = 'nck-runtime-toast ' + (success ? 'is-success' : 'is-error');
		notice.dataset.nckToast = '';
		notice.setAttribute('role', success ? 'status' : 'alert');
		const text = document.createElement('span');
		text.textContent = message;
		notice.appendChild(text);
		const close = document.createElement('button');
		close.type = 'button';
		close.setAttribute('aria-label', 'Close notification');
		close.textContent = '\u00d7';
		close.addEventListener('click', function() {
			notice.remove();
		});
		notice.appendChild(close);
		document.body.appendChild(notice);
		window.setTimeout(function() {
			notice.remove();
		}, 7000);
	}

	function responseNotice(root) {
		const notice = root.querySelector('.notice p, .updated p, .error p');
		return notice ? notice.textContent.trim() : 'Changes saved.';
	}

	async function updateSurface(url, options, historyMode) {
		const response = await fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}));
		const html = await response.text();
		const documentResult = new DOMParser().parseFromString(html, 'text/html');
		const next = documentResult.querySelector('.newsletter-campaign-kit-admin');
		const current = document.querySelector('.newsletter-campaign-kit-admin');
		if (!response.ok || !next || !current) {
			throw new Error('The administration screen could not be refreshed.');
		}
		current.replaceWith(next);
		if (documentResult.title) document.title = documentResult.title;
		if (historyMode === 'push') {
			window.history.pushState({ nck: true }, '', response.url);
		} else if (historyMode === 'replace') {
			window.history.replaceState({ nck: true }, '', response.url);
		}
		return responseNotice(next);
	}

	document.addEventListener('submit', async function(event) {
		const form = event.target;
		if (!(form instanceof HTMLFormElement) || !form.closest('.newsletter-campaign-kit-admin') || form.target) return;
		event.preventDefault();
		if (form.dataset.nckSubmitting === 'true') return;
		setBusy(form, true);
		const method = (form.method || 'GET').toUpperCase();
		try {
			let url = form.action || window.location.href;
			let options = {};
			let historyMode = 'replace';
			if (method === 'GET') {
				const query = new URLSearchParams(new FormData(form));
				url = url.split('?')[0] + '?' + query.toString();
				historyMode = 'push';
			} else {
				options = { method: method, body: new FormData(form) };
			}
			const message = await updateSurface(url, options, historyMode);
			toast(message, true);
		} catch (error) {
			setBusy(form, false);
			toast(error.message || 'The operation could not be completed.', false);
		}
	});

	document.addEventListener('click', async function(event) {
		const link = event.target.closest('.newsletter-campaign-kit-admin .nck-pagination a');
		if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
		event.preventDefault();
		try {
			await updateSurface(link.href, {}, 'push');
		} catch (error) {
			toast(error.message || 'The page could not be loaded.', false);
		}
	});

	window.addEventListener('popstate', async function() {
		if (!document.querySelector('.newsletter-campaign-kit-admin')) return;
		try {
			await updateSurface(window.location.href, {}, '');
		} catch (error) {
			window.location.reload();
		}
	});
}());
