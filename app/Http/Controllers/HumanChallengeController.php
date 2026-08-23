<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Security\HumanVerificationCookie;
use App\Services\Security\TurnstileVerifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class HumanChallengeController extends Controller
{
    public function show(
        Request $request,
        HumanVerificationCookie $humanVerificationCookie,
    ): View|RedirectResponse {
        $returnTo = $this->safeReturnTo((string) $request->query('return_to', '/'));

        if (! (bool) config('traffic_protection.enabled', false)) {
            return redirect($returnTo);
        }

        if ($humanVerificationCookie->isValid($request)) {
            return redirect($returnTo);
        }

        $siteKey = trim((string) config('traffic_protection.turnstile.site_key'));

        abort_if($siteKey === '', 503, 'Human verification is not configured.');

        return view('security.human-check', [
            'siteKey' => $siteKey,
            'action' => (string) config('traffic_protection.turnstile.action', 'human-traffic'),
            'returnTo' => $returnTo,
        ]);
    }

    public function verify(
        Request $request,
        TurnstileVerifier $turnstileVerifier,
        HumanVerificationCookie $humanVerificationCookie,
    ): RedirectResponse {
        if (! (bool) config('traffic_protection.enabled', false)) {
            return redirect($this->safeReturnTo((string) $request->input('return_to', '/')));
        }

        $validated = $request->validate([
            'cf-turnstile-response' => ['required', 'string', 'max:2048'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $token = (string) $validated['cf-turnstile-response'];

        if (! $turnstileVerifier->verify($token, (string) $request->ip())) {
            return back()
                ->withInput(['return_to' => $validated['return_to'] ?? '/'])
                ->withErrors([
                    'human_verification' => 'Weryfikacja nie powiodła się. Spróbuj ponownie.',
                ]);
        }

        $returnTo = $this->safeReturnTo((string) ($validated['return_to'] ?? '/'));

        return redirect($returnTo)
            ->withCookie($humanVerificationCookie->make($request));
    }

    private function safeReturnTo(string $returnTo): string
    {
        if (
            $returnTo === ''
            || ! str_starts_with($returnTo, '/')
            || str_starts_with($returnTo, '//')
            || str_starts_with($returnTo, '/human-check')
        ) {
            return '/';
        }

        return $returnTo;
    }
}
