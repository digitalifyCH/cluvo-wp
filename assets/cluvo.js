/* global CluvoData */
( function () {
	'use strict';

	var i18n = ( window.CluvoData && CluvoData.i18n ) || {};
	var FORM = 'form[data-cluvo-form]';
	var CONTROLS = 'input:not([type="hidden"]), select, textarea';
	var errorCount = 0;

	function each( nodes, callback ) {
		Array.prototype.forEach.call( nodes, callback );
	}

	function closest( element, selector ) {
		return element && element.closest ? element.closest( selector ) : null;
	}

	function setEnabled( container, enabled ) {
		each( container.querySelectorAll( 'input, select, textarea' ), function ( control ) {
			control.disabled = ! enabled;
		} );
	}

	/* ---------------------------------------------------------------------
	 * Name block: Privatperson / Unternehmen
	 * ------------------------------------------------------------------- */

	function syncContactType( form ) {
		var block = form.querySelector( '[data-cluvo-name]' );
		var company = block && block.querySelector( '[data-cluvo-company]' );
		if ( ! company ) {
			return;
		}

		var checked = block.querySelector( 'input[name="contact_type"]:checked' );
		var isCompany = !! checked && checked.value === 'company';

		company.hidden = ! isCompany;
		setEnabled( company, isCompany );

		// Companies may name a contact person, but don't have to.
		each( block.querySelectorAll( '[data-cluvo-person-name]' ), function ( input ) {
			input.required = ! isCompany;
			input.placeholder = isCompany ? i18n.contactPerson || '' : '';
			var star = form.querySelector( 'label[for="' + input.id + '"] .cluvo-required' );
			if ( star ) {
				star.hidden = isCompany;
			}
		} );
	}

	/* ---------------------------------------------------------------------
	 * Free contribution: interval → amounts → custom amount
	 * ------------------------------------------------------------------- */

	function syncFee( fee ) {
		var interval = fee.querySelector( 'input[name="membership_interval"]:checked' );

		each( fee.querySelectorAll( '[data-cluvo-fee-group]' ), function ( group ) {
			var key = group.getAttribute( 'data-cluvo-fee-group' );
			var active = key === '' || ( !! interval && interval.value === key );

			group.hidden = ! active;
			setEnabled( group, active );

			var toggle = group.querySelector( '[data-cluvo-custom]' );
			var wrap = group.querySelector( '[data-cluvo-custom-wrap]' );
			if ( toggle && wrap ) {
				var custom = active && toggle.checked;
				var input = wrap.querySelector( 'input' );
				wrap.hidden = ! custom;
				input.disabled = ! custom;
				input.required = custom && toggle.required;
			}
		} );
	}

	function resetAmounts( fee ) {
		each( fee.querySelectorAll( '[data-cluvo-fee-group] input' ), function ( input ) {
			if ( input.type === 'radio' ) {
				input.checked = false;
			} else {
				input.value = '';
			}
		} );
	}

	/* ---------------------------------------------------------------------
	 * Repeating groups
	 * ------------------------------------------------------------------- */

	function renumber( repeater ) {
		each( repeater.querySelectorAll( '[data-cluvo-row]' ), function ( row, index ) {
			var number = row.querySelector( '[data-cluvo-row-number]' );
			if ( number ) {
				number.textContent = ( i18n.row || '%s' ).replace( '%s', String( index + 1 ) );
			}
		} );
	}

	function addRow( repeater ) {
		var template = repeater.querySelector( 'template[data-cluvo-row-template]' );
		var rows = repeater.querySelector( '[data-cluvo-rows]' );
		if ( ! template || ! rows ) {
			return;
		}

		var index = parseInt( repeater.getAttribute( 'data-cluvo-next' ), 10 ) || 0;
		repeater.setAttribute( 'data-cluvo-next', String( index + 1 ) );

		var holder = document.createElement( 'div' );
		holder.innerHTML = template.innerHTML.replace( /__ROW__/g, String( index ) ).trim();
		var row = holder.firstElementChild;
		rows.appendChild( row );
		renumber( repeater );

		var first = row.querySelector( CONTROLS );
		if ( first ) {
			first.focus();
		}
	}

	/* ---------------------------------------------------------------------
	 * Errors and status
	 * ------------------------------------------------------------------- */

	function setStatus( form, message, type, focus ) {
		var status = form.querySelector( '.cluvo-form__status' );
		if ( ! status ) {
			return;
		}
		status.hidden = ! message;
		status.textContent = message || '';
		status.className = 'cluvo-form__status' + ( type ? ' cluvo-form__status--' + type : '' );
		if ( focus && message ) {
			status.focus();
		}
	}

	function clearField( field ) {
		if ( ! field || ! field.classList.contains( 'cluvo-field--error' ) ) {
			return;
		}
		field.classList.remove( 'cluvo-field--error' );
		each( field.querySelectorAll( '.cluvo-field__error' ), function ( error ) {
			each( field.querySelectorAll( '[aria-describedby]' ), function ( control ) {
				var ids = control.getAttribute( 'aria-describedby' ).split( ' ' ).filter( function ( id ) {
					return id && id !== error.id;
				} );
				if ( ids.length ) {
					control.setAttribute( 'aria-describedby', ids.join( ' ' ) );
				} else {
					control.removeAttribute( 'aria-describedby' );
				}
			} );
			error.remove();
		} );
		each( field.querySelectorAll( '[aria-invalid]' ), function ( control ) {
			control.removeAttribute( 'aria-invalid' );
		} );
	}

	function findField( form, name ) {
		var parts = String( name ).split( '.' );
		var selector = '[name="' + parts[ 0 ] + '"]';
		if ( parts[ 0 ] === 'custom_fields' && /^\d+$/.test( parts[ 1 ] || '' ) ) {
			selector = '[name="custom_fields[' + parts[ 1 ] + ']"], [name^="custom_fields[' + parts[ 1 ] + ']["]';
		}
		var input;

		try {
			input = form.querySelector( selector );
		} catch ( e ) {
			return null;
		}

		if ( ! input && parts[ 0 ] === 'membership_fee' ) {
			input = form.querySelector( '[data-cluvo-fee] input' );
		}

		return fieldOf( input );
	}

	// Errors of a repeating group belong to the group, not to one of its inputs.
	function fieldOf( element ) {
		return closest( element, '.cluvo-repeater' ) || closest( element, '.cluvo-field' );
	}

	function markField( field, message ) {
		field.classList.add( 'cluvo-field--error' );

		var error = document.createElement( 'p' );
		error.className = 'cluvo-field__error';
		error.id = 'cluvo-error-' + ( ++errorCount );
		error.textContent = message;
		field.appendChild( error );

		each( field.querySelectorAll( CONTROLS ), function ( control ) {
			var ids = ( control.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( Boolean );
			ids.push( error.id );
			control.setAttribute( 'aria-describedby', ids.join( ' ' ) );
			control.setAttribute( 'aria-invalid', 'true' );
		} );
	}

	function showErrors( form, errors ) {
		var unplaced = [];

		Object.keys( errors ).forEach( function ( name ) {
			var message = [].concat( errors[ name ] )[ 0 ];
			var field = findField( form, name );
			if ( field && ! closest( field, '[hidden]' ) ) {
				markField( field, message );
			} else if ( message ) {
				unplaced.push( message );
			}
		} );

		var first = form.querySelector( '.cluvo-field--error' );
		setStatus( form, [ i18n.invalid ].concat( unplaced ).join( ' ' ), 'error', ! first );

		var control = first && first.querySelector( 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])' );
		if ( control ) {
			control.focus();
		}
	}

	/* ---------------------------------------------------------------------
	 * Submit
	 * ------------------------------------------------------------------- */

	function payload( form ) {
		var data = new FormData( form );

		// «Individueller Betrag» is a radio; the amount comes from its input.
		each( form.querySelectorAll( '[data-cluvo-custom]:checked' ), function ( toggle ) {
			if ( toggle.disabled ) {
				return;
			}
			var input = closest( toggle, '[data-cluvo-fee-group]' ).querySelector( '[data-cluvo-custom-input]' );
			data.set( 'membership_fee', input ? input.value.trim() : '' );
		} );

		return data;
	}

	function handleSubmit( event ) {
		var form = event.target;
		if ( ! form.matches || ! form.matches( FORM ) ) {
			return;
		}
		event.preventDefault();

		if ( form.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}

		var button = form.querySelector( '[type="submit"]' );

		each( form.querySelectorAll( '.cluvo-field--error' ), clearField );
		setStatus( form, i18n.submitting, 'pending' );
		form.setAttribute( 'aria-busy', 'true' );
		if ( button ) {
			button.disabled = true;
		}

		fetch( form.getAttribute( 'action' ), {
			method: 'POST',
			body: payload( form ),
			credentials: 'same-origin',
			headers: { Accept: 'application/json' }
		} )
			.then( function ( response ) {
				return response.json()
					.catch( function () {
						return {};
					} )
					.then( function ( body ) {
						return { status: response.status, body: body || {} };
					} );
			} )
			.then( function ( result ) {
				if ( result.status >= 200 && result.status < 300 ) {
					var body = form.querySelector( '.cluvo-form__body' );
					if ( body ) {
						body.hidden = true;
					}
					form.classList.add( 'cluvo-form--submitted' );
					setStatus( form, result.body.status === 'pending' ? i18n.pending : i18n.success, 'success', true );
				} else if ( result.status === 422 && result.body.errors ) {
					showErrors( form, result.body.errors );
				} else {
					setStatus( form, result.body.message || i18n.error, 'error', true );
				}
			} )
			.catch( function () {
				setStatus( form, i18n.error, 'error', true );
			} )
			.then( function () {
				form.removeAttribute( 'aria-busy' );
				if ( button ) {
					button.disabled = false;
				}
			} );
	}

	/* ---------------------------------------------------------------------
	 * Wiring
	 * ------------------------------------------------------------------- */

	function init() {
		each( document.querySelectorAll( FORM ), function ( form ) {
			syncContactType( form );
			each( form.querySelectorAll( '[data-cluvo-fee]' ), syncFee );
		} );
	}

	document.addEventListener( 'submit', handleSubmit );

	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		var form = closest( target, FORM );
		if ( ! form ) {
			return;
		}

		clearField( fieldOf( target ) );

		if ( target.name === 'contact_type' ) {
			syncContactType( form );
		}

		var fee = closest( target, '[data-cluvo-fee]' );
		if ( fee ) {
			if ( target.name === 'membership_interval' ) {
				resetAmounts( fee );
			}
			syncFee( fee );
			if ( target.hasAttribute( 'data-cluvo-custom' ) && target.checked ) {
				var input = closest( target, '[data-cluvo-fee-group]' ).querySelector( '[data-cluvo-custom-input]' );
				if ( input ) {
					input.focus();
				}
			}
		}
	} );

	document.addEventListener( 'input', function ( event ) {
		if ( closest( event.target, FORM ) ) {
			clearField( fieldOf( event.target ) );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var add = closest( event.target, '[data-cluvo-add-row]' );
		if ( add ) {
			addRow( closest( add, '[data-cluvo-repeater]' ) );
			return;
		}

		var remove = closest( event.target, '[data-cluvo-remove-row]' );
		if ( remove ) {
			var repeater = closest( remove, '[data-cluvo-repeater]' );
			closest( remove, '[data-cluvo-row]' ).remove();
			renumber( repeater );
			var addButton = repeater.querySelector( '[data-cluvo-add-row]' );
			if ( addButton ) {
				addButton.focus();
			}
		}
	} );

	// Also after the browser restores a page (back button) with its form state.
	window.addEventListener( 'pageshow', init );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
