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

## Form exceptions

The current official templates for ICS 209-WF, ICS 218, ICS 233, and ICS 260 do not contain fillable AcroForm fields. They require a retained purpose-built renderer or an approved fillable replacement template. ICS 218 should continue to use its dedicated renderer until a replacement is available.

## Rollout order

The first conversion should be ICS 202. Its official template is fillable and it will validate the shared method with a multi-page operational form, narrative fields, checkboxes, dates, and approval information before the remaining forms are migrated.


## ICS 202 implementation

ICS 202 now uses this standard export path by default when the approved PDF engine is configured:

- The incident, operational period, objectives, command emphasis, situational awareness, site-safety fields, IAP checklist, and preparer/approver details are mapped to the official ICS Canada 202 template.
- The output is flattened before it is sent to the browser.
- `forms/ics202/pdf.php?legacy=1` explicitly uses the previous TCPDF renderer.
- Records with uploaded ICS 202 attachments continue to use the legacy renderer. This preserves the existing attachment append behaviour until standardized PDF attachment merging is added.
- If `pdftk` is not installed or cannot be started, the legacy renderer is retained automatically and an error is written to the server log.

This provides a safe migration path: configure the PDF engine, validate ICS 202 output, then apply the same registry/mapper pattern to the next form.
