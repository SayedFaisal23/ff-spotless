<?php

namespace App\Http\Middleware;

use App\Services\MasterAdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMasterAdmin
{
    public function __construct(
        private readonly MasterAdminSession $adminSession,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->adminSession->isAuthenticated($request)) {
            $this->adminSession->forget($request);

            return redirect()
                ->route('home')
                ->withErrors(['password' => 'Admin access is required.']);
        }

        return $next($request);
    }
}
