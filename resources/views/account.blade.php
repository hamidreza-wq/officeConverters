@extends('layouts.app')
@section('title', 'Account settings')
@section('content')
<section class="container section prose-page">
    <span class="eyebrow">YOUR ACCOUNT</span><h1 class="page-title">Your data. Your choice.</h1><p>Signed in as {{ auth()->user()->email }}.</p>
    <h2>Export your records</h2><p>Download your account details and conversion metadata as JSON. Documents are downloaded separately from your workspace.</p><p><a class="button button-outline" href="{{ route('account.export') }}">Download account data</a></p>
    <h2>Change your password</h2><p>Sign out, then use the password-reset link on the login page to choose a new password. This sends a secure reset link to your registered email.</p>
    <h2>Permanently delete your account</h2><p>This removes your account, documents, results and conversion history. Administrative audit events are retained without your account ID. Active processing must finish first. Infrastructure backups follow their own retention schedule. This cannot be undone.</p>
    <form class="stack-form" method="post" action="{{ route('account.destroy') }}">@csrf @method('delete')<label for="delete-password">Confirm your current password</label><input id="delete-password" name="password" type="password" autocomplete="current-password" required><button class="button button-danger">Permanently delete my account &amp; files</button></form>
</section>
@endsection
