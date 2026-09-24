/**
 * Progressive enhancement for the Blueprint Registry screens.
 *
 * Menus, copying, and the code editor enhance server-rendered markup. Bundle
 * uploads require JavaScript to package arbitrary file types into a ZIP.
 */
( function () {
	'use strict';

	/**
	 * Dropdown menus in the DataViews toolbars.
	 */
	function initMenus() {
		var menus = Array.prototype.slice.call( document.querySelectorAll( '[data-bp-menu]' ) );

		function closeAll( except ) {
			menus.forEach( function ( menu ) {
				if ( menu === except ) {
					return;
				}
				var toggle = menu.querySelector( '[data-bp-menu-toggle]' );
				var panel = menu.querySelector( '[data-bp-menu-panel]' );
				if ( toggle && panel ) {
					toggle.setAttribute( 'aria-expanded', 'false' );
					panel.hidden = true;
				}
			} );
		}

		menus.forEach( function ( menu ) {
			var toggle = menu.querySelector( '[data-bp-menu-toggle]' );
			var panel = menu.querySelector( '[data-bp-menu-panel]' );
			if ( ! toggle || ! panel ) {
				return;
			}

			toggle.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var open = toggle.getAttribute( 'aria-expanded' ) === 'true';
				closeAll( menu );
				toggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				panel.hidden = open;
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest( '[data-bp-menu]' ) ) {
				closeAll( null );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				closeAll( null );
			}
		} );
	}

	/**
	 * Copy-to-clipboard buttons, with the label confirming the copy in place.
	 */
	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-bp-copy]' );
			if ( ! button || ! navigator.clipboard ) {
				return;
			}

			event.preventDefault();
			navigator.clipboard.writeText( button.getAttribute( 'data-bp-copy' ) ).then( function () {
				var label = button.querySelector( 'span' );
				if ( ! label ) {
					return;
				}
				var original = label.textContent;
				label.textContent = label.getAttribute( 'data-copied' ) || 'Copied';
				window.setTimeout( function () {
					label.textContent = original;
				}, 1600 );
			} );
		} );
	}

	/**
	 * A read-only field holding one value people always want in full: one click
	 * selects all of it, including the part scrolled out of sight.
	 */
	function initSelectAll() {
		document.addEventListener( 'focusin', function ( event ) {
			var field = event.target.closest( '[data-bp-select-all]' );
			if ( field ) {
				field.select();
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			var field = event.target.closest( '[data-bp-select-all]' );
			// Only reselect when the click did not start a deliberate drag.
			if ( field && field.selectionStart === field.selectionEnd ) {
				field.select();
			}
		} );
	}

	/**
	 * Guards destructive submits behind a confirmation.
	 */
	function initConfirms() {
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			var message = ( event.submitter && event.submitter.getAttribute( 'data-bp-confirm' ) ) || form.getAttribute( 'data-bp-confirm' );
			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	}

	/**
	 * Inline <details> dropdowns close on click-away and Escape, which a plain
	 * disclosure does not do on its own.
	 */
	function initPickers() {
		function closeAll( except ) {
			Array.prototype.forEach.call( document.querySelectorAll( '.bp-picker[open]' ), function ( picker ) {
				if ( picker !== except ) {
					picker.open = false;
				}
			} );
		}

		document.addEventListener( 'click', function ( event ) {
			closeAll( event.target.closest( '.bp-picker' ) );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				closeAll( null );
			}
		} );
	}

	/**
	 * Upgrades the Blueprint JSON textarea to the core code editor.
	 */
	function initEditor() {
		var textarea = document.getElementById( 'bp_blueprint_json' );
		if ( ! textarea || ! window.BlueprintRegistryEditor || ! window.wp || ! window.wp.codeEditor ) {
			return;
		}

		var editor = window.wp.codeEditor.initialize( 'bp_blueprint_json', window.BlueprintRegistryEditor );
		if ( textarea.disabled ) {
			editor.codemirror.setOption( 'readOnly', 'nocursor' );
		}

		editor.codemirror.on( 'change', function () {
			editor.codemirror.save();
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		var form = textarea.closest( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				editor.codemirror.save();
			} );
		}
	}

	/**
	 * Keeps raw bundle files out of the form submission. The browser turns the
	 * selection into one ordinary ZIP upload, which WordPress accepts using its
	 * usual ZIP rules. Entries are stored without compression here; the goal is
	 * a portable archive rather than a smaller copy of data the user already has.
	 */
	function initBundleArchive() {
		var source = document.querySelector( '[data-bp-bundle-source]' );
		var archive = document.querySelector( '[data-bp-bundle-archive]' );
		if ( ! source || ! archive || ! window.File || ! window.DataTransfer || ! window.TextEncoder ) {
			return;
		}

		var form = source.closest( 'form' );
		var pathField = form ? form.querySelector( '[name="bp_bundle_path"]' ) : null;
		var status = form ? form.querySelector( '[data-bp-bundle-status]' ) : null;
		if ( ! form ) {
			return;
		}

		var crcTable = null;

		function crc32( bytes ) {
			var crc = 0 ^ -1;
			if ( ! crcTable ) {
				crcTable = [];
				for ( var index = 0; index < 256; index++ ) {
					var value = index;
					for ( var bit = 0; bit < 8; bit++ ) {
						value = ( value & 1 ) ? ( value >>> 1 ) ^ 0xEDB88320 : value >>> 1;
					}
					crcTable[ index ] = value;
				}
			}
			for ( var position = 0; position < bytes.length; position++ ) {
				crc = ( crc >>> 8 ) ^ crcTable[ ( crc ^ bytes[ position ] ) & 0xFF ];
			}
			return ( crc ^ -1 ) >>> 0;
		}

		function dosDate( timestamp ) {
			var date = new Date( timestamp || Date.now() );
			var year = Math.max( 1980, date.getFullYear() );
			return {
				time: ( date.getSeconds() >> 1 ) | ( date.getMinutes() << 5 ) | ( date.getHours() << 11 ),
				date: date.getDate() | ( ( date.getMonth() + 1 ) << 5 ) | ( ( year - 1980 ) << 9 )
			};
		}

		function header( length ) {
			return new Uint8Array( length );
		}

		function set16( bytes, offset, value ) {
			new DataView( bytes.buffer ).setUint16( offset, value, true );
		}

		function set32( bytes, offset, value ) {
			new DataView( bytes.buffer ).setUint32( offset, value, true );
		}

		function bundlePath( file, count ) {
			var specified = pathField ? pathField.value.trim().replace( /\\/g, '/' ) : '';
			var name = file.webkitRelativePath || file.name;
			if ( ! specified ) {
				return name;
			}
			if ( count === 1 ) {
				return specified;
			}
			return specified.replace( /\/?$/, '/' ) + name.replace( /^.*\//, '' );
		}

		function validPath( path ) {
			return path && path !== 'blueprint.json' && path.indexOf( '\u0000' ) === -1 && path.split( '/' ).every( function ( part ) {
				return part && part !== '.' && part !== '..';
			} );
		}

		async function createArchive( files ) {
			var encoder = new TextEncoder();
			var chunks = [];
			var central = [];
			var offset = 0;
			var paths = {};

			for ( var index = 0; index < files.length; index++ ) {
				var file = files[ index ];
				var path = bundlePath( file, files.length ).replace( /^\/+/, '' );
				if ( ! validPath( path ) || paths[ path ] ) {
					throw new Error( 'Choose unique bundle paths that do not use blueprint.json.' );
				}
				paths[ path ] = true;

				var name = encoder.encode( path );
				var contents = new Uint8Array( await file.arrayBuffer() );
				var checksum = crc32( contents );
				var modified = dosDate( file.lastModified );
				var local = header( 30 + name.length );
				set32( local, 0, 0x04034B50 );
				set16( local, 4, 20 );
				set16( local, 6, 0x0800 );
				set16( local, 8, 0 );
				set16( local, 10, modified.time );
				set16( local, 12, modified.date );
				set32( local, 14, checksum );
				set32( local, 18, contents.length );
				set32( local, 22, contents.length );
				set16( local, 26, name.length );
				set16( local, 28, 0 );
				local.set( name, 30 );
				chunks.push( local, contents );

				var entry = header( 46 + name.length );
				set32( entry, 0, 0x02014B50 );
				set16( entry, 4, 0x0314 );
				set16( entry, 6, 20 );
				set16( entry, 8, 0x0800 );
				set16( entry, 10, 0 );
				set16( entry, 12, modified.time );
				set16( entry, 14, modified.date );
				set32( entry, 16, checksum );
				set32( entry, 20, contents.length );
				set32( entry, 24, contents.length );
				set16( entry, 28, name.length );
				set16( entry, 30, 0 );
				set16( entry, 32, 0 );
				set16( entry, 34, 0 );
				set16( entry, 36, 0 );
				set32( entry, 38, 0 );
				set32( entry, 42, offset );
				entry.set( name, 46 );
				central.push( entry );
				offset += local.length + contents.length;
			}

			var centralSize = central.reduce( function ( total, entry ) { return total + entry.length; }, 0 );
			var end = header( 22 );
			set32( end, 0, 0x06054B50 );
			set16( end, 4, 0 );
			set16( end, 6, 0 );
			set16( end, 8, files.length );
			set16( end, 10, files.length );
			set32( end, 12, centralSize );
			set32( end, 16, offset );
			set16( end, 20, 0 );
			chunks = chunks.concat( central, [ end ] );

			return new File( chunks, 'blueprint-bundle.zip', { type: 'application/zip' } );
		}

		source.addEventListener( 'change', function () {
			archive.value = '';
			delete form.dataset.bpBundleReady;
			if ( status ) {
				status.textContent = source.files.length ? source.files.length + ( source.files.length === 1 ? ' file selected. Not saved yet.' : ' files selected. Not saved yet.' ) : 'Any file type. Files are added when you review changes.';
			}
		} );

		if ( pathField ) {
			pathField.addEventListener( 'input', function () {
				archive.value = '';
				delete form.dataset.bpBundleReady;
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			if ( event.defaultPrevented || ( event.submitter && event.submitter.name === 'bp_discard_upload' ) ) {
				return;
			}
			if ( form.dataset.bpBundleReady === '1' || ! source.files.length ) {
				return;
			}

			event.preventDefault();
			if ( form.getAttribute( 'aria-busy' ) === 'true' ) { return; }
			form.setAttribute( 'aria-busy', 'true' );
			if ( status ) {
				status.textContent = 'Packing bundle ZIP…';
			}
			createArchive( Array.prototype.slice.call( source.files ) ).then( function ( zip ) {
				var transfer = new DataTransfer();
				transfer.items.add( zip );
				archive.files = transfer.files;
				form.dataset.bpBundleReady = '1';
				if ( status ) {
					status.textContent = 'Bundle ZIP is ready to upload.';
				}
				form.removeAttribute( 'aria-busy' );
				form.requestSubmit( event.submitter );
			} ).catch( function ( error ) {
				form.removeAttribute( 'aria-busy' );
				if ( status ) {
					status.textContent = error.message || 'The bundle ZIP could not be created.';
					status.tabIndex = -1;
					status.focus();
				}
			} );
		} );
	}

	/**
	 * Keep save state near the code, and protect edits when leaving the page.
	 * A cancelled submit (including asynchronous ZIP packing) is not a save.
	 */
	function initEditState() {
		var form = document.querySelector( 'form.bp-editor' );
		if ( ! form ) { return; }
		var dirty = false;
		var submitting = false;
		var state = form.querySelector( '[data-bp-save-state]' );
		var originalLabel = state ? state.textContent : '';
		var source = form.querySelector( '[name="bp_blueprint_json"]' );
		var originalSource = source.value;

		function updateState() {
			dirty = source.value !== originalSource || Array.prototype.some.call( form.querySelectorAll( 'input[type="file"]' ), function ( input ) { return input.files.length > 0; } );
			if ( state ) { state.textContent = dirty ? 'Unsaved changes' : originalLabel; }
		}
		form.addEventListener( 'input', updateState );
		form.addEventListener( 'change', updateState );
		document.addEventListener( 'submit', function ( event ) {
			if ( event.defaultPrevented ) { return; }
			if ( event.target === form ) {
				if ( submitting ) { event.preventDefault(); return; }
				submitting = true;
				if ( state ) { state.textContent = 'Saving…'; }
			}
			dirty = false;
		} );
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				submitting = false;
				updateState();
			}
		} );
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	}

	function initReviewDecision() {
		var form = document.querySelector( 'form.bp-decision' );
		if ( ! form ) { return; }
		var note = form.querySelector( '[name="note"]' );
		note.addEventListener( 'input', function () { note.setCustomValidity( '' ); } );
		form.addEventListener( 'submit', function ( event ) {
			var decision = event.submitter ? event.submitter.value : '';
			note.setCustomValidity( '' );
			if ( ( decision === 'changes_requested' || decision === 'rejected' ) && ! note.value.trim() ) {
				event.preventDefault();
				note.setCustomValidity( 'Add a message so the contributor knows what to do next.' );
				note.reportValidity();
			}
		} );
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		initMenus();
		initCopy();
		initSelectAll();
		initConfirms();
		initPickers();
		initEditor();
		initBundleArchive();
		initEditState();
		initReviewDecision();
	} );
}() );
