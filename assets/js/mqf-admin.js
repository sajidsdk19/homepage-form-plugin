/**
 * Moving Quote Form – settings screen (colour pickers and the field builder).
 */
( function ( $ ) {
	'use strict';

	$( function () {
		if ( $.fn.wpColorPicker ) {
			$( '.mqf-color' ).wpColorPicker();
		}

		var $rows = $( '[data-mqf-fieldrows]' );
		if ( ! $rows.length ) {
			return;
		}

		var template = $( '#mqf-fieldrow-template' ).html() || '';
		var nextIndex = parseInt( $rows.attr( 'data-next-index' ), 10 ) || 0;
		var choiceTypes = [ 'select', 'radio', 'checkbox' ];

		// Show only the extra inputs that make sense for the chosen field type.
		function sync( $row ) {
			var type = $row.find( '[data-mqf-type]' ).val();
			var isChoice = choiceTypes.indexOf( type ) !== -1;
			var isGroup = type === 'radio' || type === 'checkbox';

			$row.find( '[data-mqf-show="options"]' ).toggle( isChoice );
			$row.find( '[data-mqf-show="future"]' ).toggle( type === 'date' );
			$row.find( '[data-mqf-show="placeholder"]' ).toggle( ! isGroup && type !== 'date' );
		}

		$rows.find( '[data-mqf-fieldrow]' ).each( function () {
			sync( $( this ) );
		} );

		$rows.on( 'change', '[data-mqf-type]', function () {
			sync( $( this ).closest( '[data-mqf-fieldrow]' ) );
		} );

		$( '[data-mqf-add]' ).on( 'click', function () {
			var $row = $( template.replace( /__INDEX__/g, String( nextIndex ) ) );
			nextIndex += 1;
			$rows.append( $row );
			sync( $row );
			$row.find( '[data-mqf-label]' ).trigger( 'focus' );
		} );

		$rows.on( 'click', '[data-mqf-remove]', function () {
			$( this ).closest( '[data-mqf-fieldrow]' ).remove();
		} );

		$rows.on( 'click', '[data-mqf-up]', function () {
			var $row = $( this ).closest( '[data-mqf-fieldrow]' );
			$row.prev( '[data-mqf-fieldrow]' ).before( $row );
			$( this ).trigger( 'focus' );
		} );

		$rows.on( 'click', '[data-mqf-down]', function () {
			var $row = $( this ).closest( '[data-mqf-fieldrow]' );
			$row.next( '[data-mqf-fieldrow]' ).after( $row );
			$( this ).trigger( 'focus' );
		} );
	} );
} )( jQuery );
