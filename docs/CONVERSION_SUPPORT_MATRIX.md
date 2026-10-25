# Conversion support matrix

The application exposes only pairs provided by the strict tabular adapters or by the local-engine
capability manifest. Engine-backed pairs are available only when the local engine health check succeeds.
All listed conversions process files locally; no remote conversion service is used.

## Strict tabular adapter

| Input | Output | Engine | Offline | Notes / validation |
| --- | --- | --- | --- | --- |
| CSV | TSV, JSON, XLSX | Bounded UTF-8 adapter and OpenSpout | Yes | Values-only; UTF-8 input; formula-like CSV/TSV values are prefixed for safety |
| TSV | CSV, JSON, XLSX | Bounded UTF-8 adapter and OpenSpout | Yes | Values-only; UTF-8 input |
| JSON | CSV, TSV, XLSX | Bounded UTF-8 adapter and OpenSpout | Yes | Accepts only an array of arrays of strings |
| XLSX | CSV, TSV, JSON | OpenSpout | Yes | The basic adapter is single-sheet and values-only |

The four tabular formats provide 12 directed conversions (same-format conversions are not advertised).
Configured size, row, column, and cell limits apply. Data types, formulas, styles, and workbook features
are not represented by the strict text adapters.

## Local conversion-engine manifest

The following pairs are declared in `scripts/conversion-capabilities.json`. The registry expands
multi-page, multi-sheet, or multi-frame results into ZIP files when a single output would discard
content.

| Input | Outputs | Engine family | Offline | Key limitation |
| --- | --- | --- | --- | --- |
| DOCX | PDF, ODT, RTF, TXT | LibreOffice | Yes | Office layout/features are best-effort |
| ODT | PDF, DOCX, RTF, TXT | LibreOffice | Yes | Office layout/features are best-effort |
| RTF | PDF, DOCX, ODT, TXT | LibreOffice | Yes | Formatting is best-effort |
| TXT | PDF, DOCX, ODT, RTF | LibreOffice | Yes | Plain text has no source formatting |
| PPTX | PDF, ODP | LibreOffice | Yes | Fonts, layout, and animations may change |
| ODP | PDF, PPTX | LibreOffice | Yes | Fonts, layout, and animations may change |
| XLSX | ODS, PDF, CSV, TSV, JSON | LibreOffice plus bounded adapters | Yes | Formula recalculation and formatting may differ from Excel |
| ODS | XLSX, PDF, CSV, TSV, JSON | LibreOffice plus bounded adapters | Yes | Formula recalculation and formatting may differ from Excel |
| PDF | DOCX, TXT, CSV, XLSX, PNG, JPG, TIFF, PPTX, searchable PDF | PDFium, pypdf, pdfplumber, LibreOffice, and OCR adapters | Yes | PDF-to-Word reconstructs editable text rather than original layout; PDF-to-PowerPoint uses page images; scanned table recovery is heuristic |
| PNG, JPG/JPEG, WEBP, BMP, TIFF, GIF | PNG/JPG/WEBP/BMP/TIFF as declared, PDF, OCR TXT, searchable PDF | Pillow and Tesseract | Yes | OCR accuracy depends on image quality and installed language data; animation/multiple pages may return ZIP |
| ZIP | Compatible supported targets | Safe archive adapter and per-format engines | Yes | Bounded entry count; unsafe, encrypted, nested, duplicate, or unsupported entries fail the conversion |

The exact input/output combinations are the JSON arrays in the manifest; this summary does not imply
that every output listed for an image applies to every other image source. The runtime-generated tools
directory is authoritative for the currently healthy engine set.

## Validation status

`php artisan converters:health` reported 117 available pairs on the inspected development machine.
`php artisan test --compact tests/Feature/LocalEngineTest.php` passed 16 tests and 174 assertions,
including PDF-to-Word, scanned-PDF table extraction, PDF-to-images, Office/PDF conversions, OCR,
multi-sheet spreadsheets, and multi-frame images.
The isolated desktop-mode suite also completed a real CSV-to-XLSX conversion with SQLite-backed
metadata and queue storage.

Desktop packaging additionally runs eight real queued conversions using only the bundled runtime:
CSV/XLSX in both directions, RTF/PDF, native PDF/DOCX, image OCR, PPTX/PDF, image/PDF and scanned
PDF/DOCX. Outputs are parsed to check cell values, page/slide text and OCR results. These checks also
exercise SQLite metadata/queues and a version-upgrade backup.

These real-output baselines are not per-pair clean-machine installer certification. A fresh Windows VM
with developer tools absent and networking disabled remains a required production release gate.

## Distribution and licensing

The installer bundles controlled PHP/Python/LibreOffice/Tesseract versions, production dependencies,
Electron and app-local Microsoft CRT. It includes notices and a generated runtime/package inventory.
Composer, npm and developer Node tooling are not end-user dependencies. See `LICENSING.md` and
`../THIRD_PARTY_NOTICES.md`; a full redistribution/source-offer review remains necessary before a
public production release.
