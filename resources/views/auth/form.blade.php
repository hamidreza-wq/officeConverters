@extends('layouts.app')
@section('title', match($mode) { 'register' => 'Create your workspace', 'forgot' => 'Reset password', 'reset' => 'Choose a new password', default => 'Welcome back' })
@section('content')
<section class="auth-shell">
    <div class="auth-card">
        <span class="eyebrow">A LITTLE LESS FILE FRICTION</span><h1>{{ match($mode) { 'register' => 'Your workspace awaits.', 'forgot' => 'Forgot your password?', 'reset' => 'A fresh start.', default => 'Good to see you again.' } }}</h1>
        <p class="muted">{{ $mode === 'register' ? 'Create an account to keep your documents and downloads private.' : 'A simple, private space for your documents.' }}</p>
        <form class="stack-form" method="post" action="{{ match($mode) { 'register' => route('register'), 'forgot' => route('password.email'), 'reset' => route('password.update'), default => route('login') } }}">
            @csrf
            @if($mode === 'reset')<input type="hidden" name="token" value="{{ $token }}">@endif
            @if($mode === 'register')<label for="name">Your name</label><input id="name" name="name" autocomplete="name" value="{{ old('name') }}" maxlength="100" required>@endif
            <label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" value="{{ old('email', request('email')) }}" maxlength="254" required>
            @if($mode !== 'forgot')
                <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="{{ $mode === 'login' ? 'current-password' : 'new-password' }}" @if($mode !== 'login') minlength="12" @endif required>
                @if($mode !== 'login')<p class="field-hint">At least 12 characters, including a letter and a number.</p><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>@endif
            @endif
            <button class="button">{{ match($mode) { 'register' => 'Create account', 'forgot' => 'Send reset link', 'reset' => 'Reset password', default => 'Sign in' } }} <x-icon name="arrow" /></button>
        </form>
        @if($mode === 'login')<div class="auth-links"><a href="{{ route('password.request') }}">Forgot password?</a><a href="{{ route('register') }}">Create an account</a></div>@else<p class="muted">Already have an account? <a class="text-link" href="{{ route('login') }}">Sign in</a></p>@endif
        <p class="field-hint">By using this workspace, you acknowledge our <a href="{{ route('privacy') }}">privacy and retention notice</a>.</p>
    </div>
</section>
@endsection
