<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as MonologLogger;

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
        $processor = new TenantContextProcessor;

        $monolog = $logger->getLogger();

        // A channel is not required to be Monolog-backed — the null and custom drivers are not —
        // and handlers only exist on Monolog. Nothing to decorate is not an error.
        if (! $monolog instanceof MonologLogger) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
            }
        }
    }
}
