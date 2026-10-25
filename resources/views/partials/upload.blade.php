@php($registry = app(App\Services\Conversions\FormatRegistry::class))
<form action="{{ route('conversions.store') }}" method="post" enctype="multipart/form-data" class="upload-form" data-upload-form data-capabilities="{{ json_encode($registry->pairs()) }}">
    @csrf
    <input type="hidden" name="request_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
    <div class="dropzone" data-dropzone>
        <div class="upload-orbit"><x-icon name="upload" /></div>
        <h3>Drop your files here</h3>
        <p>or choose them from your device</p>
        <label class="button file-button" for="document-files"><x-icon name="file" /> Choose files</label>
        <input id="document-files" class="file-input" type="file" name="files[]" accept="{{ implode(',', array_map(fn ($format) => '.'.$format, $registry->sources())) }}" multiple required>
        <p class="upload-caption">Documents, PDF, spreadsheets, images &amp; ZIP &middot; Up to {{ config('conversions.max_kb') / 1024 }} MB each &middot; 5 files per batch</p>
        <ul class="selected-files" data-file-list aria-live="polite"></ul>
    </div>
    <div class="output-control">
        <label for="target-format">Convert to</label>
        <select id="target-format" name="target_format" required>
            @foreach($registry->targets() as $format)
                <option value="{{ $format }}" @selected(old('target_format', request('target', 'xlsx')) === $format)>{{ $format === 'ocrpdf' ? 'Searchable PDF (English OCR)' : strtoupper($format) }}</option>
            @endforeach
        </select>
        <button class="button" type="submit">Convert files <x-icon name="arrow" /></button>
    </div>
    <details class="limitations"><summary>Read the format &amp; data limitations</summary><p>{{ App\Services\Conversions\FormatRegistry::warning() }}</p></details>
    <label class="checkbox-label"><input type="checkbox" name="accept_limitations" value="1" required> I understand the data limitations and am authorized to process these files.</label>
    <p class="form-feedback" data-upload-feedback role="status"></p>
</form>
