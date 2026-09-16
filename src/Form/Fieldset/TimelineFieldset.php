<?php
namespace MappingExtensions\Form\Fieldset;

use Laminas\Form\Fieldset;
use NumericDataTypes\Form\Element\NumericPropertySelect;
use Omeka\Stdlib\HtmlPurifier;

class TimelineFieldset extends Fieldset
{
    protected $htmlPurifier;

    public function init()
    {
        $this->add([
            'type' => 'text',
            'name' => 'o:block[__blockIndex__][o:data][timeline][title_headline]',
            'options' => [
                'label' => 'Title headline', // @translate
            ],
        ]);
        $this->add([
            'type' => 'textarea',
            'name' => 'o:block[__blockIndex__][o:data][timeline][title_text]',
            'options' => [
                'label' => 'Title text', // @translate
            ],
            'attributes' => [
                'class' => 'block-html full wysiwyg',
            ],
        ]);
        $this->add([
            'type' => 'select',
            'name' => 'o:block[__blockIndex__][o:data][timeline][fly_to]',
            'options' => [
                'label' => 'Fly to', // @translate
                'info' => 'Select the map view to fly to when navigating between events.', // @translate
                'empty_option' => 'Default view', // @translate
                'value_options' => [
                    '0' => 'Event marker, zoom 0', // @translate
                    '2' => 'Event marker, zoom 2', // @translate
                    '4' => 'Event marker, zoom 4', // @translate
                    '6' => 'Event marker, zoom 6', // @translate
                    '8' => 'Event marker, zoom 8', // @translate
                    '10' => 'Event marker, zoom 10', // @translate
                    '12' => 'Event marker, zoom 12', // @translate
                    '14' => 'Event marker, zoom 14', // @translate
                    '16' => 'Event marker, zoom 16', // @translate
                    '18' => 'Event marker, zoom 18', // @translate
                ],
            ],
        ]);
        $this->add([
            'type' => 'checkbox',
            'name' => 'o:block[__blockIndex__][o:data][timeline][show_contemporaneous]',
            'options' => [
                'label' => 'Show contemporaneous events?', // @translate
                'info' => 'Check this if you want to show all events on the map that overlap the current event. This also applies when a Fly to zoom is selected.', // @translate
            ],
        ]);
        $this->add([
            'type' => 'select',
            'name' => 'o:block[__blockIndex__][o:data][timeline][timenav_position]',
            'options' => [
                'label' => 'Timeline navigation position', // @translate
                'info' => 'Select the position of the timeline navigation.', // @translate
                'value_options' => [
                    'full_width_below' => 'Below timeline slides', // @translate
                    'full_width_above' => 'Above timeline slides', // @translate
                ],
            ],
        ]);
        $this->add([
            'type' => 'select',
            'name' => 'o:block[__blockIndex__][o:data][timeline][layout]',
            'options' => [
                'label' => 'Timeline layout', // @translate
                'value_options' => [
                    'below' => 'Full width below map', // @translate
                    'beside' => 'Beside map', // @translate
                ],
            ],
        ]);
        $this->add([
            'type' => 'text',
            'name' => 'o:block[__blockIndex__][o:data][timeline][font_family]',
            'options' => [
                'label' => 'Timeline font family', // @translate
                'info' => 'Leave blank to keep the default timeline font. Otherwise enter a font stack, for example Georgia, serif. Fonts must already be available on the site.', // @translate
            ],
        ]);
        foreach ([
            'text_color' => 'Timeline text colour', // @translate
            'background_color' => 'Timeline background colour', // @translate
        ] as $key => $label) {
            $this->add([
                'type' => 'text',
                'name' => "o:block[__blockIndex__][o:data][timeline][$key]",
                'options' => [
                    'label' => $label,
                    'info' => 'Optional hex colour, for example #333333. Leave blank to keep the default timeline colours.', // @translate
                ],
                'attributes' => ['placeholder' => '#333333'],
            ]);
        }
        $this->add([
            'type' => 'select',
            'name' => 'o:block[__blockIndex__][o:data][timeline][marker_rows]',
            'options' => [
                'label' => 'Maximum timeline marker rows', // @translate
                'info' => 'Set the maximum number of rows available for timeline markers. More rows make the timeline navigation taller and reduce marker overlap.', // @translate
                'value_options' => [
                    '4' => '4', // @translate
                    '6' => '6', // @translate
                    '8' => '8', // @translate
                    '10' => '10', // @translate
                ],
            ],
        ]);
        if (class_exists(NumericPropertySelect::class)) {
            $this->add([
                'type' => NumericPropertySelect::class,
                'name' => 'o:block[__blockIndex__][o:data][timeline][data_type_properties]',
                'options' => [
                    'label' => 'Property', // @translate
                    'info' => 'Select the timestamp or interval property to use when populating the timeline.', // @translate
                    'empty_option' => '',
                    'numeric_data_type' => ['timestamp', 'interval'],
                    'numeric_data_type_disambiguate' => true,
                ],
                'attributes' => [
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select property…', // @translate
                ],
            ]);
        }
    }

    public function filterBlockData(array $rawData)
    {
        $data = [
            'timeline' => [
                'title_headline' => null,
                'title_text' => null,
                'fly_to' => null,
                'show_contemporaneous' => null,
                'timenav_position' => 'full_width_below',
                'layout' => 'below',
                'font_family' => '',
                'text_color' => '',
                'background_color' => '',
                'marker_rows' => '4',
                'data_type_properties' => null,
            ],
        ];

        if (isset($rawData['timeline']['title_headline'])) {
            $data['timeline']['title_headline'] = $this->htmlPurifier->purify($rawData['timeline']['title_headline']);
        }
        if (isset($rawData['timeline']['title_text'])) {
            $data['timeline']['title_text'] = $this->htmlPurifier->purify($rawData['timeline']['title_text']);
        }
        if (isset($rawData['timeline']['fly_to']) && is_numeric($rawData['timeline']['fly_to'])) {
            $data['timeline']['fly_to'] = $rawData['timeline']['fly_to'];
        }
        if (isset($rawData['timeline']['show_contemporaneous']) && $rawData['timeline']['show_contemporaneous']) {
            $data['timeline']['show_contemporaneous'] = true;
        }
        if (isset($rawData['timeline']['timenav_position']) && in_array($rawData['timeline']['timenav_position'], ['full_width_below', 'full_width_above'])) {
            $data['timeline']['timenav_position'] = $rawData['timeline']['timenav_position'];
        }
        if (in_array($rawData['timeline']['layout'] ?? '', ['below', 'beside'], true)) {
            $data['timeline']['layout'] = $rawData['timeline']['layout'];
        }
        $font = $rawData['timeline']['font_family'] ?? '';
        // Only accept font names and stacks, never arbitrary CSS declarations.
        if (is_string($font) && strlen($font) <= 255 && preg_match('/^[\p{L}\p{N} ,\'"_-]*$/u', $font)) {
            $data['timeline']['font_family'] = trim($font);
        }
        foreach (['text_color', 'background_color'] as $key) {
            $color = $rawData['timeline'][$key] ?? '';
            if (is_string($color) && preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i', $color)) {
                $data['timeline'][$key] = $color;
            }
        }
        if (isset($rawData['timeline']['marker_rows'])
            && in_array((string) $rawData['timeline']['marker_rows'], ['4', '6', '8', '10'], true)
        ) {
            $data['timeline']['marker_rows'] = (string) $rawData['timeline']['marker_rows'];
        }
        if (isset($rawData['timeline']['data_type_properties'])) {
            // Anticipate future use of multiple numeric properties per
            // timeline by saving an array of properties.
            if (is_string($rawData['timeline']['data_type_properties'])) {
                $rawData['timeline']['data_type_properties'] = [$rawData['timeline']['data_type_properties']];
            }
            if (is_array($rawData['timeline']['data_type_properties'])) {
                foreach ($rawData['timeline']['data_type_properties'] as $dataTypeProperty) {
                    if (is_string($dataTypeProperty)) {
                        $dataTypeProperty = explode(':', $dataTypeProperty);
                        if (3 === count($dataTypeProperty)) {
                            [$namespace, $type, $propertyId] = $dataTypeProperty;
                            if ('numeric' === $namespace
                                && in_array($type, ['timestamp', 'interval'])
                                && is_numeric($propertyId)
                            ) {
                                $data['timeline']['data_type_properties'][] = sprintf('%s:%s:%s', $namespace, $type, $propertyId);
                            }
                        }
                    }
                }
            }
        }

        return $data;
    }

    public function setHtmlPurifier(HtmlPurifier $htmlPurifier)
    {
        $this->htmlPurifier = $htmlPurifier;
    }
}
