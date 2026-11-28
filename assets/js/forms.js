/**
 * Goede Hosting forms: conditional fields and steps.
 *
 * The same rules as the server (includes/contact-form/fields.php): a field with
 * conditions is shown or hidden based on earlier answers; hidden fields are
 * disabled, so they are neither required nor sent. The server checks it all
 * again, so this script is only about a pleasant form.
 */
(function () {
	'use strict';

	function valueOf(form, key) {
		var els = form.querySelectorAll('[name="' + key + '"], [name="' + key + '[]"]');
		var out = [];
		for (var i = 0; i < els.length; i++) {
			var el = els[i];
			if (el.disabled) {
				continue;
			}
			if (el.type === 'checkbox' || el.type === 'radio') {
				if (el.checked) {
					out.push(el.value);
				}
			} else if (el.type !== 'file' && el.value !== '') {
				out.push(el.value);
			}
		}
		return out;
	}

	function same(a, b) {
		return String(a).trim().toLowerCase() === String(b).trim().toLowerCase();
	}

	function matches(rule, values) {
		var target = String(rule.value || '');
		switch (rule.op) {
			case 'empty':
				return values.length === 0;
			case 'not_empty':
				return values.length > 0;
			case 'not':
				return !values.some(function (v) { return same(v, target); });
			case 'contains':
				return target !== '' && values.some(function (v) { return v.toLowerCase().indexOf(target.toLowerCase()) !== -1; });
			case 'gt':
			case 'lt':
				var a = parseFloat(String(values[0] || '').replace(',', '.'));
				var b = parseFloat(target.replace(',', '.'));
				if (isNaN(a) || isNaN(b)) {
					return false;
				}
				return rule.op === 'gt' ? a > b : a < b;
			default:
				return values.some(function (v) { return same(v, target); });
		}
	}

	function setDisabled(wrap, off) {
		var controls = wrap.querySelectorAll('input, select, textarea');
		for (var i = 0; i < controls.length; i++) {
			controls[i].disabled = off;
		}
	}

	function applyLogic(form) {
		var wraps = form.querySelectorAll('[data-gh-field]');
		for (var i = 0; i < wraps.length; i++) {
			var wrap = wraps[i];
			var raw = wrap.getAttribute('data-gh-logic');
			if (!raw) {
				continue;
			}
			var logic;
			try {
				logic = JSON.parse(raw);
			} catch (e) {
				continue;
			}
			var results = (logic.rules || []).map(function (rule) {
				return matches(rule, valueOf(form, rule.field));
			});
			var hit = logic.match === 'any' ? results.indexOf(true) !== -1 : results.indexOf(false) === -1;
			var show = logic.action === 'hide' ? !hit : hit;
			wrap.hidden = !show;
			setDisabled(wrap, !show);
		}
	}

	function checkFile(input) {
		var max = parseInt(input.getAttribute('data-max-size'), 10);
		input.setCustomValidity('');
		if (max && input.files && input.files[0] && input.files[0].size > max) {
			input.setCustomValidity('Dit bestand is te groot (maximaal ' + (max / 1048576).toFixed(1).replace('.', ',') + ' MB).');
		}
	}

	function validateWithin(root) {
		var controls = root.querySelectorAll('input, select, textarea');
		for (var i = 0; i < controls.length; i++) {
			var el = controls[i];
			if (el.disabled || el.type === 'hidden' || el.closest('[hidden]')) {
				continue;
			}
			if (el.type === 'file') {
				checkFile(el);
			}
			if (!el.checkValidity()) {
				el.reportValidity();
				el.focus();
				return false;
			}
		}
		return true;
	}

	function setupSteps(form) {
		var steps = Array.prototype.slice.call(form.querySelectorAll('[data-gh-step]'));
		if (steps.length < 2) {
			return;
		}
		var progress = form.querySelector('[data-gh-progress]');
		var submit = form.querySelector('[data-gh-submit]');
		var current = 0;

		function usable(step) {
			// A step whose fields are all hidden by conditions is skipped.
			var fields = step.querySelectorAll('[data-gh-field]');
			if (!fields.length) {
				return true;
			}
			for (var i = 0; i < fields.length; i++) {
				if (!fields[i].hidden) {
					return true;
				}
			}
			return false;
		}

		function show(index, focus) {
			current = index;
			steps.forEach(function (step, i) {
				step.hidden = i !== index;
			});
			var last = index === steps.length - 1 || !steps.slice(index + 1).some(usable);
			var nav = steps[index].querySelector('[data-gh-nav]');
			if (nav) {
				nav.hidden = false;
				var next = nav.querySelector('[data-gh-next]');
				if (next) {
					next.hidden = last;
				}
			}
			if (submit) {
				submit.hidden = !last;
			}
			if (progress) {
				var visibleSteps = steps.filter(usable);
				var pos = visibleSteps.indexOf(steps[index]) + 1;
				var label = steps[index].getAttribute('data-gh-step-label');
				progress.hidden = false;
				progress.querySelector('.gh-form__progress-text').textContent = 'Stap ' + pos + ' van ' + visibleSteps.length + (label ? ' · ' + label : '');
				progress.querySelector('.gh-form__progress-bar span').style.width = (pos / visibleSteps.length * 100) + '%';
			}
			if (focus) {
				var first = steps[index].querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
				form.scrollIntoView({ behavior: 'smooth', block: 'start' });
				if (first) {
					first.focus({ preventScroll: true });
				}
			}
		}

		form.addEventListener('click', function (event) {
			var next = event.target.closest('[data-gh-next]');
			var back = event.target.closest('[data-gh-back]');
			var i;
			if (next) {
				if (!validateWithin(steps[current])) {
					return;
				}
				for (i = current + 1; i < steps.length; i++) {
					if (usable(steps[i])) {
						show(i, true);
						return;
					}
				}
			} else if (back) {
				for (i = current - 1; i >= 0; i--) {
					if (usable(steps[i])) {
						show(i, true);
						return;
					}
				}
			}
		});

		// After a failed submission: open the step with the first error.
		var invalid = form.querySelector('[aria-invalid="true"]');
		var start = 0;
		if (invalid) {
			var stepEl = invalid.closest('[data-gh-step]');
			start = Math.max(0, steps.indexOf(stepEl));
		}
		show(start, false);
		form._ghShow = show;
	}

	function init(form) {
		form.noValidate = true;
		applyLogic(form);
		form.addEventListener('input', function () { applyLogic(form); });
		form.addEventListener('change', function (event) {
			applyLogic(form);
			if (event.target.type === 'file') {
				checkFile(event.target);
			}
		});
		setupSteps(form);
		form.addEventListener('submit', function (event) {
			applyLogic(form);
			var steps = form.querySelectorAll('[data-gh-step]');
			for (var i = 0; i < steps.length; i++) {
				var hiddenStep = steps[i].hidden;
				steps[i].hidden = false;
				var ok = validateWithin(steps[i]);
				steps[i].hidden = hiddenStep;
				if (!ok) {
					event.preventDefault();
					if (form._ghShow) {
						form._ghShow(i, false);
						validateWithin(steps[i]);
					}
					return;
				}
			}
		});
	}

	function start() {
		var forms = document.querySelectorAll('[data-gh-form]');
		for (var i = 0; i < forms.length; i++) {
			init(forms[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
