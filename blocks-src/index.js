/**
 * Gutenberg block editor source for wm/dsgvo-erasure-portal.
 *
 * Uses WordPress core globals (wp-blocks, wp-block-editor, wp-components,
 * wp-element, wp-i18n, wp-server-side-render) — zero external CDN dependencies.
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

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
		edit: function EditErasurePortalBlock( props ) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps();

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
							checked: Boolean( attributes.showDescription ),
							onChange: function ( value ) {
								setAttributes( { showDescription: Boolean( value ) } );
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
