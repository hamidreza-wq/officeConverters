@extends('layouts.app')
@section('title', 'Verify your email')
@section('content')
<section class="auth-shell"><div class="auth-card"><div class="upload-orbit"><x-icon name="shield" /></div><h1>Check your inbox.</h1><p>A verification link has been sent to your email. Verify it to start uploading documents.</p><p class="muted">For local development, messages are written to the configured mail log instead of sent over the internet. Ask your administrator for access; never publish that log.</p><form method="post" action="{{ route('verification.send') }}">@csrf<button class="button">Resend verification link</button></form><p><a class="text-link" href="{{ route('dashboard') }}">Continue after verifying &rarr;</a></p></div></section>
@endsection
