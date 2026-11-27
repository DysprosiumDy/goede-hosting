/**
 * Editor of the block "Goede Hosting Contactformulier" (server-rendered preview,
 * settings in the sidebar). No build step: uses the wp.* globals.
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Disabled = wp.components.Disabled;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType('goede-hosting/contact-form', {
		edit: function (props) {
			var a = props.attributes;
			var set = function (key) {
				return function (value) {
					var next = {};
					next[key] = value;
					props.setAttributes(next);
				};
			};
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Formulier', 'goede-hosting') },
						el(TextControl, {
							label: __('Knoptekst', 'goede-hosting'),
							help: __('Leeg = "Versturen".', 'goede-hosting'),
							value: a.buttonText,
							onChange: set('buttonText'),
							__nextHasNoMarginBottom: true
						}),
						el(TextareaControl, {
							label: __('Bevestiging na verzenden', 'goede-hosting'),
							help: __('Leeg = "Bedankt! Je bericht is verstuurd."', 'goede-hosting'),
							value: a.successText,
							onChange: set('successText'),
							__nextHasNoMarginBottom: true
						}),
						el(TextControl, {
							label: __('Ontvanger (optioneel)', 'goede-hosting'),
							help: __('Leeg = het adres onder Inzendingen > Instellingen of de bedrijfsgegevens. Werkt alleen op pagina’s van een beheerder.', 'goede-hosting'),
							type: 'email',
							value: a.recipient,
							onChange: set('recipient'),
							__nextHasNoMarginBottom: true
						})
					)
				),
				el(Disabled, null, el(ServerSideRender, { block: 'goede-hosting/contact-form', attributes: a }))
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
