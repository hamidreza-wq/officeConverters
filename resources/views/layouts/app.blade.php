<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Private document conversion with real engines, transparent limits and secure downloads.">
    <title>@yield('title', 'Office Converters') | Office Converters</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="container nav">
        <a class="brand" href="{{ route('home') }}"><span class="brand-mark"><x-icon name="layers" /></span><span>office<span class="brand-light">converters</span><span class="brand-dot">.</span></span></a>
        <nav class="nav-links" aria-label="Main navigation">
            <a href="{{ route('tools') }}" @class(['active' => request()->routeIs('tools')])>Conversion tools</a>
            <a href="{{ route('privacy') }}">Privacy first</a>
            @auth
                <a href="{{ route('dashboard') }}">My workspace</a>
                @if(!config('app.desktop_mode'))
                    <a href="{{ route('account') }}">Account</a>
                    @can('admin')<a href="{{ route('admin') }}">Admin</a>@endcan
                @endif
            @endauth
        </nav>
        <div class="nav-actions">
            @auth
                <span class="account-name">{{ auth()->user()->name }}</span>
                @if(!config('app.desktop_mode'))
                    <form method="post" action="{{ route('logout') }}">@csrf<button class="button button-small button-outline">Sign out</button></form>
                @endif
            @else
                <a class="login-link" href="{{ route('login') }}">Log in</a>
                <a class="button button-small" href="{{ route('register') }}">Get started <span aria-hidden="true">&rarr;</span></a>
            @endauth
        </div>
    </div>
</header>
<main id="main">
    @if(session('status'))<div class="container"><div class="notice success" role="status">{{ session('status') }}</div></div>@endif
    @if($errors->any())
        <div class="container"><div class="notice error" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
    @endif
    @yield('content')
</main>
<footer class="site-footer">
    <div class="container footer-inner">
        <a class="brand footer-brand" href="{{ route('home') }}"><span class="brand-mark"><x-icon name="layers" /></span>officeconverters.</a>
        <p>Real conversions. Clear limitations. Your files stay yours.</p>
        <a href="{{ route('privacy') }}">Privacy &amp; retention</a>
    </div>
</footer>
</body>
</html>
