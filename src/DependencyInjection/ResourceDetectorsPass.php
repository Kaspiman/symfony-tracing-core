<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle\DependencyInjection;

use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ResourceDetectorsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $detectors = $container->findTaggedServiceIds('tracing.resource_detector');
        $detectorDefinitions = [];

        foreach ($detectors as $id => $tags) {
            $detectorDefinitions[] = new Reference($id);
        }

        $compositeDetector = $container->getDefinition(ResourceDetectorInterface::class);
        $compositeDetector->setArgument('$resourceDetectors', $detectorDefinitions);
    }
}
