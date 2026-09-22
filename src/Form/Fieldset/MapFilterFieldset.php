<?php
namespace MappingExtensions\Form\Fieldset;

use Laminas\Form\Element\Select;
use Laminas\Form\Element\Text;
use Laminas\Form\Fieldset;

class MapFilterFieldset extends Fieldset
{
    public function init(): void
    {
        $this->add([
            'type' => Select::class,
            'name' => 'type',
            'options' => [
                'label' => 'Filter by', // @translate
                'value_options' => [
                    'resource_template' => 'Resource template', // @translate
                    'resource_class' => 'Resource class', // @translate
                    'property' => 'Property values', // @translate
                ],
            ],
            'attributes' => ['class' => 'mapping-filter-type'],
        ]);
        $this->add([
            'type' => Select::class,
            'name' => 'property',
            'options' => [
                'label' => 'Property', // @translate
                'info' => 'Only properties with values on the current mapped items are available. All values of the selected property become dropdown options.', // @translate
                'empty_option' => '',
                'value_options' => [],
            ],
            'attributes' => ['class' => 'mapping-filter-property'],
        ]);
        $this->add([
            'type' => Text::class,
            'name' => 'label',
            'options' => [
                'label' => 'Filter label', // @translate
                'info' => 'Optional. Leave blank to use the filter type or property term.', // @translate
            ],
        ]);
    }
}
