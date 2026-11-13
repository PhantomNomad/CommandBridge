# Official forms

`app/assets/pdf/official/` holds the ICS Canada form templates that Command Bridge fills and flattens with `pdftk`.
They are redistributed from the forms' publisher (<https://www.icscanada.ca>) and are **not** covered
by the AGPL licence of the code.

## Keeping them current

ICS Canada revises forms from time to time. Review them **at least twice a year and before each release**:

1. Compare the forms on the ICS Canada site with the versions here (the revision date is usually printed on the form).
2. If a form changed, replace the PDF, then check that the field mapper in `app/forms/<form>/` still fills every field
   (`bin/smoke_test.php --only=pdf` checks the ICS 209-WF fields).
3. Run `python3 tools/forms_manifest.py update` and commit `docs/forms-manifest.json`.

Helpers:

```bash
python3 tools/forms_manifest.py verify   # shipped files still match the manifest
python3 tools/forms_manifest.py check    # compare upstream_url entries with the recorded originals (needs internet)
```

To enable `check`, set `upstream_url` and `upstream_sha256` (hash of the original, unmodified download) for a form in
`docs/forms-manifest.json`. Forms we modified are flagged `modified_by_command_bridge` (for example ICS 209-WF, made fillable with
`tools/build_wf_template.py`).
