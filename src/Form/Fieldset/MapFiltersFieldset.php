<?php
namespace MappingExtensions\Form\Fieldset;

use Laminas\Form\Element\Checkbox;
use Laminas\Form\Fieldset;

class MapFiltersFieldset extends Fieldset
{
    public function init(): void
    {
        $this->add([
            'type' => Checkbox::class,
            'name' => 'enabled',
            'options' => [
                'label' => 'Show map filters', // @translate
                'info' => 'Display the configured filters in the bottom-right corner of the map.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => ['class' => 'mapping-filters-enabled'],
        ]);
    }

    public function filterBlockData(array $rawData): array
    {
        // Preserve the original template filter and its visibility on older blocks.
        if (!isset($rawData['filters']) || !is_array($rawData['filters'])) {
            return ['filters' => [
                'enabled' => !empty($rawData['show_resource_template_filter'] ?? true) ? '1' : '0',
                'rows' => [['type' => 'resource_template', 'property' => '', 'label' => '']],
            ]];
        }

        $rows = [];
        foreach ((array) ($rawData['filters']['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = $row['type'] ?? '';
            if (!in_array($type, ['resource_template', 'resource_class', 'property'], true)) {
                continue;
            }
            $property = $type === 'property' ? trim((string) ($row['property'] ?? '')) : '';
            if ($type === 'property' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*:[a-zA-Z0-9_.-]+$/D', $property)) {
                continue;
            }
            $rows[] = [
                'type' => $type,
                'property' => $property,
                'label' => trim(strip_tags((string) ($row['label'] ?? ''))),
            ];
        }
        return ['filters' => [
            'enabled' => !empty($rawData['filters']['enabled']) ? '1' : '0',
            'rows' => array_values($rows),
        ]];
    }
}
