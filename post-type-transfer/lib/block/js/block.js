( function ( $ ) {
	'use strict';

	let isSavingPost = false;

	// Subscribe to Gutenberg editor state so we detect save completion without polling.
	wp.data.subscribe( function () {
		const editor = wp.data.select( 'core/editor' );
		const currentlySaving = editor.isSavingPost() && ! editor.isAutosavingPost();

		// Rising edge: a save just started.
		if ( currentlySaving && ! isSavingPost ) {
			isSavingPost = true;
			return;
		}

		// Falling edge: save completed.
		if ( ! currentlySaving && isSavingPost ) {
			isSavingPost = false;

			if ( ! editor.didPostSaveRequestSucceed() ) {
				return;
			}

			const selectPostTypeLabel = $.trim( $( '#post_type_transfer_types option:selected' ).text() ) || '';
			const selectPostType      = $.trim( $( '#post_type_transfer_types option:selected' ).val() ) || '';
			const currentLabel        = $.trim( $( '#post-type-display' ).text() ) || '';

			if ( currentLabel === selectPostTypeLabel ) {
				return;
			}

			if ( selectPostType !== '' ) {
				swal( {
					title: 'Post Transfer To "' + selectPostTypeLabel + '"',
					icon: 'success',
					button: 'Click View Post',
					closeOnClickOutside: false,
					closeOnEsc: false,
				} ).then( function () {
					window.location.reload();
				} );
			}
		}
	} );
} )( jQuery );
