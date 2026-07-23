/**
 * WPCalendarCats — listens for resize messages posted by the embedded
 * calendar app and adjusts the matching iframe's height to fit its content.
 */
( function () {
	window.addEventListener( 'message', function ( event ) {
		var data = event.data;
		if ( ! data || data.type !== 'wpcalendarcats:resize' || typeof data.height !== 'number' ) {
			return;
		}

		var frames = document.querySelectorAll( 'iframe.wpcalendarcats-iframe' );
		for ( var i = 0; i < frames.length; i++ ) {
			if ( frames[ i ].contentWindow === event.source ) {
				frames[ i ].style.height = Math.max( 300, data.height ) + 'px';
				break;
			}
		}
	} );
} )();
