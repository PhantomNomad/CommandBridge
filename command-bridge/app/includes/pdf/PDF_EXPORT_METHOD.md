# Standard PDF Export Method

Command Bridge exports completed ICS forms by filling the approved official ICS Canada PDF template and flattening the result. This keeps the published output aligned with the official layout rather than re-drawing each form in PHP.

## Components

- `OfficialPdfTemplateRegistry.php` is the single approved registry of PDF templates.
- `OfficialPdfTemplateExporter.php` creates FDF form data, calls the local `pdftk` command, and flattens the PDF.
- Form-specific PDF endpoints remain responsible for authorization, record retrieval, and mapping application data to official PDF field names.

Only a template registered in `OfficialPdfTemplateRegistry` can be used. The exporter never accepts a user-supplied template path.

## Server configuration

Install an approved `pdftk` implementation on the Apache/PHP host, typically `pdftk-java`, and make the `pdftk` executable available to the PHP process.

Set these environment variables in the Apache or PHP-FPM service configuration as appropriate:

```txt
COMMAND_BRIDGE_PDFTK_BIN=/usr/bin/pdftk
COMMAND_BRIDGE_PDF_TEMP_DIR=/var/tmp/commandbridge-pdf
```

`COMMAND_BRIDGE_PDF_TEMP_DIR` must be outside the web root, writable by the web-service account, and restricted so that generated incident documents cannot be retrieved directly over HTTP.

The System Information page reports whether PHP can start approved processes, whether the configured PDF engine responds, whether the temporary directory is writable, and whether all registered templates are present. It does not expose incident data or run a form export.

## Form integration pattern

Each form endpoint must:

1. Require login and confirm the user can view the requested form.
2. Retrieve only the active incident and permitted form record.
3. Translate application fields to the official template's exact field names.
4. Use `OfficialPdfTemplateExporter::create()` to make the flattened PDF.
5. Use `sendDownload()` immediately, which removes the temporary file after delivery.

Example:

```php
require_once $appRoot . '/includes/pdf/OfficialPdfTemplateExporter.php';

$exporter = new OfficialPdfTemplateExporter();
$pdfPath = $exporter->create('ICS213', [
    '1 Incident Name Optional' => (string)$incident['name'],
    '4 Subject' => (string)$message['subject'],
    '7 Message' => (string)$message['message'],
    // Checkbox/radio states use a PDF name, for example:
    // 'Some Checkbox' => ['value' => 'Yes', 'type' => 'name'],
]);
$exporter->sendDownload($pdfPath, 'ICS213_Incident_Message.pdf');
exit;
```

Do not place field maps in browser JavaScript, pass field names from requests, or accept PDF paths from users.

## Every form uses the official template

Every ICS form's PDF (`forms/<form>/pdf.php`) fills the official ICS Canada template; nothing is redrawn in PHP.

- **Field maps** live next to each form: `forms/<form>/Ics<form>OfficialPdfFieldMapper.php`.
- **Fitting text:** mappers put the full text in each field and call `OfficialPdfText::apply()`. It uses the real size
  and font of every box, from `assets/pdf/official/fields/<template>.json`, to cut text that does not fit and move the
  full text to a continuation sheet appended to the PDF (`ics_pdf_send_official_with_continuation()`).
- **More rows than the form has:** the template is filled again for the extra rows and the copies are joined
  (`OfficialPdfTemplateExporter::createPaged()`, which can also keep only some pages of a template, e.g. the ICS 214
  continuation page).
- **ICS 218** has no fillable fields. Its text is drawn on a transparent overlay at the template's coordinates
  (`forms/ics218/Ics218OfficialPdfOverlay.php`, using the bundled FPDF) and stamped onto the official page
  (`OfficialPdfTemplateExporter::stampTemplate()`).
- **Attachments** (ICS 201 maps, ICS 202 attachments) follow the official form as pages of their own.
- **Not ICS forms:** the ICS 230 "meeting posting" wall sheet keeps its own layout.

## When ICS Canada updates a form

1. Replace the PDF in `assets/pdf/official/` (same file name).
2. Run `python3 tools/pdf_field_metadata.py` (needs `pip install pypdf`) to refresh `assets/pdf/official/fields/`.
3. Compare the new field names with the form's mapper (`pdftk <file> dump_data_fields_utf8`) and fix any that changed.
   For ICS 218, re-measure the column and row positions in `Ics218OfficialPdfOverlay.php`.
4. Download a filled PDF of the form and check it.
