/**
 * Moving Quote Form – block editor script (no build step required).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var Disabled = wp.components.Disabled;
	var ServerSideRender = wp.serverSideRender;

	function text( props, name, label, help, type ) {
		return el( TextControl, {
			label: label,
			help: help,
			type: type || 'text',
			value: props.attributes[ name ] || '',
			onChange: function ( value ) {
				var next = {};
				next[ name ] = value;
				props.setAttributes( next );
			},
		} );
	}

	wp.blocks.registerBlockType( 'mqf/quote-form', {
		edit: function ( props ) {
			var leaveEmpty = __( 'Leave empty to use the plugin setting.', 'moving-quote-form' );
			var hex = __( 'Hex colour such as #06183a. Leave empty to use the plugin setting.', 'moving-quote-form' );

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Text', 'moving-quote-form' ), initialOpen: true },
						text( props, 'pickupPlaceholder', __( 'Pickup placeholder', 'moving-quote-form' ), leaveEmpty ),
						text( props, 'dropoffPlaceholder', __( 'Drop-off placeholder', 'moving-quote-form' ), leaveEmpty ),
						text( props, 'buttonText', __( 'Button text', 'moving-quote-form' ), leaveEmpty )
					),
					el(
						PanelBody,
						{ title: __( 'Notification', 'moving-quote-form' ), initialOpen: false },
						text(
							props,
							'notificationEmail',
							__( 'Notification email', 'moving-quote-form' ),
							__( 'Requests from this form go to this address instead of the one in the plugin settings.', 'moving-quote-form' ),
							'email'
						)
					),
					el(
						PanelBody,
						{ title: __( 'Colours', 'moving-quote-form' ), initialOpen: false },
						text( props, 'barColor', __( 'Bar background', 'moving-quote-form' ), hex ),
						text( props, 'buttonColor', __( 'Button background', 'moving-quote-form' ), hex ),
						text( props, 'buttonTextColor', __( 'Button text', 'moving-quote-form' ), hex ),
						text( props, 'accentColor', __( 'Accent', 'moving-quote-form' ), hex )
					)
				),
				el(
					Disabled,
					null,
					el( ServerSideRender, { block: 'mqf/quote-form', attributes: props.attributes } )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
