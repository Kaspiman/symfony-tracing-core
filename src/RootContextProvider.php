<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingCoreBundle;

use Exception;
use OpenTelemetry\Context\ContextInterface;
use Symfony\Contracts\Service\ResetInterface;

class RootContextProvider implements ResetInterface
{
    private ?ContextInterface $rootContext = null;

    public function set(ContextInterface $context): void
    {
        if ($this->rootContext !== null) {
            throw new Exception('Root context already set');
        }

        $this->rootContext = $context;
    }

    public function get(): ?ContextInterface
    {
        return $this->rootContext;
    }

    public function hasContext(): bool
    {
        return $this->rootContext !== null;
    }

    public function reset()
    {
        $this->rootContext = null;
    }
}
