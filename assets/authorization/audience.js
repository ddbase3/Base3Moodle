(() => {
	"use strict";

	function parseConfig(row) {
		try { return JSON.parse(row.querySelector('[data-audience-config]').value || '{}'); }
		catch (_) { return {}; }
	}

	function propertyInput(name, definition, value) {
		const label = document.createElement('label');
		label.textContent = name;
		const types = Array.isArray(definition.type) ? definition.type : [definition.type];
		let input;
		if (Array.isArray(definition.enum)) {
			input = document.createElement('select');
			for (const option of definition.enum) {
				const element = document.createElement('option');
				element.value = String(option);
				element.textContent = String(option);
				input.append(element);
			}
		} else {
			input = document.createElement('input');
			input.type = types.includes('boolean') ? 'checkbox' : types.includes('integer') || types.includes('number') ? 'number' : 'text';
			if (types.includes('array')) input.placeholder = 'Comma-separated items';
			if (types.includes('integer')) input.step = '1';
		}
		input.dataset.criterionProperty = name;
		input.dataset.criterionType = types[0] || 'string';
		if (input.type === 'checkbox') input.checked = value === true;
		else if (Array.isArray(value)) input.value = value.join(', ');
		else if (value !== undefined && value !== null) input.value = String(value);
		if (definition.description) input.title = String(definition.description);
		label.append(input);
		return label;
	}

	function criterionFields(editor, row, reset = false) {
		const kind = row.querySelector('[data-audience-kind]').value;
		const name = row.querySelector('[data-audience-value="criterion"]').value;
		const catalog = JSON.parse(editor.dataset.schemas || '{}');
		const schema = catalog[name] || {};
		const properties = schema.properties || {};
		const container = row.querySelector('[data-audience-properties]');
		const textarea = row.querySelector('[data-audience-config]');
		const jsonWrap = row.querySelector('[data-audience-json-wrap]');
		const summary = row.querySelector('[data-audience-schema]');
		container.replaceChildren();
		if (kind !== 'criterion') return;
		if (reset) textarea.value = '{}';
		const simple = schema.type === 'object' && Object.values(properties).every(prop => {
			const types = Array.isArray(prop.type) ? prop.type : [prop.type];
			return !types.includes('object') && (!types.includes('array') || !!prop.items);
		});
		jsonWrap.hidden = simple;
		if (!simple) { summary.textContent = 'Enter the criterion configuration as JSON.'; return; }
		const current = parseConfig(row);
		function sync() {
			const config = {};
			for (const input of container.querySelectorAll('[data-criterion-property]')) {
				const key = input.dataset.criterionProperty;
				const type = input.dataset.criterionType;
				if (type === 'boolean') config[key] = input.checked;
				else if (input.value.trim()) {
					if (type === 'array') {
						const itemType = (properties[key].items || {}).type;
						config[key] = input.value.split(',').map(v => v.trim()).filter(Boolean).map(v => itemType === 'integer' ? Number(v) : v);
					} else if (type === 'integer' || type === 'number') config[key] = Number(input.value);
					else config[key] = input.value.trim();
				}
			}
			textarea.value = JSON.stringify(config);
		}
		for (const [property, definition] of Object.entries(properties)) {
			const field = propertyInput(property, definition, current[property]);
			container.append(field);
			field.addEventListener('input', sync);
			field.addEventListener('change', sync);
		}
		summary.textContent = Object.keys(properties).length ? 'Fields from the installed criterion schema.' : 'This criterion requires no configuration.';
		sync();
	}

	function configureRow(editor, row, reset = false) {
		const kind = row.querySelector('[data-audience-kind]').value;
		for (const wrapper of row.querySelectorAll('[data-audience-wrap]')) {
			const type = wrapper.dataset.audienceWrap;
			wrapper.hidden = type === 'config' ? !['criterion', 'expression'].includes(kind) : type !== kind;
		}
		for (const input of row.querySelectorAll('[data-audience-value]')) {
			input.disabled = input.dataset.audienceValue !== kind;
			input.required = !input.disabled;
		}
		const config = row.querySelector('[data-audience-config]');
		config.disabled = !['criterion', 'expression'].includes(kind);
		config.required = !config.disabled;
		row.querySelector('[data-audience-json-wrap]').hidden = kind !== 'expression';
		if (kind === 'criterion') criterionFields(editor, row, reset);
		if (kind === 'expression') row.querySelector('[data-audience-schema]').textContent = 'Single nested expression using any, all or not.';
	}

	function bindEditor(editor) {
		if (editor.dataset.bound) return;
		editor.dataset.bound = 'true';
		for (const row of editor.querySelectorAll('[data-audience-list] > [data-audience-row]')) configureRow(editor, row);
		editor.addEventListener('change', event => {
			const row = event.target.closest('[data-audience-row]');
			if (!row) return;
			if (event.target.matches('[data-audience-kind]')) configureRow(editor, row, true);
			if (event.target.matches('[data-audience-value="criterion"]')) criterionFields(editor, row, true);
		});
		editor.addEventListener('click', event => {
			if (event.target.closest('[data-audience-remove]')) {
				event.target.closest('[data-audience-row]').remove();
				return;
			}
			if (!event.target.closest('[data-audience-add]')) return;
			const fragment = editor.querySelector('[data-audience-template]').content.cloneNode(true);
			const index = String(Number(editor.dataset.index || 0));
			editor.dataset.index = String(Number(index) + 1);
			for (const input of fragment.querySelectorAll('[name]')) input.name = input.name.replace('__INDEX__', index);
			const row = fragment.querySelector('[data-audience-row]');
			editor.querySelector('[data-audience-list]').append(fragment);
			configureRow(editor, row, true);
			row.querySelector('[data-audience-kind]').focus();
		});
	}

	function bindRoot(root) {
		if (root.dataset.bound) return;
		root.dataset.bound = 'true';
		root.querySelectorAll('[data-audience-editor]').forEach(bindEditor);
		for (const form of root.querySelectorAll('[data-audience-form]')) {
			form.addEventListener('submit', async event => {
				event.preventDefault();
				if (event.submitter?.dataset.audienceConfirm && !window.confirm(event.submitter.dataset.audienceConfirm)) return;
				const button = event.submitter;
				const error = root.querySelector('[data-audience-error]');
				if (button) button.disabled = true;
				error.hidden = true;
				try {
					const response = await fetch(form.action, {
						method: 'POST', body: new FormData(form), credentials: 'same-origin',
						headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html'}
					});
					if (!response.ok) throw new Error('Update rejected (HTTP ' + response.status + ').');
					const next = new DOMParser().parseFromString(await response.text(), 'text/html')
						.querySelector('[data-base3-audience-root="' + root.dataset.base3AudienceRoot + '"]');
					if (!next) throw new Error('Invalid updated administration response.');
					root.replaceWith(next);
					bindRoot(next);
					const status = next.querySelector('[data-audience-status]');
					if (status) status.focus();
				} catch (errorValue) {
					error.textContent = errorValue instanceof Error ? errorValue.message : 'Request failed.';
					error.hidden = false;
					if (button) button.disabled = false;
				}
			});
		}
	}

	document.querySelectorAll('[data-base3-audience-root]').forEach(bindRoot);
})();
