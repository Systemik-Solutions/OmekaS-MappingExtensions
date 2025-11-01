<?php

namespace Mapping\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Mapping\Form\BlockLayoutMapJourneyForm;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Stdlib\ErrorStore;

class MapJourney extends AbstractMap
{
    public function getLabel()
    {
        return 'Journey map'; // @translate
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $form = $this->formElementManager->get(BlockLayoutMapJourneyForm::class);
        $data = $form->prepareBlockData($block->getData());
        $block->setData($data);
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ) {
        $form = $this->formElementManager->get(BlockLayoutMapJourneyForm::class);
        $data = $form->prepareBlockData($block ? $block->data() : []);

        $groupFs  = $form->get('group_by_control');
        $groupFs->setName('o:block[__blockIndex__][o:data][group_by_control]');

        $colorsFs = $form->get('node_colors');
        $colorsFs->setName('o:block[__blockIndex__][o:data][node_colors]');

        $formHtml = [];
        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/default-view', [
            'data' => $data,
            'form' => $form,
        ]);
        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/overlays', [
            'data' => $data,
            'form' => $form,
        ]);
        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/query', [
            'data' => $data,
            'form' => $form,
        ]);
        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/journey', [
            'data' => $data,
            'form' => $form,
        ]);
        $formHtml[] = $view->partial('common/block-layout/mapping-block-form/group-by-color', [
            'data' => $data,
            'form' => $form,
            'block' => $block,
        ]);

        return implode('', $formHtml);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $form = $this->formElementManager->get(BlockLayoutMapJourneyForm::class);
        $data = $form->prepareBlockData($block->data());

        parse_str($data['query'], $itemsQuery);
        $featuresQuery = [];


        return $view->partial('common/block-layout/mapping-block', [
            'data' => $data,
            'itemsQuery' => $itemsQuery,
            'featuresQuery' => $featuresQuery,
        ]);
    }
}
