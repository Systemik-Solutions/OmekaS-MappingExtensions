<?php

namespace Mapping\Controller\Site;

use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Representation\ItemRepresentation;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function browseAction()
    {
        $itemsQuery = $this->params()->fromQuery();
        unset(
            $itemsQuery['mapping_address'],
            $itemsQuery['mapping_radius'],
            $itemsQuery['mapping_radius_unit']
        );
        if ($this->siteSettings()->get('browse_attached_items', false)) {
            $itemsQuery['site_attachments_only'] = true;
        }

        // Get all features for all items that match the query, if any.
        $featuresQuery = [
            'address' => $this->params()->fromQuery('mapping_address'),
            'radius' => $this->params()->fromQuery('mapping_radius'),
            'radius_unit' => $this->params()->fromQuery('mapping_radius_unit'),
        ];

        $view = new ViewModel;
        $view->setVariable('query', $this->params()->fromQuery());
        $view->setVariable('itemsQuery', $itemsQuery);
        $view->setVariable('featuresQuery', $featuresQuery);
        return $view;
    }

    public function getFeaturesAction()
    {
        // --- Parse incoming queries ---
        $itemsQuery    = json_decode($this->params()->fromQuery('items_query'), true) ?: [];
        $featuresQuery = json_decode($this->params()->fromQuery('features_query'), true) ?: [];
        $blockData     = json_decode($this->params()->fromQuery('block_data'), true) ?: [];

        // --- Scope originals ---
        $itemsQuery['site_id']      = $this->currentSite()->id();
        $itemsQuery['has_features'] = true;
        $itemsQuery['limit']        = 100000;

        $api     = $this->api();

        // Node color specifications
        $groupMode  = $blockData['group_by_control']['group-by-select'] ?? '';
        $colorRows  = $blockData['node_colors']['rows'] ?? [];

        $featuresPage = (int) $this->params()->fromQuery('features_page', 1);
        if (!empty($blockData['journey']) && ($blockData['journey']['property']) && $blockData['journey']['property'] != '') {
            return $this->getFeatureActionforJourneyItems($blockData, $api, $itemsQuery, $featuresPage, $groupMode, $colorRows);
        }

        // Original items (geo-located candidates)
        $itemIds = $api->search('items', $itemsQuery, ['returnScalar' => 'id'])->getContent();

        if (!empty($blockData['map_linked_items']) && $itemIds) {
            return $this->getFeaturesActionForLinkedItems($itemIds, $blockData, $api, $itemsQuery, $featuresQuery, $groupMode, $colorRows);
        }

        // --- Default behavior (no linked-items mode): use items' own geometry ---
        $featuresQuery['page']     = (int) $this->params()->fromQuery('features_page', 1);
        $featuresQuery['per_page'] = 10000;
        $featuresQuery['item_id']  = $itemIds ?: 0;

        $featureResponse = $api->search('mapping_features', $featuresQuery);

        $features = [];
        foreach ($featureResponse->getContent() as $feature) {
            $displayItem = $feature->item();
            $color       = $this->getItemColor($displayItem, $groupMode, $colorRows, $api);
            $geo = $feature->geography();

            if ($geo->getType() == 'Point' && method_exists($geo, 'getLongitude') && method_exists($geo, 'getLatitude')) {
                $featureArray = [
                    'type'        => 'Point',
                    'coordinates' => [$geo->getLongitude(), $geo->getLatitude()],
                    'srid'        => method_exists($geo, 'getSrid') ? $geo->getSrid() : null,
                    'properties'  => [
                        'title' => $displayItem->displayTitle(),
                    ],
                ];
            } else {
                $featureArray = $geo;
            }

            $features[] = [
                $feature->id(),
                $displayItem->id(),
                $featureArray,
                $color,
            ];
        }

        return new \Laminas\View\Model\JsonModel($features);
    }

    public function getFeaturePopupContentAction()
    {
        $featureId   = (int) $this->params()->fromQuery('feature_id');
        $resourceId  = (int) $this->params()->fromQuery('resource_id'); // NEW: linked item id (optional)
        $feature     = $this->api()->read('mapping_features', $featureId)->getContent();

        // Original geo-located item (With geography)
        $originalItem = $feature->item();

        // Determine which item to show in the popup:
        // - if resource_id is provided, prefer that linked item;
        // - otherwise, fall back to the feature's item (original).
        $item = $originalItem;

        if ($resourceId) {
            try {
                $item = $this->api()->read('items', $resourceId)->getContent();
            } catch (\Throwable $e) {
                // ignore and keep $item = $originalItem
            }
        }

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setVariable('feature', $feature);
        $view->setVariable('item', $item);                 // NEW: item to display (linked or original)
        $view->setVariable('originalItem', $originalItem); // NEW: always provide original (geo-located) item


        $popupPropsRaw = $this->params()->fromQuery('popup_props', '[]');
        $popupPropsTerms = json_decode($popupPropsRaw, true);
        if (!is_array($popupPropsTerms)) {
            // fallback for CSV or single string
            $popupPropsTerms = array_values(array_filter(array_map('trim', explode(',', (string) $popupPropsRaw))));
        }
        $view->setVariable('popupPropsTerms', $popupPropsTerms);

        $isJourneyMap = (bool) $this->params()->fromQuery('is_journey_map', false);
        $view->setVariable('isJourneyMap', $isJourneyMap);

        return $view;
    }

    // --- Linked-items mode: show linked items using ORIGINALS' geography ---
    private function getFeaturesActionForLinkedItems($originalItemIds, $blockData, $api, $itemsQuery, $featuresQuery, $groupMode, $colorRows)
    {
        $itemCache = [];
        $propIdCache   = [];

        // get specific linked properties, if null then use all possible relationships
        $linkedPropsTerms = null; // null means: any property
        if (array_key_exists('linked_properties', $blockData)) {
            $raw = $blockData['linked_properties'];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && $decoded) {
                    $linkedPropsTerms = array_values(array_filter(array_map('strval', $decoded)));
                } else {
                    $csv = array_values(array_filter(array_map('trim', explode(',', $raw))));
                    if ($csv) {
                        $linkedPropsTerms = array_values(array_filter(array_map('strval', $csv)));
                    }
                }
            } elseif (is_array($raw) && $raw) {
                $linkedPropsTerms = array_values(array_filter(array_map('strval', $raw)));
            }
        }

        // For each ORIGINAL item, collect LINKED item ids
        $originalToLinked = [];

        foreach ($originalItemIds as $originalItemId) {
            try {
                $origItem = $this->getItemById($originalItemId, $api, $itemCache);
            } catch (\Throwable $e) {
                continue;
            }

            $originalToLinked[$originalItemId] = $originalToLinked[$originalItemId] ?? [];

            // -------- Outgoing links: original -> linked --------
            foreach ($origItem->values() as $term => $propData) {
                if (is_array($linkedPropsTerms) && !in_array($term, $linkedPropsTerms)) {
                    continue;
                }

                $values = [];
                if (is_array($propData) && array_key_exists('values', $propData)) {
                    $values = is_array($propData['values']) ? $propData['values'] : [];
                } elseif (is_array($propData) && isset($propData[0]) && $propData[0] instanceof \Omeka\Api\Representation\ValueRepresentation) {
                    $values = $propData;
                } else {
                    continue;
                }

                foreach ($values as $valueData) {
                    $type = $valueData->type();
                    if ($type === 'resource' || $type === 'resource:item') {
                        $linkedItem = $valueData->valueResource();
                        if (!$linkedItem instanceof ItemRepresentation) {
                            continue;
                        }
                        $itemCache[$linkedItem->id()] = $linkedItem;
                        $originalToLinked[$originalItemId][$linkedItem->id()] = true;
                    }
                }
            }

            // -------- Incoming links: linked -> original --------
            if (is_array($linkedPropsTerms) && $linkedPropsTerms) {
                foreach ($linkedPropsTerms as $term) {
                    $propId = $this->getPropertyID($term, $api, $propIdCache);
                    if (!$propId) continue;

                    $invQuery = [
                        'site_id'  => $itemsQuery['site_id'],
                        'limit'    => 1000,
                        'property' => [[
                            'joiner'   => 'and',
                            'property' => $propId,
                            'type'     => 'res',
                            'text'     => (string) $originalItemId,
                        ]],
                    ];
                    try {
                        $invIds = $api->search('items', $invQuery, ['returnScalar' => 'id'])->getContent();
                        foreach ($invIds as $lid) {
                            $originalToLinked[$originalItemId][$lid] = true;
                        }
                    } catch (\Throwable $e) {
                        // ignore per-property failures
                    }
                }
            } else if ($linkedPropsTerms === null) {
                // If NO property filter , use all possible relations 
                $invAnyQuery = [
                    'site_id'  => $itemsQuery['site_id'],
                    'limit'    => 1000,
                    'property' => [[
                        'joiner'   => 'and',
                        'property' => '',
                        'type'     => 'res',
                        'text'     => (string) $originalItemId,
                    ]],
                    'is_public' => 1, // Only use public data
                ];
                try {
                    $invAnyIds = $api->search('items', $invAnyQuery, ['returnScalar' => 'id'])->getContent();

                    foreach ($invAnyIds as $lid) {
                        $originalToLinked[$originalItemId][$lid] = true;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        // --- Fetch mapping_features for ORIGINALS only (to check geography) ---
        $featuresQuery['page']     = (int) $this->params()->fromQuery('features_page', 1);
        $featuresQuery['per_page'] = 10000;
        $featuresQuery['item_id']  = $originalItemIds ?: 0;

        $featureResponse = $api->search('mapping_features', $featuresQuery);

        $features = [];
        foreach ($featureResponse->getContent() as $feature) {

            $origItemId = $feature->item()->id();
            if (empty($originalToLinked[$origItemId])) {
                continue;
            }

            // For each linked item, emit a "feature row":
            foreach (array_keys($originalToLinked[$origItemId]) as $linkedId) {
                $displayItem = $this->getItemById($linkedId, $api, $itemCache);
                $color       = $this->getItemColor($displayItem, $groupMode, $colorRows, $api);

                $geo = $feature->geography();

                if ($geo->getType() == 'Point' && method_exists($geo, 'getLongitude') && method_exists($geo, 'getLatitude')) {
                    $featureArray = [
                        'type'        => 'Point',
                        'coordinates' => [$geo->getLongitude(), $geo->getLatitude()],
                        'srid'        => method_exists($geo, 'getSrid') ? $geo->getSrid() : null,
                        'properties'  => [
                            'title' => $displayItem->displayTitle(),
                        ],
                    ];
                } else {
                    $featureArray = $geo;
                }

                $features[] = [
                    $feature->id(),
                    (int) $linkedId,
                    $featureArray,
                    $color,
                ];
            }
        }

        return new \Laminas\View\Model\JsonModel($features);
    }

    // --- Journey mode: build features from journey property on ORIGINAL items ---
    private function getFeatureActionforJourneyItems($blockData, $api, $itemsQuery, $featuresPage, $groupMode, $colorRows)
    {
        if ($featuresPage > 1) {
            return new \Laminas\View\Model\JsonModel([]);
        }

        $journeyTerm = (string) ($blockData['journey']['property'] ?? '');

        // We dont need geography of the original items
        unset($itemsQuery['geo']);
        $itemsQuery['has_features']  = false;

        $originalItems = $api->search('items', $itemsQuery)->getContent();

        //For each original item, collect referenced (ordered) resource items from the journey property
        $features = [];

        foreach ($originalItems as $originalItem) {

            $originalItemJourneyValues = $originalItem->value($journeyTerm, ['all' => true]) ?: [];
            if (!$originalItemJourneyValues) {
                continue; // no journey, skip
            }

            if (count($originalItemJourneyValues) <= 1) {
                continue; // no valid journey , skip
            }

            // Collect ordered points for the polyline
            $lineCoords = [];

            $featureID = null;
            foreach ($originalItemJourneyValues as $journeyValue) {

                // only resource:item values participate
                if (!is_object($journeyValue) || !in_array($journeyValue->type(), ['resource', 'resource:item'], true)) {
                    continue;
                }

                $journeyPlace = $journeyValue->valueResource();
                if (!$journeyPlace) continue;

                // Fetch mapping_features of the journey place
                try {
                    $journeyPlaceMappingFeature = $api->search('mapping_features', ['item_id' => $journeyPlace->id()]);
                } catch (\Throwable $e) {
                    continue;
                }

                $journeyPlaceMappingFeatures = $journeyPlaceMappingFeature->getContent();

                // Only use first valid point data if multiple exist
                foreach ($journeyPlaceMappingFeatures as $journeyPlaceMappingFeature) {
                    $geography = $journeyPlaceMappingFeature->geography();

                    if (isset($geography) && $geography->getType() == 'Point') {

                        $lineCoords[] = [$geography->getLongitude(), $geography->getLatitude()];

                        $featureID = $journeyPlaceMappingFeature->id();
                        $features[] = [
                            (int) $journeyPlaceMappingFeature->id(), // feature_id 
                            (int) $journeyPlace->id(),              // resource_id 
                            $geography,                             // geography 
                            $this->getItemColor($originalItem, $groupMode, $colorRows, $api) //color
                        ];
                        break;
                    }
                }
            }

            // Add a polyline feature for the journey itself
            if (count($lineCoords) >= 2) {
                $lineFeature = [
                    'type'        => 'LineString',
                    'coordinates' => $lineCoords,
                    'properties'  => [
                        'title' => $originalItem->id(),
                        'stroke-width' => 6, // custom width
                    ],
                ];

                $features[] = [
                    (int) $featureID,
                    (int) $originalItem->id(),
                    $lineFeature,
                    $this->getItemColor($originalItem, $groupMode, $colorRows, $api) //color
                ];
            }
        }

        return new \Laminas\View\Model\JsonModel($features);
    }

    // Get a item content by its id
    private function getItemById($id, $api, &$itemCache = null)
    {
        if (isset($itemCache[$id])) {
            return $itemCache[$id];
        }
        try {
            $item = $api->read('items', $id)->getContent();
            $itemCache[$id] = $item;
            return $item;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Get a property id by its term name
    private function getPropertyID($term, $api, &$propIdCache = null)
    {
        if (isset($propIdCache[$term])) {
            return $propIdCache[$term];
        }
        try {
            $prop = $api->searchOne('properties', ['term' => $term])->getContent();
            if ($prop) {
                $propIdCache[$term] = (int) $prop->id();
                return $propIdCache[$term];
            }
        } catch (\Throwable $e) {
        }
        $propIdCache[$term] = null;
        return null;
    }

    // Determine item color based on grouping mode and color rows
    private function getItemColor($item,  $groupMode, $colorRows, $api)
    {
        if (!$item || !$groupMode || !$colorRows) return null;
        if ($groupMode === 'resource_class') {
            $rc = $item->resourceClass();
            $rcId = $rc ? $rc->id() : null;
            if (!$rcId) return null;
            foreach ($colorRows as $row) {
                if (!empty($row['resource_class']) && (int)$row['resource_class'] === (int)$rcId) {
                    return $row['color'] ?? null;
                }
            }
            return null;
        }

        if ($groupMode === 'resource_template') {
            $rt = $item->resourceTemplate();
            $rtId = $rt ? $rt->id() : null;
            if (!$rtId) return null;
            foreach ($colorRows as $row) {
                if (!empty($row['resource_template']) && (int)$row['resource_template'] === (int)$rtId) {
                    return $row['color'] ?? null;
                }
            }
            return null;
        }

        if ($groupMode === 'property_value') {
            foreach ($colorRows as $row) {
                $propId = isset($row['property_value']) ? (int) $row['property_value'] : 0;
                $matchText = trim((string) ($row['property_text'] ?? ''));
                if ($propId <= 0 || $matchText === '') {
                    continue;
                }

                $term = null;
                try {
                    $prop = $api->read('properties', $propId)->getContent();
                    if ($prop) {
                        $term = $prop->term(); 
                    }
                } catch (\Throwable $e) {
                    $term = null;
                }
                if (!$term) {
                    continue;
                }

                // Get all literal values for this property
                $values = $item->value($term, ['all' => true]);
                if (!$values) {
                    continue;
                }

                foreach ($values as $v) {
                    $val = trim((string) ($v->value() ?? (string) $v));
                    if (strcasecmp($val, $matchText) === 0) {
                        return $row['color'] ?? null; 
                    }
                }
            }
            return null;
        }

        return null;
    }
}
