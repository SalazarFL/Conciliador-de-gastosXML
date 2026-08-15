/**
 * JavaScript principal de la aplicación
 * Nexo Fiscal
 */

/* Diálogos visuales compartidos. Sustituyen alert/confirm del navegador para
 * que los mensajes tengan contexto, una acción clara y la identidad del sistema. */
(function () {
	'use strict';

	var queue = [];
	var active = null;
	var ui = null;
	var previousFocus = null;

	var types = {
		info:    { icon: 'fa-circle-info',          title: 'Información' },
		warning: { icon: 'fa-triangle-exclamation', title: 'Revisa esta información' },
		danger:  { icon: 'fa-circle-exclamation',   title: 'No se pudo completar' },
		success: { icon: 'fa-circle-check',         title: 'Proceso completado' }
	};

	function createUi() {
		if (ui) return ui;

		var layer = document.createElement('div');
		layer.className = 'app-dialog-layer';
		layer.setAttribute('aria-hidden', 'true');
		layer.innerHTML =
			'<section class="app-dialog" role="dialog" aria-modal="true" aria-labelledby="app-dialog-title" aria-describedby="app-dialog-message">' +
				'<button type="button" class="app-dialog-close" aria-label="Cerrar"><i class="fas fa-xmark"></i></button>' +
				'<div class="app-dialog-head">' +
					'<div class="app-dialog-icon" aria-hidden="true"><i class="fas fa-circle-info"></i></div>' +
					'<div><div class="app-dialog-brand">Nexo Fiscal</div><h2 class="app-dialog-title" id="app-dialog-title"></h2></div>' +
				'</div>' +
				'<div class="app-dialog-message" id="app-dialog-message"></div>' +
				'<div class="app-dialog-actions">' +
					'<button type="button" class="btn btn-outline app-dialog-cancel">Cancelar</button>' +
					'<button type="button" class="btn btn-primary app-dialog-confirm">Entendido</button>' +
				'</div>' +
			'</section>';
		document.body.appendChild(layer);

		ui = {
			layer: layer,
			panel: layer.querySelector('.app-dialog'),
			icon: layer.querySelector('.app-dialog-icon i'),
			title: layer.querySelector('.app-dialog-title'),
			message: layer.querySelector('.app-dialog-message'),
			close: layer.querySelector('.app-dialog-close'),
			cancel: layer.querySelector('.app-dialog-cancel'),
			confirm: layer.querySelector('.app-dialog-confirm')
		};

		ui.close.addEventListener('click', function () { finish(false); });
		ui.cancel.addEventListener('click', function () { finish(false); });
		ui.confirm.addEventListener('click', function () { finish(true); });
		layer.addEventListener('click', function (event) {
			if (event.target === layer) finish(false);
		});

		return ui;
	}

	function openNext() {
		if (active || !queue.length) return;
		createUi();
		active = queue.shift();
		previousFocus = document.activeElement;

		var type = types[active.options.type] ? active.options.type : 'info';
		var meta = types[type];
		ui.panel.className = 'app-dialog is-' + type;
		ui.icon.className = 'fas ' + meta.icon;
		ui.title.textContent = active.options.title || meta.title;
		ui.message.textContent = active.options.message || '';
		ui.cancel.textContent = active.options.cancelText || 'Cancelar';
		ui.confirm.textContent = active.options.confirmText || (active.options.confirm ? 'Continuar' : 'Entendido');
		ui.cancel.hidden = !active.options.confirm;
		ui.confirm.className = 'btn app-dialog-confirm ' + (type === 'danger' || type === 'warning' ? 'is-' + type : 'btn-primary');

		document.body.classList.add('app-dialog-open');
		ui.layer.setAttribute('aria-hidden', 'false');
		requestAnimationFrame(function () {
			ui.layer.classList.add('open');
			(active.options.confirm ? ui.cancel : ui.confirm).focus();
		});
	}

	function finish(result) {
		if (!active || !ui) return;
		var completed = active;
		active = null;
		ui.layer.classList.remove('open');
		ui.layer.setAttribute('aria-hidden', 'true');
		document.body.classList.remove('app-dialog-open');

		setTimeout(function () {
			completed.resolve(result);
			if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
			previousFocus = null;
			openNext();
		}, 180);
	}

	function show(options) {
		return new Promise(function (resolve) {
			queue.push({ options: options, resolve: resolve });
			openNext();
		});
	}

	function dataOptions(element) {
		return {
			message: element.dataset.confirm || '',
			title: element.dataset.confirmTitle || 'Confirma esta acción',
			type: element.dataset.confirmType || 'warning',
			confirmText: element.dataset.confirmAccept || 'Continuar',
			cancelText: element.dataset.confirmCancel || 'Cancelar',
			confirm: true
		};
	}

	window.AppDialog = {
		alert: function (message, options) {
			options = options || {};
			return show(Object.assign({}, options, { message: String(message || ''), confirm: false }));
		},
		confirm: function (message, options) {
			options = options || {};
			return show(Object.assign({}, options, { message: String(message || ''), confirm: true }));
		}
	};

	/* Confirmaciones declarativas para formularios, enlaces y botones, incluidos
	 * los que se agregan a la página después de cargarla. */
	document.addEventListener('submit', function (event) {
		var form = event.target.closest ? event.target.closest('form[data-confirm]') : null;
		if (!form) return;
		if (form.dataset.dialogConfirmed === 'true') {
			delete form.dataset.dialogConfirmed;
			return;
		}

		event.preventDefault();
		if (form.dataset.dialogPending === 'true') return;
		form.dataset.dialogPending = 'true';
		var submitter = event.submitter || null;

		show(dataOptions(form)).then(function (confirmed) {
			delete form.dataset.dialogPending;
			if (!confirmed) return;
			form.dataset.dialogConfirmed = 'true';
			if (typeof form.requestSubmit === 'function') {
				if (submitter) form.requestSubmit(submitter);
				else form.requestSubmit();
			}
			else form.submit();
		});
	}, true);

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest ? event.target.closest('a[data-confirm], button[data-confirm]') : null;
		if (!trigger) return;
		if (trigger.dataset.dialogConfirmed === 'true') {
			delete trigger.dataset.dialogConfirmed;
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		if (trigger.dataset.dialogPending === 'true') return;
		trigger.dataset.dialogPending = 'true';

		show(dataOptions(trigger)).then(function (confirmed) {
			delete trigger.dataset.dialogPending;
			if (!confirmed) return;
			trigger.dataset.dialogConfirmed = 'true';
			trigger.click();
		});
	}, true);

	document.addEventListener('keydown', function (event) {
		if (!active || !ui) return;
		if (event.key === 'Escape') {
			event.preventDefault();
			finish(false);
			return;
		}
		if (event.key !== 'Tab') return;
		var focusable = Array.prototype.filter.call(
			ui.panel.querySelectorAll('button:not([hidden]):not([disabled])'),
			function (element) { return element.offsetParent !== null; }
		);
		if (!focusable.length) return;
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
		else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
	});
})();

document.addEventListener('DOMContentLoaded', function () {
	var openButtons = document.querySelectorAll('[data-modal-target]');
	var closeButtons = document.querySelectorAll('[data-modal-close]');
	var modals = document.querySelectorAll('[data-modal]');

	function openModal(modalId) {
		var modal = document.getElementById(modalId);
		if (!modal) {
			return;
		}

		modal.style.display = 'block';
		document.body.style.overflow = 'hidden';
	}

	function closeModal(modal) {
		if (!modal) {
			return;
		}

		modal.style.display = 'none';
		document.body.style.overflow = '';
	}

	openButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			openModal(button.getAttribute('data-modal-target'));
		});
	});

	closeButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			closeModal(button.closest('[data-modal]'));
		});
	});

	modals.forEach(function (modal) {
		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				closeModal(modal);
			}
		});
	});

	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') {
			return;
		}

		modals.forEach(function (modal) {
			if (modal.style.display === 'block') {
				closeModal(modal);
			}
		});
	});
});
