/* global jQuery, wp, iccGgSignInOpenIdConnectAdmin */
( function ( $ ) {
	'use strict';

	$( function () {
		var mediaFrame;

		$( document ).on( 'click', '.oidc-media-upload', function ( event ) {
			event.preventDefault();

			var $button = $( this );
			var target = $button.data( 'target' );
			var $input = $( '#' + target );
			var $preview = $( '#oidc-logo-preview' );
			var $remove = $( '.oidc-media-remove[data-target="' + target + '"]' );
			var l10n = window.iccGgSignInOpenIdConnectAdmin || {};

			if ( mediaFrame ) {
				mediaFrame.open();
				return;
			}

			mediaFrame = wp.media( {
				title: l10n.chooseLogoTitle || 'Choose Login Button Logo',
				button: {
					text: l10n.useThisImage || 'Use this image'
				},
				multiple: false
			} );

			mediaFrame.on( 'select', function () {
				var attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
				var thumbnail = ( attachment.sizes && attachment.sizes.thumbnail ) ? attachment.sizes.thumbnail.url : attachment.url;

				$input.val( attachment.id );
				$preview.html( '<img src="' + thumbnail + '" class="oidc-logo-preview-img" alt="" style="max-width:150px;height:auto;">' );
				$remove.show();
			} );

			mediaFrame.open();
		} );

		$( document ).on( 'click', '.oidc-media-remove', function ( event ) {
			event.preventDefault();

			var $button = $( this );
			var target = $button.data( 'target' );

			$( '#' + target ).val( '' );
			$( '#oidc-logo-preview' ).empty();
			$button.hide();
		} );
	} );
} )( jQuery );
