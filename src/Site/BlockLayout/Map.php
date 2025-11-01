<?php

namespace Mapping\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Mapping\Form\BlockLayoutMapForm;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Stdlib\ErrorStore;

class Map extends AbstractMap
{
    public function getLabel()
    {
        return 'Map by attachments'; // @translate
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $form = $this->formElementManager->get(BlockLayoutMapForm::class);
        $data = $form->prepareBlockData($block->getData());
        $block->setData($data);

        // Validate attachments.
        $itemIds = [];
        $attachments = $block->getAttachments();
        foreach ($attachments as $attachment) {
            // When an item was removed from the base, it should be removed.
            $item = $attachment->getItem();
            if (!$item) {
                $attachments->removeElement($attachment);
                continue;
            }
            // Duplicate items are redundant, so remove them.
            $itemId = $item->getId();
            if (in_array($itemId, $itemIds)) {
                $attachments->removeElement($attachment);
            }
            $itemIds[] = $itemId;
            // Media and caption are unneeded.
            $attachment->setMedia(null);
            $attachment->setCaption('');
        }
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ) {
        $form = $this->formElementManager->get(BlockLayoutMapForm::class);
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
        $formHtml[] = $view->blockAttachmentsForm($block, true, ['has_features' => true]);


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
        $form = $this->formElementManager->get(BlockLayoutMapForm::class);
        $data = $form->prepareBlockData($block->data());

        $isTimeline = (bool) $data['timeline']['data_type_properties'];
        $timelineIsAvailable = $this->timelineIsAvailable();

        $itemIds = [];
        foreach ($block->attachments() as $attachment) {
            $item = $attachment->item();
            if (!$item) {
                continue;
            }
            $itemIds[] = $item->id();
        }
        // An empty string would get all features, so set 0 if there are no items.
        $itemsQuery = ['id' => $itemIds ? implode(',', $itemIds) : 0];
        $featuresQuery = [];

        // Get all events for the items/linked-item.
        $events = [];
        if ($isTimeline && $timelineIsAvailable) {

            $services = $view->getHelperPluginManager()->getServiceLocator();
            $this->setApiManager($services->get('Omeka\ApiManager'));

            $useLinked = !empty($data['map_linked_items']);
            $linkedPropsTerms = $useLinked ? $this->normalizeLinkedPropsTerms($data['linked_properties'] ?? null) : null;

            foreach ($itemIds as $itemId) {
                if (!$useLinked) {
                    // Original behavior: use the original item for timeline
                    $event = $this->getTimelineEvent($itemId, $data['timeline']['data_type_properties'], $view);
                    if ($event) {
                        $events[] = $event;
                    }
                    continue;
                }

                // Linked-items timeline: resolve linked item IDs from this original item
                $linkedIds = $this->collectLinkedItemIdsForTimeline($itemId, $linkedPropsTerms, (int) $block->page()->site()->id());

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
