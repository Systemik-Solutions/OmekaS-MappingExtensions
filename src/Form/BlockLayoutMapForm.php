<?php
namespace Mapping\Form;

use Laminas\Form\Form;

class BlockLayoutMapForm extends Form
{
    public function init()
    {
        $this->add([
            'type' => Fieldset\DefaultViewFieldset::class,
            'name' => 'default_view',
        ]);
        $this->add([
            'type' => Fieldset\OverlaysFieldset::class,
            'name' => 'overlays',
        ]);
        $this->add([
            'type' => Fieldset\TimelineFieldset::class,
            'name' => 'timeline',
        ]);
        $this->add([
            'type' => Fieldset\GroupByFieldset::class,
            'name' => 'group_by_control'
        ]);
        $this->add([
            'type' => Fieldset\NodeColorsFieldset::class,
            'name' => 'node_colors'
        ]);
    }

    public function prepareBlockData(array $rawData)
    {
        $data = array_merge(
            $this->get('default_view')->filterBlockData($rawData),
            $this->get('overlays')->filterBlockData($rawData),
            $this->get('timeline')->filterBlockData($rawData),
            $this->get('group_by_control')->filterBlockData($rawData),
            $this->get('node_colors')->filterBlockData($rawData)
        );
        
        $data = array_merge($data, [
            'map_linked_items'         => !empty($rawData['map_linked_items']) ? '1' : '0',
            'linked_properties' => is_array($rawData['linked_properties'] ?? null)
                ? array_values($rawData['linked_properties'])
                : (strlen(trim((string)($rawData['linked_properties'] ?? ''))) > 0
                    ? array_map('trim', explode(',', $rawData['linked_properties']))
                    : []),
            'popup_display_properties' =>
                array_values(
                    array_filter(
                        array_map(
                            'trim',
                            is_array($rawData['popup_display_properties'] ?? null)
                                ? $rawData['popup_display_properties']
                                : (
                                    strlen(trim((string)($rawData['popup_display_properties'] ?? ''))) > 0
                                    ? explode(',', $rawData['popup_display_properties'])
                                    : []
                                )
                        ),
                        fn($v) => $v !== ''
                    )
                ),
        ]);

        $this->setData([
            'default_view' => [
                'o:block[__blockIndex__][o:data][basemap_provider]' => $data['basemap_provider'],
                'o:block[__blockIndex__][o:data][min_zoom]' => $data['min_zoom'],
                'o:block[__blockIndex__][o:data][max_zoom]' => $data['max_zoom'],
                'o:block[__blockIndex__][o:data][scroll_wheel_zoom]' => $data['scroll_wheel_zoom'],
            ],
            'overlays' => [
                'o:block[__blockIndex__][o:data][overlay_mode]' => $data['overlay_mode'],
            ],
            'timeline' => [
                'o:block[__blockIndex__][o:data][timeline][title_headline]' => $data['timeline']['title_headline'],
                'o:block[__blockIndex__][o:data][timeline][title_text]' => $data['timeline']['title_text'],
                'o:block[__blockIndex__][o:data][timeline][fly_to]' => $data['timeline']['fly_to'],
                'o:block[__blockIndex__][o:data][timeline][show_contemporaneous]' => $data['timeline']['show_contemporaneous'],
                'o:block[__blockIndex__][o:data][timeline][timenav_position]' => $data['timeline']['timenav_position'],
                'o:block[__blockIndex__][o:data][timeline][data_type_properties]' => $data['timeline']['data_type_properties'][0] ?? '',
            ],
            'group_by_control' => [
                'group-by-select' => $data['group_by_control']['group-by-select'] ?? '',
                'property_value'  => $data['group_by_control']['property_value'] ?? '',
            ],
            'node_colors' => [
                'rows' => $data['node_colors']['rows'] ?? [],
            ],
        ]);
        return $data;
    }
}
