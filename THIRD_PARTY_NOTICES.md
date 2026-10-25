# Third-party notices

Office Converters includes third-party software. The installer is a pre-release test build, not a
claim of universal conversion accuracy or completed legal certification.

| Component | Licence / source | Packaged notices |
| --- | --- | --- |
| Electron / Chromium | MIT / Chromium component licences; https://www.electronjs.org/ | `resources/payload/notices/LICENSE` and `LICENSES.chromium.html` |
| PHP | PHP licence and bundled library licences; https://www.php.net/ | `resources/payload/php/license.txt`, `readme-redist-bins.txt` |
| CPython | PSF licence and component notices; https://www.python.org/ | `resources/payload/python/LICENSE.txt` |
| LibreOffice | MPL 2.0 and component licences; https://www.libreoffice.org/ | Complete installed `LICENSE.html`, `license.txt`, `NOTICE`, and component notices retained in `resources/payload/libreoffice` |
| Tesseract | Apache 2.0; https://github.com/tesseract-ocr/tesseract | `resources/payload/tesseract/doc/LICENSE` |
| Tesseract Windows libraries | Various upstream licences, including LGPL and GCC runtime exceptions; https://github.com/UB-Mannheim/tesseract/wiki | Retained upstream documentation; consult the source-distribution requirements before public redistribution |
| English / orientation OCR models | Apache 2.0; https://github.com/tesseract-ocr/tessdata | Bundled `eng.traineddata`, `osd.traineddata`; upstream licence applies |
| Microsoft Visual C++ Runtime | Microsoft Visual Studio redistribution terms; https://aka.ms/VCRedistLicense | `SOURCE.txt` in each app-local CRT directory; pinned, unmodified Microsoft redistributable binaries |
| Composer production packages | Per-package licences | Package licence files retained under `resources/payload/app/vendor`; names, versions, licences and sources in `runtime-inventory.json` |
| Python conversion packages | Per-package licences | `.dist-info/METADATA`, `licenses` and package licence files retained in `resources/payload/python/Lib/site-packages`; inventory in `runtime-inventory.json` |

No proprietary Office installation or Microsoft Office font is copied into the installer. LibreOffice's
installed files and notices are retained. Windows-provided fonts are used at runtime where available;
font substitution can affect document layout.

Build-only tools (Composer, npm, electron-builder, NSIS, test fixtures) are not installed as application
prerequisites. The Electron runtime is bundled; Node.js does not need to be installed separately.

Before public distribution, complete the redistribution checklist in `docs/LICENSING.md`, particularly
matching source availability and notices for native libraries included by the selected LibreOffice and
Tesseract distributions. Retaining a licence file alone does not satisfy every possible source obligation.
