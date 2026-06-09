/* global pttCsvModal */
( function () {
	'use strict';

	var modal     = document.getElementById( 'ptt-import-modal' );
	var overlay   = document.getElementById( 'ptt-modal-overlay' );
	var dropZone  = document.getElementById( 'ptt-drop-zone' );
	var fileInput = document.getElementById( 'ptt-csv-file-input' );
	var badge     = document.getElementById( 'ptt-file-badge' );
	var fileName  = document.getElementById( 'ptt-file-name' );
	var clearBtn  = document.getElementById( 'ptt-file-clear' );
	var submitBtn = document.getElementById( 'ptt-import-submit' );
	var openBtn   = document.getElementById( 'ptt-import-open' );

	// Bail early when the modal markup is absent (non-list-table pages).
	if ( ! modal || ! openBtn ) {
		return;
	}

	// Translated strings injected via wp_localize_script.
	var i18n = ( typeof pttCsvModal !== 'undefined' ) ? pttCsvModal : {};

	function openModal() {
		modal.classList.add( 'ptt-is-open' );
		modal.setAttribute( 'aria-hidden', 'false' );
		document.body.style.overflow = 'hidden';

		// Move focus inside the dialog for keyboard / screen-reader users.
		var firstFocusable = modal.querySelector( 'button, [tabindex="0"]' );
		if ( firstFocusable ) {
			firstFocusable.focus();
		}
	}

	function closeModal() {
		modal.classList.remove( 'ptt-is-open' );
		modal.setAttribute( 'aria-hidden', 'true' );
		document.body.style.overflow = '';
		resetFile();

		// Return focus to the trigger button.
		if ( openBtn ) {
			openBtn.focus();
		}
	}

	openBtn.addEventListener( 'click', openModal );

	// Close when clicking the semi-transparent backdrop.
	overlay.addEventListener( 'click', closeModal );

	// Close via the ✕ button or the Cancel button (both share a class).
	modal.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.ptt-modal-x, .ptt-modal-cancel' ) ) {
			closeModal();
		}
	} );

	// Close with the Escape key.
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && modal.classList.contains( 'ptt-is-open' ) ) {
			closeModal();
		}
	} );

	function setFile( file ) {
		if ( ! file ) {
			return;
		}

		// Client-side extension guard (server re-validates independently).
		var ext = file.name.split( '.' ).pop().toLowerCase();
		if ( 'csv' !== ext ) {
			// eslint-disable-next-line no-alert
			window.alert( i18n.invalidFile || 'Please select a .csv file.' );
			return;
		}

		if ( ! ( fileInput.files && fileInput.files.length > 0 ) ) {
			try {
				var dt = new DataTransfer();
				dt.items.add( file );
				fileInput.files = dt.files;
			} catch ( err ) {
				// DataTransfer not supported — drag-and-drop cannot assign the file.
			}

			if ( ! fileInput.files || fileInput.files.length === 0 ) {
				window.alert( i18n.useFilePicker || 'Drag and drop is not supported in your browser. Please use the file picker.' ); // eslint-disable-line no-alert
				return;
			}
		}

		fileName.textContent = file.name;
		badge.hidden         = false;
		submitBtn.disabled   = false;
	}

	function resetFile() {
		fileInput.value      = '';
		badge.hidden         = true;
		fileName.textContent = '';
		submitBtn.disabled   = true;
		dropZone.classList.remove( 'ptt-dragover' );
	}

	// Change event from the native file picker.
	fileInput.addEventListener( 'change', function () {
		if ( fileInput.files && fileInput.files.length > 0 ) {
			setFile( fileInput.files[0] );
		}
	} );

	// Clear-file badge button.
	clearBtn.addEventListener( 'click', function ( e ) {
		e.stopPropagation();
		resetFile();
	} );

	dropZone.addEventListener( 'dragenter', function ( e ) {
		e.preventDefault();
		dropZone.classList.add( 'ptt-dragover' );
	} );

	dropZone.addEventListener( 'dragover', function ( e ) {
		e.preventDefault();
		dropZone.classList.add( 'ptt-dragover' );
	} );

	dropZone.addEventListener( 'dragleave', function ( e ) {
		// Only clear the highlight when focus actually leaves the zone (not its children).
		if ( ! dropZone.contains( e.relatedTarget ) ) {
			dropZone.classList.remove( 'ptt-dragover' );
		}
	} );

	dropZone.addEventListener( 'drop', function ( e ) {
		e.preventDefault();
		dropZone.classList.remove( 'ptt-dragover' );
		var files = e.dataTransfer && e.dataTransfer.files;
		if ( files && files.length > 0 ) {
			setFile( files[0] );
		}
	} );

	document.getElementById( 'ptt-import-form' ).addEventListener( 'submit', function () {
		submitBtn.disabled    = true;
		submitBtn.textContent = i18n.importing || 'Importing…';
	} );

}() );
