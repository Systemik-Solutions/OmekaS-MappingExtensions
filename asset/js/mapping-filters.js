/** Independent filter state for one map block. */
class MappingFilters {
    constructor(element, config, onChange) {
        if (element.mappingFilters) element.mappingFilters.destroy();
        element.mappingFilters = this;
        this.element = element;
        this.onChange = onChange;
        this.destroyed = false;
        this.pending = false;
        this.resetting = false;
        this.filters = [];
        const body = element.querySelector('.mapping-template-body');
        body.replaceChildren();
        const rows = [false, 0, '0'].includes(config.enabled) ? [] : (config.rows || []);
        rows.forEach((row, index) => {
            const key = row.type === 'property' ? 'property:' + row.property : row.type;
            const label = row.label || (row.type === 'property' ? row.property
                : element.dataset[row.type === 'resource_class' ? 'classLabel' : 'templateLabel']);
            const section = document.createElement('div');
            section.className = 'mapping-filter-section';
            section.dataset.filterKey = key;
            const heading = document.createElement('label');
            heading.className = 'mapping-template-label';
            heading.id = body.id + '-label-' + index;
            heading.textContent = label;
            section.appendChild(heading);
            const state = {key, type: row.type, options: new Map(), buttons: new Map(), excluded: new Set(), selected: new Set(), section};
            if (row.type === 'property') {
                const select = document.createElement('select');
                select.multiple = true;
                select.id = body.id + '-select-' + index;
                select.className = 'mapping-property-filter';
                select.setAttribute('aria-labelledby', heading.id);
                select.dataset.placeholder = element.dataset.allValues;
                heading.htmlFor = select.id;
                section.appendChild(select);
                state.select = select;
                select.addEventListener('change', () => {
                    if (this.resetting) return;
                    state.selected = new Set(Array.from(select.selectedOptions, option => option.value));
                    this.updatePlaceholder(state);
                    onChange();
                });
            } else {
                const buttons = document.createElement('div');
                buttons.className = 'mapping-template-buttons';
                buttons.setAttribute('role', 'group');
                buttons.setAttribute('aria-labelledby', heading.id);
                section.appendChild(buttons);
                state.buttonContainer = buttons;
            }
            body.appendChild(section);
            this.filters.push(state);
            if (state.select && typeof Choices !== 'undefined') {
                state.choices = new Choices(state.select, {
                    allowHTML: false,
                    allowHtmlUserInput: false,
                    removeItemButton: true,
                    shouldSort: true,
                    searchResultLimit: -1,
                    placeholderValue: element.dataset.allValues,
                    searchPlaceholderValue: element.dataset.searchValues,
                    noChoicesText: element.dataset.noValues,
                    noResultsText: element.dataset.noResults,
                    itemSelectText: '',
                    position: 'bottom',
                    labelId: heading.id,
                });
            }
        });
        this.click = event => {
            const button = event.target.closest('button');
            if (!button) return;
            if (button.classList.contains('mapping-template-reset')) {
                this.reset();
            } else if (button.classList.contains('mapping-template-toggle')) {
                const expanded = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', String(expanded));
                button.textContent = expanded ? button.dataset.hideLabel : button.dataset.showLabel;
                body.hidden = !expanded;
                element.classList.toggle('is-collapsed', !expanded);
            } else if (button.dataset.filterValue !== undefined) {
                const section = button.closest('.mapping-filter-section');
                const state = this.filters.find(filter => filter.section === section);
                const value = button.dataset.filterValue;
                if (state.excluded.has(value)) state.excluded.delete(value);
                else state.excluded.add(value);
                button.setAttribute('aria-pressed', String(!state.excluded.has(value)));
                onChange();
            }
        };
        element.addEventListener('click', this.click);
    }

    values(layer, state) {
        const values = layer.mapping_filter_values && layer.mapping_filter_values[state.key];
        if (Array.isArray(values)) return values;
        if (state.type === 'resource_template') {
            const template = layer.mapping_resource_template || {id: 0};
            return [{value: String(template.id), label: template.label, color: template.color}];
        }
        return [];
    }

    matches(layer) {
        return this.filters.every(state => {
            if (state.type === 'property' && !state.selected.size) return true;
            return this.values(layer, state).some(option => state.type === 'property'
                ? state.selected.has(String(option.value)) : !state.excluded.has(String(option.value)));
        });
    }

    register(layer) {
        this.filters.forEach(state => {
            this.values(layer, state).forEach(option => {
                const value = String(option.value);
                if (state.options.has(value)) return;
                state.options.set(value, {...option, value, label: option.label
                    || this.element.dataset[state.type === 'resource_class' ? 'noClass' : 'noTemplate']});
            });
        });
        // Batch updates for each response page, keeping selections when more data arrives.
        if (!this.pending) {
            this.pending = true;
            queueMicrotask(() => {
                this.pending = false;
                if (!this.destroyed) this.render();
            });
        }
    }

    render() {
        this.filters.forEach(state => {
            const added = Array.from(state.options.values()).filter(option => !option.rendered);
            added.forEach(option => { option.rendered = true; });
            if (state.select) {
                const choices = added.map(({value, label}) => ({value, label}));
                if (state.choices && choices.length) state.choices.setChoices(choices, 'value', 'label', false);
                else if (!state.choices) choices.forEach(option => state.select.add(new Option(option.label, option.value)));
            } else {
                added.forEach(option => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.filterValue = option.value;
                    button.setAttribute('aria-pressed', String(!state.excluded.has(option.value)));
                    button.textContent = option.label;
                    MappingFilters.setButtonColor(button, option.color);
                    const next = Array.from(state.buttonContainer.children).find(other => other.textContent.localeCompare(option.label) > 0);
                    state.buttonContainer.insertBefore(button, next || null);
                    state.buttons.set(option.value, button);
                });
            }
        });
        this.element.hidden = !this.filters.length;
    }

    reset() {
        this.resetting = true;
        this.filters.forEach(state => {
            state.excluded.clear();
            state.selected.clear();
            state.buttons.forEach(button => button.setAttribute('aria-pressed', 'true'));
            if (state.choices) state.choices.removeActiveItems();
            else if (state.select) Array.from(state.select.options).forEach(option => { option.selected = false; });
            this.updatePlaceholder(state);
        });
        this.resetting = false;
        this.onChange();
    }

    destroy() {
        this.destroyed = true;
        this.filters.forEach(state => { if (state.choices) state.choices.destroy(); });
        this.element.removeEventListener('click', this.click);
        delete this.element.mappingFilters;
    }

    updatePlaceholder(state) {
        const input = state.section.querySelector('.choices__input--cloned');
        if (input) input.placeholder = state.selected.size ? this.element.dataset.searchValues : this.element.dataset.allValues;
    }

    static setButtonColor(button, color) {
        if (!/^#(?:[a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/i.test(color || '')) color = '#6699ff';
        let hex = color.slice(1);
        if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
        const alpha = hex.length === 8 ? parseInt(hex.slice(6, 8), 16) / 255 : 1;
        const channels = [0, 2, 4].map(offset => {
            const value = (parseInt(hex.slice(offset, offset + 2), 16) * alpha + 255 * (1 - alpha)) / 255;
            return value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
        });
        const luminance = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        button.style.setProperty('--mapping-category-color', color);
        button.style.setProperty('--mapping-category-text', luminance > 0.179 ? '#000' : '#fff');
    }
}
