<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · EgMenu</title>
    <link rel="icon" href="{{ versioned_asset('favicon.svg') }}" type="image/svg+xml">
    {{-- Static on purpose: no Vite, no database, no tenant lookups, so it still renders when the app is down. --}}
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#f4f6fb;color:#0A2540;font-family:Tahoma,"Segoe UI",system-ui,-apple-system,sans-serif;line-height:1.7}
        .card{width:100%;max-width:480px;background:#fff;border-radius:20px;padding:40px 28px;text-align:center;box-shadow:0 10px 40px rgba(30,45,79,.08)}
        .code{margin:24px 0 4px;font-size:56px;font-weight:800;color:#F26522;line-height:1}
        h1{margin:8px 0 6px;font-size:22px}
        p{margin:0;color:#5b6786}
        .en{margin-top:14px;font-size:14px;direction:ltr}
        a.btn{display:inline-block;margin-top:24px;padding:10px 22px;border-radius:12px;background:#F26522;color:#fff;text-decoration:none;font-weight:700}
    </style>
</head>
<body>
    <main class="card">
        <x-logo :size="44" class="justify-center" />
        <div class="code">@yield('code')</div>
        <h1>@yield('title_ar')</h1>
        <p>@yield('message_ar')</p>
        <p class="en">@yield('message_en')</p>
        @hasSection('action')
            <a class="btn" href="/">الرئيسية · Home</a>
        @endif
    </main>
</body>
</html>
