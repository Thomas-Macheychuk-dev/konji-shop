<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\GoogleCrawlerVerifier;
use App\Services\Security\HumanVerificationCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class EnforceHumanTraffic
{
    public function __construct(
        private readonly GoogleCrawlerVerifier $googleCrawlerVerifier,
        private readonly HumanVerificationCookie $humanVerificationCookie,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('traffic_protection.enabled', false)) {
            return $next($request);
        }

        if ($this->isExemptPath($request)) {
            return $next($request);
        }

        $userAgent = trim((string) $request->userAgent());
        $ip = (string) $request->ip();

        if ($this->googleCrawlerVerifier->isGoogleUserAgent($userAgent)) {
            if ($this->googleCrawlerVerifier->isVerified($ip, $userAgent)) {
                return $next($request);
            }

            $this->logBlock($request, 'spoofed_or_unverified_google');

            abort(403);
        }

        if ($this->isKnownAutomatedUserAgent($userAgent)) {
            $this->logBlock($request, $userAgent === '' ? 'missing_user_agent' : 'blocked_user_agent');

            abort(403);
        }

        if ($this->humanVerificationCookie->isValid($request)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && $request->acceptsHtml()) {
            return redirect()->route('traffic.challenge', [
                'return_to' => $this->safeReturnTo($request->getRequestUri()),
            ]);
        }

        $this->logBlock($request, 'human_verification_required');

        abort(403);
    }

    private function isExemptPath(Request $request): bool
    {
        foreach ((array) config('traffic_protection.exempt_paths', []) as $path) {
            if (is_string($path) && $path !== '' && $request->is($path)) {
                return true;
            }
        }

        return false;
    }

    private function isKnownAutomatedUserAgent(string $userAgent): bool
    {
        if ($userAgent === '') {
            return true;
        }

        $normalized = mb_strtolower($userAgent);

        foreach ((array) config('traffic_protection.blocked_user_agent_fragments', []) as $fragment) {
            if (! is_string($fragment) || $fragment === '') {
                continue;
            }

            if (str_contains($normalized, mb_strtolower($fragment))) {
                return true;
            }
        }

        return false;
    }

    private function safeReturnTo(string $returnTo): string
    {
        if ($returnTo === '' || ! str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            return '/';
        }

        return $returnTo;
    }

    private function logBlock(Request $request, string $reason): void
    {
        Log::notice('Traffic protection blocked request.', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
        ]);
    }
}
