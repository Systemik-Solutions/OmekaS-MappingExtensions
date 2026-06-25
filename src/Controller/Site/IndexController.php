<?php

namespace MappingExtensions\Controller\Site;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Site-facing controller for browsing and retrieving mapping features.
 *
 * Handles map browse pages, feature retrieval (default, linked-items,
 * and journey modes), and popup content rendering.
 */
class IndexController extends AbstractActionController
{
    /** @var int Maximum items returned in a broad search. */
    private const ITEMS_LIMIT = 100000;

    /** @var int Maximum features per page. */
    private const FEATURES_PER_PAGE = 10000;

    /** @var int Maximum linked/inverse items per query. */
    private const LINKED_ITEMS_LIMIT = 1000;

    /** @var string Fallback color when no grouping color is configured. */
    private const DEFAULT_COLOR = '#6699ff';

    /** @var int Default stroke width for journey polylines. */
    private const JOURNEY_STROKE_WIDTH = 6;

    /**
     * Browse action: renders the map browse page with query parameters.
     *
     * @return ViewModel
     */
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

        $view = new ViewModel();
        $view->setVariable('query', $this->params()->fromQuery());
        $view->setVariable('itemsQuery', $itemsQuery);
        $view->setVariable('featuresQuery', $featuresQuery);
        return $view;
    }

    /**
     * Retrieve map features as JSON for the current site.
     *
     * Delegates to journey or linked-items mode when applicable.
     *
     * @return JsonModel
     */
    public function getFeaturesAction()
    {
        // --- Parse incoming queries ---
        $itemsQuery    = json_decode($this->params()->fromQuery('items_query'), true) ?: [];
        $featuresQuery = json_decode($this->params()->fromQuery('features_query'), true) ?: [];
        $blockData     = json_decode($this->params()->fromQuery('block_data'), true) ?: [];

        // --- Scope originals ---
        $itemsQuery['site_id']      = $this->currentSite()->id();
        $itemsQuery['has_features'] = true;
        $itemsQuery['limit']        = self::ITEMS_LIMIT;

        $api = $this->api();

        $featuresPage = (int) $this->params()->fromQuery('features_page', 1);
        if (!empty($blockData['journey'])
            && ($blockData['journey']['property'])
            && $blockData['journey']['property'] !== ''
        ) {
            return $this->getFeatureActionForJourneyItems(
                $blockData, $api, $itemsQuery, $featuresPage
            );
        }

        // Original items (geo-located candidates)
        $itemIds = $api->search('items', $itemsQuery, ['returnScalar' => 'id'])->getContent();

        if (!empty($blockData['map_linked_items']) && $itemIds) {
            return $this->getFeaturesActionForLinkedItems(
                $itemIds, $blockData, $api, $itemsQuery, $featuresQuery
            );
        }

        // --- Default behavior (no linked-items mode): use items' own geometry ---
        $featuresQuery['page']     = (int) $this->params()->fromQuery('features_page', 1);
        $featuresQuery['per_page'] = self::FEATURES_PER_PAGE;
        $featuresQuery['item_id']  = $itemIds ?: 0;

        $featureResponse = $api->search('mapping_features', $featuresQuery);

        $features  = [];
        $legendMap = [];

        foreach ($featureResponse->getContent() as $feature) {
            $displayItem = $feature->item();
            $color       = $this->getItemColor($displayItem, $blockData, $api);
            $geo         = $feature->geography();
            $featureArray = $this->buildFeatureArray($geo, $displayItem->displayTitle());

            $features[] = [
                $feature->id(),
                $displayItem->id(),
                $featureArray,
                $color,
                $displayItem->url(),
            ];

            $this->addLegendForItem($legendMap, $displayItem, $color, $blockData, $api);
        }

        return $this->buildFeaturesJsonResponse($features, $legendMap);
    }

    /**
     * Linked-items mode: show linked items using originals' geography.
     *
     * @param int[]  $originalItemIds IDs of geo-located original items
     * @param array  $blockData       Block configuration data
     * @param mixed  $api             Omeka API manager
     * @param array  $itemsQuery      Base items query
     * @param array  $featuresQuery   Base features query
     * @return JsonModel
     */
    private function getFeaturesActionForLinkedItems(
        $originalItemIds,
        $blockData,
        $api,
        $itemsQuery,
        $featuresQuery
    ) {
        $itemCache     = [];
        $propIdCache   = [];

        $linkedPropsTerms = null;
        if (array_key_exists('linked_properties', $blockData)) {
            $linkedPropsTerms = $this->parseLinkedProperties(
                $blockData['linked_properties']
            );
        }

        // For each ORIGINAL item, collect LINKED item ids
        $originalToLinked = [];

        foreach ($originalItemIds as $originalItemId) {
            try {
                $origItem = $this->getItemById($originalItemId, $api, $itemCache);
            } catch (\Exception $e) {
                $this->logger()->warn(
                    sprintf('Failed to load item %d: %s', $originalItemId, $e->getMessage())
                );
                continue;
            }

            $originalToLinked[$originalItemId] = $originalToLinked[$originalItemId] ?? [];

            // -------- Outgoing links: original -> linked --------
            foreach ($origItem->values() as $term => $propData) {
                if (is_array($linkedPropsTerms)
                    && !in_array($term, $linkedPropsTerms)
                ) {
                    continue;
                }

                $values = [];
                if (is_array($propData) && array_key_exists('values', $propData)) {
                    $values = is_array($propData['values'])
                        ? $propData['values']
                        : [];
                } elseif (is_array($propData)
                    && isset($propData[0])
                    && $propData[0] instanceof ValueRepresentation
                ) {
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
                    if (!$propId) {
                        continue;
                    }

                    $invQuery = [
                        'site_id'  => $itemsQuery['site_id'],
                        'limit'    => self::LINKED_ITEMS_LIMIT,
                        'property' => [[
                            'joiner'   => 'and',
                            'property' => $propId,
                            'type'     => 'res',
                            'text'     => (string) $originalItemId,
                        ]],
                    ];
                    try {
                        $invIds = $api->search(
                            'items', $invQuery, ['returnScalar' => 'id']
                        )->getContent();
                        foreach ($invIds as $lid) {
                            $originalToLinked[$originalItemId][$lid] = true;
                        }
                    } catch (\Exception $e) {
                        $this->logger()->warn(
                            sprintf('Inverse link lookup failed for property %s: %s', $term, $e->getMessage())
                        );
                    }
                }
            } elseif ($linkedPropsTerms === null) {
                // No property filter: use all possible relations
                $invAnyQuery = [
                    'site_id'  => $itemsQuery['site_id'],
                    'limit'    => self::LINKED_ITEMS_LIMIT,
                    'property' => [[
                        'joiner'   => 'and',
                        'property' => '',
                        'type'     => 'res',
                        'text'     => (string) $originalItemId,
                    ]],
                    'is_public' => 1,
                ];
                try {
                    $invAnyIds = $api->search(
                        'items', $invAnyQuery, ['returnScalar' => 'id']
                    )->getContent();

                    foreach ($invAnyIds as $lid) {
                        $originalToLinked[$originalItemId][$lid] = true;
                    }
                } catch (\Exception $e) {
                    $this->logger()->warn(
                        sprintf('Inverse link lookup failed for item %d: %s', $originalItemId, $e->getMessage())
                    );
                }
            }
        }

        // --- Fetch mapping_features for ORIGINALS only ---
        $featuresQuery['page']     = (int) $this->params()->fromQuery('features_page', 1);
        $featuresQuery['per_page'] = self::FEATURES_PER_PAGE;
        $featuresQuery['item_id']  = $originalItemIds ?: 0;

        $featureResponse = $api->search('mapping_features', $featuresQuery);

        $features  = [];
        $legendMap = [];

        foreach ($featureResponse->getContent() as $feature) {

            $origItemId = $feature->item()->id();
            if (empty($originalToLinked[$origItemId])) {
                continue;
            }

            // For each linked item, emit a feature row
            foreach (array_keys($originalToLinked[$origItemId]) as $linkedId) {
                $displayItem  = $this->getItemById($linkedId, $api, $itemCache);
                $color        = $this->getItemColor($displayItem, $blockData, $api);
                $geo          = $feature->geography();
                $featureArray = $this->buildFeatureArray($geo, $displayItem->displayTitle());

                $features[] = [
                    $feature->id(),
                    (int) $linkedId,
                    $featureArray,
                    $color,
                    $displayItem->url(),
                ];

                $this->addLegendForItem($legendMap, $displayItem, $color, $blockData, $api);
            }
        }

        return $this->buildFeaturesJsonResponse($features, $legendMap);
    }

    /**
     * Journey mode: build features from journey property on original items.
     *
     * @param array $blockData    Block configuration data
     * @param mixed $api          Omeka API manager
     * @param array $itemsQuery   Base items query
     * @param int   $featuresPage Current features page
     * @return JsonModel
     */
    private function getFeatureActionForJourneyItems(
        $blockData,
        $api,
        $itemsQuery,
        $featuresPage
    ) {
        if ($featuresPage > 1) {
            return new JsonModel([]);
        }

        $journeyTerm = (string) ($blockData['journey']['property'] ?? '');

        unset($itemsQuery['geo']);
        $itemsQuery['has_features'] = false;

        $originalItems = $api->search('items', $itemsQuery)->getContent();

        $features  = [];
        $legendMap = [];

        foreach ($originalItems as $originalItem) {

            $originalItemJourneyValues = $originalItem->value(
                $journeyTerm, ['all' => true]
            ) ?: [];
            if (!$originalItemJourneyValues) {
                continue;
            }

            if (count($originalItemJourneyValues) <= 1) {
                continue;
            }

            $lineCoords = [];
            $featureID  = null;

            foreach ($originalItemJourneyValues as $journeyValue) {

                if (!is_object($journeyValue)
                    || !in_array($journeyValue->type(), ['resource', 'resource:item'], true)
                ) {
                    continue;
                }

                $journeyPlace = $journeyValue->valueResource();
                if (!$journeyPlace) {
                    continue;
                }

                try {
                    $journeyPlaceMappingFeature = $api->search(
                        'mapping_features', ['item_id' => $journeyPlace->id()]
                    );
                } catch (\Exception $e) {
                    $this->logger()->warn(sprintf(
                        'Failed to load mapping features for place %d: %s',
                        $journeyPlace->id(),
                        $e->getMessage()
                    ));
                    continue;
                }

                $journeyPlaceMappingFeatures = $journeyPlaceMappingFeature->getContent();

                foreach ($journeyPlaceMappingFeatures as $journeyPlaceMappingFeature) {
                    $geography = $journeyPlaceMappingFeature->geography();

                    if (isset($geography) && $geography->getType() === 'Point') {

                        $lineCoords[] = [
                            $geography->getLongitude(),
                            $geography->getLatitude(),
                        ];

                        $featureID = $journeyPlaceMappingFeature->id();
                        $color = $this->getItemColor($originalItem, $blockData, $api);

                        $features[] = [
                            (int) $journeyPlaceMappingFeature->id(),
                            (int) $journeyPlace->id(),
                            $this->buildFeatureArray($geography, $journeyPlace->displayTitle()),
                            $color,
                            $journeyPlace->url(),
                        ];

                        $this->addLegendForItem(
                            $legendMap, $originalItem, $color, $blockData, $api
                        );
                        break;
                    }
                }
            }

            if (count($lineCoords) >= 2) {
                $lineFeature = [
                    'type'        => 'LineString',
                    'coordinates' => $lineCoords,
                    'properties'  => [
                        'title'        => $originalItem->id(),
                        'stroke-width' => self::JOURNEY_STROKE_WIDTH,
                    ],
                ];

                $color = $this->getItemColor($originalItem, $blockData, $api);

                $features[] = [
                    (int) $featureID,
                    (int) $originalItem->id(),
                    $lineFeature,
                    $color,
                    $originalItem->url(),
                ];
            }
        }

        return $this->buildFeaturesJsonResponse($features, $legendMap);
    }


    /**
     * Render the popup content for a single mapping feature.
     *
     * @return ViewModel
     */
    public function getFeaturePopupContentAction()
    {
        $featureId  = (int) $this->params()->fromQuery('feature_id');
        $resourceId = (int) $this->params()->fromQuery('resource_id');
        $feature    = $this->api()->read('mapping_features', $featureId)->getContent();

        $originalItem = $feature->item();

        $item = $originalItem;

        if ($resourceId) {
            try {
                $item = $this->api()->read('items', $resourceId)->getContent();
            } catch (\Exception $e) {
                $this->logger()->warn(
                    sprintf('Failed to load linked item %d for popup: %s', $resourceId, $e->getMessage())
                );
            }
        }

        $isSidebarContent = (bool) $this->params()->fromQuery('sidebar_content', false);
        $sidebarContentOptions = $this->getSidebarContentOptions();

        $view = new ViewModel();
        $view->setTerminal(true);
        $view->setTemplate($isSidebarContent
            ? 'mapping/site/index/get-feature-sidebar-content'
            : 'mapping/site/index/get-feature-popup-content'
        );
        $view->setVariable('feature', $feature);
        $view->setVariable('item', $item);
        $view->setVariable('originalItem', $originalItem);

        $popupPropsRaw = $this->params()->fromQuery('popup_props', '[]');
        $popupPropsTerms = json_decode($popupPropsRaw, true);
        if (!is_array($popupPropsTerms)) {
            $popupPropsTerms = array_values(
                array_filter(array_map('trim', explode(',', (string) $popupPropsRaw)))
            );
        }
        // Validate terms: only allow strings matching property term format
        $popupPropsTerms = array_values(array_filter($popupPropsTerms, function ($term) {
            return is_string($term)
                && preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*:[a-zA-Z][a-zA-Z0-9_-]*$/', $term);
        }));
        $view->setVariable('popupPropsTerms', $popupPropsTerms);

        $isJourneyMap = (bool) $this->params()->fromQuery('is_journey_map', false);
        $view->setVariable('isJourneyMap', $isJourneyMap);
        $view->setVariable('sidebarContentOptions', $sidebarContentOptions);
        $view->setVariable('showMedia', in_array('media', $sidebarContentOptions, true));
        $view->setVariable('showProperties', in_array('property', $sidebarContentOptions, true));
        $view->setVariable('showLinkedFrom', in_array('linked_from', $sidebarContentOptions, true));
        $view->setVariable('showExternalLink', in_array('external_link', $sidebarContentOptions, true));

        $relatedItems = [];
        if ($isSidebarContent && in_array('linked_from', $sidebarContentOptions, true) && $item) {
            try {
                $relatedItems = $this->api()->search('items', [
                    'property' => [[
                        'joiner'   => 'and',
                        'property' => 0,
                        'type'     => 'res',
                        'text'     => $item->id(),
                    ]],
                    'per_page'  => 999,
                    'page'      => 1,
                    'is_public' => 1,
                ])->getContent();
            } catch (\Exception $e) {
                $this->logger()->warn(sprintf(
                    'Failed to load linked-from items for item %d: %s',
                    $item->id(),
                    $e->getMessage()
                ));
            }
        }
        $view->setVariable('relatedItems', $relatedItems);

        return $view;
    }

    private function getSidebarContentOptions(): array
    {
        $raw = $this->params()->fromQuery('sidebar_content_options', '[]');
        $options = json_decode($raw, true);
        if (!is_array($options)) {
            $options = array_filter(array_map('trim', explode(',', (string) $raw)));
        }

        $allowed = ['media', 'property', 'linked_from', 'external_link'];
        return array_values(array_intersect($allowed, array_unique(array_map(static function ($option) {
            return trim((string) $option);
        }, $options))));
    }

    /**
     * Retrieve an item by ID, using an in-memory cache.
     *
     * @param int        $id        Item ID
     * @param mixed      $api       Omeka API manager
     * @param array|null $itemCache Reference to item cache
     * @return ItemRepresentation|null
     */
    private function getItemById($id, $api, &$itemCache = null)
    {
        if (isset($itemCache[$id])) {
            return $itemCache[$id];
        }
        try {
            $item = $api->read('items', $id)->getContent();
            $itemCache[$id] = $item;
            return $item;
        } catch (\Exception $e) {
            $this->logger()->debug(
                sprintf('Could not load item %d: %s', $id, $e->getMessage())
            );
            return null;
        }
    }

    /**
     * Resolve a property term (e.g. "dcterms:title") to its numeric ID.
     *
     * @param string     $term         Property term
     * @param mixed      $api          Omeka API manager
     * @param array|null $propIdCache  Reference to property ID cache
     * @return int|null
     */
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
        } catch (\Exception $e) {
            $this->logger()->debug(
                sprintf('Could not resolve property term "%s": %s', $term, $e->getMessage())
            );
        }
        $propIdCache[$term] = null;
        return null;
    }

    /**
     * Determine item color based on grouping mode and color configuration.
     *
     * @param ItemRepresentation|null $item      The item to color
     * @param array                   $blockData Block configuration data
     * @param mixed                   $api       Omeka API manager
     * @return string|null Hex color string or null
     */
    private function getItemColor($item, $blockData, $api)
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
                if (empty($row['resource_class'])) {
                    continue;
                }
                $hasConfiguredRow = true;

                if ((int) $row['resource_class'] === (int) $rcId) {
                    return $row['color'] ?? null;
                }
            }

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

            $propIdForTerm = null;
            try {
                $propRep = $api->searchOne('properties', ['term' => $term])->getContent();
                if ($propRep) {
                    $propIdForTerm = (int) $propRep->id();
                }
            } catch (\Exception $e) {
                $propIdForTerm = null;
            }

            $vals = $item->value($term, ['all' => true]) ?: [];
            if (!$vals) {
                return null;
            }

            $itemTexts = [];
            foreach ($vals as $v) {
                $itemTexts[] = trim((string) ($v->value() ?? (string) $v));
            }

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

                foreach ($itemTexts as $txt) {
                    if (strcasecmp($txt, $needle) === 0) {
                        return $row['color'] ?? null;
                    }
                }
            }

            if (!$hasConfiguredRow) {
                if (!empty($itemTexts)) {
                    $first   = mb_strtolower($itemTexts[0]);
                    $autoKey = 'pv:' . $term . ':' . $first;
                    return $this->getAutoColor($autoKey);
                }
                return self::DEFAULT_COLOR;
            }

            return null;
        }
        return null;
    }

    /**
     * Build or update legend entries for a given item and color.
     *
     * @param array                   $legendMap Reference to legend entries map
     * @param ItemRepresentation|null $item      The item to add to legend
     * @param string|null             $color     Hex color string
     * @param array                   $blockData Block configuration data
     * @param mixed                   $api       Omeka API manager
     */
    private function addLegendForItem(
        array &$legendMap,
        $item,
        ?string $color,
        array $blockData,
        $api
    ): void {
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

            $hasConfiguredRow = false;
            foreach ($colorRows as $row) {
                if (!empty($row['resource_class'])) {
                    $hasConfiguredRow = true;
                    break;
                }
            }

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

            $hasConfiguredRow = false;
            foreach ($colorRows as $row) {
                if (!empty($row['resource_template'])) {
                    $hasConfiguredRow = true;
                    break;
                }
            }

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

            // Resolve property label and ID in a single API call
            $propLabel     = $term;
            $propIdForTerm = null;
            try {
                $prop = $api->searchOne('properties', ['term' => $term])->getContent();
                if ($prop) {
                    $propLabel     = $prop->label();
                    $propIdForTerm = (int) $prop->id();
                }
            } catch (\Exception $e) {
                $this->logger()->debug(
                    sprintf('Could not resolve property "%s" for legend: %s', $term, $e->getMessage())
                );
            }

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
                foreach ($colorRows as $row) {
                    $textLabel = trim((string) ($row['property_text'] ?? ''));
                    if ($textLabel === '') {
                        continue;
                    }

                    $rowPropId = isset($row['property_value']) ? (int) $row['property_value'] : 0;
                    if ($rowPropId && $propIdForTerm && $rowPropId !== $propIdForTerm) {
                        continue;
                    }

                    $rowColor = $row['color'] ?? $color ?? self::DEFAULT_COLOR;
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

    /**
     * Deterministically assign a color from a fixed palette based on a key.
     *
     * @param string $key Grouping key used to select a palette color
     * @return string Hex color string
     */
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
        $index = abs($hash) % count($palette);
        return $palette[$index];
    }

    /**
     * Convert a geography object into a feature array suitable for JSON output.
     *
     * @param mixed  $geo   Geography object from a mapping feature
     * @param string $title Display title for Point features
     * @return array|mixed Feature array or raw geography object
     */
    private function buildFeatureArray($geo, string $title = '')
    {
        if ($geo->getType() === 'Point'
            && method_exists($geo, 'getLongitude')
            && method_exists($geo, 'getLatitude')
        ) {
            return [
                'type'        => 'Point',
                'coordinates' => [$geo->getLongitude(), $geo->getLatitude()],
                'srid'        => method_exists($geo, 'getSrid') ? $geo->getSrid() : null,
                'properties'  => [
                    'title' => $title,
                ],
            ];
        }
        return $geo;
    }

    /**
     * Sort the legend map and return a JsonModel with features and legend.
     *
     * @param array $features  Feature rows
     * @param array $legendMap Legend entries keyed by grouping key
     * @return JsonModel
     */
    private function buildFeaturesJsonResponse(array $features, array $legendMap): JsonModel
    {
        uasort($legendMap, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });
        $legendEntries = array_values($legendMap);

        return new JsonModel([
            'features' => $features,
            'legend'   => $legendEntries,
        ]);
    }

    /**
     * Parse linked_properties block data into an array of property terms.
     *
     * Accepts JSON-encoded arrays, CSV strings, or plain arrays.
     * Returns null if the input is empty (meaning "any property").
     *
     * @param mixed $raw Raw linked_properties value from block data
     * @return string[]|null Array of property term strings, or null
     */
    private function parseLinkedProperties($raw): ?array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded) {
                return array_values(array_filter(array_map('strval', $decoded)));
            }
            $csv = array_values(array_filter(array_map('trim', explode(',', $raw))));
            if ($csv) {
                return array_values(array_filter(array_map('strval', $csv)));
            }
        } elseif (is_array($raw) && $raw) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        return null;
    }
}
