<?php

namespace MappingExtensions\Form\Fieldset;

use Laminas\Form\Element\Button;
use Laminas\Form\Element\MultiCheckbox;
use Laminas\Form\Element\Text;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\PropertySelect;

class SidebarTabFieldset extends Fieldset
{
    public function init(): void
    {
        $this->add([
            'type' => Text::class,
            'name' => 'label',
            'options' => [
                'label' => 'Tab label', // @translate
            ],
            'attributes' => [
                'class' => 'sidebar-tab-label',
            ],
        ]);

        $this->add([
            'type' => MultiCheckbox::class,
            'name' => 'popup_content',
            'options' => [
                'label' => 'Popup Content', // @translate
                'value_options' => [
                    'media' => 'Media', // @translate
                    'property' => 'Property', // @translate
                    'linked_from' => 'Linked from', // @translate
                    'external_link' => 'External link', // @translate
                ],
            ],
            'attributes' => [
                'class' => 'sidebar-tab-popup-content',
            ],
        ]);

        $this->add([
            'type' => PropertySelect::class,
            'name' => 'properties',
            'options' => [
                'label' => 'Fields', // @translate
                'info' => 'Leave empty to show all fields.', // @translate
                'empty_option' => '',
                'term_as_value' => true,
                'use_hidden_element' => true,
            ],
            'attributes' => [
                'multiple' => true,
                'class' => 'chosen-select sidebar-tab-properties',
                'data-placeholder' => 'Select one or more properties...', // @translate
            ],
        ]);

        $this->add([
            'type' => Button::class,
            'name' => 'remove',
            'options' => [
                'label' => 'Remove', // @translate
            ],
            'attributes' => [
                'type' => 'button',
                'class' => 'button sidebar-tab-remove',
            ],
        ]);
    }
}
