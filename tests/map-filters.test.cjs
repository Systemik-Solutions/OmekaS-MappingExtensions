const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const MappingFilters = vm.runInNewContext(fs.readFileSync(
    path.join(__dirname, '../asset/js/mapping-filters.js'), 'utf8'
) + '\nMappingFilters;');
const property = (key, values = []) => ({key: 'property:' + key, type: 'property', selected: new Set(values)});
const category = (type, excluded = []) => ({key: type, type, excluded: new Set(excluded)});
const matcher = filters => Object.assign(Object.create(MappingFilters.prototype), {filters});
const layer = values => ({mapping_filter_values: Object.fromEntries(
    Object.entries(values).map(([key, options]) => [key, options.map(value => ({value}))])
)});

test('empty property selection includes items without that property', () => {
    assert(matcher([property('schema:category')]).matches(layer({})));
    assert(matcher([]).matches(layer({})));
});
test('multiple property choices match any value on a multi-valued item', () => {
    const filters = matcher([property('schema:category', ['literal:Art', 'literal:History'])]);
    assert(filters.matches(layer({'property:schema:category': ['literal:Science', 'literal:History']})));
    assert(!filters.matches(layer({'property:schema:category': ['literal:Science']})));
    assert(!filters.matches(layer({})));
});
test('separate filters must all match', () => {
    const filters = matcher([property('schema:category', ['literal:Art']), property('schema:inLanguage', ['literal:English'])]);
    assert(filters.matches(layer({'property:schema:category': ['literal:Art'], 'property:schema:inLanguage': ['literal:English']})));
    assert(!filters.matches(layer({'property:schema:category': ['literal:Art'], 'property:schema:inLanguage': ['literal:French']})));
});
test('templates and classes toggle independently and may exclude every category', () => {
    const states = [category('resource_template', ['1']), category('resource_class', ['3'])];
    const filters = matcher(states);
    assert(!filters.matches(layer({resource_template: ['1'], resource_class: ['2']})));
    assert(!filters.matches(layer({resource_template: ['2'], resource_class: ['3']})));
    assert(filters.matches(layer({resource_template: ['2'], resource_class: ['2']})));
    states[0].excluded.add('2');
    assert(!filters.matches(layer({resource_template: ['2'], resource_class: ['2']})));
});
test('unassigned template/class categories remain selectable', () => {
    const states = [category('resource_template'), category('resource_class')];
    const filters = matcher(states);
    assert(filters.matches(layer({resource_template: ['0'], resource_class: ['0']})));
    states[1].excluded.add('0');
    assert(!filters.matches(layer({resource_template: ['0'], resource_class: ['0']})));
});
test('resource and URI choices match by identity rather than display label', () => {
    const filters = matcher([property('schema:category', ['resource:7', 'uri:https://example.com/category'])]);
    assert(filters.matches(layer({'property:schema:category': ['resource:7']})));
    assert(filters.matches(layer({'property:schema:category': ['uri:https://example.com/category']})));
    assert(!filters.matches(layer({'property:schema:category': ['literal:7']})));
});
test('old template metadata remains supported', () => {
    const filters = matcher([category('resource_template', ['2'])]);
    assert(filters.matches({mapping_resource_template: {id: 1}}));
    assert(!filters.matches({mapping_resource_template: {id: 2}}));
});
