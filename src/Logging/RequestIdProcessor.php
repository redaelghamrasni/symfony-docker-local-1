<?php

namespace App\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Stamps every log record with the id of the request that produced it.
 *
 * nginx generates the id and passes it as HTTP_X_REQUEST_ID (see
 * docker/nginx.conf), which is also returned to the browser as the
 * X-Request-ID response header. That makes three things line up: the nginx
 * access line, every application record for the same request, and whatever a
 * user or a frontend error report quotes back at us.
 *
 * Outside an HTTP request — CLI commands, messenger workers — there is no
 * nginx id, so one is generated per process instead.
 */
#[AsMonologProcessor]
final class RequestIdProcessor
{
    private ?string $cliId = null;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['request_id'] = $this->resolveId();

        return $record;
    }

    private function resolveId(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null) {
            $id = $request->headers->get('X-Request-ID');

            if (is_string($id) && $id !== '') {
                // Cap the length: the header is trusted (nginx overwrites any
                // client-supplied value) but a log field should never be able
                // to grow without bound.
                return substr($id, 0, 64);
            }
        }

        // CLI / worker context: stable for the life of the process, so all
        // records from one command or one consumed message share an id.
        return $this->cliId ??= Uuid::v4()->toRfc4122();
    }
}
