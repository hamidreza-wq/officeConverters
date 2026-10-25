@extends('layouts.app')
@section('title', 'Conversion details')
@section('content')
<section class="container section" data-conversion-status="{{ route('conversions.status', $conversion) }}" data-current-status="{{ $conversion->status }}">
    <a class="text-link" href="{{ route('dashboard') }}">&larr; Back to workspace</a>
    <div class="section-heading detail-heading"><div><span class="eyebrow">CONVERSION DETAILS</span><h1 class="page-title filename">{{ $conversion->original_name }}</h1><p>{{ strtoupper($conversion->source_format) }} &rarr; {{ strtoupper($conversion->target_format) }} &middot; {{ number_format($conversion->input_bytes / 1024, 1) }} KB &middot; Expires {{ $conversion->expires_at->diffForHumans() }}</p></div><span class="status-badge status-{{ $conversion->status }}">{{ ucfirst($conversion->status) }}</span></div>
    @if($conversion->expires_at->isPast())
        <div class="notice info">This conversion has expired. Downloads are blocked immediately at expiry; scheduled cleanup removes the stored files.</div>
    @elseif(in_array($conversion->status, ['queued', 'processing']))
        <div class="processing-panel" role="status"><span class="spinner"></span><h2>{{ $conversion->status === 'queued' ? 'In the queue.' : 'Working on your document.' }}</h2><p>This page updates automatically. Exact progress is not available; no percentages are estimated.</p><small>Processing requires a running queue worker. If this stays queued, contact your administrator.</small><p class="poll-error" data-poll-error hidden>Live status is unavailable. Check your connection and refresh this page.</p></div>
    @elseif($conversion->status === 'failed')
        <div class="notice error"><strong>We couldn't convert this document.</strong><p>{{ $conversion->error_message }}</p><p>Review the supported format restrictions before retrying.</p></div>
        @if($conversion->expires_at->isFuture() && $conversion->attempts < 6)<form method="post" action="{{ route('conversions.retry', $conversion) }}">@csrf<button class="button">Retry conversion</button></form>@endif
    @elseif($conversion->available())
        <div class="result-banner"><div><span class="eyebrow">READY WHEN YOU ARE</span><h2>Your new document is ready.</h2><p>{{ number_format($conversion->output_bytes / 1024, 1) }} KB &middot; Privately accessible to you.</p></div><a class="button" href="{{ route('conversions.download', $conversion) }}"><x-icon name="download" /> Download {{ strtoupper($conversion->resultExtension()) }}</a></div>
        @if($conversion->warnings)<div class="notice info"><strong>Review before using your result:</strong><ul>@foreach($conversion->warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>@endif
        <h2 class="subsection-title">A quick look at your result</h2><p class="muted">{{ $conversion->preview ? 'First 10 rows/lines and 10 columns, escaped text only.' : 'An inline preview is not available for this binary format. Download it and inspect it in a compatible application.' }}</p>
        <div class="table-wrap preview"><table><tbody>@foreach($conversion->preview ?? [] as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table></div>
    @else
        <div class="notice info">This result has expired. Downloads are blocked immediately at expiry; scheduled cleanup removes the stored files.</div>
    @endif
    <details class="limitations"><summary>Conversion limitations</summary><p>{{ App\Services\Conversions\FormatRegistry::warning() }}</p></details>
    @if($conversion->status !== 'processing')
        <form class="delete-form" method="post" action="{{ route('conversions.destroy', $conversion) }}">@csrf @method('delete')<button class="button button-danger">Delete document &amp; history</button><span class="muted">This permanently removes your stored files.</span></form>
    @endif
</section>
@endsection
