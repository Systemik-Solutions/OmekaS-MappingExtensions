<?php
namespace MappingExtensions\Service\BlockLayout;

use Interop\Container\ContainerInterface;
use MappingExtensions\Site\BlockLayout\Map;
use MappingExtensions\Site\BlockLayout\MapGroups;
use MappingExtensions\Site\BlockLayout\MapQuery;
use MappingExtensions\Site\BlockLayout\MapJourney;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MapFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        switch ($requestedName) {
            case 'mappingMapGroups':
                $blockLayout = new MapGroups;
                $blockLayout->setFormElementManager($services->get('FormElementManager'));
                $blockLayout->setConnection($services->get('Omeka\Connection'));
                break;
            case 'mappingMapQuery':
                $blockLayout = new MapQuery;
                $blockLayout->setModuleManager($services->get('Omeka\ModuleManager'));
                $blockLayout->setFormElementManager($services->get('FormElementManager'));
                $blockLayout->setApiManager($services->get('Omeka\ApiManager'));
                break;
            case 'mappingMap':
                $blockLayout = new Map;
                $blockLayout->setModuleManager($services->get('Omeka\ModuleManager'));
                $blockLayout->setFormElementManager($services->get('FormElementManager'));
                break;
            case 'mappingMapJourney':
                $blockLayout = new MapJourney;
                $blockLayout->setModuleManager($services->get('Omeka\ModuleManager'));
                $blockLayout->setFormElementManager($services->get('FormElementManager'));
                break;
        }
        return $blockLayout;
    }
}
