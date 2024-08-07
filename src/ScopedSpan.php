<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;

class ScopedSpan
{
    public function __construct(
        private SpanInterface $span,
        private ScopeInterface $scope,
    )
    {
    }

    public function getSpan(): SpanInterface
    {
        return $this->span;
    }

    public function getScope(): ScopeInterface
    {
        return $this->scope;
    }

    public function end(): void
    {
        $this->scope->detach();
        $this->span->end();
    }
}
