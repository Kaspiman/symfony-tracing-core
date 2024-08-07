<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;

class ScopedTracer implements ScopedTracerInterface
{
    public function __construct(
        private TracerInterface $inner
    )
    {
    }

    public function startSpan(
        string $name,
        int $spanKing = SpanKind::KIND_INTERNAL,
        ?ContextInterface $parentContext = null,
    ): ScopedSpan
    {
        $span = $this->inner
            ->spanBuilder($name)
            ->setSpanKind($spanKing)
            ->setParent($parentContext)
            ->startSpan()
        ;

        $scope = $span->activate();

        return new ScopedSpan($span, $scope);
    }
}
