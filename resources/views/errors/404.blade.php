{{--
    "Page not found", in the visitor's language (taken from the address, since an
    unknown address never reaches the middleware that sets it). Self-contained:
    no built assets or shared data, so it works for any address. A search box and
    the main sections bring the visitor back into the store.
--}}
@php
    $first = request()->segment(1);
    $locale = in_array($first, ['ar', 'en', 'he'], true) ? $first : 'ar';
    $text = [
        'ar' => [
            'title' => 'الصفحة غير موجودة',
            'lead' => 'ربما تغيّر عنوان هذه الصفحة أو لم تعد متوفرة. ابحث عن المنتج الذي تريده أو تصفّح المتجر.',
            'search' => 'ابحث عن منتج…',
            'go' => 'بحث',
            'home' => 'الصفحة الرئيسية',
            'products' => 'كل المنتجات',
            'categories' => 'الأقسام',
            'contact' => 'تواصل معنا',
        ],
        'en' => [
            'title' => 'Page not found',
            'lead' => 'This page may have moved or is no longer available. Search for the product you want or browse the store.',
            'search' => 'Search for a product…',
            'go' => 'Search',
            'home' => 'Home',
            'products' => 'All products',
            'categories' => 'Categories',
            'contact' => 'Contact us',
        ],
        'he' => [
            'title' => 'הדף לא נמצא',
            'lead' => 'ייתכן שכתובת הדף השתנתה או שהוא אינו זמין עוד. חפשו את המוצר הרצוי או עיינו בחנות.',
            'search' => 'חיפוש מוצר…',
            'go' => 'חיפוש',
            'home' => 'דף הבית',
            'products' => 'כל המוצרים',
            'categories' => 'קטגוריות',
            'contact' => 'צור קשר',
        ],
    ][$locale];
    $site = config('app.name');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'en' ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#1B2A4A">
    <title>{{ $text['title'] }} - {{ $site }}</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;flex-direction:column;font-family:Cairo,"Segoe UI",Tahoma,Arial,sans-serif;background:#f6f7fb;color:#1B2A4A}
        header{background:#1B2A4A;padding:14px 20px}
        header a{color:#fff;text-decoration:none;font-weight:900;font-size:22px}
        header span{color:#F5731C}
        main{flex:1;display:flex;align-items:center;justify-content:center;padding:32px 16px}
        .card{width:100%;max-width:560px;background:#fff;border:1px solid #e5e7ef;border-radius:20px;padding:32px 24px;text-align:center;box-shadow:0 10px 30px rgba(27,42,74,.08)}
        .code{font-size:64px;font-weight:900;line-height:1;color:#F5731C;margin:0}
        h1{font-size:24px;margin:12px 0 8px}
        p{color:#5b6478;line-height:1.7;margin:0 0 20px}
        form{display:flex;gap:8px;margin-bottom:20px}
        input{flex:1;min-width:0;height:48px;border:1px solid #d6dae5;border-radius:12px;padding:0 14px;font:inherit;font-size:16px;background:#f6f7fb}
        input:focus{outline:2px solid #F5731C;border-color:transparent;background:#fff}
        button{height:48px;padding:0 20px;border:0;border-radius:12px;background:#F5731C;color:#fff;font:inherit;font-weight:800;cursor:pointer}
        nav{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        nav a{display:block;padding:12px;border-radius:12px;background:#f1f3f9;color:#1B2A4A;text-decoration:none;font-weight:700}
        nav a:first-child{background:#1B2A4A;color:#fff}
        nav a:hover{outline:2px solid #F5731C}
    </style>
</head>
<body>
    <header><a href="/{{ $locale }}">By<span>TechM</span></a></header>
    <main>
        <div class="card">
            <p class="code">404</p>
            <h1>{{ $text['title'] }}</h1>
            <p>{{ $text['lead'] }}</p>
            <form action="/{{ $locale }}/products" method="get" role="search">
                <input type="search" name="search" maxlength="100" placeholder="{{ $text['search'] }}" aria-label="{{ $text['search'] }}">
                <button type="submit">{{ $text['go'] }}</button>
            </form>
            <nav>
                <a href="/{{ $locale }}">{{ $text['home'] }}</a>
                <a href="/{{ $locale }}/products">{{ $text['products'] }}</a>
                <a href="/{{ $locale }}/categories">{{ $text['categories'] }}</a>
                <a href="/{{ $locale }}/contact">{{ $text['contact'] }}</a>
            </nav>
        </div>
    </main>
</body>
</html>
