/**
 * JavaScript principal de la aplicación
 * VerificadorXMLConciliacion
 */

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
