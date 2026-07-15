( function () {
	'use strict';

	function insertAtCursor( field, content ) {
		if ( ! field || ! content ) {
			return;
		}
		var start = Number.isInteger( field.selectionStart ) ? field.selectionStart : field.value.length;
		var end = Number.isInteger( field.selectionEnd ) ? field.selectionEnd : start;
		var prefix = start > 0 && field.value.charAt( start - 1 ) !== '\n' ? '\n\n' : '';
		var suffix = end < field.value.length && field.value.charAt( end ) !== '\n' ? '\n\n' : '';
		field.setRangeText( prefix + content + suffix, start, end, 'end' );
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && typeof event.target.closest === 'function'
			? event.target.closest( '[data-newsletter-insert-block]' )
			: null;
		if ( ! button || ! window.NewsletterCampaignBlocks ) {
			return;
		}
		event.preventDefault();
		var select = document.getElementById( 'nck-editorial-block' );
		var block = select ? window.NewsletterCampaignBlocks.blocks[ select.value ] : null;
		if ( ! block ) {
			return;
		}
		insertAtCursor( document.getElementById( 'nck-campaign-html-body' ), block.html );
		insertAtCursor( document.getElementById( 'nck-campaign-text-body' ), block.text );
	} );
}() );
