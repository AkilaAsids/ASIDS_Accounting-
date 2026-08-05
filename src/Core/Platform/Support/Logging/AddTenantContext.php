<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;

/**
 * Laravel log "tap": attaches TenantContextProcessor to every handler on the
 * channel as it is constructed.
 *
 * Pushing the processor onto the handlers rather than onto the Monolog instance
 * keeps it effective for channels composed inside a `stack`, where the outer
 * logger's processors are not applied to the inner handlers.
 */
final class AddTenantContext
{
    public function __invoke(Logger $logger): void
    {
        $processor = new TenantContextProcessor();

        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
            }
        }
    }
}
