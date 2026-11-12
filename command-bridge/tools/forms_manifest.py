#!/usr/bin/env python3
"""Track the official ICS Canada form templates shipped in app/assets/pdf/official.

  python3 tools/forms_manifest.py verify   - confirm shipped files match docs/forms-manifest.json
  python3 tools/forms_manifest.py update   - (re)write the manifest from the files on disk
  python3 tools/forms_manifest.py check    - download each entry's upstream_url (if set) and report
                                             whether the published form changed (needs internet)

Review the forms at least twice a year and before each release; see docs/FORMS.md.
"""
import hashlib, json, sys, urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FORMS = ROOT / "app" / "assets" / "pdf" / "official"
MANIFEST = ROOT / "docs" / "forms-manifest.json"
SOURCE = "https://www.icscanada.ca"


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def load():
    return json.loads(MANIFEST.read_text()) if MANIFEST.exists() else {"source": SOURCE, "forms": {}}


def main(argv):
    cmd = argv[1] if len(argv) > 1 else "verify"
    m = load()
    if cmd == "update":
        forms = m.get("forms", {})
        for f in sorted(FORMS.glob("*.pdf")):
            e = forms.setdefault(f.name, {"upstream_url": "", "upstream_sha256": "", "modified_by_command_bridge": False, "notes": ""})
            e["sha256"] = sha(f.read_bytes())
        m["forms"] = forms
        m["source"] = SOURCE
        MANIFEST.write_text(json.dumps(m, indent=2, sort_keys=True) + "\n")
        print(f"Wrote {MANIFEST} ({len(forms)} forms)")
        return 0
    bad = 0
    for name, e in sorted(m.get("forms", {}).items()):
        p = FORMS / name
        if cmd == "verify":
            ok = p.exists() and sha(p.read_bytes()) == e.get("sha256")
            print(("ok       " if ok else "CHANGED  ") + name)
            bad += 0 if ok else 1
        elif cmd == "check":
            url = e.get("upstream_url")
            if not url:
                print("no url   " + name)
                continue
            try:
                data = urllib.request.urlopen(url, timeout=30).read()
            except Exception as ex:  # noqa: BLE001
                print(f"ERROR    {name}: {ex}")
                bad += 1
                continue
            same = sha(data) == e.get("upstream_sha256")
            print(("current  " if same else "UPDATED  ") + name + ("" if same else "  - published form differs from the recorded original"))
            bad += 0 if same else 1
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
