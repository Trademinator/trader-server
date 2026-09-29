<?php

namespace App\Http\Middleware;

use App\Domain\Operations\ActionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ObserveRequest
{
    public function __construct(private ActionContext $context) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('trademinator_trace', $this->context->begin());

        return $next($request);
    }
}
