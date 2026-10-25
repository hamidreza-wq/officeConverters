@extends('layouts.app')
@section('title', 'Conversion tools')
@section('content')
<section class="container section">
    <span class="eyebrow">THE CAPABILITY DIRECTORY</span><h1 class="page-title">The right tool for your next format.</h1>
    <p class="page-description">Available means implemented. Unsupported means unsupported, not an empty promise.</p>
    <div class="search-field"><x-icon name="search" /><label class="sr-only" for="tool-search">Search available conversions</label><input id="tool-search" type="search" placeholder="Search CSV, JSON, Excel..." value="{{ request('q') }}" data-tool-search></div>
    <div class="notice info"><strong>Local conversion engines.</strong> {{ App\Services\Conversions\FormatRegistry::warning() }} File size limit: {{ config('conversions.max_kb') / 1024 }} MB; {{ config('conversions.max_pages') }} PDF pages/image frames; 20 million rendered pixels total. Every conversion is queued locally; no external document-processing API is used.</div>
    @if(!app(App\Services\Conversions\FormatRegistry::class)->localReady())
        <div class="notice error" role="alert">Office, PDF/OCR, image and archive engines are unavailable. {{ app(App\Services\Conversions\LocalEngineConverter::class)->health()['reason'] ?? 'Ask an administrator to check engine health.' }} Only the working base tabular adapters are listed.</div>
    @endif
    <div class="tool-cards directory">
        @foreach($pairs as $pair)
            <article class="tool-card" data-tool="{{ $pair['source'] }} {{ $pair['target'] }} {{ in_array('xlsx', [$pair['source'], $pair['target']]) ? 'excel spreadsheet' : '' }} {{ $pair['ocr_required'] ? 'ocr scanned searchable' : '' }}">
                <span class="pill available">AVAILABLE</span><h3>{{ strtoupper($pair['source']) }} <span class="purple">&rarr;</span> {{ strtoupper($pair['target']) }}</h3>
                <p>{{ $pair['engine'] }}. {{ $pair['source'] === 'zip' ? 'All ZIP entries must support the selected target; unsupported entries fail explicitly.' : 'Best-effort conversion; review warnings and output.' }}</p><a class="text-link" href="{{ auth()->check() || config('app.desktop_mode') ? route('dashboard', ['target' => $pair['target']]) : route('register') }}">Open workspace &rarr;</a>
            </article>
        @endforeach
    </div>
    <p class="muted" data-no-tools hidden>No available conversions match your search.</p>
    <h2 class="subsection-title">What each engine can do</h2>
    <div class="unavailable-grid">
        @foreach([['PDF & OCR','Editable text DOCX, TXT, table CSV/XLSX, rendered page images, image-based PowerPoint and searchable PDFs. English OCR is automatic for scanned pages; table columns are best-effort and require review.'],['Office documents','LibreOffice converts DOCX, ODT, RTF and TXT documents, plus PPTX/ODP presentations, into the compatible formats listed above. PDF layouts and font substitution require review.'],['Images & archives','PNG, JPEG, WebP, BMP, TIFF and GIF conversion, image-to-PDF and English OCR. All image frames are retained as separate files where needed. ZIP uploads are bounded, reject unsafe/nested entries and return a results ZIP with a manifest.'],['Complex spreadsheets','XLSX/ODS conversion preserves supported workbook features using LibreOffice. PDF export renders sheets. CSV/TSV/JSON export includes all worksheets in ZIP results with calculated values, not formulas or styling. Macros and external links are rejected.']] as [$label,$reason])
            <div class="unavailable-card"><span class="pill">{{ app(App\Services\Conversions\FormatRegistry::class)->localReady() ? 'LOCAL ENGINE' : 'ENGINE UNAVAILABLE' }}</span><h3>{{ $label }}</h3><p>{{ $reason }}</p></div>
        @endforeach
    </div>
    <p class="muted">Not supported: arbitrary proprietary formats, password-protected documents, macro execution, legacy DOC/XLS/PPT, SVG/HEIC, non-ZIP archives or guaranteed perfect formatting. No unsafe format is silently skipped.</p>
</section>
@endsection
