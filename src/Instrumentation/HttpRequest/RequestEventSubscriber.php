<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle\Instrumentation\HttpRequest;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Zim\SymfonyTracingCoreBundle\RootContextProvider;
use Zim\SymfonyTracingCoreBundle\ScopedSpan;
use Zim\SymfonyTracingCoreBundle\ScopedTracerInterface;

readonly class RequestEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ScopedTracerInterface $tracer,
        private RootContextProvider $rootTraceContextProvider,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onRequest', 9999],
            ],
            KernelEvents::CONTROLLER => [
                ['onKernelController', 0],
            ],
            KernelEvents::RESPONSE => [
                ['onKernelResponse', 0],
            ],
            KernelEvents::FINISH_REQUEST => [
                ['onFinishRequest', 0],
            ],
            KernelEvents::EXCEPTION => [
                ['onKernelException', 0],
            ],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        $this->rootTraceContextProvider->reset();
        $rootContext = TraceContextPropagator::getInstance()->extract($request->headers->all());

        $spanName = sprintf('Incoming %s %s', $request->getMethod(), $request->getUri());

        $span = $this->tracer->startSpan(
            name: $spanName,
            spanKing:  SpanKind::KIND_SERVER,
            parentContext: $rootContext
        );

        $request->attributes->set('_span', $span);
        $this->rootTraceContextProvider->set(Context::getCurrent());
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        $spanName = sprintf('Controller %s', $event->getRequest()->attributes->get('_controller'));
        $span = $this->tracer->startSpan($spanName);
        $request->attributes->set('_controller_span', $span);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        $span = $request->attributes->get('_controller_span');

        if ($span instanceof ScopedSpan) {
            $span->end();
            $request->attributes->remove('_controller_span');
        }
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        $span = $request->attributes->get('_span');

        if ($span instanceof ScopedSpan) {
            $span->getSpan()->recordException($event->getThrowable());
            $span->getSpan()->setStatus(StatusCode::STATUS_ERROR);
        }

        $currentSpan = Span::getCurrent();

        if ($currentSpan->getContext()->getSpanId() !== $span->getSpan()->getContext()->getSpanId()) {
            $currentSpan->recordException($event->getThrowable());
            $currentSpan->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        $span = $request->attributes->get('_span');

        if ($span instanceof ScopedSpan) {
            $span->end();
            $request->attributes->remove('_span');
        }

        $this->rootTraceContextProvider->reset();
    }
}
