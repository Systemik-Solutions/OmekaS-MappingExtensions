<?php
// NEW: modules/Mapping/src/Form/Fieldset/GroupByFieldset.php
namespace MappingExtensions\Form\Fieldset;

use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\Select;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\PropertySelect;

class GroupByFieldset extends Fieldset
{
    public function init(): void
    {
        $this->add([
            'type' => Select::class,
            'name' => 'group-by-select',
            'options' => [
                'label' => 'Group by',
                'value_options' => [
                    'resource_class'    => 'Resource class',
                    'resource_template' => 'Resource template',
                    'property_value'    => 'Property value',
                    'none'              => 'None',
                ],
            ],
            'attributes' => [
                'class' => 'mp_group_by_select',
            ],
        ]);

        $this->add([
            'type' => PropertySelect::class,
            'name' => 'property_value',
            'options' => [
                'label'              => 'Property',
                'empty_option'       => '',
                'term_as_value'      => true,
                'use_hidden_element' => true,
            ],
            'attributes' => [
                'class' => 'chosen-select mp-group-by-property-value',
            ],
        ]);

        $this->add([
            'type' => Checkbox::class,
            'name' => 'show_property_name_in_legend',
            'options' => [
                'label' => 'Show property name in legend', // @translate
                'info' => 'When unchecked, the legend shows only the property value.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'class' => 'mp-show-property-name-in-legend',
            ],
        ]);
    }

    public function filterBlockData(array $rawData): array
    {
        return [
            'group_by_control' => [
                'group-by-select' => $rawData['group_by_control']['group-by-select'] ?? '',
                'property_value'  => $rawData['group_by_control']['property_value'] ?? '',
                'show_property_name_in_legend' =>
                    !empty($rawData['group_by_control']['show_property_name_in_legend']) ? '1' : '0',
            ],
        ];
    }
}
