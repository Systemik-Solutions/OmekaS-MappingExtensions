(function($) {
    let propertyErrorIndex = 0;

    function clearPropertyError(select) {
        const field = select.closest('.field');
        field.find('.mapping-filter-property-error').remove();
        select.add(field.find('.chosen-single, .chosen-search-input'))
            .removeAttr('aria-invalid aria-describedby');
    }

    function reportMissingProperty(select) {
        const scope = select.closest('.mapping-filters-settings');
        const field = select.closest('.field');
        clearPropertyError(select);
        const id = 'mapping-filter-property-error-' + (++propertyErrorIndex);
        $('<p>', {id, class: 'mapping-filter-property-error', role: 'alert', text: scope.attr('data-required-property')})
            .appendTo(field.find('.inputs'));
        select.add(field.find('.chosen-single, .chosen-search-input'))
            .attr({'aria-invalid': 'true', 'aria-describedby': id});

        // Reveal the editor controls before focusing the visible dropdown.
        const section = select.closest('.section');
        if (section.length && !section.hasClass('active') && window.Omeka) Omeka.switchActiveSection(section);
        select.parents('.block').each(function() {
            $(this).children('.block-header').find('.toggle-block-visibility.expand').trigger('click');
        });
        select.parents('.collapsible').each(function() { $(this).prev('a.expand').trigger('click'); });
        field[0].scrollIntoView({block: 'center'});
        if (select.prop('disabled')) {
            select.closest('.mapping-filter-row').find('.mapping-filter-type').trigger('focus');
        } else if (select.data('chosen')) {
            select.trigger('chosen:close').trigger('chosen:open');
        } else {
            select.trigger('focus');
        }
    }

    // Chosen hides the select, so native required validation cannot focus it.
    // Validate before Omeka's submit handlers alter names or mark the form saved.
    document.addEventListener('submit', function(event) {
        let missing;
        $(event.target).find('.mapping-filters-settings').each(function() {
            const scope = $(this);
            const enabled = scope.find('.mapping-filters-enabled[type="checkbox"]');
            if (scope.closest('.block.delete').length || !enabled.prop('checked') || enabled.prop('disabled')) return;
            scope.find('.mapping-filter-row').each(function() {
                const row = $(this);
                const select = row.find('.mapping-filter-property');
                if (!missing && row.find('.mapping-filter-type').val() === 'property'
                    && !select.val() && !row.find('.mapping-filter-property-fallback').val()) {
                    missing = select;
                }
            });
        });
        if (missing) {
            event.preventDefault();
            event.stopImmediatePropagation();
            reportMissingProperty(missing);
        }
    }, true);

    function preserveSelection(select) {
        const row = select.closest('.mapping-filter-row');
        const value = select.val() || select.attr('data-selected-property') || '';
        row.find('.mapping-filter-property-fallback').remove();
        if (value) $('<input>', {type: 'hidden', name: select.attr('name'), value, class: 'mapping-filter-property-fallback'}).insertAfter(select);
        return value;
    }

    function setProperties(scope, select, properties) {
        const selected = preserveSelection(select);
        const groups = new Map();
        select.empty().append(new Option('', ''));
        properties.forEach(property => {
            if (!groups.has(property.vocabulary)) {
                groups.set(property.vocabulary, $('<optgroup>', {label: property.vocabulary}).appendTo(select));
            }
            $('<option>', {value: property.term, text: property.label + ' (' + property.term + ')'})
                .appendTo(groups.get(property.vocabulary));
        });
        const unused = selected && !properties.some(property => property.term === selected);
        const row = select.closest('.mapping-filter-row');
        row.find('.mapping-filter-property-unused').remove();
        if (unused) {
            // Keep the saved filter when a query change makes it unavailable, but
            // do not offer that field in the list of selectable properties.
            $('<option>', {value: selected, text: selected, disabled: true}).appendTo(select);
            $('<p>', {class: 'mapping-filter-property-unused', text: scope.attr('data-unused-property')})
                .appendTo(select.closest('.field'));
        } else {
            row.find('.mapping-filter-property-fallback').remove();
        }
        select.val(selected).prop('disabled', false).trigger('chosen:updated');
        if (selected) clearPropertyError(select);
    }

    function loadProperties(scope, force = false) {
        const block = scope.closest('.block');
        const url = scope.attr('data-properties-url');
        if (!url || !block.length) return;
        let itemsQuery;
        if (scope.attr('data-filter-mode') === 'attachments') {
            const ids = block.find('.attachments .attachment:not(.delete) input.item:not(:disabled)').map(function() { return this.value; }).get().filter(Boolean);
            itemsQuery = JSON.stringify({id: ids.length ? ids : 0});
        } else {
            itemsQuery = String(block.find('.query-form-query').val() || '').trim().replace(/^\?+/, '');
        }
        const requestData = {
            items_query_string: scope.attr('data-filter-mode') === 'query' ? itemsQuery : '',
            items_query: scope.attr('data-filter-mode') === 'attachments' ? itemsQuery : '{}',
            features_query: '{}',
            block_data: JSON.stringify({
                map_linked_items: block.find('input[type="checkbox"][name$="[map_linked_items]"]').prop('checked') ? '1' : '0',
                linked_properties: block.find('.linked-prop-select').val() || [],
            }),
        };
        const signature = JSON.stringify(requestData);
        if (!force && scope.data('propertySignature') === signature) return;
        scope.data('propertySignature', signature);
        scope.removeData('propertyOptions');
        const version = (scope.data('propertyRequestVersion') || 0) + 1;
        scope.data('propertyRequestVersion', version);
        const previous = scope.data('propertyRequest');
        if (previous) previous.abort();
        scope.find('.mapping-filter-property').each(function() {
            const select = $(this);
            preserveSelection(select);
            select.prop('disabled', true).trigger('chosen:updated');
        });
        const status = scope.find('.mapping-filter-properties-status');
        status.prop('hidden', false).text(scope.attr('data-loading-properties'));
        scope.find('.mapping-filter-properties-retry').prop('hidden', true);
        const properties = new Map();
        function fetchPage(page) {
            scope.data('propertyRequest', $.getJSON(url, {...requestData, features_page: page}).done(function(response) {
                if (scope.data('propertyRequestVersion') !== version || !scope[0].isConnected) return;
                (response.properties || []).forEach(property => properties.set(property.term, property));
                if (response.has_more) {
                    fetchPage(page + 1);
                    return;
                }
                const available = Array.from(properties.values()).sort((a, b) => a.label.localeCompare(b.label) || a.term.localeCompare(b.term));
                scope.data('propertyOptions', available);
                scope.find('.mapping-filter-property').each(function() { setProperties(scope, $(this), available); });
                status.prop('hidden', available.length > 0).text(scope.attr('data-no-properties'));
            }).fail(function(xhr, reason) {
                if (reason === 'abort' || scope.data('propertyRequestVersion') !== version) return;
                status.prop('hidden', false).text(scope.attr('data-properties-error'));
                scope.find('.mapping-filter-properties-retry').prop('hidden', false);
            }));
        }
        fetchPage(1);
    }

    function refreshBlock(block) {
        const scope = block.find('.mapping-filters-settings').first();
        clearTimeout(scope.data('propertyTimer'));
        scope.data('propertyTimer', setTimeout(() => loadProperties(scope), 200));
    }

    function initialize(scope) {
        update(scope);
        loadProperties(scope);
        if (scope.attr('data-filter-mode') === 'attachments' && !scope.data('attachmentsObserver')) {
            const block = scope.closest('.block');
            const attachments = block.find('.attachments')[0];
            if (attachments) {
                const observer = new MutationObserver(() => refreshBlock(block));
                observer.observe(attachments, {childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'value']});
                scope.data('attachmentsObserver', observer);
            }
        }
    }

    function update(scope) {
        const enabled = scope.find('.mapping-filters-enabled[type="checkbox"]').prop('checked');
        scope.find('.mapping-filters-configuration').toggle(enabled);
        scope.find('.mapping-filter-row').each(function() {
            const row = $(this);
            const property = row.find('.mapping-filter-type').val() === 'property';
            const select = row.find('.mapping-filter-property');
            select.prop('required', false).attr('aria-required', String(enabled && property)).closest('.field').toggle(property);
            if (!enabled || !property) clearPropertyError(select);
            let labelsChanged = false;
            select.find('option[data-term]').each(function() {
                if (!this.dataset.mappingTermAdded) {
                    this.textContent += ' (' + this.dataset.term + ')';
                    this.dataset.mappingTermAdded = '1';
                    labelsChanged = true;
                }
            });
            if ($.fn.chosen && !select.data('chosen')) {
                select.chosen({width: '100%', allow_single_deselect: true, display_disabled_options: false});
            } else if (labelsChanged) {
                select.trigger('chosen:updated');
            }
            row.find('.chosen-single, .chosen-search-input').attr('aria-required', String(enabled && property));
        });
    }
    $(function() {
        $('.mapping-filters-settings').each(function() { initialize($(this)); });
    });
    $(document).on('o:block-added o:form-loaded', function() {
        $('.mapping-filters-settings').each(function() { initialize($(this)); });
    });
    $(document).on('input change', '.query-form-query, .linked-prop-select, input[name$="[map_linked_items]"], .attachments input.item', function() {
        refreshBlock($(this).closest('.block'));
    });
    $(document).on('click', '.attachment .delete, .attachment .undo', function() {
        refreshBlock($(this).closest('.block'));
    });
    $(document).on('change', '.mapping-filter-property', function() {
        clearPropertyError($(this));
        $(this).attr('data-selected-property', $(this).val() || '');
        $(this).closest('.mapping-filter-row').find('.mapping-filter-property-fallback, .mapping-filter-property-unused').remove();
    });
    $(document).on('click', '.mapping-filter-properties-retry', function() {
        loadProperties($(this).closest('.mapping-filters-settings'), true);
    });
    $(document).on('change', '.mapping-filters-enabled, .mapping-filter-type', function() {
        update($(this).closest('.mapping-filters-settings'));
    });
    $(document).on('click', '.mapping-filter-add', function() {
        const scope = $(this).closest('.mapping-filters-settings');
        let index = 0;
        scope.find('.mapping-filter-rows [name]').each(function() {
            const match = this.name.match(/\[rows\]\[(\d+)\]/);
            if (match) index = Math.max(index, Number(match[1]) + 1);
        });
        const blockIndex = scope.find('.mapping-filters-enabled[type="checkbox"]').attr('name').match(/^o:block\[([^\]]+)\]/)[1];
        const html = scope.find('.mapping-filter-row-template').html()
            .replace(/__filterIndex__/g, String(index)).replace(/__blockIndex__/g, blockIndex);
        const row = $(html).appendTo(scope.find('.mapping-filter-rows'));
        row.find('.mapping-filter-type').val($(this).attr('data-filter-type') || 'resource_template');
        update(scope);
        const properties = scope.data('propertyOptions');
        if (properties) setProperties(scope, row.find('.mapping-filter-property'), properties);
        else row.find('.mapping-filter-property').prop('disabled', true).trigger('chosen:updated');
        row.find('.mapping-filter-type').trigger('focus');
    });
    $(document).on('click', '.mapping-filter-remove', function() {
        const scope = $(this).closest('.mapping-filters-settings');
        const type = $(this).closest('.mapping-filter-row').find('.mapping-filter-type').val();
        $(this).closest('.mapping-filter-row').remove();
        scope.find('.mapping-filter-add').filter(function() {
            return $(this).attr('data-filter-type') === type;
        }).first().trigger('focus');
    });
})(jQuery);
