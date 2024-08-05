<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;

readonly class SymfonyResourceDetector implements ResourceDetectorInterface
{
    public function __construct(private array $attributes)
    {
    }

    public function getResource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create($this->attributes));
    }
}
