<?php

declare(strict_types=1);

namespace app\Middleware;
use app\Http\Request;
use app\Http\Response;

class MiddlewarePipeline
{
    private array $middleware = [];

    public function addMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function handle(Request $request, callable $next): ?Response
    {
        $middleware = array_reverse($this->middleware);
        $handler = $next;

        foreach ($middleware as $m) {
            $handler = function ($request) use ($m, $handler) {
                return $m($request, $handler);
            };
        }

        return $handler($request);
    }
}
