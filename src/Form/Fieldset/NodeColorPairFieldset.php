<?php

namespace Mapping\Form\Fieldset;

use Laminas\Form\Fieldset;
use Laminas\Form\Element\Text;
use Laminas\Form\Element\Button;
use Omeka\Form\Element\ResourceClassSelect;
use Omeka\Form\Element\ResourceTemplateSelect;

class NodeColorPairFieldset extends Fieldset
{
    public function init(): void
    {
        // Target: resource class
        $this->add([
            'type' => ResourceClassSelect::class,
            'name' => 'resource_class',
            'options' => [
                'label'        => 'Resource class', // @translate
                'empty_option' => '',
            ],
            'attributes' => [
                'class' => 'mp-target mp--rc',
            ],
        ]);

        // Target: resource template
        $this->add([
            'type' => ResourceTemplateSelect::class,
            'name' => 'resource_template',
            'options' => [
                'label'        => 'Resource template', // @translate
                'empty_option' => '',
            ],
            'attributes' => [
                'class' => 'mp-target mp--rt',
            ],
        ]);

        // Color input
        $this->add([
            'type' => Text::class,
            'name' => 'color',
            'options' => ['label' => 'Color'], // @translate
            'attributes' => [
                'type'  => 'color',
                'value' => '#6699ff',
                'class' => 'mp-color',
            ],
        ]);

         // Remove row button 
        $this->add([
            'type' => Button::class,
            'name' => 'remove',
            'options' => ['label' => 'Remove'], // @translate
            'attributes' => [
                'type'  => 'button',
                'class' => 'button mp-remove',
                'style' => 'margin-top:.5rem;',
            ],
        ]);
    }
}
