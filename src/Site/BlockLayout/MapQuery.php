<?php

namespace Mapping\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Mapping\Form\BlockLayoutMapQueryForm;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Stdlib\ErrorStore;

class MapQuery extends AbstractMap
{
    public function getLabel()
    {
        return 'Map by query'; // @translate
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $form = $this->formElementManager->get(BlockLayoutMapQueryForm::class);
        $data = $form->prepareBlockData($block->getData());
        $block->setData($data);
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ) {
        $form = $this->formElementManager->get(BlockLayoutMapQueryForm::class);
        $data = $form->prepareBlockData($block ? $block->data() : []);

        $formHtml = [];
        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/default-view', [
            'data' => $data,
            'form' => $form,
        ]);
        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/overlays', [
            'data' => $data,
            'form' => $form,
        ]);
        if ($this->timelineIsAvailable()) {
            $formHtml[] = $view->partial('common/block-layout/mapping-block-form/timeline', [
                'data' => $data,
                'form' => $form,
            ]);
        }
        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/query', [
            'data' => $data,
            'form' => $form,
        ]);

        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/linked-items', [
            'data' => $data,
            'form' => $form,
        ]);

        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/group-by-color', [
            'data' => $data,
            'form' => $form,
            'block' => $block
        ]);

        return implode('', $formHtml);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $form = $this->formElementManager->get(BlockLayoutMapQueryForm::class);
        $data = $form->prepareBlockData($block->data());

        $isTimeline = (bool) $data['timeline']['data_type_properties'];
        $timelineIsAvailable = $this->timelineIsAvailable();

        parse_str($data['query'], $itemsQuery);
        $featuresQuery = [];

        // Get all events for the items/linked-item.
        $events = [];
        if ($isTimeline && $timelineIsAvailable) {
            $itemsQuery['site_id'] = $block->page()->site()->id();
            $itemsQuery['has_features'] = true;
            $itemsQuery['limit'] = 100000;
            $itemIds = $this->apiManager->search('items', $itemsQuery, ['returnScalar' => 'id'])->getContent();

            $useLinked = !empty($data['map_linked_items']);
            $linkedPropsTerms = $useLinked ? $this->normalizeLinkedPropsTerms($data['linked_properties'] ?? null) : null;

            foreach ($itemIds as $itemId) {
                if (!$useLinked) {
                    // Original behavior: use the original item for timeline
                    $event = $this->getTimelineEvent($itemId, $data['timeline']['data_type_properties'], $view);
                    if ($event) $events[] = $event;
                    continue;
                }

                // Linked-items timeline: resolve linked item IDs from this original item
                $linkedIds = $this->collectLinkedItemIdsForTimeline($itemId, $linkedPropsTerms, (int)$itemsQuery['site_id']);

                // Build events from linked items (deduped)
                foreach (array_keys($linkedIds) as $lid) {
                    $event = $this->getTimelineEvent($lid, $data['timeline']['data_type_properties'], $view, false);
                    if ($event) {
                        $events[] = $event;
                    }
                }
            }
        }
        return $view->partial('common/block-layout/mapping-block', [
            'data' => $data,
            'itemsQuery' => $itemsQuery,
            'featuresQuery' => $featuresQuery,
            'isTimeline' => $isTimeline,
            'timelineData' => $this->getTimelineData($events, $data, $view),
            'timelineOptions' => $this->getTimelineOptions($data),
        ]);
    }
}
