# Third-party components

| Component | Use | Licence |
|---|---|---|
| FPDF 1.86 (Olivier Plathey) - `app/vendor/fpdf` | PDF generation | FPDF licence (free to use, modify and redistribute) |
| QRCode for JavaScript (Kazuhiko Arase) - `app/assets/js/external_checkin_qr.js` | Local QR codes | MIT |
| TCPDF (`php-tcpdf` Debian package) | PDF generation | LGPL-3.0 (installed by the installer, not bundled) |
| pdftk-java, poppler-utils, ghostscript | PDF form fill/flatten/preview | GPL / AGPL (installed by the installer, not bundled) |
| Apache, MariaDB, PHP | Runtime | their own licences (installed from Debian) |

## ICS Canada forms

The PDF templates in `app/assets/pdf/official/` are based on the Incident Command System forms published by
ICS Canada (<https://www.icscanada.ca>). They are included with the source. Some have been made
fillable or adjusted by Command Bridge (see `docs/forms-manifest.json`). The forms remain the property of their
owners and are not covered by this project's AGPL-3.0 licence. See [docs/FORMS.md](docs/FORMS.md).
