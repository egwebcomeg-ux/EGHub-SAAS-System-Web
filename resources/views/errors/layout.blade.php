@php
    // The page speaks the visitor's language (Arabic unless the session chose English) and shows the other one below.
    $en = app()->getLocale() === 'en';
    $primaryTitle = $en ? $__env->yieldContent('title') : $__env->yieldContent('title_ar');
    $otherTitle = $en ? $__env->yieldContent('title_ar') : $__env->yieldContent('title');
    $primaryMessage = $en ? $__env->yieldContent('message_en') : $__env->yieldContent('message_ar');
    $otherMessage = $en ? $__env->yieldContent('message_ar') : $__env->yieldContent('message_en');
    $actionHref = $__env->hasSection('action_href') ? $__env->yieldContent('action_href') : '/';
    $actionLabel = $__env->hasSection('action_label_ar')
        ? ($en ? $__env->yieldContent('action_label_en') : $__env->yieldContent('action_label_ar'))
        : ($en ? 'Home' : 'الرئيسية');
@endphp
<!doctype html>
<html lang="{{ $en ? 'en' : 'ar' }}" dir="{{ $en ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{!! $primaryTitle !!} · EgMenu</title>
    <link rel="icon" href="{{ versioned_asset('favicon.svg') }}" type="image/svg+xml">
    {{-- Static on purpose: no Vite, no database, no tenant lookups, so it still renders when the app is down. --}}
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#f4f6fb;color:#0A2540;font-family:Tahoma,"Segoe UI",system-ui,-apple-system,sans-serif;line-height:1.7}
        .card{width:100%;max-width:480px;background:#fff;border-radius:20px;padding:40px 28px;text-align:center;box-shadow:0 10px 40px rgba(30,45,79,.08)}
        .code{margin:24px 0 4px;font-size:56px;font-weight:800;color:#C94A0D;line-height:1}
        h1{margin:8px 0 6px;font-size:22px}
        p{margin:0;color:#5b6786}
        .alt{margin-top:14px;font-size:14px}
        a.btn{display:inline-block;margin-top:24px;padding:10px 22px;border-radius:12px;background:#C94A0D;color:#fff;text-decoration:none;font-weight:700}
    </style>
</head>
<body>
    <main class="card">
        <x-logo :size="44" class="justify-center" />
        <div class="code">@yield('code')</div>
        <h1>{!! $primaryTitle !!}</h1>
        <p>{!! $primaryMessage !!}</p>
        <p class="alt" lang="{{ $en ? 'ar' : 'en' }}" dir="{{ $en ? 'rtl' : 'ltr' }}"><b>{!! $otherTitle !!}</b> · {!! $otherMessage !!}</p>
        @hasSection('action')
            <a class="btn" href="{{ $actionHref }}">{{ $actionLabel }}</a>
        @endif
    </main>
</body>
</html>
