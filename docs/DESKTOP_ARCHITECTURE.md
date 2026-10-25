# Windows desktop architecture

## Existing application and choice

The original application is Laravel 13 with Blade/Vite, MySQL/MariaDB accounts/metadata/queues, and
real PHP/Python conversion adapters. It previously required manually installed PHP, Python,
LibreOffice, Tesseract, a database server and separately started workers.

The desktop product retains the tested UI, authorization policies and conversion engines behind
Electron, rather than rewriting conversion behavior. Electron bundles Chromium and its runtime, avoids
an externally launched browser, and provides native menus, Save dialogs, lifecycle control and mature
NSIS packaging. Tauri/native WebView alternatives introduce a separate native toolchain/WebView
prerequisite without simplifying PHP/Office engine packaging. The tradeoff is a larger installer and a
Chromium/Electron maintenance obligation.

## Installed layout

- Electron executable and hardened application archive.
- `resources/payload/app`: production Laravel code/assets/Composer dependencies; no `.env` or user data.
- `resources/payload/php`: production PHP and required extensions.
- `resources/payload/python`: standalone CPython, isolated paths and pinned conversion libraries.
- `resources/payload/libreoffice`: local Office conversion engine, fonts and notices.
- `resources/payload/tesseract`: OCR executable/libraries and English/orientation models.
- App-local Microsoft C++ runtime DLLs are included for PHP, Python and LibreOffice; no global
  runtime installer or administrator action is needed.
- Runtime inventory, executable hashes and third-party notices.

The Windows offline installer installs the complete payload, Start Menu shortcut and uninstaller for
the current user. Nothing in the installed application points back to the development directory.

## Startup and process ownership

1. Acquire Electron's single-instance lock.
2. Create per-user data directories, generate/preserve the application key and create a SQLite file.
3. Build a minimal child environment with only packaged engine paths, production/debug-off settings,
   file-based cache/sessions and per-user Laravel cache/storage paths.
4. Discover production packages, back up metadata on version upgrades, migrate SQLite and mark work
   interrupted during a previous launch as failed/retryable.
5. Start a loopback PHP service on an OS-selected port, a database queue worker and maintenance
   scheduler. Each service is owned by a Windows Job Object supervisor.
6. Wait for a token-protected engine-readiness response tied to this launch before displaying the
   workspace. A missing/broken engine fails startup visibly.

The supervisors watch their host pipes. A normal shutdown or desktop-host crash closes those pipes
and terminates the entire service/descendant process tree. The Python conversion bridge retains its
own resource limits. The installer does not install a permanent background Windows service.

## Local access and UI isolation

- The router gates every HTTP request, including static assets, using a random 256-bit per-launch
  token. Laravel additionally checks desktop requests originate on loopback.
- The Electron session injects the token only for the exact local origin and blocks other network
  origins. The readiness response is checked against a SHA-256 launch fingerprint.
- Node integration is off, context isolation and the Chromium sandbox are on, remote windows/webviews
  and permission requests are denied, and production developer tools are disabled.
- Electron's RunAsNode/NodeOptions/Node inspector fuses are off; embedded archive validation and
  only-load-from-ASAR fuses are on.
- Existing Laravel CSRF, input validation, format restrictions and owner-download policies remain.
- No account or email service is needed: one non-administrator local identity is used in desktop mode,
  and account/admin/registration/password-reset routes are omitted.

## Persistence and outputs

SQLite with WAL, a busy timeout and foreign keys replaces MySQL for desktop metadata and queues.
It is suitable for one local user and one worker; it is not a multi-user database-server replacement.
The existing hosted web deployment retains MySQL as its default.

User data lives in `%APPDATA%\OfficeConverters`, not the install directory. The application key is
created once and preserved; version changes produce consistent SQLite backups before migrations.
Unexpected settings/key corruption raises a visible error rather than silently resetting data.
Uninstallation preserves history/settings and saved outputs.

Downloads use native Save dialogs with overwrite confirmation and numbered suggested filenames.
The default output directory is `Documents\Office Converters`, configurable from the File menu.
Temporary/private files retain the existing documented retention and cleanup policies. Originals
selected by the user are copied into private storage, never removed.

## Remaining production gates

The installer is unsigned without a publisher certificate. Full clean-Windows/offline, upgrade and
recovery certification, native dependency redistribution/source obligations, and representative
document fidelity review remain necessary before a public production release. The desktop shell
does not improve PDF reconstruction or OCR accuracy beyond the engines' documented limitations.
