/**
 * Goede Hosting forms: the form builder in the block editor (plain JS, no build step).
 *
 *  - goede-hosting/veld       one field: preview in the canvas, settings in the sidebar
 *  - goede-hosting/stap       "Volgende stap": splits the form into steps
 *  - goede-hosting/formulier  places a form on a page
 *  - document panel "Formulier" (only for gh_formulier posts): button, confirmation, recipient
 */
(function (wp) {
	'use strict';
	if (!wp || !wp.blocks) {
		return;
	}
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var be = wp.blockEditor;
	var c = wp.components;
	var useSelect = wp.data.useSelect;
	var cfg = window.goedeHostingForms || { types: {}, operators: {}, extensions: [], maxUpload: 5, canMail: false, postType: 'gh_formulier', newUrl: '' };
	var CHOICES = ['select', 'radio', 'checkboxes'];

	function slug(text) {
		return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
			.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
	}

	function keyOf(attrs) {
		return slug(attrs.key) || slug(attrs.label) || 'veld';
	}

	function options(obj) {
		return Object.keys(obj).map(function (k) { return { value: k, label: obj[k] }; });
	}

	// ------------------------------------------------------------------ preview
	function preview(a) {
		var type = a.type || 'text';
		var label = a.label || '(zonder label)';
		var list = String(a.options || '').split(/\r?\n/).filter(function (o) { return o.trim() !== ''; });
		var control;
		if (type === 'hidden') {
			return el('div', { className: 'gh-builder-field' },
				el('span', { className: 'gh-builder-field__label' }, label),
				el('span', { className: 'gh-builder-field__meta' }, 'Verborgen veld' + (a.default ? ' · waarde: ' + a.default : '')));
		}
		if (type === 'radio' || type === 'checkboxes') {
			control = el('div', null, (list.length ? list : ['Optie 1', 'Optie 2']).map(function (o, i) {
				return el('div', { key: i }, (type === 'radio' ? '○ ' : '☐ ') + o);
			}));
		} else if (type === 'consent') {
			control = el('div', null, '☐ ' + (a.placeholder || label));
		} else if (type === 'textarea') {
			control = el('div', { className: 'gh-builder-field__control', style: { minHeight: '70px' } }, a.placeholder || '');
		} else if (type === 'file') {
			control = el('div', { className: 'gh-builder-field__control' }, 'Bestand kiezen… (' + (a.accept || 'pdf, jpg, png, docx') + ', max ' + (a.maxSize || 5) + ' MB)');
		} else if (type === 'select') {
			control = el('div', { className: 'gh-builder-field__control' }, (a.placeholder || 'Maak een keuze') + ' ▾');
		} else {
			control = el('div', { className: 'gh-builder-field__control' }, a.placeholder || '');
		}
		var meta = [cfg.types[type] || type, 'naam: ' + keyOf(a)];
		if (a.logic && a.logic.rules && a.logic.rules.length) {
			meta.push('met voorwaarden');
		}
		return el('div', { className: 'gh-builder-field' + (a.width === 'half' ? ' gh-builder-field--half' : '') },
			type !== 'consent' ? el('span', { className: 'gh-builder-field__label' }, label + (a.required ? ' *' : '')) : null,
			control,
			a.help ? el('span', { className: 'gh-builder-field__meta' }, a.help) : null,
			el('span', { className: 'gh-builder-field__meta' }, meta.join(' · ')));
	}

	// ------------------------------------------------------------------ conditions
	function LogicPanel(props) {
		var a = props.attributes;
		var set = props.setAttributes;
		var logic = a.logic && a.logic.rules ? a.logic : null;
		var self = keyOf(a);
		var others = useSelect(function (select) {
			return select('core/block-editor').getBlocks().filter(function (b) {
				return b.name === 'goede-hosting/veld' && b.clientId !== props.clientId;
			}).map(function (b) {
				return { value: keyOf(b.attributes), label: (b.attributes.label || keyOf(b.attributes)) };
			}).filter(function (o) { return o.value !== self; });
		}, [props.clientId, self]);

		function update(changes) {
			set({ logic: Object.assign({ action: 'show', match: 'all', rules: [] }, logic || {}, changes) });
		}
		function setRule(i, changes) {
			var rules = logic.rules.slice();
			rules[i] = Object.assign({}, rules[i], changes);
			update({ rules: rules });
		}

		var body = [
			el(c.ToggleControl, {
				key: 'on',
				label: 'Alleen tonen onder voorwaarden',
				checked: !!logic,
				disabled: !others.length,
				help: others.length ? null : 'Voeg eerst andere velden toe.',
				onChange: function (on) {
					set({ logic: on ? { action: 'show', match: 'all', rules: [{ field: others[0].value, op: 'is', value: '' }] } : {} });
				}
			})
		];
		if (logic) {
			body.push(el(c.SelectControl, {
				key: 'action', label: 'Dit veld', value: logic.action,
				options: [{ value: 'show', label: 'tonen als' }, { value: 'hide', label: 'verbergen als' }],
				onChange: function (v) { update({ action: v }); }
			}));
			body.push(el(c.SelectControl, {
				key: 'match', label: 'Van de regels hieronder', value: logic.match,
				options: [{ value: 'all', label: 'alle kloppen' }, { value: 'any', label: 'er minstens één klopt' }],
				onChange: function (v) { update({ match: v }); }
			}));
			logic.rules.forEach(function (rule, i) {
				var noValue = rule.op === 'empty' || rule.op === 'not_empty';
				body.push(el('div', { key: 'rule' + i, style: { borderTop: '1px solid #ddd', paddingTop: '8px', marginTop: '8px' } },
					el(c.SelectControl, { label: 'Veld', value: rule.field, options: others, onChange: function (v) { setRule(i, { field: v }); } }),
					el(c.SelectControl, { label: 'Voorwaarde', value: rule.op, options: options(cfg.operators), onChange: function (v) { setRule(i, { op: v }); } }),
					noValue ? null : el(c.TextControl, { label: 'Waarde', value: rule.value || '', onChange: function (v) { setRule(i, { value: v }); } }),
					el(c.Button, {
						variant: 'link', isDestructive: true,
						onClick: function () {
							var rules = logic.rules.filter(function (r, j) { return j !== i; });
							set({ logic: rules.length ? Object.assign({}, logic, { rules: rules }) : {} });
						}
					}, 'Regel verwijderen')));
			});
			body.push(el(c.Button, {
				key: 'add', variant: 'secondary', style: { marginTop: '10px' },
				onClick: function () { update({ rules: logic.rules.concat([{ field: others[0] ? others[0].value : '', op: 'is', value: '' }]) }); }
			}, 'Regel toevoegen'));
		}
		return el(c.PanelBody, { title: 'Voorwaarden', initialOpen: !!logic }, body);
	}

	// ------------------------------------------------------------------ field block
	wp.blocks.registerBlockType('goede-hosting/veld', {
		apiVersion: 3,
		title: 'Formulierveld',
		category: 'widgets',
		icon: 'feedback',
		edit: function (props) {
			var a = props.attributes;
			var set = props.setAttributes;
			var type = a.type || 'text';
			var blockProps = be.useBlockProps();
			var fields = [
				el(c.SelectControl, { key: 'type', label: 'Soort veld', value: type, options: options(cfg.types), onChange: function (v) { set({ type: v }); } }),
				el(c.TextControl, {
					key: 'label', label: 'Label', value: a.label,
					onChange: function (v) {
						var changes = { label: v };
						// The technical name follows the label until someone sets it by hand.
						if (!a.key || a.key === slug(a.label)) {
							changes.key = slug(v);
						}
						set(changes);
					}
				}),
				el(c.TextControl, {
					key: 'key', label: 'Technische naam', value: a.key,
					help: 'Gebruikt in voorwaarden en inzendingen. Kleine letters, cijfers en streepjes.',
					onChange: function (v) { set({ key: slug(v) }); }
				})
			];
			if (type !== 'hidden') {
				fields.push(el(c.ToggleControl, { key: 'req', label: 'Verplicht', checked: !!a.required, onChange: function (v) { set({ required: v }); } }));
			}
			if (CHOICES.indexOf(type) !== -1) {
				fields.push(el(c.TextareaControl, { key: 'opt', label: 'Opties (één per regel)', value: a.options, rows: 5, onChange: function (v) { set({ options: v }); } }));
			}
			if (['text', 'textarea', 'email', 'tel', 'url', 'number', 'select', 'consent'].indexOf(type) !== -1) {
				fields.push(el(c.TextControl, {
					key: 'ph', label: type === 'consent' ? 'Tekst naast het vinkje' : (type === 'select' ? 'Eerste regel van de lijst' : 'Voorbeeldtekst in het veld'),
					value: a.placeholder, onChange: function (v) { set({ placeholder: v }); }
				}));
			}
			if (type !== 'file' && type !== 'consent') {
				fields.push(el(c.TextControl, {
					key: 'def', label: type === 'hidden' ? 'Waarde' : 'Standaardwaarde', value: a.default,
					help: type === 'checkboxes' ? 'Opties gescheiden door komma\'s.' : null,
					onChange: function (v) { set({ default: v }); }
				}));
			}
			if (type === 'number' || type === 'date') {
				var hint = type === 'date' ? 'JJJJ-MM-DD' : null;
				fields.push(el(c.TextControl, { key: 'min', label: 'Minimum', value: a.min, help: hint, onChange: function (v) { set({ min: v }); } }));
				fields.push(el(c.TextControl, { key: 'max', label: 'Maximum', value: a.max, help: hint, onChange: function (v) { set({ max: v }); } }));
			}
			if (type === 'file') {
				fields.push(el(c.TextControl, {
					key: 'acc', label: 'Toegestane bestandstypes', value: a.accept, placeholder: 'pdf, jpg, png, docx',
					help: 'Kies uit: ' + cfg.extensions.join(', ') + '.',
					onChange: function (v) { set({ accept: v }); }
				}));
				fields.push(el(c.RangeControl, {
					key: 'size', label: 'Maximale grootte (MB)', value: a.maxSize, min: 1, max: Math.max(1, Math.floor(cfg.maxUpload)),
					help: 'De server staat maximaal ' + cfg.maxUpload + ' MB toe.',
					onChange: function (v) { set({ maxSize: v }); }
				}));
			}
			if (type !== 'hidden') {
				fields.push(el(c.TextareaControl, { key: 'help', label: 'Uitleg onder het veld', value: a.help, rows: 2, onChange: function (v) { set({ help: v }); } }));
				fields.push(el(c.SelectControl, {
					key: 'w', label: 'Breedte', value: a.width,
					options: [{ value: 'full', label: 'Volle breedte' }, { value: 'half', label: 'Halve breedte (naast elkaar)' }],
					onChange: function (v) { set({ width: v }); }
				}));
				fields.push(el(c.ToggleControl, {
					key: 'sum', label: 'Tonen in de titel van de inzending', checked: !!a.summary,
					onChange: function (v) { set({ summary: v }); }
				}));
			}
			return el(Fragment, null,
				el(be.InspectorControls, null,
					el(c.PanelBody, { title: 'Veld', initialOpen: true }, fields),
					el(LogicPanel, props)),
				el('div', blockProps, preview(a)));
		},
		save: function () { return null; }
	});

	// ------------------------------------------------------------------ step block
	wp.blocks.registerBlockType('goede-hosting/stap', {
		apiVersion: 3,
		title: 'Volgende stap',
		category: 'widgets',
		icon: 'controls-forward',
		edit: function (props) {
			var blockProps = be.useBlockProps();
			return el(Fragment, null,
				el(be.InspectorControls, null,
					el(c.PanelBody, { title: 'Stap' },
						el(c.TextControl, {
							label: 'Titel van de volgende stap', value: props.attributes.label,
							onChange: function (v) { props.setAttributes({ label: v }); }
						}))),
				el('div', blockProps, el('div', { className: 'gh-builder-step' }, 'Volgende stap' + (props.attributes.label ? ': ' + props.attributes.label : ''))));
		},
		save: function () { return null; }
	});

	// ------------------------------------------------------------------ embed block
	wp.blocks.registerBlockType('goede-hosting/formulier', {
		apiVersion: 3,
		title: 'Goede Hosting Formulier',
		category: 'widgets',
		icon: 'forms',
		edit: function (props) {
			var blockProps = be.useBlockProps();
			var forms = useSelect(function (select) {
				return select('core').getEntityRecords('postType', cfg.postType, { per_page: 100, status: 'publish', _fields: 'id,title' });
			}, []);
			var list = [{ value: 0, label: forms ? 'Kies een formulier' : 'Laden…' }].concat((forms || []).map(function (f) {
				return { value: f.id, label: (f.title && f.title.rendered) || ('#' + f.id) };
			}));
			return el(Fragment, null,
				el(be.InspectorControls, null,
					el(c.PanelBody, { title: 'Formulier' },
						el(c.SelectControl, {
							label: 'Formulier', value: props.attributes.formId, options: list,
							onChange: function (v) { props.setAttributes({ formId: parseInt(v, 10) || 0 }); }
						}),
						el(c.ExternalLink, { href: cfg.newUrl }, 'Nieuw formulier maken'))),
				el('div', blockProps, props.attributes.formId
					? el(wp.serverSideRender, { block: 'goede-hosting/formulier', attributes: props.attributes })
					: el(c.Placeholder, { icon: 'forms', label: 'Goede Hosting Formulier', instructions: 'Kies een formulier uit Goede Hosting > Formulieren.' },
						el(c.SelectControl, {
							value: props.attributes.formId, options: list,
							onChange: function (v) { props.setAttributes({ formId: parseInt(v, 10) || 0 }); }
						}))));
		},
		save: function () { return null; }
	});

	// ------------------------------------------------------------------ form settings panel
	var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	if (Panel && wp.plugins) {
		wp.plugins.registerPlugin('goede-hosting-form-settings', {
			render: function () {
				var postType = useSelect(function (select) { return select('core/editor').getCurrentPostType(); }, []);
				var entity = wp.coreData.useEntityProp('postType', cfg.postType, 'meta');
				if (postType !== cfg.postType) {
					return null;
				}
				var meta = entity[0] || {};
				var setMeta = entity[1];
				function field(key, value) {
					var changes = {};
					changes[key] = value;
					setMeta(Object.assign({}, meta, changes));
				}
				return el(Panel, { name: 'goede-hosting-form', title: 'Formulier' },
					el(c.TextControl, { label: 'Tekst op de verzendknop', value: meta.gh_form_button || '', placeholder: 'Versturen', onChange: function (v) { field('gh_form_button', v); } }),
					el(c.TextareaControl, { label: 'Bevestiging na het versturen', value: meta.gh_form_success || '', placeholder: 'Bedankt! Je bericht is verstuurd.', onChange: function (v) { field('gh_form_success', v); } }),
					cfg.canMail
						? el(c.TextControl, { label: 'Ontvanger (e-mail)', type: 'email', value: meta.gh_form_recipient || '', help: 'Leeg: het adres uit Inzendingen > Instellingen of de bedrijfsgegevens.', onChange: function (v) { field('gh_form_recipient', v); } })
						: null);
			}
		});
	}
})(window.wp);
