# Windows installer

## End-user installation

Run `dist/officeConverters-Setup-x64.exe` on Windows 10/11 x64. The offline NSIS wizard installs for
the current user, allows an installation-directory choice, creates Start Menu and desktop shortcuts,
and registers an uninstaller. Reinstalling also recreates the desktop shortcut if it was removed.
Launch **Office Converters** from the Start
Menu. No PHP, Composer, Node.js, Python, MySQL, LibreOffice or Tesseract installation is required.

The installer includes Electron, PHP, a standalone Python runtime and pinned libraries, LibreOffice,
Tesseract with English OCR data, and app-local Microsoft C++ runtime DLLs. It does not download
prerequisites on the target PC. SQLite replaces MySQL for desktop metadata.

The first launch creates the local database, applies migrations and checks all bundled engines before
opening the workspace. No account, email verification, remote hosting or cloud service is required.
The web deployment remains a separate, unchanged-default configuration.

This is version 0.1.0, an **unsigned pre-release**. Windows may show an unknown publisher or SmartScreen
warning. Only use an installer from a trusted source and verify its SHA-256; do not disable Windows
security controls. A trusted signing certificate is required for a signed public release.

## Building on Windows

Build-time tools are not end-user prerequisites. Install supported Node/npm, Composer and the controlled
x64 runtime versions listed in `desktop/runtime-versions.json` from their official sources. The build
requires those version matches and the English/orientation Tesseract models. The runtime directories
may be specified explicitly; they are copied into the installer, never referenced on the target PC.

```powershell
npm ci --ignore-scripts
composer install --no-interaction
pwsh -NoProfile -File scripts\build-windows.ps1 `
  -PhpDirectory C:\php85 `
  -PythonDirectory C:\Python314 `
  -LibreOfficeDirectory "C:\Program Files\LibreOffice" `
  -TesseractDirectory "$env:LOCALAPPDATA\Programs\Tesseract-OCR"
```

Use PowerShell 7 with the machine's normal script policy. Do not change execution policy or disable
security controls. The build downloads pinned npm/Python/Composer build inputs and the pinned,
Microsoft-signed CRT redistributable. If its hash changes, the build stops for explicit review.
The Electron binary is installed explicitly because dependency lifecycle scripts are disabled.

Use `-StageOnly` to create and validate the runtime payload without compiling NSIS. On a subsequent
clean build, add `-Rebuild`; this removes only the generated `.desktop-build/payload` directory before
restaging. Build caches and the current web environment/database are not removed or modified.

Outputs:

- `dist/officeConverters-Setup-x64.exe`: complete offline installer.
- `dist/officeConverters-Setup-x64.sha256.json`: installer integrity digest.
- `dist/win-unpacked/`: intermediate installed-layout test build, not a supported portable release.
- `dist/win-unpacked/resources/payload/runtime-inventory.json`: actual bundled versions, production
  package licences/sources and principal executable/OCR hashes.

## Build gates

The build runs Node launcher/lifecycle tests, isolated SQLite desktop tests, Vite production compilation,
a production-only Composer install, standalone Python import checks, and real queued conversion smoke
tests before packaging. It never copies `.env`, web credentials, the existing database, user documents,
development caches, `node_modules`, Composer, npm, or the build-machine Python environment into the
application runtime. The standalone conversion Python has no pip; the intact upstream LibreOffice
distribution may contain its own Python support/tooling.

The smoke suite starts only bundled PHP/Python/LibreOffice/Tesseract with an environment that excludes
the developer's PATH, database settings and Python paths. It validates CSV/XLSX cell content, RTF/PDF
text, native and scanned PDF-to-DOCX text, OCR image text and PPTX/PDF content with real parsers. It
also checks the token gate, static assets, SQLite queue, upgrade backup and preservation of the key.

Run against an installed payload:

```powershell
node scripts\smoke-desktop.cjs --payload "C:\path\to\Office Converters\resources\payload"
```

## Data, upgrades and removal

- Application data: `%APPDATA%\OfficeConverters`.
- Settings/key/database/backups: that user-data directory; the key must be backed up with the database.
- Private working files: `storage\app\private` under user data; temporary workspaces: `temp`.
- Detailed diagnostics: `logs\desktop.log` and `storage\logs\laravel.log`.
- Saved outputs default to `Documents\Office Converters`; choose another directory in **File > Choose
  output folder**. The native Save dialog confirms overwrites; suggested duplicate names are numbered.

Close the app and install a newer trusted installer over the current version. The application key and
metadata are retained. Version changes trigger a consistent SQLite backup before migrations. Work
interrupted by a previous application exit is marked failed with a retry message, not reported successful.
There is no automatic updater or online conversion dependency.

Uninstall through Windows Settings. Saved documents, settings and history are deliberately retained.
Reinstallation can reuse them. To remove them permanently, back up any needed files, uninstall/close
the app, and remove the specific `%APPDATA%\OfficeConverters` folder yourself. The uninstaller never
deletes source documents or saved output directories.

## Release acceptance

The generated 0.1.0 installer was installed into a separate per-user QA directory on the development
Windows machine. Its Start Menu and desktop shortcuts and Windows uninstall registration were checked.
Launching through the actual Start Menu shortcut opened the responsive conversion workspace; a second
launch reused the same window. All eight parsed-output smoke conversions passed against the installed
payload, using a Unicode/spaced temporary data path, along with upgrade backup/key/history checks.
Closing the app left no installed runtime processes. The actual uninstaller removed the application,
shortcuts and registration while retaining the key and SQLite database unchanged.

The application's copyright holder is **Hamid Reza Bayat**, displayed in executable metadata, the
installer's installation details and **Help > About Office Converters**. Third-party notices remain
unchanged. The generated `dist/officeConverters-Setup-x64.sha256.json` contains the authoritative
SHA-256 for the current installer.

An installer built and tested on a developer Windows machine is not a clean-machine certification.
Before a production release, test on a fresh Windows VM with developer tools absent and networking
disabled: install wizard, shortcuts, first launch, conversions, cancellation/failures, upgrade, interrupted
install recovery, uninstall and reinstall. Verify fonts/layout with representative real documents.
Complete the licensing checklist in `LICENSING.md` and configure trusted code signing.
