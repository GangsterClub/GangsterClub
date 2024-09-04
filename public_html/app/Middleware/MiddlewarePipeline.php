<?php

declare(strict_types=1);

namespace app\Middleware;
use app\Http\Request;
use app\Http\Response;

class MiddlewarePipeline
{
    /**
     * Summary of middleware
     * @var array
     */
    private array $middleware = [];

    /**
     * Summary of addMiddleware
     * @param callable $middleware
     * @return \app\Middleware\MiddlewarePipeline
     */
    public function addMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Summary of handle
     * @param \app\Http\Request $request
     * @param callable $next
     * @return \app\Http\Response|object
     */
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
