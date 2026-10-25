# Distribution and licensing

The build retains the runtime distributions' notices and production package metadata. Its generated
`resources/payload/runtime-inventory.json` records the actual PHP, Python, LibreOffice and Tesseract
versions, production Composer/Python packages, and hashes of the principal executables and English OCR
data. `THIRD_PARTY_NOTICES.md` points to the notices shipped with the installer.

## Build inputs

- Use official, unmodified Windows x64 PHP and CPython distributions. The build stages runtime files,
  not Composer, pip, npm, source repositories, or development environment files.
- Use a legitimately installed LibreOffice distribution with its complete notices and bundled fonts.
  Source and licence information: https://www.libreoffice.org/about-us/licenses/
- Use a Tesseract distribution with English and orientation models. The selected Windows binary
  distribution is UB Mannheim: https://github.com/UB-Mannheim/tesseract/wiki
- Microsoft CRT DLLs are extracted unchanged from a pinned, Microsoft-signed official redistributable.
  Source and terms: https://aka.ms/vc14/vc_redist.x64.exe and https://aka.ms/VCRedistLicense
- Electron and build tools are pinned by the npm lockfile; Composer production packages are locked;
  the Windows Python runtime closure is pinned in `scripts/requirements-desktop.txt`.

## Public redistribution checklist

1. Review the generated inventory and all bundled native dependency licences.
2. Provide corresponding source or an appropriate source offer where required, including LGPL/native
   dependencies in the chosen Tesseract/LibreOffice binaries. Preserve relinking/replacement rights.
3. Retain all copyright, attribution and licence notices, including Chromium, PDFium, image codecs and
   CPython dependencies.
4. Confirm the Microsoft runtime redistribution terms apply to the intended distribution.
5. Verify the application's own licence and all branding permissions before claiming it is fully
   open-source or freely redistributable.
6. Sign the application and installer with a trusted publisher certificate for a public release.

This implementation does not provide legal advice or certify that a private test installer is ready
for unrestricted public redistribution. No signing certificate is configured, so generated installers
are unsigned and Windows may display an unknown-publisher/SmartScreen warning.
