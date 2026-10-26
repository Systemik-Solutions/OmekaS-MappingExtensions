/** Independent filter state for one map block. */
class MappingFilters {
    constructor(element, config, onChange) {
        if (element.mappingFilters) element.mappingFilters.destroy();
        element.mappingFilters = this;
        this.element = element;
        this.onChange = onChange;
        this.destroyed = false;
        this.pending = false;
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
            const heading = document.createElement('button');
            heading.type = 'button';
            heading.className = 'mapping-template-label mapping-filter-section-toggle';
            heading.id = body.id + '-label-' + index;
            heading.textContent = label;
            heading.setAttribute('aria-expanded', 'true');
            heading.setAttribute('aria-controls', body.id + '-values-' + index);
            section.appendChild(heading);
            const state = {key, type: row.type, options: new Map(), buttons: new Map(), excluded: new Set(), selected: new Set(), section};
            const buttons = document.createElement('div');
            buttons.id = body.id + '-values-' + index;
            buttons.className = 'mapping-template-buttons' + (row.type === 'property' ? ' mapping-property-tags' : '');
            buttons.setAttribute('role', 'group');
            buttons.setAttribute('aria-labelledby', heading.id);
            section.appendChild(buttons);
            state.buttonContainer = buttons;
            state.emptyMessage = document.createElement('span');
            state.emptyMessage.className = 'mapping-filter-no-values';
            state.emptyMessage.textContent = element.dataset.noValues;
            buttons.appendChild(state.emptyMessage);
            body.appendChild(section);
            this.filters.push(state);
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
            } else if (button.classList.contains('mapping-filter-section-toggle')) {
                const expanded = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', String(expanded));
                button.nextElementSibling.hidden = !expanded;
            } else if (button.dataset.filterValue !== undefined) {
                const section = button.closest('.mapping-filter-section');
                const state = this.filters.find(filter => filter.section === section);
                const value = button.dataset.filterValue;
                const selection = state.type === 'property' ? state.selected : state.excluded;
                if (selection.has(value)) selection.delete(value);
                else selection.add(value);
                button.setAttribute('aria-pressed', String(this.isSelected(state, value)));
                onChange();
            }
        };
        element.addEventListener('click', this.click);
    }

    isSelected(state, value) {
        return state.type === 'property' ? state.selected.has(value) : !state.excluded.has(value);
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

    matchesTimelineEvent(event) {
        if (!event.mapping_group_property) return true;
        // A multi-valued item may still match the map filter while this
        // particular category row does not belong to the selected values.
        const key = 'property:' + event.mapping_group_property;
        return this.filters.every(state => state.key !== key || !state.selected.size
            || state.selected.has(event.mapping_group_value));
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
            added.forEach(option => {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.filterValue = option.value;
                button.setAttribute('aria-pressed', String(this.isSelected(state, option.value)));
                button.textContent = option.label;
                MappingFilters.setButtonColor(button, option.color || (state.type === 'property' ? '#365770' : null));
                const next = Array.from(state.buttonContainer.querySelectorAll('button')).find(other => other.textContent.localeCompare(option.label) > 0);
                state.buttonContainer.insertBefore(button, next || null);
                state.buttons.set(option.value, button);
            });
            state.emptyMessage.hidden = state.options.size > 0;
        });
        this.element.hidden = !this.filters.length;
    }

    reset() {
        this.filters.forEach(state => {
            state.excluded.clear();
            state.selected.clear();
            state.buttons.forEach((button, value) => button.setAttribute('aria-pressed', String(this.isSelected(state, value))));
        });
        this.onChange();
    }

    destroy() {
        this.destroyed = true;
        this.element.removeEventListener('click', this.click);
        delete this.element.mappingFilters;
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
