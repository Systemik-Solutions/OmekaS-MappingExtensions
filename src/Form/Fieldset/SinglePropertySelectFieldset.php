<?php

namespace Mapping\Form\Fieldset;

use Laminas\Form\Fieldset;
use Omeka\Form\Element\PropertySelect;

class SinglePropertySelectFieldset extends Fieldset
{
    public function init()
{
    // ✅ keep it simple; Laminas will nest it under the fieldset name for you
    $this->add([
        'type' => \Omeka\Form\Element\PropertySelect::class,
        'name' => 'property',
        'options' => [
            'label'              => 'Property', // @translate
            'empty_option'       => '',
            'term_as_value'      => true,
            'use_hidden_element' => true,
        ],
        'attributes' => [
            'class' => 'chosen-select',
        ],
    ]);
}

    public function filterBlockData(array $rawData): array
    {
        // Saved shape
        if (isset($rawData['journey']['property'])) {
            return ['journey' => ['property' => (string) $rawData['journey']['property']]];
        }

        // Namespaced POST (safety net)
        foreach ($rawData as $k => $v) {
            if (is_string($k) && preg_match('/\[journey\]\[property\]$/', $k)) {
                return ['journey' => ['property' => (string) $v]];
            }
        }

        return ['journey' => ['property' => '']]; // first render / not set
    }
}
