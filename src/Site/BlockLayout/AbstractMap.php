<?php

namespace Mapping\Site\BlockLayout;

use Composer\Semver\Comparator;
use Doctrine\DBAL\Connection;
use Laminas\View\Renderer\PhpRenderer;
use Mapping\Module;
use NumericDataTypes\DataType\Timestamp;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use WeakReference;

abstract class AbstractMap extends AbstractBlockLayout
{
    protected $moduleManager;

    protected $formElementManager;

    protected $connection;

    protected $apiManager;

    public function prepareForm(PhpRenderer $view)
    {
        $view->headLink()->appendStylesheet($view->assetUrl('node_modules/leaflet/dist/leaflet.css', 'Mapping'));
        $view->headLink()->appendStylesheet($view->assetUrl('node_modules/leaflet.fullscreen/Control.FullScreen.css', 'Mapping'));

        $view->headLink()->appendStylesheet($view->assetUrl('css/mapping-block-form.css', 'Mapping'));

        $view->headScript()->appendFile($view->assetUrl('node_modules/leaflet/dist/leaflet.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('node_modules/leaflet-providers/leaflet-providers.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('node_modules/leaflet.fullscreen/Control.FullScreen.js', 'Mapping'));

        $view->headScript()->appendFile($view->assetUrl('js/mapping-block-form.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('js/control.default-view.js', 'Mapping'));
    }

    public function prepareRender(PhpRenderer $view)
    {
        $view->headLink()->appendStylesheet($view->assetUrl('node_modules/leaflet/dist/leaflet.css', 'Mapping'));
        $view->headLink()->appendStylesheet($view->assetUrl('node_modules/leaflet.markercluster/dist/MarkerCluster.css', 'Mapping'));
        $view->headLink()->appendStylesheet($view->assetUrl('node_modules/leaflet.markercluster/dist/MarkerCluster.Default.css', 'Mapping'));
        $view->headLink()->appendStylesheet($view->assetUrl('node_modules/leaflet-groupedlayercontrol/dist/leaflet.groupedlayercontrol.min.css', 'Mapping'));
        $view->headLink()->appendStylesheet($view->assetUrl('node_modules/leaflet.fullscreen/Control.FullScreen.css', 'Mapping'));

        $view->headLink()->appendStylesheet($view->assetUrl('css/mapping.css', 'Mapping'));

        $view->headScript()->appendFile($view->assetUrl('node_modules/leaflet/dist/leaflet.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('node_modules/leaflet.markercluster/dist/leaflet.markercluster-src.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('node_modules/leaflet-providers/leaflet-providers.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('node_modules/leaflet-groupedlayercontrol/dist/leaflet.groupedlayercontrol.min.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('node_modules/leaflet.fullscreen/Control.FullScreen.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('node_modules/Leaflet.Deflate/dist/L.Deflate.js', 'Mapping'));
        $view->headScript()->appendFile('https://cdn.jsdelivr.net/npm/@allmaps/leaflet/dist/bundled/allmaps-leaflet-1.9.umd.js');

        $view->headScript()->appendFile($view->assetUrl('js/MappingModule.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('js/control.opacity.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('js/control.fit-bounds.js', 'Mapping'));
        $view->headScript()->appendFile($view->assetUrl('js/mapping-block.js', 'Mapping'));
    }

    /**
     * Is the timeline feature available?
     *
     * @return bool
     */
    public function timelineIsAvailable()
    {
        // Available when the NumericDataTypes module is active and the version
        // >= 1.1.0 (when it introduced interval data type).
        $module = $this->moduleManager->getModule('NumericDataTypes');
        return (
            $module
            && ModuleManager::STATE_ACTIVE === $module->getState()
            && Comparator::greaterThanOrEqualTo($module->getDb('version'), '1.1.0')
        );
    }

    /**
     * Get timeline options.
     *
     * @see https://timeline.knightlab.com/docs/options.html
     * @param srray $data
     * @return array
     */
    public function getTimelineOptions(array $data)
    {
        return [
            'debug' => false,
            'timenav_position' => 'bottom',
        ];
    }

    /**
     * Get timeline data.
     *
     * @see https://timeline.knightlab.com/docs/json-format.html
     * @param array $events
     * @param array $data
     * @param PhpRenderer $view
     * @return array
     */
    public function getTimelineData(array $events, array $data, PhpRenderer $view)
    {
        $timelineData = [
            'title' => null,
            'events' => $events,
        ];
        // Set the timeline title.
        if (isset($data['timeline']['title_headline']) || isset($data['timeline']['title_text'])) {
            $timelineData['title'] = [
                'text' => [
                    'headline' => $data['timeline']['title_headline'],
                    'text' => $data['timeline']['title_text'],
                ],
            ];
        }
        return $timelineData;
    }

    /**
     * Get a timeline event.
     *
     * @see https://timeline.knightlab.com/docs/json-format.html#json-slide
     * @param int $itemId
     * @param array $dataTypeProperties
     * @return array
     */
    public function getTimelineEvent($itemId, array $dataTypeProperties, $view, $has_features = true)
    {
        $query = [
            'id' => $itemId,
            'has_features' => $has_features,
        ];
        $item = $view->api()->searchOne('items', $query)->getContent();
        if (!$item) {
            // This item has no features.
            return;
        }
        $property = null;
        $dataType = null;
        $value = null;
        foreach ($dataTypeProperties as $dataTypeProperty) {
            $dataTypeProperty = explode(':', $dataTypeProperty);
            try {
                $property = $view->api()->read('properties', $dataTypeProperty[2])->getContent();
            } catch (NotFoundException $e) {
                // Invalid property.
                continue;
            }
            $dataType = sprintf('%s:%s', $dataTypeProperty[0], $dataTypeProperty[1]);
            $value = $item->value($property->term(), ['type' => $dataType]);
            if ($value) {
                // Set only the first matching numeric value.
                break;
            }
        }
        if (!$value) {
            // This item has no numeric values.
            return;
        }

        // Set the unique ID and "text" object.
        $title = $item->value('dcterms:title');
        $description = $item->value('dcterms:description');
        $event = [
            'unique_id' => (string) $item->id(), // must cast to string
            'text' => [
                'headline' => $item->link($item->displayTitle(null, $view->lang()), null, ['target' => '_blank']),
                'text' => $item->displayDescription(),
            ],
        ];

        // Set the "media" object.
        $media = $item->primaryMedia();
        if ($media) {
            $event['media'] = [
                'url' => $media->thumbnailUrl('large'),
                'thumbnail' => $media->thumbnailUrl('medium'),
                'link' => $item->url(),
                'alt' => $media->altTextResolved(),
            ];
        }

        // Set the start and end "date" objects.
        if ('numeric:timestamp' === $dataType) {
            $dateTime = Timestamp::getDateTimeFromValue($value->value());
            $event['start_date'] = [
                'year' => $dateTime['year'],
                'month' => $dateTime['month'],
                'day' => $dateTime['day'],
                'hour' => $dateTime['hour'],
                'minute' => $dateTime['minute'],
                'second' => $dateTime['second'],
            ];
        } elseif ('numeric:interval' === $dataType) {
            [$intervalStart, $intervalEnd] = explode('/', $value->value());
            $dateTimeStart = Timestamp::getDateTimeFromValue($intervalStart);
            $event['start_date'] = [
                'year' => $dateTimeStart['year'],
                'month' => $dateTimeStart['month'],
                'day' => $dateTimeStart['day'],
                'hour' => $dateTimeStart['hour'],
                'minute' => $dateTimeStart['minute'],
                'second' => $dateTimeStart['second'],
            ];
            $dateTimeEnd = Timestamp::getDateTimeFromValue($intervalEnd, false);
            $event['end_date'] = [
                'year' => $dateTimeEnd['year'],
                'month' => $dateTimeEnd['month_normalized'],
                'day' => $dateTimeEnd['day_normalized'],
                'hour' => $dateTimeEnd['hour_normalized'],
                'minute' => $dateTimeEnd['minute_normalized'],
                'second' => $dateTimeEnd['second_normalized'],
            ];
            $event['display_date'] = sprintf(
                '%s — %s',
                $dateTimeStart['date']->format($dateTimeStart['format_render']),
                $dateTimeEnd['date']->format($dateTimeEnd['format_render'])
            );
        }
        return $event;
    }

    public function setFormElementManager($formElementManager)
    {
        $this->formElementManager = $formElementManager;
    }

    public function setModuleManager(ModuleManager $moduleManager)
    {
        $this->moduleManager = $moduleManager;
    }

    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function setApiManager($apiManager)
    {
        $this->apiManager = $apiManager;
    }

    /**
     * Normalize linked_properties input (null | csv string | json string | array) to array of terms or null (meaning "any").
     */
    public function normalizeLinkedPropsTerms($raw)
    {
        if ($raw === null || $raw === '' || $raw === []) return null; // null => any property
        if (is_array($raw)) {
            $list = $raw;
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $list = $decoded;
            else $list = array_values(array_filter(array_map('trim', explode(',', $raw))));
        } else {
            return null;
        }
        $list = array_values(array_unique(array_filter(array_map('strval', $list))));
        return $list ?: null;
    }


    /**
     * Resolve property ID from term 
     */
    public function getPropertyIdForTerm($term)
    {
        try {
            $prop = $this->api()->search('properties', ['term' => $term])->getContent();
            return $prop[0] ? (int)$prop[0]->id() : null;
        } catch (\Throwable $e) {
            error_log("Error retrieving property for term $term: " . $e->getMessage());
        }
    }

    /**
     * Collect linked item IDs for timeline purposes (both outgoing & incoming).
     * - If $linkedPropsTerms is null => any property
     * - Else only the listed properties.
     */
    public function collectLinkedItemIdsForTimeline(int $originalItemId, ?array $linkedPropsTerms, int $siteId): array
    {
        $linked = [];

        // 1) Outgoing links: original -> linked
        try {
            /** @var \Omeka\Api\Representation\ItemRepresentation $orig */
            $orig = $this->apiManager->read('items', $originalItemId)->getContent();
            foreach ($orig->values() as $term => $propData) {
                if (is_array($linkedPropsTerms) && !in_array($term, $linkedPropsTerms, true)) {
                    continue;
                }

                // normalize values
                $values = [];
                if (is_array($propData) && array_key_exists('values', $propData)) {
                    $values = is_array($propData['values']) ? $propData['values'] : [];
                } elseif (is_array($propData) && isset($propData[0]) && $propData[0] instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $values = $propData;
                }

                foreach ($values as $v) {
                    if (!$v instanceof \Omeka\Api\Representation\ValueRepresentation) continue;
                    $type = $v->type();
                    if ($type === 'resource' || $type === 'resource:item') {
                        $vr = $v->valueResource();
                        if ($vr instanceof \Omeka\Api\Representation\ItemRepresentation) {
                            $linked[(int)$vr->id()] = true;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 2) Incoming links: linked -> original
        try {
            if (is_array($linkedPropsTerms) && $linkedPropsTerms) {
                // specific properties
                foreach ($linkedPropsTerms as $term) {
                    $pid = $this->getPropertyIdForTerm($term);
                    if (!$pid) {
                        error_log("Error, Property id for term $term not found");
                        continue;
                    }

                    try {
                        $inv = $this->apiManager->search('items', [
                            'site_id'  => $siteId,
                            'limit'    => 1000,
                            'property' => [[
                                'joiner'   => 'and',
                                'property' => $pid,
                                'type'     => 'res',
                                'text'     => (string)$originalItemId,
                            ]],
                            'is_public' => 1,
                        ], ['returnScalar' => 'id'])->getContent();
                    } catch (\Throwable $e) {
                        error_log("Error searching incoming links for property $term (id $pid): " . $e->getMessage());
                    }

                    foreach ($inv as $lid) $linked[(int)$lid] = true;
                }
            } else {
                // any property (Omeka uses 0 for "any")
                try {
                    $invAny = $this->apiManager->search('items', [
                        'site_id'  => $siteId,
                        'limit'    => 1000,
                        'property' => [[
                            'joiner'   => 'and',
                            'property' => '',
                            'type'     => 'res',
                            'text'     => $originalItemId,
                        ]],
                        'is_public' => 1,
                    ], ['returnScalar' => 'id'])->getContent();
                } catch (\Throwable $e) {
                    $invAny = [];
                }

                foreach ($invAny as $lid) $linked[(int)$lid] = true;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $linked;
    }

    private function api()
    {
        if (!$this->apiManager) {
            $sm = $this->formElementManager->getServiceLocator();
            $this->apiManager = $sm->get('Omeka\ApiManager');
        }
        return $this->apiManager;
    }
}
