<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Infrastructure\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Documents 429 on every operation whose route has a `throttle` middleware
 * (issue #202): the usual error body and the `Retry-After` header. A 429
 * that the controller documents itself is kept, and gets the header.
 */
final class DocumentRateLimits extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! $this->isThrottled($routeInfo)) {
            return;
        }

        $response = $this->documented($operation);

        if ($response === null) {
            // The error body Scramble documents for every other error.
            $message = new StringType;
            $message->setDescription('Error overview.');
            $body = new ObjectType;
            $body->addProperty('message', $message);
            $body->setRequired(['message']);

            $response = new Response(429);
            $response->setDescription('Too many requests. Retry after the seconds in `Retry-After`.');
            $response->setContent('application/json', self::schema($body));
            $operation->addResponse($response);
        }

        $response->addHeader('Retry-After', new Header(
            description: 'Seconds until the limit lets a request through again.',
            required: true,
            schema: self::schema(new IntegerType),
        ));
    }

    // Schema::fromType() declares no return type.
    private static function schema(Type $type): Schema
    {
        $schema = new Schema;
        $schema->type = $type;

        return $schema;
    }

    private function isThrottled(RouteInfo $routeInfo): bool
    {
        foreach ($routeInfo->route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'throttle' || str_starts_with($middleware, 'throttle:'))) {
                return true;
            }
        }

        return false;
    }

    private function documented(Operation $operation): ?Response
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (string) $response->code === '429') {
                return $response;
            }
        }

        return null;
    }
}
