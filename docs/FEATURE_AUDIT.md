# Feature and desktop-readiness audit

## Existing functionality

The Laravel application provides a Blade/Vite UI, bounded uploads, private local storage, conversion
history, retry/cleanup behavior and explicit failures/warnings. Hosted web mode also retains accounts,
email verification, administration and MySQL/MariaDB. Desktop behavior is explicitly gated and does
not silently change the existing web deployment.

OpenSpout handles strict tabular formats; LibreOffice handles Office conversion; isolated Python,
PDFium/pypdf/pdfplumber, Pillow and Tesseract provide PDF/OCR/image adapters. The shared capability
manifest advertises only implemented, healthy pairs. No conversion is a filename-extension rename
and no document is submitted to a remote conversion service.

## Desktop implementation

| Area | Implemented behavior | Remaining validation |
| --- | --- | --- |
| Launch | Hardened Electron window; installed Start Menu/desktop shortcuts tested | Clean-machine shortcut/first-launch certification |
| PHP | Bundled production PHP, extensions and Composer dependencies | Fresh Windows with no global runtimes |
| Database | Per-user SQLite, WAL/busy timeout, file sessions/cache | Long-running/stress and production-scale recovery review |
| Queue | Host-managed database worker/scheduler; Job Object descendants stop on exit | Extended interrupted-conversion UX testing |
| Conversion executables | Bundled controlled Python/LibreOffice/Tesseract versions, English OCR data and app-local CRT | Representative customer-document fidelity review |
| Accounts | One verified local non-admin identity, no account routes; per-launch loopback token | Normal public-release hardening review |
| Installer | Complete offline per-user NSIS, shortcuts, uninstaller, retained user data | Fresh-VM install/upgrade/uninstall matrix and trusted signing |
| Offline | Local storage/engines; external renderer requests blocked; no runtime dependency downloads | Fresh VM with networking disabled |
| Updates | Manual trusted installer, version-based SQLite backup before migration | Cross-version production migration/recovery testing |
| Licences | Runtime inventory, sources/notices, pinned official CRT with signature/hash verification | Redistribution/source-offer review before public release |

## Observed validation

- Original full MySQL/MariaDB web baseline: 64 tests / 538 assertions.
- Latest focused hosted-web account/conversion regressions: 32 tests / 304 assertions.
- Existing real-engine PHP suite: 16 tests / 174 assertions.
- Isolated desktop SQLite suite: 4 tests / 36 assertions.
- Node launcher and actual Windows descendant-lifecycle suite: 5 tests.
- Bundled-runtime smoke: eight real queued conversions with parsed Office/PDF/OCR outputs, protected
  static assets, CSRF/cookies, no account routes, upgrade backup/key/history preservation.
- Packaged Electron native window: actual workspace title, HTTP 200 compiled assets, readiness log,
  responsive window and no surviving packaged runtime processes after close.
- Actual NSIS installation: per-user install/registration, Start Menu launch, single-instance reuse,
  eight parsed-output conversions from the installed payload with Unicode data paths, and uninstall.
  Uninstall removed shortcuts/registration and retained the user key/database unchanged.
- npm dependency audit: no reported vulnerabilities at verification time.

The smoke tests validate useful representative outputs, not every complex document layout. The registry's
117 available pairs and original all-pairs engine tests are not 117 clean-machine certifications.

## Production release gates

No clean Windows VM, trusted publisher certificate or legal redistribution certification has been
provided. An unsigned developer-machine-tested installer is a usable pre-release, not a completed
public production-release certification. Complete fresh-Windows/offline install/shortcut/upgrade/
uninstall checks and the licensing checklist before a public release.
