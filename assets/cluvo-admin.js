/* global CluvoAdmin */
( function () {
	'use strict';

	var table = document.getElementById( 'cluvo-accounts' );
	var addButton = document.getElementById( 'cluvo-add-account' );
	var template = document.getElementById( 'cluvo-account-template' );

	if ( ! table || ! addButton || ! template ) {
		return;
	}

	var tbody = table.querySelector( 'tbody' );
	var index = tbody.querySelectorAll( 'tr' ).length;

	addButton.addEventListener( 'click', function () {
		var holder = document.createElement( 'tbody' );
		holder.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( index++ ) ).trim();
		var row = holder.firstElementChild;
		tbody.appendChild( row );
		row.querySelector( 'input[type="text"]' ).focus();
	} );

	function showResult( out, message, success, forms, accountId ) {
		out.textContent = '';
		out.className = 'cluvo-test-result ' + ( success ? 'is-success' : 'is-error' );

		var text = document.createElement( 'p' );
		text.textContent = message;
		out.appendChild( text );

		if ( ! forms || ! forms.length ) {
			return;
		}

		var list = document.createElement( 'ul' );
		forms.forEach( function ( form ) {
			var item = document.createElement( 'li' );
			var code = document.createElement( 'code' );
			code.textContent = '[cluvo_form' + ( accountId ? ' account="' + accountId + '"' : '' ) + ' slug="' + form.slug + '"]';
			item.appendChild( code );
			if ( form.name ) {
				item.appendChild( document.createTextNode( ' ' + form.name ) );
			}
			list.appendChild( item );
		} );
		out.appendChild( list );

		if ( ! accountId ) {
			var hint = document.createElement( 'p' );
			hint.textContent = CluvoAdmin.i18n.saveFirst;
			out.appendChild( hint );
		}
	}

	tbody.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( 'button' );
		if ( ! button ) {
			return;
		}

		var row = button.closest( 'tr' );

		if ( button.classList.contains( 'cluvo-remove-account' ) ) {
			var next = row.nextElementSibling || row.previousElementSibling;
			row.remove();
			( next ? next.querySelector( 'input[type="text"]' ) : addButton ).focus();
			return;
		}

		if ( ! button.classList.contains( 'cluvo-test-account' ) ) {
			return;
		}

		var input = row.querySelector( '.cluvo-key-input' );
		var out = row.querySelector( '.cluvo-test-result' );
		var accountId = button.getAttribute( 'data-account' );

		if ( ! input.value.trim() && input.getAttribute( 'data-has-key' ) !== '1' ) {
			showResult( out, CluvoAdmin.i18n.noKey, false );
			return;
		}

		var body = new FormData();
		body.append( 'action', 'cluvo_test_connection' );
		body.append( '_ajax_nonce', CluvoAdmin.nonce );
		body.append( 'api_key', input.value );
		body.append( 'account_id', accountId );

		button.disabled = true;
		out.className = 'cluvo-test-result';
		out.textContent = CluvoAdmin.i18n.testing;

		fetch( CluvoAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				var data = result && result.data ? result.data : {};
				showResult( out, data.message || CluvoAdmin.i18n.error, !! result.success, data.forms, accountId !== '0' ? accountId : '' );
			} )
			.catch( function () {
				showResult( out, CluvoAdmin.i18n.error, false );
			} )
			.then( function () {
				button.disabled = false;
			} );
	} );
} )();
