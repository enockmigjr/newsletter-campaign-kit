(function () {
	'use strict';

	function progress(busy) {
		let bar = document.querySelector('[data-nck-progress]');
		if (!bar) {
			bar = document.createElement('div');
			bar.className = 'nck-admin-progress';
			bar.dataset.nckProgress = '';
			bar.setAttribute('role', 'progressbar');
			bar.setAttribute('aria-label', 'Operation en cours');
			document.body.appendChild(bar);
		}
		window.requestAnimationFrame(function() {
			bar.classList.toggle('is-active', busy);
		});
	}

	function setBusy(form, busy) {
		form.dataset.nckSubmitting = busy ? 'true' : 'false';
		form.toggleAttribute('aria-busy', busy);
		form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(button) {
			button.disabled = busy;
			button.classList.toggle('is-busy', busy);
			button.toggleAttribute('aria-busy', busy);
			if (button instanceof HTMLInputElement) {
				if (busy) {
					button.dataset.nckIdleValue = button.value;
					button.value = 'Traitement...';
				} else if (button.dataset.nckIdleValue) {
					button.value = button.dataset.nckIdleValue;
					delete button.dataset.nckIdleValue;
				}
			}
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

	function confirmAction(message) {
		let dialog = document.querySelector('[data-nck-confirm-dialog]');
		if (!dialog) {
			dialog = document.createElement('dialog');
			dialog.className = 'nck-admin-dialog nck-confirm-dialog';
			dialog.dataset.nckConfirmDialog = '';
			dialog.innerHTML = '<div class="nck-admin-dialog__body"><h2>Confirmer l\u2019action</h2><p data-nck-confirm-message></p><div class="nck-confirm-dialog__actions"><button type="button" class="button" data-nck-confirm-cancel>Annuler</button><button type="button" class="button button-primary" data-nck-confirm-accept>Confirmer</button></div></div>';
			document.body.appendChild(dialog);
		}
		dialog.querySelector('[data-nck-confirm-message]').textContent = message;
		dialog.showModal();

		return new Promise(function(resolve) {
			const cancel = dialog.querySelector('[data-nck-confirm-cancel]');
			const accept = dialog.querySelector('[data-nck-confirm-accept]');
			function finish(value) {
				cancel.removeEventListener('click', reject);
				accept.removeEventListener('click', approve);
				dialog.removeEventListener('cancel', reject);
				dialog.close();
				resolve(value);
			}
			function reject(event) {
				if (event) event.preventDefault();
				finish(false);
			}
			function approve() {
				finish(true);
			}
			cancel.addEventListener('click', reject);
			accept.addEventListener('click', approve);
			dialog.addEventListener('cancel', reject);
		});
	}

	function removeEditors(root) {
		if (!window.wp || !wp.editor || typeof wp.editor.remove !== 'function') return;
		root.querySelectorAll('textarea[data-nck-rich-editor]').forEach(function(textarea) {
			if (textarea.id) {
				try {
					wp.editor.remove(textarea.id);
				} catch (error) {
					// The editor may not have been initialized on this surface.
				}
			}
		});
	}

	function initializeEditors(root) {
		if (!window.wp || !wp.editor || typeof wp.editor.initialize !== 'function') return;
		root.querySelectorAll('textarea[data-nck-rich-editor]').forEach(function(textarea) {
			if (!textarea.id || textarea.dataset.nckEditorBound === '1') return;
			textarea.dataset.nckEditorBound = '1';
			wp.editor.initialize(textarea.id, {
				tinymce: {
					wpautop: true,
					toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
					toolbar2: 'forecolor,removeformat,charmap,outdent,indent,wp_help'
				},
				quicktags: true,
				mediaButtons: false
			});
		});
	}

	function initializeSurface(root) {
		const scope = root || document;
		initializeEditors(scope);
		const autoOpen = scope.querySelector('[data-nck-dialog-auto-open]');
		if (autoOpen && typeof autoOpen.showModal === 'function' && !autoOpen.open) autoOpen.showModal();
		scope.querySelectorAll('[data-nck-source-select]').forEach(function(select) {
			function syncSourceFields() {
				const value = select.value;
				const container = select.closest('form') || scope;
				container.querySelectorAll('[data-nck-source-for]').forEach(function(field) {
					const modes = (field.dataset.nckSourceFor || '').split(/\s+/);
					field.hidden = modes.indexOf(value) === -1;
					field.querySelectorAll('input, select, textarea').forEach(function(control) {
						control.disabled = field.hidden;
					});
				});
			}
			if (select.dataset.nckSourceBound !== '1') {
				select.dataset.nckSourceBound = '1';
				select.addEventListener('change', syncSourceFields);
			}
			syncSourceFields();
		});
		scope.querySelectorAll('[data-nck-source-content-type]').forEach(function(select) {
			const form = select.closest('form');
			const contentSelect = form ? form.querySelector('[data-nck-source-content-items]') : null;
			if (!contentSelect) return;
			function syncContentItems() {
				contentSelect.querySelectorAll('option[data-nck-post-type]').forEach(function(option) {
					const matches = option.dataset.nckPostType === select.value;
					option.hidden = !matches;
					option.disabled = !matches;
					if (!matches) option.selected = false;
				});
			}
			if (select.dataset.nckContentTypeBound !== '1') {
				select.dataset.nckContentTypeBound = '1';
				select.addEventListener('change', syncContentItems);
			}
			syncContentItems();
		});
		scope.querySelectorAll('[data-nck-check-filter]').forEach(function(input) {
			const list = document.getElementById(input.dataset.nckCheckFilter);
			if (!list || input.dataset.nckCheckFilterBound === '1') return;
			input.dataset.nckCheckFilterBound = '1';
			input.addEventListener('input', function() {
				const query = input.value.trim().toLowerCase();
				list.querySelectorAll('label').forEach(function(option) {
					option.hidden = query && option.textContent.toLowerCase().indexOf(query) === -1;
				});
			});
		});
	}

	async function updateSurface(url, options, historyMode) {
		progress(true);
		try {
			const response = await fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}));
			const html = await response.text();
			const documentResult = new DOMParser().parseFromString(html, 'text/html');
			const next = documentResult.querySelector('.newsletter-campaign-kit-admin');
			const current = document.querySelector('.newsletter-campaign-kit-admin');
			if (!response.ok || !next || !current) {
				throw new Error('The administration screen could not be refreshed.');
			}
			removeEditors(current);
			current.replaceWith(next);
			if (documentResult.title) document.title = documentResult.title;
			if (historyMode === 'push') {
				window.history.pushState({ nck: true }, '', response.url);
			} else if (historyMode === 'replace') {
				window.history.replaceState({ nck: true }, '', response.url);
			}
			initializeSurface(next);
			return responseNotice(next);
		} finally {
			progress(false);
		}
	}

	document.addEventListener('submit', async function(event) {
		const form = event.target;
		if (!(form instanceof HTMLFormElement) || !form.closest('.newsletter-campaign-kit-admin') || form.target) return;
		event.preventDefault();
		if (form.dataset.nckSubmitting === 'true') return;
		if (form.dataset.nckConfirm) {
			const confirmed = await confirmAction(form.dataset.nckConfirm);
			if (!confirmed) return;
		}
		if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') {
			window.tinyMCE.triggerSave();
		}
		setBusy(form, true);
		const method = (form.method || 'GET').toUpperCase();
		try {
			let url = new URL(form.getAttribute('action') || window.location.href, window.location.href).href;
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
		const opener = event.target.closest('[data-nck-dialog-open]');
		if (opener) {
			const dialog = document.getElementById(opener.dataset.nckDialogOpen);
			if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
			return;
		}
		const closer = event.target.closest('[data-nck-dialog-close]');
		if (closer) {
			const dialog = closer.closest('dialog');
			if (dialog) dialog.close();
			return;
		}
		const link = event.target.closest('.newsletter-campaign-kit-admin .nck-pagination a');
		const surfaceLink = event.target.closest('.newsletter-campaign-kit-admin a[href]');
		const candidate = link || surfaceLink;
		if (!candidate || candidate.target || candidate.hasAttribute('download') || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
		const url = new URL(candidate.href, window.location.href);
		if (url.origin !== window.location.origin || /\/admin-post\.php$/.test(url.pathname) || candidate.dataset.nckNative !== undefined) return;
		if (!/\/admin\.php$/.test(url.pathname)) return;
		event.preventDefault();
		try {
			await updateSurface(candidate.href, {}, 'push');
		} catch (error) {
			toast(error.message || 'The page could not be loaded.', false);
		}
	});

	document.addEventListener('click', function(event) {
		if (event.target instanceof HTMLDialogElement && event.target.classList.contains('nck-admin-dialog')) {
			event.target.close();
		}
	});

	document.addEventListener('DOMContentLoaded', function() {
		initializeSurface(document);
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
