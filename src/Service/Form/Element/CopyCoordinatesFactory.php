<?php
namespace MappingExtensions\Service\Form\Element;

use Interop\Container\ContainerInterface;
use MappingExtensions\Form\Element\CopyCoordinates;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CopyCoordinatesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $element = new CopyCoordinates;
        $element->setFormElementManager($services->get('FormElementManager'));
        return $element;
    }
}
