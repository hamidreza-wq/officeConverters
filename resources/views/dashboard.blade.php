@extends('layouts.app')
@section('title', 'Your workspace')
@section('content')
<section class="container section">
    <div class="section-heading"><div><span class="eyebrow">YOUR PRIVATE WORKSPACE</span><h1 class="page-title">Hello, {{ auth()->user()->name }}.</h1><p>Convert something new, or pick up where you left off.</p></div><span class="pill">{{ number_format($storageBytes / 1024, 1) }} KB stored</span></div>
    @if($enabled)<div class="workspace-upload upload-card">@include('partials.upload')</div>@else<div class="notice info">New conversions are temporarily paused by an administrator. Existing results remain accessible until expiry.</div>@endif
    <h2 class="subsection-title">Recent conversions</h2>
    <form id="batch-download" method="post" action="{{ route('conversions.batch') }}">@csrf<button class="button button-small button-outline">Download selected as ZIP (up to 5)</button></form>
    <div class="table-wrap"><table><thead><tr><th><span class="sr-only">Select</span></th><th>Document</th><th>Conversion</th><th>Status</th><th>Expires</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
        @forelse($conversions as $conversion)
            <tr><td>@if($conversion->available())<input type="checkbox" form="batch-download" name="conversion_ids[]" value="{{ $conversion->id }}" aria-label="Select {{ $conversion->original_name }}">@endif</td><td><a href="{{ route('conversions.show', $conversion) }}">{{ $conversion->original_name }}</a><small>{{ number_format($conversion->input_bytes / 1024, 1) }} KB</small></td><td>{{ strtoupper($conversion->source_format) }} &rarr; {{ strtoupper($conversion->target_format) }}</td><td><span class="status-badge status-{{ $conversion->status }}">{{ ucfirst($conversion->status) }}</span></td><td>{{ $conversion->expires_at->diffForHumans() }}</td><td><a class="text-link" href="{{ route('conversions.show', $conversion) }}">View &rarr;</a></td></tr>
        @empty<tr><td colspan="6" class="empty-state">A fresh start. Your converted documents will appear here.</td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination">{{ $conversions->links() }}</div>
</section>
@endsection
