( function () {
	'use strict';

	function syncMaster( master, boxes ) {
		if ( ! master ) {
			return;
		}
		const checked = boxes.filter( function ( box ) {
			return box.checked;
		} ).length;
		master.checked = boxes.length > 0 && checked === boxes.length;
		master.indeterminate = checked > 0 && checked < boxes.length;
		master.disabled = boxes.length === 0;
	}

	const typeMaster = document.getElementById( 'calico-jmcb-select-all-types' );
	const typeBoxes = Array.from( document.querySelectorAll( '.calico-jmcb-type' ) );

	if ( typeMaster ) {
		typeMaster.addEventListener( 'change', function () {
			typeBoxes.forEach( function ( box ) {
				box.checked = typeMaster.checked;
			} );
			syncMaster( typeMaster, typeBoxes );
		} );
		typeBoxes.forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				syncMaster( typeMaster, typeBoxes );
			} );
		} );
		syncMaster( typeMaster, typeBoxes );
	}

	document.querySelectorAll( '.calico-jmcb-type-card' ).forEach( function ( card ) {
		const fieldMaster = card.querySelector( '.calico-jmcb-select-all-fields' );
		const fieldBoxes = Array.from( card.querySelectorAll( '.calico-jmcb-field' ) );
		const typeBox = card.querySelector( '.calico-jmcb-type' );

		if ( ! fieldMaster ) {
			return;
		}
		fieldMaster.addEventListener( 'change', function () {
			fieldBoxes.forEach( function ( box ) {
				box.checked = fieldMaster.checked;
			} );
			if ( fieldMaster.checked && typeBox ) {
				typeBox.checked = true;
				syncMaster( typeMaster, typeBoxes );
			}
			syncMaster( fieldMaster, fieldBoxes );
		} );
		fieldBoxes.forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				syncMaster( fieldMaster, fieldBoxes );
			} );
		} );
		syncMaster( fieldMaster, fieldBoxes );
	} );
}() );
