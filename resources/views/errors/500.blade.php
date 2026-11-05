{{-- Standalone on purpose: no layout, database or Vite assets, so it still renders when those are what failed. --}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Chwilowy problem - Ortezka.pl</title>
    <style>
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: #f8fafc; color: #0f172a; }
        main { max-width: 560px; margin: 0 auto; padding: 96px 16px; text-align: center; }
        .brand { font-size: 28px; font-weight: 800; color: #155fa8; text-decoration: none; }
        h1 { margin: 32px 0 12px; font-size: 26px; }
        p { margin: 0 0 12px; font-size: 15px; line-height: 1.6; color: #475569; }
        a.button { display: inline-block; margin-top: 16px; padding: 12px 20px; border-radius: 12px; background: #155fa8; color: #fff; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <a class="brand" href="/">ortezka.pl</a>
    <h1>Coś poszło nie tak po naszej stronie</h1>
    <p>Spróbuj ponownie za chwilę. Jeśli problem się powtarza, zadzwoń: {{ config('legal.seller.phone', '+48 697 595 045') }}.</p>
    <a class="button" href="/">Wróć na stronę główną</a>
</main>
</body>
</html>
