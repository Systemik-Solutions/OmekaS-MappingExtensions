<?php

namespace MappingExtensions\Form\Fieldset;

use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\Collection;
use Laminas\Form\Fieldset;

class SidebarTabsFieldset extends Fieldset
{
    public function init(): void
    {
        $this->add([
            'type' => Checkbox::class,
            'name' => 'enabled',
            'options' => [
                'label' => 'Show tabs in place sidebar', // @translate
                'info' => 'When unchecked, the sidebar uses the existing flat detail view.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'class' => 'sidebar-tabs-enabled',
            ],
        ]);

        $this->add([
            'type' => Checkbox::class,
            'name' => 'popup_enabled',
            'options' => [
                'label' => 'Show tabs in marker popup', // @translate
                'info' => 'When checked, marker clicks show the configured tabs in the popup instead of the left sidebar.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'class' => 'sidebar-tabs-popup-enabled',
            ],
        ]);

        $this->add([
            'type' => Collection::class,
            'name' => 'tabs',
            'options' => [
                'label' => 'Tabs', // @translate
                'count' => 0,
                'allow_add' => true,
                'allow_remove' => true,
                'should_create_template' => true,
                'template_placeholder' => '__index__',
                'target_element' => [
                    'type' => SidebarTabFieldset::class,
                ],
            ],
            'attributes' => [
                'class' => 'sidebar-tabs-collection',
            ],
        ]);
    }

    public function filterBlockData(array $rawData): array
    {
        $rawSidebarTabs = $rawData['sidebar_tabs'] ?? [];
        if (!is_array($rawSidebarTabs)) {
            $rawSidebarTabs = [];
        }

        $tabs = [];
        $rawTabs = $rawSidebarTabs['tabs'] ?? [];
        if (is_array($rawTabs)) {
            foreach ($rawTabs as $rawTab) {
                if (!is_array($rawTab)) {
                    continue;
                }

                $label = trim(strip_tags((string) ($rawTab['label'] ?? '')));
                $properties = $this->normalizeProperties($rawTab['properties'] ?? []);
                $popupContent = $this->normalizePopupContent($rawTab);

                if ('' === $label && !$properties && !$popupContent) {
                    continue;
                }

                $tabs[] = [
                    'label' => $label,
                    'properties' => $properties,
                    'popup_content' => $popupContent,
                ];
            }
        }

        return [
            'sidebar_tabs' => [
                'enabled' => !empty($rawSidebarTabs['enabled']) ? '1' : '0',
                'popup_enabled' => !empty($rawSidebarTabs['popup_enabled']) ? '1' : '0',
                'tabs' => $tabs,
            ],
        ];
    }

    private function normalizeProperties($properties): array
    {
        if (is_string($properties)) {
            $properties = strlen(trim($properties)) ? explode(',', $properties) : [];
        }

        if (!is_array($properties)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($property) {
            return trim((string) $property);
        }, $properties), static function ($property) {
            return '' !== $property;
        }));
    }

    private function normalizePopupContent(array $rawTab): array
    {
        $popupContent = $rawTab['popup_content'] ?? [];
        if (is_string($popupContent)) {
            $popupContent = strlen(trim($popupContent)) ? explode(',', $popupContent) : [];
        }
        if (!is_array($popupContent)) {
            $popupContent = [];
        }

        // Migrate the previous per-tab media checkbox if present.
        if (!empty($rawTab['show_media'])) {
            $popupContent[] = 'media';
        }

        $allowed = ['media', 'property', 'linked_from', 'external_link'];
        return array_values(array_intersect($allowed, array_unique(array_map(static function ($value) {
            return trim((string) $value);
        }, $popupContent))));
    }
}
