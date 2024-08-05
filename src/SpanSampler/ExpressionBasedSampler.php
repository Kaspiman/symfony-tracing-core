<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle\SpanSampler;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use OpenTelemetry\SDK\Trace\Span;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\RequestStack;

class ExpressionBasedSampler implements SamplerInterface
{
    private readonly ExpressionLanguage $language;

    public function __construct(
        private readonly string $expression,
        private readonly RequestStack $requestStack,
    )
    {
        $this->language = new ExpressionLanguage();
    }

    public function shouldSample(ContextInterface $parentContext, string $traceId, string $spanName, int $spanKind, AttributesInterface $attributes, array $links): SamplingResult
    {
        $parentSpan = Span::fromContext($parentContext);
        $parentSpanContext = $parentSpan->getContext();
        $traceState = $parentSpanContext->getTraceState();

        if ($this->requestStack->getParentRequest() !== null) {
            return new SamplingResult(
                SamplingResult::RECORD_AND_SAMPLE,
                [],
                $traceState
            );
        }

        $request = $this->requestStack->getMainRequest();
        $result = $this->language->evaluate($this->expression, ['request' => $request]);

        if ($result) {
            return new SamplingResult(
                SamplingResult::RECORD_AND_SAMPLE,
                [],
                $traceState
            );
        } else {
            return new SamplingResult(
                SamplingResult::RECORD_ONLY,
                [],
                $traceState
            );
        }
    }

    public function getDescription(): string
    {
        return 'Sample decision based on symfony expression';
    }
}
