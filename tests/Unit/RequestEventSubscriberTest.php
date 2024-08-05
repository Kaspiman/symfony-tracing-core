<?php
declare(strict_types=1);

namespace Tests;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zim\SymfonyTracingCoreBundle\Instrumentation\HttpRequest\RequestEventSubscriber;
use Zim\SymfonyTracingCoreBundle\RootContextProvider;
use Zim\SymfonyTracingCoreBundle\ScopedSpan;
use Zim\SymfonyTracingCoreBundle\ScopedTracerInterface;

class RequestEventSubscriberTest extends TestCase
{
    private RequestEventSubscriber $requestEventSubscriber;
    private ScopedTracerInterface|MockObject $scopedTracer;

    private RootContextProvider $rootContextProvider;

    protected function setUp(): void
    {
        $this->scopedTracer = $this->createMock(ScopedTracerInterface::class);
        $this->rootContextProvider = new RootContextProvider();

        $this->requestEventSubscriber = new RequestEventSubscriber(
            $this->scopedTracer,
            $this->rootContextProvider,
        );
    }

    public function testRequestAndFinish(): void
    {
        $span = $this->createMock(ScopedSpan::class);

        $span
            ->expects($this->once())
            ->method('end')
        ;

        $this->scopedTracer
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($span)
        ;

        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/test');

        $this->requestEventSubscriber->onRequest(
            new RequestEvent(
                $httpKernel,
                $request,
                HttpKernelInterface::MAIN_REQUEST,
            )
        );

        $this->assertEquals(true, $this->rootContextProvider->hasContext());

        $this->requestEventSubscriber->onFinishRequest(
            new FinishRequestEvent(
                $httpKernel,
                $request,
                HttpKernelInterface::MAIN_REQUEST,
            )
        );

        $this->assertEquals(false, $this->rootContextProvider->hasContext());
    }

    public function testRequestAndError(): void
    {
        $scopedSpan = $this->createMock(ScopedSpan::class);
        $realSpan = $this->createMock(SpanInterface::class);

        $scopedSpan
            ->expects($this->once())
            ->method('end')
        ;

        $scopedSpan
            ->expects($this->any())
            ->method('getSpan')
            ->willReturn($realSpan)
        ;

        $this->scopedTracer
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($scopedSpan)
        ;

        $realSpan
            ->expects($this->once())
            ->method('setStatus')
            ->with(StatusCode::STATUS_ERROR)
        ;

        $realSpan
            ->expects($this->once())
            ->method('recordException')
        ;

        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/test');

        $this->requestEventSubscriber->onRequest(
            new RequestEvent(
                $httpKernel,
                $request,
                HttpKernelInterface::MAIN_REQUEST,
            )
        );

        $this->assertTrue($this->rootContextProvider->hasContext());

        $this->requestEventSubscriber->onKernelException(
            new ExceptionEvent(
                $httpKernel,
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                new \Exception('Test exception')
            )
        );

        $this->requestEventSubscriber->onFinishRequest(
            new FinishRequestEvent(
                $httpKernel,
                $request,
                HttpKernelInterface::MAIN_REQUEST,
            )
        );

        $this->assertFalse($this->rootContextProvider->hasContext());
    }
}
