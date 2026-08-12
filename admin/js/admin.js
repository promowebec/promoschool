/* Sistema Educativo · interacciones del admin */
(function ( $ ) {
	'use strict';

	// Mostrar/ocultar el campo Especialidad según subnivel.
	$( document ).on( 'change', '#edu-sublevel', function () {
		var $row = $( '#edu-row-specialty' );
		if ( $( this ).val() === 'bt' ) {
			$row.show();
		} else {
			$row.hide();
			$row.find( 'input' ).val( '' );
		}

		// Sincronizar nivel con subnivel (informativo).
		var level = $( this ).find( ':selected' ).data( 'level' );
		if ( level ) {
			$( '#edu-level' ).val( level );
		}
	} );

	// Auto-rellenar fechas de período según régimen (solo si están en los defaults conocidos).
	$( document ).on( 'change', 'input[name="regime"]', function () {
		var regime = $( this ).val();
		var $start = $( '#edu-start' );
		var $end   = $( '#edu-end' );
		if ( ! $start.length || ! $end.length ) {
			return;
		}
		if ( regime === 'sierra' ) {
			if ( $start.val() === '2026-05-01' ) { $start.val( '2026-09-01' ); }
			if ( $end.val()   === '2027-02-10' ) { $end.val( '2027-07-10' ); }
		} else {
			if ( $start.val() === '2026-09-01' ) { $start.val( '2026-05-01' ); }
			if ( $end.val()   === '2027-07-10' ) { $end.val( '2027-02-10' ); }
		}
	} );

} )( jQuery );
