<?php
// NEW: modules/Mapping/src/Form/Fieldset/GroupByFieldset.php
namespace Mapping\Form\Fieldset;

use Laminas\Form\Fieldset;
use Laminas\Form\Element\Select;
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
    }

    public function filterBlockData(array $rawData): array
    {
        return [
            'group_by_control' => [
                'group-by-select' => $rawData['group_by_control']['group-by-select'] ?? '',
                'property_value'  => $rawData['group_by_control']['property_value'] ?? '',
            ],
        ];
    }
}
