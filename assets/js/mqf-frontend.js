/**
 * Moving Quote Form – front-end module.
 *
 * Step 1: two independent Google address autocompletes (pickup, drop-off).
 * Step 2: customer details, sent with the locations in one AJAX request.
 *
 * No dependencies. Configuration arrives in window.mqfConfig (printed by WordPress
 * from the plugin settings), so nothing here is site-specific or hard-coded.
 */
( function () {
	'use strict';

	var cfg = window.mqfConfig;
	if ( ! cfg || ! cfg.ajaxUrl ) {
		return;
	}

	var t = cfg.i18n || {};
	var instances = [];
	var STORE_TTL = 2 * 60 * 60 * 1000;
	var reported = {};

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------- */

	function uuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		var out = '';
		for ( var i = 0; i < 32; i++ ) {
			out += Math.floor( Math.random() * 16 ).toString( 16 );
		}
		return out;
	}

	function errText( err ) {
		if ( ! err ) {
			return 'Unknown error';
		}
		return String( err.message || err ).slice( 0, 300 );
	}

	function makeError( code, message ) {
		var err = new Error( message );
		err.mqfCode = code;
		return err;
	}

	function localToday() {
		var d = new Date();
		var m = String( d.getMonth() + 1 );
		var day = String( d.getDate() );
		return d.getFullYear() + '-' + ( m.length < 2 ? '0' + m : m ) + '-' + ( day.length < 2 ? '0' + day : day );
	}

	function normalize( text ) {
		return String( text || '' ).toLowerCase().replace( /[^a-z0-9]+/g, '' );
	}

	function prefersReducedMotion() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	function request( action, data ) {
		var body = data instanceof FormData ? data : new FormData();
		if ( ! ( data instanceof FormData ) && data ) {
			Object.keys( data ).forEach( function ( key ) {
				body.append( key, data[ key ] );
			} );
		}
		body.append( 'action', action );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
		} ).then( function ( response ) {
			return response.text().then( function ( text ) {
				var json = null;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					json = null;
				}
				return { status: response.status, json: json };
			} );
		} );
	}

	/**
	 * Tell the site (once per problem per page view) about a technical failure,
	 * so the administrator can see it in the plugin's error log.
	 */
	function reportError( code, message ) {
		if ( reported[ code ] || code === 'no_key' ) {
			return;
		}
		reported[ code ] = true;
		if ( window.console && console.warn ) {
			console.warn( '[Moving Quote Form] ' + code + ': ' + message );
		}
		try {
			request( 'mqf_log_error', {
				code: code,
				message: String( message ).slice( 0, 300 ),
				url: location.origin + location.pathname,
			} ).catch( function () {} );
		} catch ( e ) {
			// Reporting must never break the form.
		}
	}

	/* ---------------------------------------------------------------------
	 * Google Maps / Places loader (shared by every form on the page)
	 * ------------------------------------------------------------------- */

	var gm = {
		promise: null,
		failed: false,
		restrictionsOk: true,
		errors: 0,
	};

	function googleFailed( code, message ) {
		if ( gm.failed ) {
			return;
		}
		gm.failed = true;
		reportError( code, message );
		instances.forEach( function ( instance ) {
			instance.onGoogleFailed();
		} );
	}

	// Google calls this global when the key is rejected (bad key, referrer not allowed, billing off).
	var previousAuthFailure = window.gm_authFailure;
	window.gm_authFailure = function () {
		googleFailed( 'auth_failure', 'Google rejected the API key. Check the key, its referrer and API restrictions, and that billing is enabled.' );
		if ( typeof previousAuthFailure === 'function' ) {
			previousAuthFailure();
		}
	};

	function loadPlaces() {
		if ( gm.promise ) {
			return gm.promise;
		}

		gm.promise = new Promise( function ( resolve, reject ) {
			var settled = false;
			var poll = null;

			function fail( err ) {
				if ( settled ) {
					return;
				}
				settled = true;
				clearTimeout( timer );
				clearInterval( poll );
				reject( err );
			}

			function ready() {
				if ( settled ) {
					return;
				}
				var maps = window.google && window.google.maps;
				if ( ! maps || typeof maps.importLibrary !== 'function' ) {
					fail( makeError( 'maps_outdated', 'The Google Maps script on this page does not support importLibrary().' ) );
					return;
				}
				maps.importLibrary( 'places' ).then(
					function ( lib ) {
						if ( settled ) {
							return;
						}
						if ( ! lib || ! lib.AutocompleteSuggestion || ! lib.AutocompleteSessionToken ) {
							fail( makeError( 'places_missing', 'Places API (New) autocomplete is not available in the loaded Google Maps script.' ) );
							return;
						}
						settled = true;
						clearTimeout( timer );
						clearInterval( poll );
						resolve( lib );
					},
					function ( err ) {
						fail( makeError( 'import_failed', 'Could not load the Google Places library: ' + errText( err ) ) );
					}
				);
			}

			var timer = setTimeout( function () {
				fail( makeError( 'timeout', 'The Google Maps script did not load in time.' ) );
			}, cfg.loadTimeout || 12000 );

			// Another plugin or the theme already loaded Google Maps: reuse it.
			if ( window.google && window.google.maps && typeof window.google.maps.importLibrary === 'function' ) {
				ready();
				return;
			}
			if ( document.querySelector( 'script[src*="maps.googleapis.com/maps/api/js"]' ) ) {
				poll = setInterval( function () {
					if ( window.google && window.google.maps && typeof window.google.maps.importLibrary === 'function' ) {
						clearInterval( poll );
						ready();
					}
				}, 150 );
				return;
			}

			if ( ! cfg.apiKey ) {
				fail( makeError( 'no_key', 'No Google Maps API key is configured.' ) );
				return;
			}

			var callback = '__mqfMapsReady';
			window[ callback ] = ready;

			var params = [
				'key=' + encodeURIComponent( cfg.apiKey ),
				'v=weekly',
				'loading=async',
				'libraries=places',
				'callback=' + callback,
			];
			if ( cfg.language ) {
				params.push( 'language=' + encodeURIComponent( cfg.language ) );
			}

			var script = document.createElement( 'script' );
			script.src = 'https://maps.googleapis.com/maps/api/js?' + params.join( '&' );
			script.async = true;
			script.onerror = function () {
				fail( makeError( 'script_error', 'The Google Maps script could not be loaded (network error or blocked by the browser).' ) );
			};
			document.head.appendChild( script );
		} );

		gm.promise.catch( function ( err ) {
			googleFailed( err.mqfCode || 'load_failed', errText( err ) );
		} );

		return gm.promise;
	}

	function buildRequest( input, token, restricted ) {
		var req = { input: input, sessionToken: token };
		if ( cfg.language ) {
			req.language = cfg.language;
		}
		if ( restricted ) {
			if ( cfg.countries && cfg.countries.length ) {
				req.includedRegionCodes = cfg.countries;
			}
			if ( cfg.types && cfg.types.length ) {
				req.includedPrimaryTypes = cfg.types;
			}
		}
		return req;
	}

	/**
	 * Ask Google for suggestions. If Google rejects the country/type restriction,
	 * retry without it so autocomplete keeps working, and log the reason.
	 */
	function fetchSuggestions( lib, input, token ) {
		var restricted = gm.restrictionsOk && ( ( cfg.countries && cfg.countries.length ) || ( cfg.types && cfg.types.length ) );

		return lib.AutocompleteSuggestion.fetchAutocompleteSuggestions( buildRequest( input, token, restricted ) )
			.catch( function ( err ) {
				if ( ! restricted ) {
					throw err;
				}
				return lib.AutocompleteSuggestion.fetchAutocompleteSuggestions( buildRequest( input, token, false ) ).then( function ( result ) {
					gm.restrictionsOk = false;
					reportError( 'restriction_rejected', 'Google rejected the country/type restriction, so suggestions are running without it. ' + errText( err ) );
					return result;
				} );
			} )
			.then( function ( result ) {
				gm.errors = 0;
				return ( result && result.suggestions ) || [];
			} );
	}

	function onRequestError( err ) {
		if ( err && err.mqfCode ) {
			googleFailed( err.mqfCode, errText( err ) );
			return;
		}
		gm.errors += 1;
		// One hiccup is tolerated; a second failure in a row means quota/billing/network trouble.
		if ( gm.errors >= 2 ) {
			googleFailed( 'request_failed', 'Autocomplete requests are failing: ' + errText( err ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * One location field (pickup or drop-off) with its own autocomplete
	 * ------------------------------------------------------------------- */

	function LocationField( quote, name ) {
		this.quote = quote;
		this.name = name;
		this.wrap = quote.root.querySelector( '[data-mqf-loc="' + name + '"]' );
		this.control = this.wrap.querySelector( '.mqf-loc__control' );
		this.input = this.wrap.querySelector( '.mqf-loc__input' );
		this.clearBtn = this.wrap.querySelector( '[data-mqf-clear]' );
		this.errorEl = this.wrap.querySelector( '.mqf-error' );

		this.value = { address: '', placeId: '', lat: '', lng: '' };
		this.selected = false;
		this.items = [];
		this.active = -1;
		this.isOpen = false;
		this.requestId = 0;
		this.timer = null;
		this.token = null;
		this.pending = null;
		this.box = null;
		this.list = null;
		this.pointerInList = false;
		this.reposition = this.position.bind( this );

		this.bind();
	}

	LocationField.prototype.bind = function () {
		var self = this;

		this.input.addEventListener( 'input', function () {
			self.onInput();
		} );

		this.input.addEventListener( 'focus', function () {
			self.quote.onLocationFocus();
			loadPlaces().catch( function () {} );
			if ( ! self.selected && self.items.length && ! gm.failed ) {
				self.open();
			}
		} );

		this.input.addEventListener( 'blur', function () {
			if ( ! self.pointerInList ) {
				self.close();
			}
		} );

		this.input.addEventListener( 'keydown', function ( event ) {
			self.onKeydown( event );
		} );

		this.clearBtn.addEventListener( 'click', function () {
			self.set( { address: '', placeId: '', lat: '', lng: '' }, false );
			self.items = [];
			self.close();
			self.setError( '' );
			self.quote.save();
			self.input.focus();
		} );
	};

	LocationField.prototype.text = function () {
		return this.input.value.trim();
	};

	LocationField.prototype.data = function () {
		return {
			address: this.selected && this.value.address ? this.value.address : this.text(),
			placeId: this.selected ? this.value.placeId : '',
			lat: this.selected ? this.value.lat : '',
			lng: this.selected ? this.value.lng : '',
		};
	};

	LocationField.prototype.set = function ( value, selected ) {
		this.value = {
			address: value.address || '',
			placeId: value.placeId || '',
			lat: value.lat || '',
			lng: value.lng || '',
		};
		this.selected = !! selected;
		this.input.value = this.value.address;
		this.clearBtn.hidden = ! this.input.value;
	};

	LocationField.prototype.setError = function ( message ) {
		this.errorEl.textContent = message || '';
		this.errorEl.hidden = ! message;
		this.wrap.classList.toggle( 'mqf-has-error', !! message );
		if ( message ) {
			this.input.setAttribute( 'aria-invalid', 'true' );
		} else {
			this.input.removeAttribute( 'aria-invalid' );
		}
	};

	LocationField.prototype.onInput = function () {
		var self = this;
		var text = this.text();

		// Any edit invalidates a previous selection: the stored place no longer matches the text.
		this.selected = false;
		this.value = { address: text, placeId: '', lat: '', lng: '' };
		this.clearBtn.hidden = ! this.input.value;
		this.setError( '' );
		this.quote.save();

		clearTimeout( this.timer );
		this.requestId += 1;

		if ( gm.failed || text.length < ( cfg.minChars || 3 ) ) {
			this.items = [];
			this.close();
			return;
		}

		this.timer = setTimeout( function () {
			self.query( text );
		}, cfg.debounce || 200 );
	};

	LocationField.prototype.query = function ( text ) {
		var self = this;
		var id = ++this.requestId;

		if ( ! this.isOpen ) {
			this.renderStatus( t.searching || '' );
		}

		loadPlaces()
			.then( function ( lib ) {
				if ( ! self.token ) {
					self.token = new lib.AutocompleteSessionToken();
				}
				return fetchSuggestions( lib, text, self.token );
			} )
			.then(
				function ( suggestions ) {
					// Ignore answers that arrive after the visitor typed something newer or left the field.
					if ( id !== self.requestId || document.activeElement !== self.input ) {
						return;
					}
					var items = [];
					suggestions.forEach( function ( suggestion ) {
						if ( suggestion && suggestion.placePrediction ) {
							items.push( suggestion.placePrediction );
						}
					} );
					self.renderItems( items );
				},
				function ( err ) {
					if ( id === self.requestId ) {
						self.close();
					}
					onRequestError( err );
				}
			);
	};

	LocationField.prototype.ensureBox = function () {
		if ( this.box ) {
			return;
		}
		var self = this;
		var listId = this.input.getAttribute( 'aria-controls' );

		// The dropdown lives directly in <body> so no parent container (Elementor section,
		// slider, overflow:hidden wrapper) can clip it.
		this.box = document.createElement( 'div' );
		this.box.className = 'mqf-suggest';
		this.box.hidden = true;

		this.list = document.createElement( 'ul' );
		this.list.className = 'mqf-suggest__list';
		this.list.id = listId;
		this.list.setAttribute( 'role', 'listbox' );
		this.list.setAttribute( 'aria-label', this.wrap.querySelector( 'label' ).textContent );

		this.status = document.createElement( 'div' );
		this.status.className = 'mqf-suggest__status';
		this.status.hidden = true;

		this.footer = document.createElement( 'div' );
		this.footer.className = 'mqf-suggest__footer';
		this.footer.textContent = t.poweredBy || 'Powered by Google';

		this.box.appendChild( this.list );
		this.box.appendChild( this.status );
		this.box.appendChild( this.footer );
		document.body.appendChild( this.box );

		// Keep focus in the input while the pointer is used on the list.
		this.box.addEventListener( 'mousedown', function ( event ) {
			event.preventDefault();
		} );
		this.box.addEventListener( 'pointerdown', function () {
			self.pointerInList = true;
		} );
		var release = function () {
			setTimeout( function () {
				self.pointerInList = false;
				if ( document.activeElement !== self.input ) {
					self.close();
				}
			}, 0 );
		};
		this.box.addEventListener( 'pointerup', release );
		this.box.addEventListener( 'pointercancel', release );

		this.box.addEventListener( 'click', function ( event ) {
			var item = event.target.closest ? event.target.closest( '[data-mqf-index]' ) : null;
			if ( item ) {
				self.choose( parseInt( item.getAttribute( 'data-mqf-index' ), 10 ) );
			}
		} );

		this.list.addEventListener( 'mousemove', function ( event ) {
			var item = event.target.closest ? event.target.closest( '[data-mqf-index]' ) : null;
			if ( item ) {
				self.setActive( parseInt( item.getAttribute( 'data-mqf-index' ), 10 ), false );
			}
		} );
	};

	function appendFormatted( parent, formattable ) {
		var text = '';
		if ( formattable ) {
			text = typeof formattable.text === 'string' ? formattable.text : String( formattable );
		}
		var matches = ( formattable && formattable.matches ) || [];
		var pos = 0;

		matches
			.slice()
			.sort( function ( a, b ) {
				return ( a.startOffset || 0 ) - ( b.startOffset || 0 );
			} )
			.forEach( function ( match ) {
				var start = Math.max( pos, match.startOffset || 0 );
				var end = Math.min( text.length, match.endOffset || 0 );
				if ( end <= start ) {
					return;
				}
				if ( start > pos ) {
					parent.appendChild( document.createTextNode( text.slice( pos, start ) ) );
				}
				var strong = document.createElement( 'strong' );
				strong.textContent = text.slice( start, end );
				parent.appendChild( strong );
				pos = end;
			} );

		if ( pos < text.length ) {
			parent.appendChild( document.createTextNode( text.slice( pos ) ) );
		}
	}

	function predictionText( prediction ) {
		if ( prediction.text ) {
			return typeof prediction.text.text === 'string' ? prediction.text.text : String( prediction.text );
		}
		return '';
	}

	LocationField.prototype.renderItems = function ( items ) {
		var self = this;
		this.ensureBox();
		this.items = items;
		this.active = -1;
		this.list.textContent = '';

		items.forEach( function ( prediction, index ) {
			var li = document.createElement( 'li' );
			li.className = 'mqf-suggest__item';
			li.id = self.input.id + '-option-' + index;
			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'aria-selected', 'false' );
			li.setAttribute( 'data-mqf-index', String( index ) );

			var icon = document.createElement( 'span' );
			icon.className = 'mqf-suggest__icon';
			icon.setAttribute( 'aria-hidden', 'true' );

			var body = document.createElement( 'span' );
			body.className = 'mqf-suggest__text';

			var main = document.createElement( 'span' );
			main.className = 'mqf-suggest__main';
			appendFormatted( main, prediction.mainText || prediction.text );
			body.appendChild( main );

			if ( prediction.mainText && prediction.secondaryText ) {
				var secondary = document.createElement( 'span' );
				secondary.className = 'mqf-suggest__secondary';
				appendFormatted( secondary, prediction.secondaryText );
				body.appendChild( secondary );
			}

			li.appendChild( icon );
			li.appendChild( body );
			self.list.appendChild( li );
		} );

		this.list.hidden = ! items.length;
		this.status.hidden = !! items.length;
		this.status.textContent = items.length ? '' : ( t.noResults || '' );
		this.footer.hidden = ! items.length;
		this.open();

		this.quote.announce( items.length ? ( t.resultsCount || '' ).replace( '%d', String( items.length ) ) : ( t.noResults || '' ) );
	};

	LocationField.prototype.renderStatus = function ( message ) {
		this.ensureBox();
		this.items = [];
		this.active = -1;
		this.list.textContent = '';
		this.list.hidden = true;
		this.footer.hidden = true;
		this.status.hidden = false;
		this.status.textContent = message;
		this.open();
	};

	LocationField.prototype.open = function () {
		this.ensureBox();
		if ( ! this.isOpen ) {
			this.isOpen = true;
			this.box.hidden = false;
			this.input.setAttribute( 'aria-expanded', 'true' );

			// Colours follow the form the dropdown belongs to.
			var styles = window.getComputedStyle( this.quote.root );
			var accent = styles.getPropertyValue( '--mqf-accent' );
			if ( accent ) {
				this.box.style.setProperty( '--mqf-accent', accent.trim() );
			}

			window.addEventListener( 'scroll', this.reposition, true );
			window.addEventListener( 'resize', this.reposition );
			if ( window.visualViewport ) {
				window.visualViewport.addEventListener( 'resize', this.reposition );
				window.visualViewport.addEventListener( 'scroll', this.reposition );
			}
		}
		this.position();
	};

	LocationField.prototype.close = function () {
		if ( ! this.isOpen ) {
			return;
		}
		this.isOpen = false;
		this.active = -1;
		this.box.hidden = true;
		this.input.setAttribute( 'aria-expanded', 'false' );
		this.input.removeAttribute( 'aria-activedescendant' );

		window.removeEventListener( 'scroll', this.reposition, true );
		window.removeEventListener( 'resize', this.reposition );
		if ( window.visualViewport ) {
			window.visualViewport.removeEventListener( 'resize', this.reposition );
			window.visualViewport.removeEventListener( 'scroll', this.reposition );
		}
	};

	/**
	 * Place the dropdown directly below its field. Uses fixed positioning and then
	 * corrects for any offset a transformed ancestor may introduce.
	 */
	LocationField.prototype.position = function () {
		if ( ! this.isOpen || ! this.box ) {
			return;
		}
		var rect = this.control.getBoundingClientRect();
		var viewportWidth = document.documentElement.clientWidth;
		var viewportHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
		var gap = 6;
		var margin = 8;

		var width = Math.min( Math.max( rect.width, 280 ), viewportWidth - margin * 2 );
		var left = Math.min( Math.max( rect.left, margin ), viewportWidth - width - margin );
		var top = rect.bottom + gap;
		var room = viewportHeight - top - margin;

		this.box.style.width = width + 'px';
		this.box.style.left = left + 'px';
		this.box.style.top = top + 'px';
		this.list.style.maxHeight = Math.max( 132, Math.min( 320, room - 34 ) ) + 'px';

		var actual = this.box.getBoundingClientRect();
		var dx = left - actual.left;
		var dy = top - actual.top;
		if ( Math.abs( dx ) > 0.5 || Math.abs( dy ) > 0.5 ) {
			this.box.style.left = ( left + dx ) + 'px';
			this.box.style.top = ( top + dy ) + 'px';
		}
	};

	LocationField.prototype.setActive = function ( index, scroll ) {
		var options = this.list ? this.list.children : [];
		if ( ! options.length ) {
			return;
		}
		if ( index < 0 ) {
			index = options.length - 1;
		} else if ( index >= options.length ) {
			index = 0;
		}
		if ( index === this.active ) {
			return;
		}
		for ( var i = 0; i < options.length; i++ ) {
			var on = i === index;
			options[ i ].classList.toggle( 'mqf-is-active', on );
			options[ i ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
		}
		this.active = index;
		this.input.setAttribute( 'aria-activedescendant', options[ index ].id );
		if ( scroll !== false && options[ index ].scrollIntoView ) {
			options[ index ].scrollIntoView( { block: 'nearest' } );
		}
	};

	LocationField.prototype.onKeydown = function ( event ) {
		var hasItems = this.items.length > 0;

		switch ( event.key ) {
			case 'ArrowDown':
			case 'ArrowUp':
				if ( ! hasItems ) {
					return;
				}
				event.preventDefault();
				if ( ! this.isOpen ) {
					this.open();
					this.setActive( event.key === 'ArrowDown' ? 0 : this.items.length - 1 );
				} else {
					this.setActive( this.active + ( event.key === 'ArrowDown' ? 1 : -1 ) );
				}
				break;

			case 'Enter':
				// With the list open, Enter picks a suggestion instead of submitting the form.
				if ( this.isOpen && hasItems ) {
					event.preventDefault();
					this.choose( this.active >= 0 ? this.active : 0 );
				}
				break;

			case 'Escape':
				if ( this.isOpen ) {
					event.preventDefault();
					event.stopPropagation();
					this.close();
				}
				break;

			case 'Tab':
				this.close();
				break;
		}
	};

	LocationField.prototype.choose = function ( index ) {
		var self = this;
		var prediction = this.items[ index ];
		if ( ! prediction ) {
			return;
		}

		var label = predictionText( prediction );
		var placeId = prediction.placeId || '';
		var types = prediction.types || [];
		var isNamedPlace = types.indexOf( 'establishment' ) !== -1 || types.indexOf( 'point_of_interest' ) !== -1;
		var name = prediction.mainText && typeof prediction.mainText.text === 'string' ? prediction.mainText.text : '';

		clearTimeout( this.timer );
		this.requestId += 1;
		this.set( { address: label, placeId: placeId, lat: '', lng: '' }, true );
		this.items = [];
		this.setError( '' );
		this.close();
		this.quote.save();
		this.quote.onLocationChosen( this );

		// Fetch the canonical address and coordinates. This also closes the billing session.
		this.pending = Promise.resolve()
			.then( function () {
				var place = prediction.toPlace();
				return place.fetchFields( { fields: [ 'formattedAddress', 'location' ] } ).then( function () {
					return place;
				} );
			} )
			.then( function ( place ) {
				self.token = null;
				if ( ! self.selected || self.value.placeId !== placeId ) {
					return; // The visitor changed the field in the meantime.
				}
				var address = place.formattedAddress || label;
				if ( isNamedPlace && name && normalize( address ).indexOf( normalize( name ) ) === -1 ) {
					address = name + ', ' + address;
				}
				var lat = '';
				var lng = '';
				if ( place.location ) {
					lat = typeof place.location.lat === 'function' ? place.location.lat() : place.location.lat;
					lng = typeof place.location.lng === 'function' ? place.location.lng() : place.location.lng;
				}
				var showing = self.input.value === self.value.address;
				self.value.address = address;
				self.value.lat = isFinite( lat ) && lat !== '' && lat !== null ? String( lat ) : '';
				self.value.lng = isFinite( lng ) && lng !== '' && lng !== null ? String( lng ) : '';
				if ( showing ) {
					self.input.value = address;
				}
				self.quote.save();
				self.quote.updateSummary();
			} )
			.catch( function ( err ) {
				// The selection itself is still valid (text + place ID); only the extra details are missing.
				self.token = null;
				reportError( 'details_failed', 'Could not fetch place details: ' + errText( err ) );
			} );
	};

	/* ---------------------------------------------------------------------
	 * One quote form (both steps)
	 * ------------------------------------------------------------------- */

	function Quote( root, index ) {
		var self = this;

		this.root = root;
		this.instanceKey = root.getAttribute( 'data-mqf-instance' ) || '';
		this.steps = {
			locations: root.querySelector( '[data-mqf-step="locations"]' ),
			details: root.querySelector( '[data-mqf-step="details"]' ),
			success: root.querySelector( '[data-mqf-step="success"]' ),
		};
		this.current = 'locations';
		this.sequence = 0;
		this.submitting = false;
		this.done = false;
		this.token = uuid();
		this.storeKey = 'mqf:' + location.pathname + ':' + index;
		this.noticeEl = root.querySelector( '[data-mqf-notice]' );
		this.formErrorEl = root.querySelector( '[data-mqf-form-error]' );
		this.submitBtn = root.querySelector( '[data-mqf-submit]' );
		this.submitLabel = root.querySelector( '[data-mqf-submit-label]' );
		this.submitText = this.submitLabel ? this.submitLabel.textContent : '';
		this.liveEl = root.querySelector( '[data-mqf-live]' );
		this.fieldWraps = Array.prototype.slice.call( root.querySelectorAll( '[data-mqf-field]' ) );
		this.touched = false;
		this.wantsNotice = false;
		this.pushedState = false;

		this.fields = {
			pickup: new LocationField( this, 'pickup' ),
			dropoff: new LocationField( this, 'dropoff' ),
		};

		// "Instant Quote" – wired to the plugin logic, not just a visual button.
		this.steps.locations.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( self.validateLocations( true ) ) {
				self.goToDetails();
			}
		} );

		this.steps.details.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			self.submit();
		} );

		root.querySelector( '[data-mqf-back]' ).addEventListener( 'click', function () {
			self.backToLocations();
		} );

		this.fieldWraps.forEach( function ( wrap ) {
			var clear = function () {
				if ( wrap.classList.contains( 'mqf-has-error' ) ) {
					self.setFieldError( wrap, '' );
				}
			};
			wrap.addEventListener( 'input', clear );
			wrap.addEventListener( 'change', clear );
			wrap.addEventListener( 'focusout', function () {
				if ( self.touched ) {
					self.validateField( wrap );
				}
			} );
		} );

		// Past dates are checked against the visitor's own "today".
		var today = localToday();
		Array.prototype.forEach.call( root.querySelectorAll( 'input[data-mqf-future]' ), function ( input ) {
			input.setAttribute( 'min', today );
		} );

		// Start loading Google as soon as the form is (nearly) on screen.
		if ( 'IntersectionObserver' in window ) {
			var observer = new IntersectionObserver(
				function ( entries ) {
					if ( entries.some( function ( entry ) { return entry.isIntersecting; } ) ) {
						observer.disconnect();
						loadPlaces().catch( function () {} );
					}
				},
				{ rootMargin: '300px' }
			);
			observer.observe( root );
		} else {
			loadPlaces().catch( function () {} );
		}

		this.restore();
		root.setAttribute( 'data-mqf-ready', '1' );
	}

	Quote.prototype.announce = function ( message ) {
		if ( this.liveEl ) {
			this.liveEl.textContent = message || '';
		}
	};

	Quote.prototype.showNotice = function () {
		var blocked = cfg.requireSuggestion && ! cfg.manualFallback;
		this.noticeEl.textContent = blocked ? ( t.googleBlocked || '' ) : ( t.googleFallback || '' );
		this.noticeEl.hidden = ! this.noticeEl.textContent;
		this.noticeEl.classList.toggle( 'mqf-notice--error', blocked );
	};

	Quote.prototype.onGoogleFailed = function () {
		var self = this;
		[ 'pickup', 'dropoff' ].forEach( function ( name ) {
			self.fields[ name ].items = [];
			self.fields[ name ].close();
		} );
		var active = document.activeElement;
		if ( active === this.fields.pickup.input || active === this.fields.dropoff.input ) {
			this.showNotice();
		} else {
			this.wantsNotice = true;
		}
	};

	Quote.prototype.onLocationFocus = function () {
		if ( gm.failed || this.wantsNotice ) {
			this.wantsNotice = false;
			if ( gm.failed ) {
				this.showNotice();
			}
		}
	};

	Quote.prototype.onLocationChosen = function ( field ) {
		this.updateSummary();
		// Guide the visitor on: after pickup comes drop-off.
		if ( field.name === 'pickup' && ! this.fields.dropoff.text() ) {
			this.fields.dropoff.input.focus();
		}
	};

	Quote.prototype.locationMessage = function ( name ) {
		var field = this.fields[ name ];
		var text = field.text();

		if ( ! text ) {
			return name === 'pickup' ? t.pickupRequired : t.dropoffRequired;
		}
		if ( field.selected || ! cfg.requireSuggestion ) {
			return '';
		}
		// Typed, not selected, and a selection is normally required.
		if ( gm.failed ) {
			return cfg.manualFallback ? '' : t.googleBlocked;
		}
		return t.selectSuggestion;
	};

	Quote.prototype.sameLocation = function () {
		var a = this.fields.pickup.data();
		var b = this.fields.dropoff.data();
		if ( a.placeId && a.placeId === b.placeId ) {
			return true;
		}
		var one = normalize( a.address );
		return one !== '' && one === normalize( b.address );
	};

	Quote.prototype.validateLocations = function ( show ) {
		var self = this;
		var firstInvalid = null;

		[ 'pickup', 'dropoff' ].forEach( function ( name ) {
			var message = self.locationMessage( name );
			if ( show ) {
				self.fields[ name ].setError( message );
			}
			if ( message && ! firstInvalid ) {
				firstInvalid = self.fields[ name ];
			}
		} );

		if ( ! firstInvalid && cfg.blockSameLocation && this.sameLocation() ) {
			firstInvalid = this.fields.dropoff;
			if ( show ) {
				firstInvalid.setError( t.sameLocation );
			}
		}

		if ( firstInvalid && show ) {
			if ( gm.failed ) {
				this.showNotice();
			}
			firstInvalid.input.focus();
		}
		return ! firstInvalid;
	};

	Quote.prototype.updateSummary = function () {
		var self = this;
		[ 'pickup', 'dropoff' ].forEach( function ( name ) {
			var el = self.root.querySelector( '[data-mqf-summary="' + name + '"]' );
			if ( el ) {
				el.textContent = self.fields[ name ].data().address;
			}
		} );
	};

	/**
	 * Swap the visible step without a page reload.
	 */
	Quote.prototype.show = function ( name, options ) {
		options = options || {};
		if ( name === this.current ) {
			return;
		}

		var self = this;
		var from = this.steps[ this.current ];
		var to = this.steps[ name ];
		var sequence = ++this.sequence;
		var instant = options.instant || prefersReducedMotion();

		this.current = name;
		this.root.setAttribute( 'data-mqf-current', name );

		function enter() {
			if ( sequence !== self.sequence ) {
				return;
			}
			Object.keys( self.steps ).forEach( function ( key ) {
				self.steps[ key ].classList.remove( 'mqf-is-leaving', 'mqf-is-entering' );
				self.steps[ key ].hidden = key !== name;
			} );

			if ( ! instant ) {
				to.classList.add( 'mqf-is-entering' );
				void to.offsetWidth; // Commit the start state so the transition runs.
				to.classList.remove( 'mqf-is-entering' );
			}

			if ( options.focus !== false ) {
				var target = null;
				if ( name === 'details' ) {
					target = self.root.querySelector( '[data-mqf-details-title]' );
				} else if ( name === 'success' ) {
					target = self.root.querySelector( '[data-mqf-success]' );
				} else {
					target = self.fields.pickup.input;
				}
				if ( target && target.focus ) {
					try {
						target.focus( { preventScroll: true } );
					} catch ( e ) {
						target.focus();
					}
				}
			}

			if ( options.scroll !== false ) {
				var rect = self.root.getBoundingClientRect();
				var height = window.innerHeight || document.documentElement.clientHeight;
				if ( rect.top < 0 || rect.top > height * 0.55 ) {
					self.root.scrollIntoView( { behavior: instant ? 'auto' : 'smooth', block: 'start' } );
				}
			}
		}

		if ( instant ) {
			enter();
		} else {
			from.classList.add( 'mqf-is-leaving' );
			setTimeout( enter, 190 );
		}
	};

	Quote.prototype.goToDetails = function ( options ) {
		options = options || {};
		this.fields.pickup.close();
		this.fields.dropoff.close();
		this.updateSummary();
		this.show( 'details', options );
		this.save();

		if ( cfg.useHistory && ! options.fromHistory && window.history && history.pushState ) {
			try {
				var state = {};
				var existing = history.state;
				if ( existing && typeof existing === 'object' ) {
					Object.keys( existing ).forEach( function ( key ) {
						state[ key ] = existing[ key ];
					} );
				}
				if ( state.mqfStep !== this.root.id ) {
					state.mqfStep = this.root.id;
					history.pushState( state, '' );
					this.pushedState = true;
				}
			} catch ( e ) {
				// History is a convenience only.
			}
		}
	};

	Quote.prototype.backToLocations = function () {
		// Only step back through history when this page view created the entry. After a
		// refresh the entry belongs to the previous page view, and site routers may reload on it.
		if ( cfg.useHistory && this.pushedState && window.history && history.state && history.state.mqfStep === this.root.id ) {
			this.pushedState = false;
			history.back(); // The popstate handler shows the bar.
			return;
		}
		this.show( 'locations' );
		this.save();
	};

	Quote.prototype.onPopState = function ( state ) {
		var mine = !! ( state && state.mqfStep === this.root.id );
		if ( this.done || this.submitting ) {
			return;
		}
		if ( this.current === 'details' && ! mine ) {
			this.pushedState = false;
			this.show( 'locations' );
			this.save();
		} else if ( this.current === 'locations' && mine && this.validateLocations( false ) ) {
			this.goToDetails( { fromHistory: true } );
		}
	};

	/* ----- step 2 validation ----- */

	Quote.prototype.setFieldError = function ( wrap, message ) {
		var errorEl = wrap.querySelector( '.mqf-error' );
		if ( errorEl ) {
			errorEl.textContent = message || '';
			errorEl.hidden = ! message;
		}
		wrap.classList.toggle( 'mqf-has-error', !! message );
		Array.prototype.forEach.call( wrap.querySelectorAll( 'input, select, textarea' ), function ( control ) {
			if ( message ) {
				control.setAttribute( 'aria-invalid', 'true' );
			} else {
				control.removeAttribute( 'aria-invalid' );
			}
		} );
	};

	Quote.prototype.validateField = function ( wrap ) {
		var type = wrap.getAttribute( 'data-mqf-type' );
		var required = wrap.getAttribute( 'data-mqf-required' ) === '1';
		var message = '';

		if ( type === 'radio' || type === 'checkbox' ) {
			if ( required && ! wrap.querySelector( 'input:checked' ) ) {
				message = type === 'checkbox' ? t.chooseOption : t.required;
			}
		} else {
			var control = wrap.querySelector( '.mqf-input' );
			var value = control ? control.value.trim() : '';
			var badInput = !! ( control && control.validity && control.validity.badInput );

			if ( ! value ) {
				if ( badInput ) {
					message = type === 'date' ? t.invalidDate : t.invalidNumber;
				} else if ( required ) {
					message = t.required;
				}
			} else if ( type === 'email' ) {
				if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( value ) ) {
					message = t.invalidEmail;
				}
			} else if ( type === 'tel' ) {
				var digits = value.replace( /\D/g, '' );
				if ( ! /^[0-9+()\-.\s]+$/.test( value ) || digits.length < 6 || digits.length > 15 ) {
					message = t.invalidPhone;
				}
			} else if ( type === 'date' ) {
				if ( ! /^\d{4}-\d{2}-\d{2}$/.test( value ) ) {
					message = t.invalidDate;
				} else if ( control.hasAttribute( 'data-mqf-future' ) && value < localToday() ) {
					message = t.pastDate;
				}
			} else if ( type === 'number' ) {
				if ( isNaN( Number( value ) ) ) {
					message = t.invalidNumber;
				}
			}
		}

		this.setFieldError( wrap, message );
		return ! message;
	};

	Quote.prototype.setFormError = function ( message ) {
		this.formErrorEl.textContent = message || '';
		this.formErrorEl.hidden = ! message;
	};

	Quote.prototype.setSubmitting = function ( on ) {
		this.submitting = on;
		this.submitBtn.disabled = on;
		this.submitBtn.classList.toggle( 'mqf-is-loading', on );
		if ( on ) {
			this.submitBtn.setAttribute( 'aria-busy', 'true' );
			this.submitLabel.textContent = t.sending || this.submitText;
		} else {
			this.submitBtn.removeAttribute( 'aria-busy' );
			this.submitLabel.textContent = this.submitText;
		}
	};

	/* ----- submission ----- */

	Quote.prototype.refreshNonce = function () {
		return request( 'mqf_refresh_nonce' ).then( function ( result ) {
			if ( result.json && result.json.success && result.json.data && result.json.data.nonce ) {
				cfg.nonce = result.json.data.nonce;
				return true;
			}
			return false;
		} );
	};

	Quote.prototype.send = function ( retried ) {
		var self = this;
		var body = new FormData( this.steps.details );

		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'instance', this.instanceKey );
		body.append( 'token', this.token );
		body.append( 'page_url', ( location.origin + location.pathname + location.search ).slice( 0, 300 ) );

		[ 'pickup', 'dropoff' ].forEach( function ( name ) {
			var data = self.fields[ name ].data();
			body.append( 'mqf_' + name + '[address]', data.address );
			body.append( 'mqf_' + name + '[place_id]', data.placeId );
			body.append( 'mqf_' + name + '[lat]', data.lat );
			body.append( 'mqf_' + name + '[lng]', data.lng );
		} );

		return request( 'mqf_submit_quote', body ).then( function ( result ) {
			var json = result.json;
			var data = ( json && json.data ) || {};

			if ( json && json.success ) {
				return { ok: true, message: data.message || '' };
			}
			// A cached page can carry an expired security token: fetch a new one and retry once.
			if ( data.code === 'invalid_nonce' && ! retried ) {
				return self.refreshNonce().then( function ( refreshed ) {
					return refreshed ? self.send( true ) : { ok: false, message: data.message || t.genericError };
				} );
			}
			return {
				ok: false,
				message: data.message || t.genericError,
				fields: data.fields || null,
			};
		} );
	};

	Quote.prototype.submit = function () {
		var self = this;

		// Duplicate-submit guard: one request at a time, one success per form.
		if ( this.submitting || this.done ) {
			return;
		}

		if ( ! this.validateLocations( false ) ) {
			this.show( 'locations' );
			this.validateLocations( true );
			return;
		}

		this.touched = true;
		var firstInvalid = null;
		this.fieldWraps.forEach( function ( wrap ) {
			if ( ! self.validateField( wrap ) && ! firstInvalid ) {
				firstInvalid = wrap;
			}
		} );
		if ( firstInvalid ) {
			var control = firstInvalid.querySelector( 'input, select, textarea' );
			if ( control ) {
				control.focus();
			}
			return;
		}

		this.setFormError( '' );
		this.setSubmitting( true );

		// Give a just-selected address a moment to resolve its coordinates, but never hang on it.
		var details = Promise.all(
			[ this.fields.pickup.pending, this.fields.dropoff.pending ].map( function ( promise ) {
				return promise || Promise.resolve();
			} )
		);
		var patience = new Promise( function ( resolve ) {
			setTimeout( resolve, 2500 );
		} );

		Promise.race( [ details, patience ] )
			.then( function () {
				return self.send( false );
			} )
			.then( function ( result ) {
				if ( result.ok ) {
					self.onSuccess( result.message );
					return;
				}
				self.setSubmitting( false );
				self.onServerErrors( result );
			} )
			.catch( function () {
				self.setSubmitting( false );
				self.setFormError( t.genericError );
			} );
	};

	Quote.prototype.onServerErrors = function ( result ) {
		var self = this;
		var errors = result.fields || {};
		var locationError = false;
		var firstWrap = null;

		[ 'pickup', 'dropoff' ].forEach( function ( name ) {
			if ( errors[ name ] ) {
				locationError = true;
			}
		} );

		if ( locationError ) {
			this.show( 'locations' );
			[ 'pickup', 'dropoff' ].forEach( function ( name ) {
				self.fields[ name ].setError( errors[ name ] || '' );
			} );
			return;
		}

		this.fieldWraps.forEach( function ( wrap ) {
			var key = wrap.getAttribute( 'data-mqf-field' );
			if ( errors[ key ] ) {
				self.setFieldError( wrap, errors[ key ] );
				firstWrap = firstWrap || wrap;
			}
		} );

		this.setFormError( result.message || t.genericError );
		if ( firstWrap ) {
			var control = firstWrap.querySelector( 'input, select, textarea' );
			if ( control ) {
				control.focus();
			}
		}
	};

	Quote.prototype.onSuccess = function ( message ) {
		this.done = true;
		this.submitting = false;
		this.clearStore();

		var text = this.root.querySelector( '[data-mqf-success-text]' );
		if ( text ) {
			text.textContent = message || '';
		}
		this.show( 'success' );

		// For analytics / tag managers: document.addEventListener('mqf:submitted', …).
		try {
			this.root.dispatchEvent( new CustomEvent( 'mqf:submitted', {
				bubbles: true,
				detail: {
					pickup: this.fields.pickup.data().address,
					dropoff: this.fields.dropoff.data().address,
				},
			} ) );
		} catch ( e ) {
			// Older browsers without CustomEvent constructor.
		}
	};

	/* ----- keep the journey across a refresh (locations only, this tab only) ----- */

	Quote.prototype.save = function () {
		if ( this.done ) {
			return;
		}
		try {
			var self = this;
			var payload = { ts: Date.now(), step: this.current === 'details' ? 'details' : 'locations' };
			[ 'pickup', 'dropoff' ].forEach( function ( name ) {
				var field = self.fields[ name ];
				payload[ name ] = {
					address: field.selected ? field.value.address : field.text(),
					placeId: field.selected ? field.value.placeId : '',
					lat: field.selected ? field.value.lat : '',
					lng: field.selected ? field.value.lng : '',
					selected: field.selected,
				};
			} );
			window.sessionStorage.setItem( this.storeKey, JSON.stringify( payload ) );
		} catch ( e ) {
			// Storage can be unavailable (private mode, blocked cookies). The form still works.
		}
	};

	Quote.prototype.clearStore = function () {
		try {
			window.sessionStorage.removeItem( this.storeKey );
		} catch ( e ) {
			// See save().
		}
	};

	Quote.prototype.restore = function () {
		var payload = null;
		try {
			payload = JSON.parse( window.sessionStorage.getItem( this.storeKey ) || 'null' );
		} catch ( e ) {
			payload = null;
		}
		if ( ! payload || ! payload.ts || Date.now() - payload.ts > STORE_TTL ) {
			this.clearStore();
			return;
		}

		var self = this;
		[ 'pickup', 'dropoff' ].forEach( function ( name ) {
			var saved = payload[ name ];
			if ( saved && typeof saved.address === 'string' && saved.address ) {
				self.fields[ name ].set(
					{
						address: saved.address.slice( 0, 300 ),
						placeId: typeof saved.placeId === 'string' ? saved.placeId : '',
						lat: typeof saved.lat === 'string' ? saved.lat : '',
						lng: typeof saved.lng === 'string' ? saved.lng : '',
					},
					!! saved.selected && !! saved.placeId
				);
			}
		} );

		if ( payload.step === 'details' && this.validateLocations( false ) ) {
			this.updateSummary();
			this.show( 'details', { instant: true, focus: false, scroll: false } );
		}
	};

	/* ---------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------- */

	function init( scope ) {
		var context = scope && scope.querySelectorAll ? scope : document;
		var roots = Array.prototype.slice.call( context.querySelectorAll( '[data-mqf]' ) );
		if ( context !== document && context.matches && context.matches( '[data-mqf]' ) ) {
			roots.unshift( context );
		}
		roots.forEach( function ( root ) {
			if ( root.mqfInstance || ! root.querySelector( '[data-mqf-step="locations"]' ) ) {
				return;
			}
			try {
				root.mqfInstance = new Quote( root, instances.length );
				instances.push( root.mqfInstance );
				if ( gm.failed ) {
					root.mqfInstance.onGoogleFailed();
				}
			} catch ( e ) {
				if ( window.console && console.error ) {
					console.error( '[Moving Quote Form] could not start', e );
				}
			}
		} );
	}

	window.addEventListener( 'popstate', function ( event ) {
		if ( ! cfg.useHistory ) {
			return;
		}
		instances.forEach( function ( instance ) {
			instance.onPopState( event.state );
		} );
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
		} );
	} else {
		init();
	}

	// Forms added after page load: Elementor editor previews and Elementor popups.
	function hookElementor() {
		var frontend = window.elementorFrontend;
		if ( ! frontend || ! frontend.hooks || hookElementor.done ) {
			return;
		}
		hookElementor.done = true;
		[ 'mqf_quote_form.default', 'shortcode.default' ].forEach( function ( widget ) {
			frontend.hooks.addAction( 'frontend/element_ready/' + widget, function ( $scope ) {
				init( $scope && $scope[ 0 ] ? $scope[ 0 ] : document );
			} );
		} );
	}
	window.addEventListener( 'elementor/frontend/init', hookElementor );
	hookElementor();

	window.addEventListener( 'elementor/popup/show', function () {
		init();
	} );
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'elementor/popup/show', function () {
			init();
		} );
	}

	// Public hook for themes that inject the form dynamically: MQFQuote.init( containerElement ).
	window.MQFQuote = { init: init };
} )();
