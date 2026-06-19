/* global CluvoData */
( function () {
	'use strict';

	function clearErrors( form ) {
		form.querySelectorAll( '.cluvo-field--error' ).forEach( function ( el ) {
			el.classList.remove( 'cluvo-field--error' );
		} );
		form.querySelectorAll( '.cluvo-field__error' ).forEach( function ( el ) {
			el.remove();
		} );
	}

	function showFieldError( form, name, message ) {
		// Custom field names arrive as custom_fields.5 from Laravel.
		var selector = '[name="' + name + '"]';
		if ( name.indexOf( 'custom_fields.' ) === 0 ) {
			var id = name.split( '.' )[ 1 ];
			selector = '[name="custom_fields[' + id + ']"]';
		}
		var input = form.querySelector( selector );
		if ( ! input ) { return; }
		var field = input.closest( '.cluvo-field' );
		if ( ! field ) { return; }
		field.classList.add( 'cluvo-field--error' );
		var err = document.createElement( 'p' );
		err.className = 'cluvo-field__error';
		err.textContent = message;
		field.appendChild( err );
	}

	function setStatus( form, message, type ) {
		var status = form.querySelector( '.cluvo-form__status' );
		if ( ! status ) { return; }
		status.hidden = ! message;
		status.textContent = message || '';
		status.className = 'cluvo-form__status' + ( type ? ' cluvo-form__status--' + type : '' );
	}

	function handleSubmit( e ) {
		var form = e.target;
		if ( ! form.matches || ! form.matches( 'form[data-cluvo-form]' ) ) { return; }
		e.preventDefault();

		var slug = form.getAttribute( 'data-cluvo-form' );
		var account = form.getAttribute( 'data-cluvo-account' );
		var button = form.querySelector( '.cluvo-form__submit' );
		clearErrors( form );
		setStatus( form, CluvoData.i18n.submitting, 'pending' );
		if ( button ) { button.disabled = true; }

		fetch( CluvoData.restUrl + '/accounts/' + encodeURIComponent( account ) + '/forms/' + encodeURIComponent( slug ) + '/submit', {
			method: 'POST',
			headers: { 'X-WP-Nonce': CluvoData.nonce },
			body: new FormData( form )
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { status: response.status, body: body };
				} );
			} )
			.then( function ( res ) {
				if ( res.status >= 200 && res.status < 300 ) {
					form.reset();
					setStatus( form, res.body.message || '', 'success' );
				} else if ( res.status === 422 && res.body.errors ) {
					Object.keys( res.body.errors ).forEach( function ( name ) {
						showFieldError( form, name, res.body.errors[ name ][ 0 ] );
					} );
					setStatus( form, res.body.message || CluvoData.i18n.error, 'error' );
				} else {
					setStatus( form, res.body.message || CluvoData.i18n.error, 'error' );
				}
			} )
			.catch( function () {
				setStatus( form, CluvoData.i18n.error, 'error' );
			} )
			.finally( function () {
				if ( button ) { button.disabled = false; }
			} );
	}

	document.addEventListener( 'submit', handleSubmit );
} )();
