<?php
namespace MappingExtensions\Service\Delegator;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use MappingExtensions\Form\Element\CopyCoordinates;
use MappingExtensions\Form\Element\UpdateFeatures;

class FormElementDelegatorFactory implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $container, $name, callable $callback, array $options = null)
    {
        $formElement = $callback();
        $formElement->addClass(CopyCoordinates::class, 'formMappingCopyCoordinates');
        $formElement->addClass(UpdateFeatures::class, 'formMappingUpdateFeatures');
        return $formElement;
    }
}
