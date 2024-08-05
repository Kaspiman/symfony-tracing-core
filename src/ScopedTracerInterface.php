<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;

interface ScopedTracerInterface
{
    public function startSpan(
        string $name,
        int $spanKing = SpanKind::KIND_INTERNAL,
        ?ContextInterface $parentContext = null,
    ): ScopedSpan;
}
