<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Resource\Detectors\Composite;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\LoggerExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Zim\SymfonyTracingCoreBundle\DependencyInjection\ResourceDetectorsPass;
use Zim\SymfonyTracingCoreBundle\Instrumentation\HttpRequest\RequestEventSubscriber;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Zim\SymfonyTracingCoreBundle\SpanSampler\ExpressionBasedSampler;

class SymfonyTracingCoreBundle extends AbstractBundle
{
    protected string $extensionAlias = 'tracing';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('foo')
                ->end()
            ->end()
            ->children()
                ->arrayNode('tracers')
                    ->isRequired()
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->children()
                ->arrayNode('exporter')
                    ->isRequired()
                    ->validate()
                        ->ifTrue(fn (array $value) => count($value) > 1)
                        ->thenInvalid("You can specify only one exporter")
                    ->end()
                    ->children()
                        ->arrayNode('log')
                            ->children()
                                ->scalarNode('service_name')
                                    ->defaultValue('tracing')
                                ->end()
                                ->scalarNode('level')
                                    ->defaultValue(LogLevel::DEBUG)
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->children()
                        ->arrayNode('otlp_http')
                            ->children()
                                ->scalarNode('endpoint')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->children()
                ->arrayNode('span_sampler')
                    ->validate()
                        ->ifTrue(fn (array $value) => count($value) > 1)
                        ->thenInvalid("You can specify only one span_sampler")
                    ->end()
                    ->children()
                        ->arrayNode('always_on')
                        ->end()
                    ->end()
                    ->children()
                        ->arrayNode('always_off')
                        ->end()
                    ->end()
                    ->children()
                        ->arrayNode('ratio')
                            ->children()
                                ->floatNode('ratio')
                                    ->isRequired()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->children()
                        ->arrayNode('service')
                            ->children()
                                ->scalarNode('id')
                                    ->isRequired()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->children()
                        ->arrayNode('expression')
                            ->children()
                                ->scalarNode('expression')
                                    ->isRequired()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder
            ->registerForAutoconfiguration(ResourceDetectorInterface::class)
            ->addTag('tracing.resource_detector')
        ;

        $this->registerResourceDetectors($builder);

        $builder->setDefinition('tracing.root_context_provider', $this->createRootTraceContextProviderDefinition());
        $builder->setDefinition('tracing.root_context', $this->createRootContextDefinition());
        $builder->setDefinition('tracing.resource_info_factory', $this->createResourceInfoFactoryDefinition());
        $builder->setDefinition('tracing.resource_info', $this->createResourceInfoDefinition());

        $builder->setDefinition('tracing.span_sampler', $this->createSpanSampleDefinition($config['span_sampler'] ?? ['always_on' => []]));
        $builder->setDefinition('tracing.span_processor.simple', $this->createSimpleSpanProcessorDefinition());
        $builder->setAlias('tracing.span_processor', 'tracing.span_processor.simple');

        $builder->setDefinition('tracing.trace_provider', $this->createTraceProviderDefinition());
        $builder->setDefinition('tracing.exporter', $this->createExporterDefinition(key($config['exporter']), $config['exporter']));
        $this->createTracers($config['tracers'], $builder);
        $this->registerHttpRequestInstrumentation($config, $builder);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ResourceDetectorsPass());
    }

    private function createRootContextDefinition(): Definition
    {
        return (new Definition(ContextInterface::class))
            ->setFactory([new Reference('tracing.root_context_factory'), 'create'])
        ;
    }

    private function createResourceInfoFactoryDefinition(): Definition
    {
        return (new Definition(ResourceInfoFactory::class))
            ->setArgument('$resourceDetector', new Reference(ResourceDetectorInterface::class))
        ;
    }

    private function createResourceInfoDefinition(): Definition
    {
        return (new Definition(ResourceInfo::class))
            ->setFactory([new Reference('tracing.resource_info_factory'), 'create'])
        ;
    }

    private function createSimpleSpanProcessorDefinition(): Definition
    {
        return (new Definition(SimpleSpanProcessor::class))
            ->setArgument('$exporter', new Reference('tracing.exporter'))
        ;
    }

    private function createTraceProviderDefinition(): Definition
    {
        return (new Definition(TracerProvider::class))
            ->setArguments([
                '$spanProcessors' => [new Reference('tracing.span_processor')],
                '$resource' => new Reference('tracing.resource_info'),
                '$sampler' => new Reference('tracing.span_sampler'),
            ])
        ;
    }

    private function createTracers(array $config, ContainerBuilder $builder): void
    {
        foreach ($config as $tracerName => $params) {
            $name = $params['name'] ?? $tracerName;

            $definition = $this->createTracerDefinition(
                name: $name,
            );

            $scopedTracerDefinition = $this->createScopedTracerDefinition(
                innerTracer: $definition,
            );

            $builder->setDefinition('tracing.tracer.' . $name, $definition);
            $builder->setDefinition('tracing.scoped_tracer.' . $name, $scopedTracerDefinition);

            $builder->setAlias(TracerInterface::class . ' $defaultTracer', new Alias('tracing.tracer.' . $name));
            $builder->setAlias(ScopedTracerInterface::class . ' $defaultTracer', new Alias('tracing.scoped_tracer.' . $name));

            if ($name === 'default') {
                $builder->setAlias(TracerInterface::class, 'tracing.tracer.default');
                $builder->setDefinition(ScopedTracerInterface::class, $scopedTracerDefinition);
            }
        }
    }

    private function createSpanSampleDefinition(array $config): Definition
    {
        $type = key($config);
        $params = $config[$type];

        $innerDefinition = match ($type) {
            'always_on' => $this->createAlwaysOnSpanSamplerDefinition(),
            'always_off' => $this->createAlwaysOffSpanSamplerDefinition(),
            'ratio' => $this->createRatioSpanSamplerDefinition($params),
            'service' => $this->createServiceSpanSamplerDefinition($params),
            'expression' => $this->createExpressionSpanSamplerDefinition($params),
        };

        return (new Definition(ParentBased::class))
            ->addArgument($innerDefinition)
        ;
    }

    private function createAlwaysOnSpanSamplerDefinition(): Definition
    {
        return (new Definition(AlwaysOnSampler::class));
    }

    private function createAlwaysOffSpanSamplerDefinition(): Definition
    {
        return (new Definition(AlwaysOffSampler::class));
    }

    private function createRatioSpanSamplerDefinition(array $params): Definition
    {
        return (new Definition(TraceIdRatioBasedSampler::class))
            ->setArgument('$probability', (float)$params['ratio'])
        ;
    }

    private function createServiceSpanSamplerDefinition(array $params): Reference
    {
        return new Reference($params['id']);
    }

    private function createExpressionSpanSamplerDefinition(array $params): Definition
    {
        if (!ContainerBuilder::willBeAvailable('symfony/expression-language', ExpressionLanguage::class, [])) {
            throw new \Exception('Span sampler of type "expression" requires "symfony/expression-language" package to be installed');
        }

        return (new Definition(ExpressionBasedSampler::class))
            ->addArgument($params['expression'])
            ->addArgument(new Reference('request_stack'))
        ;
    }

    private function createTracerDefinition(string $name): Definition
    {
        return (new Definition(TracerInterface::class))
            ->setFactory([new Reference('tracing.trace_provider'), 'getTracer'])
            ->setArgument('$name', $name)
        ;
    }

    private function createScopedTracerDefinition(Definition $innerTracer): Definition
    {
        return (new Definition(ScopedTracer::class))
            ->setArgument('$inner', $innerTracer)
        ;
    }

    private function createExporterDefinition(string $type, array $params): Definition
    {
        return match ($type) {
            'log' => $this->createLogExporterDefinition($params[$type]),
            'otlp_http' => $this->createOtlpHttpExporterDefinition($params[$type]),
        };
    }

    private function createRootTraceContextProviderDefinition(): Definition
    {
        return (new Definition(RootContextProvider::class))
            ->addTag('kernel.reset', ['method' => 'reset'])
        ;
    }

    private function createLogExporterDefinition(array $params): Definition
    {
        return (new Definition(LoggerExporter::class))
            ->setArguments([
                '$serviceName' => $params['service_name'],
                '$logger' => new Reference(LoggerInterface::class),
                '$defaultLogLevel' => $params['level'],
            ])
        ;
    }

    private function createOtlpHttpExporterDefinition(array $params): Definition
    {
        if(!ContainerBuilder::willBeAvailable('open-telemetry/exporter-otlp', OtlpHttpTransportFactory::class, [])) {
            throw new \Exception('otlp_http exporter requires "open-telemetry/exporter-otlp" package to be installed');
        }

        $factory = (new Definition(OtlpHttpTransportFactory::class));

        $transport = (new Definition('tracing.transport'))
            ->setFactory([$factory, 'create'])
            ->setArguments([
                '$endpoint' => $params['endpoint'],
                '$contentType' => 'application/json',
            ])
        ;

        return (new Definition(SpanExporter::class))
            ->setArguments([
                '$transport' => $transport,
            ])
        ;
    }

    private function registerHttpRequestInstrumentation(array $config, ContainerBuilder $builder): void
    {
        $definition = (new Definition(RequestEventSubscriber::class))
            ->setArgument('$tracer', new Reference(ScopedTracerInterface::class))
            ->setArgument('$rootTraceContextProvider', new Reference('tracing.root_context_provider'))
            ->addTag('kernel.event_subscriber')
        ;
        $builder->setDefinition(RequestEventSubscriber::class, $definition);
    }

    private function registerResourceDetectors(ContainerBuilder $builder): void
    {
        $builder->register(ResourceDetectorInterface::class, Composite::class);
    }
}
