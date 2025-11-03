<?php

namespace Mapping\Form;

use Laminas\Form\Form;

class BlockLayoutMapJourneyForm extends Form
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
            'type' => Fieldset\QueryFieldset::class,
            'name' => 'query',
        ]);
        $this->add([
            'type' => Fieldset\SinglePropertySelectFieldset::class,
            'name' => 'journey',
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
            $this->get('query')->filterBlockData($rawData),
            $this->get('journey')->filterBlockData($rawData),
            $this->get('group_by_control')->filterBlockData($rawData),
            $this->get('node_colors')->filterBlockData($rawData)
        );

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
            'query' => [
                'o:block[__blockIndex__][o:data][query]' => $data['query'],
            ],
            'journey' => [
                'property' => $data['journey']['property']
            ],
            'group_by_control' => [
                'group-by-select' => $data['group_by_control']['group-by-select'] ?? '',
            ],
            'node_colors' => [
                'rows' => $data['node_colors']['rows'] ?? [],
            ],
        ]);
        return $data;
    }
}
