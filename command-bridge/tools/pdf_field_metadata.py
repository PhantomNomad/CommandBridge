#!/usr/bin/env python3
"""Developer tool: write the size, font size and multi-line flag of every text field in the official ICS PDF templates.

    python3 tools/pdf_field_metadata.py            (needs: pip install pypdf)

Writes app/assets/pdf/official/fields/<file>.json, used by app/includes/pdf/OfficialPdfText.php to fit text into the
boxes. Run it again whenever a template PDF in app/assets/pdf/official is replaced. Only field names and geometry are
written, no form text.
"""
import json, os, re, sys
from pypdf import PdfReader

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'app', 'assets', 'pdf', 'official')
OUT = os.path.join(ROOT, 'fields')

def full_name(obj):
    parts = []
    while obj is not None:
        if '/T' in obj:
            parts.append(str(obj['/T']))
        parent = obj.get('/Parent')
        obj = parent.get_object() if parent is not None else None
    return '.'.join(reversed(parts))

def inherited(obj, key):
    while obj is not None:
        if key in obj:
            return obj[key]
        parent = obj.get('/Parent')
        obj = parent.get_object() if parent is not None else None
    return None

def main():
    os.makedirs(OUT, exist_ok=True)
    for name in sorted(os.listdir(ROOT)):
        if not name.lower().endswith('.pdf'):
            continue
        reader = PdfReader(os.path.join(ROOT, name))
        acro = reader.trailer['/Root'].get('/AcroForm')
        default_da = str(acro.get_object().get('/DA', '')) if acro else ''
        fields = {}
        for page_no, page in enumerate(reader.pages, start=1):
            mb = [float(x) for x in page.mediabox]
            for annot in page.get('/Annots') or []:
                a = annot.get_object()
                if a.get('/Subtype') != '/Widget' or inherited(a, '/FT') != '/Tx':
                    continue
                fname = full_name(a)
                x1, y1, x2, y2 = [float(v) for v in a['/Rect']]
                w, h = abs(x2 - x1), abs(y2 - y1)
                on_page = mb[0] <= min(x1, x2) and max(y1, y2) <= mb[3] + 1
                da = str(inherited(a, '/DA') or default_da)
                m = re.search(r'([0-9.]+)\s+Tf', da)
                size = float(m.group(1)) if m else 0.0
                multiline = 1 if int(inherited(a, '/Ff') or 0) & 4096 else 0
                entry = [round(w, 1), round(h, 1), size, multiline, page_no]
                if fname not in fields or (on_page and fields[fname][5] == 0):
                    fields[fname] = entry + [1 if on_page else 0]
        out = {k: v[:5] for k, v in fields.items()}
        with open(os.path.join(OUT, os.path.splitext(name)[0] + '.json'), 'w') as fh:
            json.dump(out, fh, indent=0, sort_keys=True)
        print(f'{name}: {len(out)} text fields')

if __name__ == '__main__':
    sys.exit(main())
