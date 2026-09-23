( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		var toggle = document.querySelector( '.ssw-view-toggle' );
		var products = document.querySelector( 'ul.products' );
		if ( ! toggle || ! products ) {
			return;
		}

		var setView = function ( view ) {
			products.classList.toggle( 'list-view', 'list' === view );
			toggle.querySelectorAll( '.ssw-view-btn' ).forEach( function ( btn ) {
				btn.classList.toggle( 'active', btn.getAttribute( 'data-view' ) === view );
			} );
			try {
				window.localStorage.setItem( 'ssw-shop-view', view );
			} catch ( e ) {}
		};

		var stored;
		try {
			stored = window.localStorage.getItem( 'ssw-shop-view' );
		} catch ( e ) {}
		if ( 'list' === stored ) {
			setView( 'list' );
		}

		toggle.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.ssw-view-btn' );
			if ( btn ) {
				setView( btn.getAttribute( 'data-view' ) );
			}
		} );
	} );
} )();
