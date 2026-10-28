/* global inlineEditPost, typenow */
(function ($) {
	'use strict';

	function ptt_bulk_edit() {
		const _edit = inlineEditPost.edit;
		$( '#bulk-edit' ).find( '.inline-edit-col-right .inline-edit-col' ).append( $('#bulk-edit #ptt_bulk_edit' ) );
		$( '.inline-edit-row' ).not( '#bulk-edit' ).find( '.inline-edit-col-right .inline-edit-col' ).append( $( '.inline-edit-row #ptt_bulk_edit' ) );

		inlineEditPost.edit = function( id ) {
			const args = Array.prototype.slice.call( arguments );
			_edit.apply( this, args );
			if ( typeof( id ) === 'object' ) {
				id = this.getId( id );
			}
			// editRow is the quick-edit row, containing the inputs that need to be updated
			const editRow = $( `#edit-${id}` );
			// postRow is the row shown when a post isn't being edited, which also holds the existing values.
			const postRow = $( `#post-${id}` );
			// get the existing values
			const postType = $( 'td.post_type span', postRow ).data( 'post-type' );
			// set the values in the quick-editor
			$( `select[name="post_type_transfer_types"] option[value="${postType}"]`, editRow ).attr( 'selected', 'selected' );
		};
	}
	// Another way of ensuring inlineEditPost.edit isn't patched until it's defined
	if ( typeof inlineEditPost !== 'undefined' ) {
		ptt_bulk_edit();
	} else {
		$( ptt_bulk_edit );
	}

	// Remove Post row after ptt process is completed.
	$( document ).ajaxSuccess( function( _event, _xhr, settings ) {
		const params = new URLSearchParams( settings.data );
		if ( settings.data && settings.data.indexOf('action=inline-save') !== -1 ) {
			const postId = params.get('post_ID');
			if ( postId ) {
				// New selected post type from custom field
				const selectedPostType = params.get('post_type_transfer_types');
				if ( typeof typenow !== 'undefined' && selectedPostType !== typenow ) {
					location.reload();
				}
			}
		}
	});
})(jQuery);
