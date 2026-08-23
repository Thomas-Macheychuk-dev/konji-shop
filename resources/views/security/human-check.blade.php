<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Weryfikacja ruchu — {{ config('app.name') }}</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <style>
        :root { color-scheme: light; font-family: ui-sans-serif, system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f7f9fc; color: #0f172a; }
        main { width: min(92vw, 32rem); background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 2rem; box-shadow: 0 16px 40px rgba(15, 23, 42, .08); }
        h1 { margin: 0 0 .75rem; font-size: 1.5rem; }
        p { margin: 0 0 1.5rem; color: #475569; line-height: 1.6; }
        form { display: grid; gap: 1rem; }
        button { border: 0; border-radius: .75rem; padding: .8rem 1rem; background: #0f172a; color: #fff; font-weight: 700; cursor: pointer; }
        .error { margin-bottom: 1rem; padding: .75rem 1rem; border-radius: .75rem; background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
<main>
    <h1>Potwierdź, że jesteś człowiekiem</h1>
    <p>To jednorazowa kontrola chroniąca sklep przed automatycznymi botami i masowym pobieraniem danych.</p>

    @if ($errors->has('human_verification') || $errors->has('cf-turnstile-response'))
        <div class="error" role="alert">
            {{ $errors->first('human_verification') ?: $errors->first('cf-turnstile-response') }}
        </div>
    @endif

    <form method="POST" action="{{ route('traffic.challenge.verify') }}">
        @csrf
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
        <div
            class="cf-turnstile"
            data-sitekey="{{ $siteKey }}"
            data-action="{{ $action }}"
            data-theme="light"
        ></div>
        <button type="submit">Kontynuuj do sklepu</button>
    </form>
</main>
</body>
</html>
