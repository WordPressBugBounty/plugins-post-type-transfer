/* global pttCsvModal */
(function () {
	'use strict';

	const modal = document.getElementById('ptt-import-modal');
	const overlay = document.getElementById('ptt-modal-overlay');
	const dropZone = document.getElementById('ptt-drop-zone');
	const fileInput = document.getElementById('ptt-csv-file-input');
	const badge = document.getElementById('ptt-file-badge');
	const fileName = document.getElementById('ptt-file-name');
	const clearBtn = document.getElementById('ptt-file-clear');
	const submitBtn = document.getElementById('ptt-import-submit');
	const openBtn = document.getElementById('ptt-import-open');

	// Bail early when the modal markup is absent (non-list-table pages).
	if (!modal || !openBtn) {
		return;
	}

	// Translated strings injected via wp_localize_script.
	const i18n = (typeof pttCsvModal !== 'undefined') ? pttCsvModal : {};

	function openModal() {
		modal.classList.add('ptt-is-open');
		modal.setAttribute('aria-hidden', 'false');
		document.body.style.overflow = 'hidden';

		// Move focus inside the dialog for keyboard / screen-reader users.
		const firstFocusable = modal.querySelector('button, [tabindex="0"]');
		if (firstFocusable) {
			firstFocusable.focus();
		}
	}

	function closeModal() {
		modal.classList.remove('ptt-is-open');
		modal.setAttribute('aria-hidden', 'true');
		document.body.style.overflow = '';
		resetFile();

		// Return focus to the trigger button.
		if (openBtn) {
			openBtn.focus();
		}
	}

	openBtn.addEventListener('click', openModal);

	// Close when clicking the semi-transparent backdrop.
	overlay.addEventListener('click', closeModal);

	// Close via the ✕ button or the Cancel button (both share a class).
	modal.addEventListener('click', function (e) {
		if (e.target.closest('.ptt-modal-x, .ptt-modal-cancel')) {
			closeModal();
		}
	});

	// Close with the Escape key.
	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key && modal.classList.contains('ptt-is-open')) {
			closeModal();
		}
	});

	function setFile(file) {
		if (!file) {
			return;
		}

		// Client-side extension guard (server re-validates independently).
		const ext = file.name.split('.').pop().toLowerCase();
		if ('csv' !== ext) {
			// eslint-disable-next-line no-alert
			window.alert(i18n.invalidFile || 'Please select a .csv file.');
			return;
		}

		if (!(fileInput.files && fileInput.files.length > 0)) {
			try {
				const dt = new DataTransfer();
				dt.items.add(file);
				fileInput.files = dt.files;
			} catch (err) {
				// DataTransfer not supported — drag-and-drop cannot assign the file.
			}

			if (!fileInput.files || fileInput.files.length === 0) {
				window.alert(i18n.useFilePicker || 'Drag and drop is not supported in your browser. Please use the file picker.'); // eslint-disable-line no-alert
				return;
			}
		}

		fileName.textContent = file.name;
		badge.hidden = false;
		submitBtn.disabled = false;
	}

	function resetFile() {
		fileInput.value = '';
		badge.hidden = true;
		fileName.textContent = '';
		submitBtn.disabled = true;
		dropZone.classList.remove('ptt-dragover');
	}

	// Change event from the native file picker.
	fileInput.addEventListener('change', function () {
		if (fileInput.files && fileInput.files.length > 0) {
			setFile(fileInput.files[0]);
		}
	});

	// Clear-file badge button.
	clearBtn.addEventListener('click', function (e) {
		e.stopPropagation();
		resetFile();
	});

	dropZone.addEventListener('dragenter', function (e) {
		e.preventDefault();
		dropZone.classList.add('ptt-dragover');
	});

	dropZone.addEventListener('dragover', function (e) {
		e.preventDefault();
		dropZone.classList.add('ptt-dragover');
	});

	dropZone.addEventListener('dragleave', function (e) {
		// Only clear the highlight when focus actually leaves the zone (not its children).
		if (!dropZone.contains(e.relatedTarget)) {
			dropZone.classList.remove('ptt-dragover');
		}
	});

	dropZone.addEventListener('drop', function (e) {
		e.preventDefault();
		dropZone.classList.remove('ptt-dragover');
		const files = e.dataTransfer?.files;
		if (files && files.length > 0) {
			setFile(files[0]);
		}
	});

	document.getElementById('ptt-import-form').addEventListener('submit', function () {
		submitBtn.disabled = true;
		submitBtn.textContent = i18n.importing || 'Importing…';
	});

}());
