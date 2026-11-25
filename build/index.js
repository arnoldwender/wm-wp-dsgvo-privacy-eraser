( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	registerBlockType( 'wm/dsgvo-erasure-portal', {
		title: __( 'DSGVO Löschantrag (Art. 17)', 'wm-dsgvo-privacy-eraser' ),
		description: __( 'Öffentliches Formular für Löschanträge nach Art. 17 DSGVO mit Einmal-Bestätigungslink.', 'wm-dsgvo-privacy-eraser' ),
		category: 'widgets',
		icon: 'shield',
		attributes: {
			showDescription: {
				type: 'boolean',
				default: true,
			},
		},
		supports: {
			html: false,
			align: [ 'wide', 'full' ],
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Formulareinstellungen', 'wm-dsgvo-privacy-eraser' ),
							initialOpen: true,
						},
						el( ToggleControl, {
							label: __( 'Hinweistext anzeigen', 'wm-dsgvo-privacy-eraser' ),
							help: __( 'Blendet den erläuternden Hinweis oberhalb des E-Mail-Feldes ein oder aus.', 'wm-dsgvo-privacy-eraser' ),
							checked: !! attributes.showDescription,
							onChange: function ( value ) {
								setAttributes( { showDescription: !! value } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'wm/dsgvo-erasure-portal',
						attributes: attributes,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
