<?php
// NEW: modules/Mapping/src/Form/Fieldset/GroupByFieldset.php
namespace Mapping\Form\Fieldset;

use Laminas\Form\Fieldset;
use Laminas\Form\Element\Select;

class GroupByFieldset extends Fieldset
{
    public function init(): void
    {
        $this->add([
            'type' => Select::class,
            'name' => 'group-by-select',
            'options' => [
                'label' => 'Group by', // @translate
                'value_options' => [
                    'resource_class'    => 'Resource class',    // @translate
                    'resource_template' => 'Resource template', // @translate
                ],
            ],
            'attributes' => [
                'class' => 'mp_group_by_select',
            ],
        ]);
    }

    public function filterBlockData(array $rawData): array
    {
        return [
            'group_by_control' => [
                'group-by-select' => $rawData['group_by_control']['group-by-select'] ?? '',
            ],
        ];
    }
}
