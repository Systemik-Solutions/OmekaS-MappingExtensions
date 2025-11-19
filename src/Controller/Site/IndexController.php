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

        $featuresPage = (int) $this->params()->fromQuery('features_page', 1);
        if (!empty($blockData['journey']) && ($blockData['journey']['property']) && $blockData['journey']['property'] != '') {
            return $this->getFeatureActionforJourneyItems($blockData, $api, $itemsQuery, $featuresPage);
        }

        // Original items (geo-located candidates)
        $itemIds = $api->search('items', $itemsQuery, ['returnScalar' => 'id'])->getContent();

        if (!empty($blockData['map_linked_items']) && $itemIds) {
            return $this->getFeaturesActionForLinkedItems($itemIds, $blockData, $api, $itemsQuery, $featuresQuery);
        }

        // --- Default behavior (no linked-items mode): use items' own geometry ---
        $featuresQuery['page']     = (int) $this->params()->fromQuery('features_page', 1);
        $featuresQuery['per_page'] = 10000;
        $featuresQuery['item_id']  = $itemIds ?: 0;

        $featureResponse = $api->search('mapping_features', $featuresQuery);

        $features = [];
        $legendMap  = [];

        foreach ($featureResponse->getContent() as $feature) {
            $displayItem = $feature->item();
            $color       = $this->getItemColor($displayItem, $blockData, $api);
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

            // Update legend map
            $this->addLegendForItem($legendMap, $displayItem, $color, $blockData, $api);
        }

        uasort($legendMap, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });
        $legendEntries = array_values($legendMap);

        return new \Laminas\View\Model\JsonModel([
            'features' => $features,
            'legend'   => $legendEntries,
        ]);
    }

    // --- Linked-items mode: show linked items using ORIGINALS' geography ---
    private function getFeaturesActionForLinkedItems($originalItemIds, $blockData, $api, $itemsQuery, $featuresQuery)
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
        $legendMap  = [];

        foreach ($featureResponse->getContent() as $feature) {

            $origItemId = $feature->item()->id();
            if (empty($originalToLinked[$origItemId])) {
                continue;
            }

            // For each linked item, emit a "feature row":
            foreach (array_keys($originalToLinked[$origItemId]) as $linkedId) {
                $displayItem = $this->getItemById($linkedId, $api, $itemCache);
                $color       = $this->getItemColor($displayItem, $blockData, $api);

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

                // update legend from linked item
                $this->addLegendForItem($legendMap, $displayItem, $color, $blockData, $api);
            }
        }

        uasort($legendMap, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });
        $legendEntries = array_values($legendMap);

        return new \Laminas\View\Model\JsonModel([
            'features' => $features,
            'legend'   => $legendEntries,
        ]);
    }

    // --- Journey mode: build features from journey property on ORIGINAL items ---
    private function getFeatureActionforJourneyItems($blockData, $api, $itemsQuery, $featuresPage)
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
        $legendMap  = [];

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
                        $color = $this->getItemColor($originalItem, $blockData, $api);

                        $features[] = [
                            (int) $journeyPlaceMappingFeature->id(), // feature_id 
                            (int) $journeyPlace->id(),              // resource_id 
                            $geography,                             // geography 
                            $color,                                 // color
                        ];

                        $this->addLegendForItem($legendMap, $originalItem, $color, $blockData, $api);
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

                $color = $this->getItemColor($originalItem, $blockData, $api);

                $features[] = [
                    (int) $featureID,
                    (int) $originalItem->id(),
                    $lineFeature,
                    $color //color
                ];
            }
        }

        uasort($legendMap, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });
        $legendEntries = array_values($legendMap);

        return new \Laminas\View\Model\JsonModel([
            'features' => $features,
            'legend'   => $legendEntries,
        ]);
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
    private function getItemColor($item,  $blockData, $api)
    {
        if (!$item) {
            return null;
        }

        $groupMode            = $blockData['group_by_control']['group-by-select'] ?? '';
        $groupByPropertyValue = $blockData['group_by_control']['property_value'] ?? '';
        $colorRows            = $blockData['node_colors']['rows'] ?? [];

        if ($groupMode === 'none') {
            return null;
        }

        // ---------- Group by resource class ----------
        if ($groupMode === 'resource_class') {
            $rc   = $item->resourceClass();
            $rcId = $rc ? $rc->id() : null;
            if (!$rcId) {
                return null;
            }

            $hasConfiguredRow = false;

            foreach ($colorRows as $row) {
                // skip rows with no class set (these are effectively "empty" rows)
                if (empty($row['resource_class'])) {
                    continue;
                }
                $hasConfiguredRow = true;

                if ((int) $row['resource_class'] === (int) $rcId) {
                    return $row['color'] ?? null;
                }
            }

            // color set , return default color
            if (!$hasConfiguredRow) {
                return $this->getAutoColor('rc:' . $rcId);
            }

            return null;
        }

        // ---------- Group by resource template ----------
        if ($groupMode === 'resource_template') {
            $rt   = $item->resourceTemplate();
            $rtId = $rt ? $rt->id() : null;
            if (!$rtId) {
                return null;
            }

            $hasConfiguredRow = false;

            foreach ($colorRows as $row) {
                if (empty($row['resource_template'])) {
                    continue;
                }
                $hasConfiguredRow = true;

                if ((int) $row['resource_template'] === (int) $rtId) {
                    return $row['color'] ?? null;
                }
            }

            // No color set , return default color
            if (!$hasConfiguredRow) {
                return $this->getAutoColor('rt:' . $rtId);
            }

            return null;
        }

        // ---------- Group by property value ----------
        if ($groupMode === 'property_value') {
            $term = trim((string) $groupByPropertyValue);
            if ($term === '') {
                return null;
            }

            // Resolve property id (if possible) for matching configured rows
            $propIdForTerm = null;
            try {
                $propRep = $api->searchOne('properties', ['term' => $term])->getContent();
                if ($propRep) {
                    $propIdForTerm = (int) $propRep->id();
                }
            } catch (\Throwable $e) {
                $propIdForTerm = null;
            }

            // Collect this item's values for the chosen term
            $vals = $item->value($term, ['all' => true]) ?: [];
            if (!$vals) {
                return null;
            }

            $itemTexts = [];
            foreach ($vals as $v) {
                $itemTexts[] = trim((string) ($v->value() ?? (string) $v));
            }

            // Try to match against configured rows (if any)
            $hasConfiguredRow = false;

            foreach ($colorRows as $row) {
                $rowPropId = isset($row['property_value']) ? (int) $row['property_value'] : 0;
                $needle    = trim((string) ($row['property_text'] ?? ''));

                // skip rows that don't target this property or have no text
                if ($needle === '') {
                    continue;
                }
                if ($rowPropId && $propIdForTerm && $rowPropId !== $propIdForTerm) {
                    continue;
                }

                $hasConfiguredRow = true;

                foreach ($itemTexts as $txt) {
                    if (strcasecmp($txt, $needle) === 0) {
                        return $row['color'] ?? null;
                    }
                }
            }

            // If no valid rows configured for this property,
            if (!$hasConfiguredRow) {
                if (!empty($itemTexts)) {
                    $first   = mb_strtolower($itemTexts[0]);
                    $autoKey = 'pv:' . $term . ':' . $first;
                    return $this->getAutoColor($autoKey);
                }
                return '#6699ff';
            }

            return null;
        }
        return null;
    }

    // Build / update legend entries for a given item and color
    private function addLegendForItem(array &$legendMap, $item, ?string $color, array $blockData, $api): void
    {
        if (!$item) {
            return;
        }

        $groupMode            = $blockData['group_by_control']['group-by-select'] ?? 'none';
        $groupByPropertyValue = $blockData['group_by_control']['property_value'] ?? '';
        $colorRows            = $blockData['node_colors']['rows'] ?? [];

        if ($groupMode === 'none' || $groupMode === '') {
            return;
        }

        // ---------- resource_class ----------
        if ($groupMode === 'resource_class') {
            $rc = $item->resourceClass();
            if (!$rc) {
                return;
            }
            $rcId = (int) $rc->id();
            $key  = 'rc:' . $rcId;

            // Check whether ANY row is configured for resource_class
            $hasConfiguredRow = false;
            foreach ($colorRows as $row) {
                if (!empty($row['resource_class'])) {
                    $hasConfiguredRow = true;
                    break;
                }
            }

            // If there are configured rows but THIS item got no color
            // (getItemColor returned null), then this class is not user-configured
            // => skip it in the legend.
            if ($hasConfiguredRow && $color === null) {
                return;
            }

            if (isset($legendMap[$key])) {
                return;
            }

            $label = $rc->label() ?: ('Class #' . $rcId);
            $legendMap[$key] = [
                'label' => $label,
                'color' => $color ?? $this->getAutoColor('rc:' . $rcId),
            ];
            return;
        }

        // ---------- resource_template ----------
        if ($groupMode === 'resource_template') {
            $rt = $item->resourceTemplate();
            if (!$rt) {
                return;
            }
            $rtId = (int) $rt->id();
            $key  = 'rt:' . $rtId;

            // Check whether ANY row is configured for resource_template
            $hasConfiguredRow = false;
            foreach ($colorRows as $row) {
                if (!empty($row['resource_template'])) {
                    $hasConfiguredRow = true;
                    break;
                }
            }

            // If there are configured rows but THIS item got no color
            // => skip it in the legend.
            if ($hasConfiguredRow && $color === null) {
                return;
            }

            if (isset($legendMap[$key])) {
                return;
            }

            $label = $rt->label() ?: ('Template #' . $rtId);
            $legendMap[$key] = [
                'label' => $label,
                'color' => $color ?? $this->getAutoColor('rt:' . $rtId),
            ];
            return;
        }

        // ---------- property_value ----------
        if ($groupMode === 'property_value') {
            $term = trim((string) $groupByPropertyValue);
            if ($term === '') {
                return;
            }

            // Try to resolve property label from term
            $propLabel = $term;
            try {
                $prop = $api->searchOne('properties', ['term' => $term])->getContent();
                if ($prop) {
                    $propLabel = $prop->label();
                }
            } catch (\Throwable $e) {
                // fall back to term
            }

            // Resolve property id (if possible) for matching configured rows
            $propIdForTerm = null;
            try {
                $propRep = $api->searchOne('properties', ['term' => $term])->getContent();
                if ($propRep) {
                    $propIdForTerm = (int) $propRep->id();
                }
            } catch (\Throwable $e) {
                $propIdForTerm = null;
            }

            // Detect if there is at least one *valid* configured row
            $hasConfiguredRow = false;
            foreach ($colorRows as $row) {
                $rowPropId = isset($row['property_value']) ? (int) $row['property_value'] : 0;
                $needle    = trim((string) ($row['property_text'] ?? ''));

                if ($needle === '') {
                    continue;
                }
                if ($rowPropId && $propIdForTerm && $rowPropId !== $propIdForTerm) {
                    continue;
                }

                $hasConfiguredRow = true;
                break;
            }

            if ($hasConfiguredRow) {
                // Use *configured* rows as legend entries (like before)
                foreach ($colorRows as $row) {
                    $textLabel = trim((string) ($row['property_text'] ?? ''));
                    if ($textLabel === '') {
                        continue;
                    }

                    $rowPropId = isset($row['property_value']) ? (int) $row['property_value'] : 0;
                    if ($rowPropId && $propIdForTerm && $rowPropId !== $propIdForTerm) {
                        continue;
                    }

                    $rowColor = $row['color'] ?? $color ?? '#6699ff';
                    $key      = 'pv:' . $term . ':' . mb_strtolower($textLabel);

                    if (isset($legendMap[$key])) {
                        continue;
                    }

                    $legendMap[$key] = [
                        'label' => sprintf('%s: %s', ucfirst($propLabel), $textLabel),
                        'color' => $rowColor,
                    ];
                }
            } else {
                // NO valid rows configured: treat as "default" grouping.
                // Build ONE legend entry based on the FIRST value, same as getItemColor().
                $vals = $item->value($term, ['all' => true]) ?: [];
                if (!$vals) {
                    return;
                }

                $firstText = '';
                foreach ($vals as $v) {
                    $firstText = trim((string) ($v->value() ?? (string) $v));
                    if ($firstText !== '') {
                        break;
                    }
                }
                if ($firstText === '') {
                    return;
                }

                $key = 'pv:' . $term . ':' . mb_strtolower($firstText);
                if (isset($legendMap[$key])) {
                    return;
                }

                $legendMap[$key] = [
                    'label' => sprintf('%s: %s', ucfirst($propLabel), $firstText),
                    'color' => $color ?? $this->getAutoColor($key),
                ];
            }
        }
    }

    private function getAutoColor(string $key): string
    {
        static $palette = [
            '#ff7f0e',
            '#d62728',
            '#9467bd',
            '#8c564b',
            '#271919ff',
            '#bcbd22',
            '#17becf',
            '#393b79',
            '#637939',
            '#3182bd',
            '#31a354',
            '#ef08ffff',
            '#969696',
            '#bcbddc',
            '#c7e9c0',
            '#9e9ac8',
            '#fdd0a2',
            '#a1d99b',
            '#bdbdbd',
            '#140c6aff',
            '#fdae6b',
            '#74c476',
            '#981442ff',
            '#6baed6',
            '#88b199ff',
            '#252525',
            '#3182bd',
            '#636363',
            '#0de6d0ff',
            '#1f3e29ff',
            '#7c158aff',
            '#316987ff',
        ];

        $hash  = crc32($key);
        $index = $hash % count($palette);
        return $palette[$index];
    }
}
