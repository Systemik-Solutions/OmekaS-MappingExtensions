<?php

namespace Mapping\Form\Fieldset;

use Laminas\Form\Fieldset;
use Laminas\Form\Element\Collection;

class NodeColorsFieldset extends Fieldset
{
    public function init(): void
    {
        $this->add([
            'type' => Collection::class,
            'name' => 'rows',
            'options' => [
                'label' => 'Group colors', // @translate
                'count' => 1,
                'allow_add' => true,
                'allow_remove' => true,
                'should_create_template' => true,
                'template_placeholder' => '__index__',
                'target_element' => [
                    'type' => \Mapping\Form\Fieldset\NodeColorPairFieldset::class,
                ],
            ],
            'attributes' => [
                'class' => 'mp_node_colors',
            ],
        ]);
    }

    public function filterBlockData(array $rawData): array
    {
        $rows = $rawData['node_colors']['rows'] ?? [];
        // normalize each row
        $norm = [];
        foreach ($rows as $r) {
            $norm[] = [
                'resource_class'    => $r['resource_class']    ?? '',
                'resource_template' => $r['resource_template'] ?? '',
                'property_value'    => $r['property_value']    ?? '',
                'property_text'     => $r['property_text']     ?? '', 
                'color'             => $r['color']             ?? '#6699ff',
            ];
        }
        return ['node_colors' => ['rows' => $norm]];
    }
}
