# Office Converters

A working, staged Laravel document-conversion platform using **MySQL or XAMPP MariaDB**, through PDO MySQL.
It combines strict tabular adapters with installed local PDF/OCR, Office, image and ZIP conversion engines.
This is **not a universal converter or a production-readiness certification**: only healthy, implemented pairs are advertised.

## Implemented

- Responsive Blade/Tailwind interface, drag-and-drop uploads and searchable capability directory.
- Registration, login/logout, signed email verification and password reset.
- Verified-account-only uploads; private local storage; owner-only status, previews and downloads.
- Real OpenSpout XLSX reading/writing and bounded UTF-8 CSV, TSV and JSON adapters.
- LibreOffice Office/spreadsheet conversions, PDF text/table extraction, English Tesseract OCR and Pillow image conversions.
- Multi-page, multi-frame and multi-sheet results preserved through ZIP outputs, with the actual download extension and explicit fidelity warnings.
- ZIP input with all-or-fail conversion of supported contents, numbered results and a manifest.
- Database-backed background jobs, transactional submission, idempotency keys, retries and explicit errors.
- Batches of up to five inputs; individual downloads and ZIP downloads of up to five completed results.
- Dashboard/history, escaped output previews and immediate download expiry.
- Password-confirmed account deletion and account/metadata export.
- Backend-enforced administrator access, queue counts, worker heartbeat, submission pause/resume and audit events.
- Scheduled expiration cleanup and recovery of abandoned processing jobs.
- Separate MySQL users/databases for the application and integration tests.

No document is submitted to an external conversion service.
Guests can browse capabilities but cannot upload documents in this release.

## Supported conversion matrix

The installed engine set currently exposes **117 conversion pairs**, including ZIP targets. The shared
[capability manifest](scripts/conversion-capabilities.json) and [format registry](app/Services/Conversions/FormatRegistry.php)
drive upload validation, target selection and the tools directory. Every declared local pair and every ZIP target is tested with real outputs.

| Source | CSV | TSV | JSON | XLSX |
| --- | --- | --- | --- | --- |
| CSV | -- | Yes | Yes | Yes |
| TSV | Yes | -- | Yes | Yes |
| JSON | Yes | Yes | -- | Yes |
| XLSX | Yes | Yes | Yes | -- |

Additional local-engine conversions:

| Source | Targets |
| --- | --- |
| DOCX, ODT, RTF, TXT | Other listed document formats and PDF |
| PPTX, ODP | Each other and PDF |
| XLSX | ODS, PDF, CSV, TSV, JSON |
| ODS | XLSX, PDF, CSV, TSV, JSON |
| PDF | DOCX, TXT, CSV tables, XLSX tables, PNG, JPG, TIFF, PPTX, searchable PDF |
| PNG, JPG/JPEG, WebP, BMP, TIFF, GIF | Supported alternate image formats, PDF, OCR TXT, searchable PDF; see manifest for exact pairs |
| ZIP | A compatible supported target for every contained document; result always ZIP |

If local engines are disabled or fail their health probe, the directory falls back to the 12 strict
CSV/TSV/JSON/XLSX pairs. Missing engines are reported, not simulated.

### Important data limitations

- Text inputs must be UTF-8; UTF-8 BOMs are supported. CSV uses commas, TSV uses tabs and quoting uses doubled double quotes.
- JSON must be a top-level array of arrays of **strings**, e.g. `[["Name","Amount"],["Example","123.4500"]]`.
  Objects, numeric JSON values and nested structures are rejected rather than coerced into guessed types.
- Output is values-only. Text inputs remain strings, preserving leading zeros, large identifiers and decimal text.
  Numeric cells already stored as numbers in an uploaded XLSX cannot recover precision previously lost in Excel.
- With local engines enabled, XLSX/ODS exports recalculate supported formulas through LibreOffice and include **all worksheets**.
  Multiple worksheets exported to CSV/TSV/JSON produce one file per sheet inside ZIP. Text exports are values-only:
  styles, charts, merged layouts and number formats do not survive. Office-to-Office conversions preserve formatting only best-effort.
  Without local engines, the strict PHP XLSX reader accepts only a single plain, values-only worksheet.
- Macros, executable/OLE content, external relationships/resources and external or active formulas are rejected.
  Ordinary formulas can calculate differently from Excel. Dates and numeric cells may be normalized; always review the result.
- CSV/TSV cells whose first non-whitespace character is `=`, `+`, `-` or `@` receive an apostrophe prefix
  to reduce spreadsheet formula-injection risk. **This intentionally changes those values**, including negative-number strings.
  XLSX outputs use explicit string cells instead of formulas.
- Previews show at most ten rows and ten columns from the emitted output.
- PDF-to-Word rebuilds editable text and page breaks, **not the original visual layout or images**.
  PDF-to-PowerPoint uses rendered page images; slide text is not individually editable.
- Native PDF tables are detected geometrically. Scanned PDF tables use English OCR and spacing heuristics, not guaranteed table reconstruction.
  Partial-grid tables can reconstruct missing row borders from aligned vertical line segments; sub-point accessibility labels are excluded.
  Header-only detections are not treated as successful data tables. Undecodable PDF glyphs are retained with an explicit warning rather than guessed.
  Searchable PDF output is rasterized with an OCR text layer: forms, links, vector artwork and signatures are not retained.
- Multi-page image exports and animated-image frame exports produce ZIPs rather than discarding later pages/frames.
- ZIP input is limited to 20 entries. Nested archives, encrypted entries, symlinks, unsafe paths, duplicates and unsupported entries
  fail the whole conversion; files are never silently skipped.
- Password-protected, encrypted, corrupt and unsupported documents are rejected.

Default limits: 5 MiB/input, five inputs/batch, five active jobs/user, 100 submissions/user/rolling 24 hours,
20,000 rows, 100 columns, 32,767 bytes/cell, 500 Office-package entries, 32 MiB expanded/package/output data,
20 PDF pages/image frames and 20 million combined rendered/image pixels.
The upload endpoint is additionally rate-limited to ten requests/minute/user.
Runtime limits and retention settings are in [config/conversions.php](config/conversions.php).

### Not implemented or advertised

Arbitrary extensions, legacy binary DOC/XLS/PPT, RAR/7z, password removal, arbitrary JSON schemas,
perfect PDF layout reconstruction, handwriting/non-English OCR and unrestricted document sizes.
The engine is expandable, not a promise to handle every file. New pairs require bounded adapters and real-output tests.

## Runtime requirements

Verified locally on Windows:

| Component | Verified version | Role |
| --- | --- | --- |
| PHP | 8.5.11 | Application and queue worker |
| Laravel framework | 13.38.0 | Website, accounts, validation, storage and jobs |
| OpenSpout | 5.12.2 | Actual XLSX engine |
| Python | 3.14.7 | Isolated local conversion bridge and libraries |
| LibreOffice | 26.8.1.1 | Headless Office conversion and spreadsheet recalculation |
| Tesseract | 5.4.0.20240606 | English OCR |
| Pillow | 12.2.0 | Image decoding/encoding |
| PDFium / pypdf / pdfplumber | See pinned requirements | PDF rendering, writing and table/text extraction |
| XAMPP MariaDB | 10.4.32 | Current application/test database, sessions, cache and queue |
| Node.js / npm | 26.8.2 / 12.0.2 | Asset build |
| Vite | 8.3.5 | Asset build |
| Composer | 2.8.9 | PHP package installation |

The dependency set requires PHP 8.4, 8.5 or 8.6 (see Composer's platform check).
Enable PDO MySQL, fileinfo, mbstring, DOM, XMLReader/XMLWriter, libxml, ZIP, OpenSSL and the standard Laravel extensions.
Use `composer check-platform-reqs` to check the actual PHP executable.
GD is not required. Python, LibreOffice and Tesseract are required for the advanced groups.
Use a Node version supported by the locked Vite version; Node 22.12+ or a compatible supported newer release.

## MySQL / XAMPP MariaDB setup

**Do not use an existing production database for tests.**
At the user's request, the application now uses the existing XAMPP MariaDB server on `127.0.0.1:3306`.
`DB_CONNECTION=mysql` is retained because PDO MySQL supports both servers. MySQL 8.0+ and MariaDB 10.4+
are accepted. MariaDB 10.4 is end-of-life: upgrade before production deployment.
No alternative application database configuration or automatic fallback exists.

Provision databases and distinct users using a MySQL administrator (replace the example passwords):

```sql
CREATE DATABASE office_converters CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE office_converters_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'office_converters'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_APP_PASSWORD';
CREATE USER 'office_converters_test'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_TEST_PASSWORD';
GRANT ALL PRIVILEGES ON office_converters.* TO 'office_converters'@'127.0.0.1';
GRANT ALL PRIVILEGES ON office_converters_test.* TO 'office_converters_test'@'127.0.0.1';
```

These privileges permit migrations only within each user's own database; no global privileges are needed.
For production, prefer a separate migration account and reduce runtime privileges to necessary DML.
Adapt host-based grants to your deployment; do not expose the local database to the internet.

### Current XAMPP configuration and preserved original instance

The `office_converters` and `office_converters_test` databases now exist in XAMPP MariaDB.
They have separate restricted users; the application does not run as XAMPP root.
All application records, including the existing account, sessions and settings, were copied from the
original MySQL instance and compared field-for-field. Private document storage and the application key
were not changed. The test database was migrated separately and is never used by the website.
View the application database in XAMPP phpMyAdmin at `http://localhost/phpmyadmin`.
Keep the XAMPP MySQL service running; no separate database server is needed for the app.

The original MySQL Community Server 8.4.10 instance and its source databases were preserved as a rollback copy under:

```text
%LOCALAPPDATA%\OfficeConverters\mysql-8.4.10\mysql-8.4.10-winx64
```

That original instance uses `127.0.0.1:3307`, with MySQL X protocol disabled; it is no longer the configured application server.
Application and test passwords are independently generated and stored in ignored `.env` and `.env.testing` files.
The local root recovery options file is `%LOCALAPPDATA%\OfficeConverters\mysql-root.ini`.
Protect that file and environment files with owner-only filesystem permissions; never commit or share them.
The instance is not installed as an auto-start Windows service.

Only if deliberately returning to the original MySQL instance, start it with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\start-local-mysql.ps1
```

To shut down only this dedicated server, use its own MySQL admin executable and private root options file:

```powershell
$base = "$env:LOCALAPPDATA\OfficeConverters\mysql-8.4.10\mysql-8.4.10-winx64"
& "$base\bin\mysqladmin.exe" "--defaults-extra-file=$env:LOCALAPPDATA\OfficeConverters\mysql-root.ini" shutdown
```

For a fresh dedicated instance only, `scripts/provision-local.php --new-local-instance` provisions databases/users
against an initialized local server with an empty root password on port 3307, sets random passwords,
and writes ignored local environment files. **Do not rerun it against the configured instance or an existing database.**
It is not a general production database installer.

## Install and run

### Windows / XAMPP

Use the PHP 8.4+ executable selected for this project, not an older XAMPP PHP binary.
Check `php -v`, `php --ini` and `php -m`; enable required extensions in that executable's loaded `php.ini`.
XAMPP's MySQL control-panel entry runs MariaDB; this is now the selected database server.

```powershell
composer install
Copy-Item .env.example .env
# Configure the real MySQL app credentials in .env before continuing.
php artisan key:generate
php artisan migrate --seed
npm ci --ignore-scripts
npm run build
php artisan converters:health
```

Do not overwrite an already configured `.env`. It contains the application encryption key and private credentials.
Set `APP_URL` to the exact browser origin so emailed signed links work.
Defaults in `.env.example` target `http://127.0.0.1:8000` and XAMPP database port 3306.

### Enable the local conversion engines

Install LibreOffice and Tesseract with the English language model using their official Windows installers.
Create an isolated environment using a compatible Python interpreter (verified here with Python 3.14):

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r scripts\requirements.txt
.\.venv\Scripts\python.exe -m pip check
```

Set `CONVERSION_LOCAL_ENGINES=true` in the ignored application and test environment files.
Defaults expect `.venv\Scripts\python.exe`, `C:\Program Files\LibreOffice\program\soffice.com`
and `%LOCALAPPDATA%\Programs\Tesseract-OCR\tesseract.exe`. Override `CONVERSION_PYTHON`,
`CONVERSION_LIBREOFFICE` and `CONVERSION_TESSERACT` with absolute paths if installed elsewhere.
Run `php artisan config:clear`, `php artisan converters:health`, and restart workers.
Health checks are cached for 60 seconds; it must report `"ready":true` and 117 available pairs on this installation.
Laravel does not automatically install these external executables.

Run each of these in a separate terminal:

```powershell
php scripts\serve.php
php -d memory_limit=256M artisan queue:work database --sleep=2 --tries=3 --timeout=150 --memory=256
php artisan schedule:work
```

Or run the equivalent three tasks from VS Code's **Run Task** menu.
The development server wrapper sets upload/post limits to accommodate a five-file batch.
For Apache/XAMPP, set the document root to the project's `public` directory, enable rewrite rules,
and set `upload_max_filesize=6M`, `post_max_size=32M` and `memory_limit=256M` in the web SAPI's configuration.
Keep `.env`, `storage`, `vendor` and the MySQL data directory outside the served document root.

### Email and administrator access

Development uses Laravel's `log` mail driver: verification/reset emails are written to the private Laravel log.
They are **not delivered to a real mailbox** until SMTP is configured. The authentication flows themselves are implemented.
Access only your own verification/reset message; authentication links and logs must not be published.
Set a real mail transport and sender address before production deployment.

Register and verify your account, then grant administrator access explicitly:

```powershell
php artisan converters:admin your-verified-email@example.com
```

There are no seeded users, default administrator passwords or public role-escalation endpoints.
Admin settings changes require the current password and create an audit event.
Role grants are CLI-only; the admin UI does not provide full user/permission management.

### Linux deployment prerequisites

Install a supported PHP runtime with required extensions, Composer, a compatible Node runtime and a supported MySQL or MariaDB release.
Copy `.env.example` to `.env`, set credentials, then run the same Composer, migration and asset-build steps.
Point Nginx/Apache + PHP-FPM at `public`; make `storage` and `bootstrap/cache` writable only by appropriate runtime users.
Run queue workers under a dedicated low-privilege account with a process supervisor.
Use cron to run `php artisan schedule:run` every minute.
For advanced conversions, also install Python, LibreOffice and Tesseract/English, create `.venv`, install
the pinned Python requirements and configure executable paths. The Python integration fixtures require
DejaVu Sans on Linux (Arial is used on Windows). There is no supplied container deployment.

Before exposing the application:

1. Set `APP_ENV=production`, `APP_DEBUG=false`, the correct HTTPS `APP_URL` and `SESSION_SECURE_COOKIE=true`.
2. Configure trusted proxies/TLS correctly; do not trust arbitrary forwarded client headers.
3. Set real email delivery and verify verification/reset links from the deployed origin.
4. Use separate database principals, private storage ACLs and a restricted worker account.
5. Add malware scanning/quarantine and hardened worker isolation before accepting arbitrary public documents.
6. Configure supervisor restart policies. Python enforces a 120-second aggregate deadline, bounded 60-second
   LibreOffice calls and 30-second OCR calls; PHP terminates the local-engine process after 130 seconds.
   The queue job timeout is 150 seconds, below the 180-second queue retry interval. On Windows the Python bridge
   uses a Job Object with eight processes, 768 MiB per-process memory and descendant termination on exit.
   POSIX uses process memory/CPU limits. PHP's worker-level hard timeout additionally requires PCNTL on Linux.
   These safeguards are **not a filesystem or network security sandbox**.
7. Run migrations, seed settings, build assets, run the MySQL tests and `php artisan converters:health`.
8. Cache deployment configuration/views and restart workers after code/config changes.
9. Monitor queue age, failures, disk usage, scheduler activity and worker heartbeat; a heartbeat is not full queue monitoring.
10. Review legal notices, backup retention and operational access before making privacy or compliance claims.

The database queue is deliberately pinned to the MySQL connection so submission records and work items commit atomically.
Changing queue backends requires revisiting that transactional guarantee; this release does not advertise Redis queue support.
Use a small supervised worker pool (one locally); do not start an unbounded number of conversion workers.

## Testing and verification

Create `.env.testing` from `.env.example`, use the **same APP_KEY or a separate generated test key**,
set `APP_ENV=testing`, `DB_DATABASE=office_converters_test`, and the distinct test username/password.
The local provisioning script has already created this ignored file for this workspace.
Never point tests at the application database. PHPUnit forces the test database name and the test bootstrap
refuses connections without the PDO MySQL driver or a database other than `office_converters_test` before any test transaction starts.

```powershell
php artisan config:clear
php artisan migrate --env=testing --force
php artisan test --compact
.\.venv\Scripts\python.exe -I scripts\test_conversion_engines.py -v
php vendor\bin\pint --test
composer validate --strict
composer check-platform-reqs
composer audit
npm audit
npm run build
php artisan converters:health
```

The PHP suite covers the 12 strict tabular pairs and real expanded upload/job/download workflows,
including PDF-to-Word, scanned PDF-to-Excel, Office-to-PDF, searchable PDFs, animation frames, ZIP archives,
multi-sheet calculated results, actual ZIP download extensions, explicit timeouts and temporary-workspace cleanup.
The Python suite independently checks every advertised local pair and ZIP target using readable output parsers.
Additional coverage includes Unicode, quoted/multiline values,
leading zeros, large numeric identifiers, decimal strings, database queue execution, duplicate submission,
owner isolation, unsafe XML, expansion/row/column limits, invalid data, formula handling, expiration,
cleanup, retries, abandoned jobs, batch ZIP contents, email verification/reset, administration and account deletion/export.
Tests use MySQL transactions and a separate fake filesystem, not an in-memory application database or fake conversion engine.
Failure callbacks and aggregate deadline rejection are tested; concurrent multi-process stress tests are not yet certified.

The original XAMPP baseline passed **48 tests / 364 assertions** against MariaDB 10.4.32 on port 3306.
The expanded complete PHP suite passed **63 tests / 515 assertions** against the separate XAMPP test database.
The expanded Python all-pairs suite passed **7 tests**, including subtests for every local pair and ZIP target.
Two final targeted regressions also passed for emitted CSV previews and calculated multi-sheet exports.
All six migrations, including actual output extensions and warnings, ran on both XAMPP databases.
The website returned HTTP 200 and the database worker heartbeat was observed on the new connection.
The original verification also passed against MySQL 8.4.10.
PHP formatting, Composer platform/manifest checks, dependency audits, Blade compilation and the production asset build passed.
Mobile layout was checked at 390px without horizontal overflow; a cross-origin POST without a CSRF token returned HTTP 419.

A browser smoke test also exercised registration, signed verification, real CSV upload, worker completion,
preview and authenticated HTTP download. The returned file was a valid XLSX ZIP package, and its SHA-256
matched the server's real output; parsed values included `123.4500`, `000123` and a 20-digit identifier.
The synthetic browser account and its documents were deleted through the account UI afterward.
The integrated browser did not expose a native download event, so download bytes were checked with authenticated browser `fetch`.
The expanded browser smoke test also completed a queued RTF-to-PDF conversion and a ZIP-of-CSV-to-XLSX
conversion. Authenticated downloads returned valid PDF/ZIP signatures and the correct actual filenames;
account export included output extensions and warnings. Its synthetic account and files were deleted through
the account UI. The expanded directory/search also worked at 390px without horizontal overflow.

### MySQL verification observed locally

```text
MySQL 8.4.10; database office_converters; PDO driver mysql
All five application migrations: Ran
Same migrations ran independently on office_converters_test
Worker heartbeat observed during an actual queued browser conversion
```

The initial verification above used MySQL. The app was subsequently switched explicitly to XAMPP MariaDB at the user's request;
the same migrations and integration suite were rerun there. This is an intentional configuration change, not an automatic fallback.
Composer and npm dependency audits reported no known advisories at verification time; Python dependency
compatibility was checked with `pip check`. These checks are not a comprehensive security certification.

## Retention, operations and recovery

- Default retention is 24 hours from submission, not completion. Expired downloads are blocked immediately.
- `converters:cleanup` runs every ten minutes, removes expired files/previews, then purges expired metadata after seven days.
- Processing jobs are skipped by cleanup; `converters:recover` runs every five minutes and recovers jobs abandoned for ten minutes.
- Completed/failed/expired jobs are not processed again. Automatic retries use 10/30/90-second backoff and stop after three tries.
  The manual retry endpoint stops after six recorded processing attempts and respects active-job limits.
- Generated ZIPs are deleted after the HTTP response. Cleanup removes abandoned ZIPs after one hour.
- Local engine workspaces and LibreOffice profiles use short OS-temporary paths to avoid Windows `MAX_PATH`
  failures. Their private per-job workspace records allow PHP to remove them after success, rejection or timeout.
  Expiration, document deletion and account deletion also clean recorded workspaces after interrupted workers.
  No broad sweep of other applications' temporary files is performed.
- Minimal per-account submission counts enforce the rolling daily quota even when conversion history is deleted.
  These counters have no document names/content and are removed after two days or account deletion.
- Account deletion removes files and relational conversion records, clears that user's sessions/reset tokens,
  and detaches the user ID from retained audit events. Backups are not rewritten by application deletion.
- Back up MySQL, private storage and the application key consistently. Encrypt backups and restrict access.
  Restore to an isolated environment, preserve the key, run migration/health checks and test owner-only downloads.
  Apply a separately documented backup retention/deletion policy; never restore production data into the automated test database.

Troubleshooting:

| Symptom | Check |
| --- | --- |
| Database connection refused | Start XAMPP's MySQL service; check `DB_HOST`, port 3306 and `converters:health`. |
| Access denied | Check the ignored environment file and host-specific database grant; do not switch database engines. |
| Permanently queued | Start the database queue worker; inspect heartbeat, queue counts and private operational logs. |
| Verification link fails | Use the same origin as `APP_URL`, check expiry and ensure the correct account is signed in. |
| No email in inbox | The local mail driver is `log`; configure SMTP for actual delivery. |
| Upload rejected before validation | Check PHP/web-server request limits; use the supplied development wrapper. |
| Advanced groups missing | Check configured executable paths, Python requirements, English OCR data and local-engine health; wait for the 60-second health cache. |
| Workbook rejected | Review the explicit restriction; remove macros, external resources or active/unsupported formulas. With engines disabled, export one plain values-only sheet. |
| Expired download | Submit a new conversion; do not extend access to expired private files. |
| Asset manifest missing | Run `npm ci --ignore-scripts` and `npm run build`. |
| New worker code not taking effect | Run `php artisan queue:restart`, then restart the supervised worker/task. |

## Architecture and licensing

`FormatRegistry` is the single capability/MIME/limitation registry. `Converter` defines the adapter boundary.
`TabularConverter` handles strict data conversion; `LocalEngineConverter` invokes the isolated Python bridge
using argument arrays and a JSON protocol, not shell-interpolated filenames. `ConversionResult` carries
the actual output extension, bounded preview and warnings. `ConvertDocument` coordinates persisted job states.
Controllers/Form Requests/policies separate HTTP validation, authorization and orchestration.
File contents stay on the private disk; MySQL stores metadata, account records, settings, queue work and audit events.

| Dependency | License | Notes |
| --- | --- | --- |
| Laravel | MIT | Application framework; installed through Composer. |
| OpenSpout | MIT | Spreadsheet engine; installed through Composer. |
| LibreOffice | MPL-2.0 | Separately installed headless Office engine; consult its bundled third-party notices. |
| Tesseract | Apache-2.0 | Separately installed OCR engine with English language data. |
| Pillow | MIT-CMU | Image processing; Python environment. |
| pdfplumber / python-docx / python-pptx / openpyxl | MIT | PDF tables and Office-package libraries. |
| pypdf | BSD-3-Clause | PDF structure/writing. |
| pypdfium2 / PDFium | BSD-3-Clause, Apache-2.0 and dependency licenses | PDF rendering; preserve bundled license notices when redistributing. |
| pytesseract / defusedxml | Apache-2.0 / PSF License | OCR bridge and safe XML parsing. |
| Symfony components | MIT | Laravel/process infrastructure. |
| Tailwind CSS / Vite | MIT | Build-time frontend tooling. |
| PHPUnit / Laravel Pint | BSD-3-Clause / MIT | Development testing/formatting tools. |
| PHP | PHP License 3.01 | Runtime; separately installed. |
| MySQL Community Server | GPL-2.0 | Separately installed local server, not bundled with this source tree. |

Consult the installed packages' license files and lockfiles for the complete transitive inventory and exact versions.
No paid conversion API or Ghostscript is used. LibreOffice and Tesseract are installed separately;
Python packages are installed from the pinned requirements, not vendored into this source tree.

## Remaining stages and known risks

The requested conversion groups now have real local engines. Remaining production features include
antivirus/quarantine, hardened filesystem/network isolation, full role/user administration, configurable guest access,
per-format admin controls, MFA, optional versioned API, deployment automation and public-load validation remain.
Do not market this stage as an all-extension, perfect-fidelity or production-certified converter.
There is no guarantee for proprietary, damaged, encrypted or engine-incompatible documents.
