@extends('layouts.app')
@section('title', 'Less file friction. More possibility.')
@section('content')
<section class="hero">
    <div class="container hero-grid">
        <div class="hero-copy">
            <span class="eyebrow"><span class="status-dot"></span> YOUR EVERYDAY FILE WORKSPACE</span>
            <h1>Different formats.<br>Same possibilities<span class="purple">.</span></h1>
            <p class="hero-description">Turn the files you have into the files you need. Real conversion tools, a simpler workflow, and privacy built into every step.</p>
            <div class="hero-tags"><span><x-icon name="shield" /> Private by default</span><span><x-icon name="check" /> No external document APIs</span></div>
            <div class="hero-footnote"><span class="mini-file green">X</span><span class="mini-file blue">{ }</span><span class="mini-file violet">PDF</span><p>Office, PDF, images &amp; structured data.<br><strong>Real local conversion engines.</strong></p></div>
        </div>
        <div class="upload-card">
            <div class="card-topline"><span><span class="status-dot"></span> LET'S CONVERT SOMETHING</span><span class="pill">LOCAL ENGINES</span></div>
            @auth
                @if(auth()->user()->hasVerifiedEmail())
                    @include('partials.upload')
                @else
                    <div class="guest-upload"><div class="upload-orbit"><x-icon name="shield" /></div><h3>One more step for a safer workspace.</h3><p>Verify your email before uploading documents.</p><a class="button" href="{{ route('verification.notice') }}">Verify your email <x-icon name="arrow" /></a></div>
                @endif
            @else
                <div class="guest-upload">
                    <div class="upload-orbit"><x-icon name="upload" /></div>
                    <h3>Your next format starts here.</h3>
                    <p>Sign in to upload, convert and privately download your documents.</p>
                    <a class="button" href="{{ route('register') }}">Create your free workspace <x-icon name="arrow" /></a>
                    <p class="upload-caption">Office &middot; PDF &amp; OCR &middot; Images &middot; ZIP &middot; Data</p>
                </div>
            @endauth
            <div class="privacy-strip"><x-icon name="shield" /><span>Private storage. Automatic expiry after {{ config('conversions.retention_hours') }} hours.</span></div>
        </div>
    </div>
</section>
<section class="trust-band"><div class="container trust-grid"><div><x-icon name="shield" /><span><strong>Your files, your business</strong><small>Owner-only downloads</small></span></div><div><x-icon name="grid" /><span><strong>Real conversion engines</strong><small>No simulated results</small></span></div><div><x-icon name="clock" /><span><strong>Less waiting, more doing</strong><small>Background processing</small></span></div></div></section>
<section class="container section">
    <div class="section-heading"><div><span class="eyebrow">REAL ENGINES. CLEAR LIMITS.</span><h2>Pick a format. Keep moving.</h2><p>Local document conversions, with clear limits before you upload.</p></div><a class="text-link" href="{{ route('tools') }}">Explore all tools <span>&rarr;</span></a></div>
    <div class="tool-cards">
        @foreach([['csv','xlsx','green','grid','CSV to Excel','Turn delimited data into a real XLSX workbook.'],['xlsx','csv','blue','file','Excel to CSV','Export worksheet values with clear formatting limits.'],['csv','json','violet','code','CSV to JSON','Create structured rows without guessing data types.'],['tsv','xlsx','orange','grid','TSV to Excel','Bring tab-separated rows into your spreadsheet.']] as [$source,$target,$color,$icon,$label,$description])
        <a class="tool-card" href="{{ route('tools', ['q' => $source.' '.$target]) }}"><div class="tool-icon {{ $color }}"><x-icon :name="$icon" /></div><h3>{{ $label }}</h3><p>{{ $description }}</p><span class="tool-card-bottom">{{ strtoupper($source) }} <x-icon name="arrow" /> {{ strtoupper($target) }}<span class="circle-arrow">&nearr;</span></span></a>
        @endforeach
    </div>
</section>
<section class="container how-section"><div><span class="eyebrow">NO MANUAL REQUIRED</span><h2>From upload to done.<br>Three simple steps.</h2><p>We keep the workflow simple, not the promises unrealistic.</p></div><ol class="steps"><li><span>01</span><div><h3>Choose your files</h3><p>Upload a supported document to your private workspace.</p></div></li><li><span>02</span><div><h3>Choose the next format</h3><p>Review the limitations, then let the engine do its work.</p></div></li><li><span>03</span><div><h3>Preview. Download. Done.</h3><p>Check the resulting rows and securely download your file.</p></div></li></ol></section>
<section class="container roadmap-banner"><div><span class="eyebrow">HONEST ABOUT CONVERSION QUALITY</span><h2>Broad capabilities. Clear expectations.</h2><p>Installed local engines support Office, PDF/OCR, images, ZIP batches and multi-sheet spreadsheets. Complex layouts and OCR are best-effort; check the capability directory and review every result.</p></div><a class="button button-outline" href="{{ route('tools') }}">See supported formats <x-icon name="arrow" /></a></section>
@endsection
