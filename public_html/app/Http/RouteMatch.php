<?php

declare(strict_types=1);

namespace app\Http;

final readonly class RouteMatch
{
    public function __construct(
        public Route $route,
        public array $parameters = [],
    ) {
    }
}
