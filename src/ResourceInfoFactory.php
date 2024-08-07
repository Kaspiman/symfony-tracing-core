<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle;

use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory as SdkResourceInfoFactory;

class ResourceInfoFactory
{
    public function __construct(
        private ResourceDetectorInterface $resourceDetector,
    )
    {
    }

    public function create(): ResourceInfo
    {
        $defaultResource = SdkResourceInfoFactory::defaultResource();
        return $defaultResource->merge($this->resourceDetector->getResource());
    }
}
