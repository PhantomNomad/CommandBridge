#!/usr/bin/env python3
"""
Builds assets/pdf/official/ICS209WF.pdf (fillable) from the flattened ICS Canada ICS 209-WF sheet (ICS209-wf.pdf).
Input boxes are found from the grey / coloured fills of the sheet (render at 144 dpi, connected components), then
named and added as AcroForm widgets.   Usage: build_wf_template.py <in.pdf> <out.pdf> <spec.json>
Needs pikepdf, pdftoppm, numpy, scipy, Pillow.
"""
import sys, subprocess, json, os, tempfile
import numpy as np, pikepdf
from pikepdf import Name, Dictionary, Array, String
from PIL import Image
from scipy import ndimage

SRC, OUT, SPEC = sys.argv[1], sys.argv[2], sys.argv[3]
tmp = tempfile.mkdtemp()
subprocess.run(['pdftoppm', '-r', '144', '-png', SRC, tmp + '/p'], check=True)

def comps(pg, color, tol=2, minarea=100):
    fn = [f for f in os.listdir(tmp) if f.startswith('p-') and int(f[2:-4]) == pg][0]
    im = np.array(Image.open(os.path.join(tmp, fn)).convert('RGB')).astype(int)
    m = np.ones(im.shape[:2], bool)
    for k in range(3): m &= abs(im[:, :, k] - color[k]) <= tol
    lab, n = ndimage.label(m)
    out = []
    for i, s in enumerate(ndimage.find_objects(lab)):
        h = s[0].stop - s[0].start; w = s[1].stop - s[1].start
        area = int((lab[s] == i + 1).sum())
        if area >= minarea and w > 8 and h > 6:
            out.append(dict(x=s[1].start / 2, y=s[0].start / 2, w=w / 2, h=h / 2, fill=area / (w * h)))
    return sorted(out, key=lambda c: (round(c['y'] / 6), c['x']))

GRAY = (242, 242, 242)
fields = []   # dict(name, page, x, y, w, h, kind, fs, multi, q, rot, tip)

def add(name, page, c, kind='text', fs=8, multi=False, q=0, rot=0, tip=None):
    fields.append(dict(name=name, page=page, x=c['x'], y=c['y'], w=c['w'], h=c['h'], kind=kind, fs=fs, multi=multi, q=q, rot=rot, tip=tip or name))

def pick(cs, x, y, tol=3):
    for c in cs:
        if abs(c['x'] - x) <= tol and abs(c['y'] - y) <= tol: return c
    raise SystemExit('no box near %s,%s' % (x, y))

# ---------------- page 1 -----------------
g = comps(1, GRAY)
P1 = [  # name, x, y, kwargs
    ('wf_incident_name', 38, 98, {}), ('wf_incident_no', 348, 98, {}), ('wf_report_number', 131, 144, {'fs': 9}),
    ('wf_incident_commanders', 184, 144, {'multi': True}), ('wf_incident_mgmt_org', 348, 144, {'multi': True}),
    ('wf_start_date', 554, 144, {}), ('wf_start_time', 554, 162, {}), ('wf_pct_contained', 230, 180, {}),
    ('wf_current_size', 38, 198, {'multi': True}), ('wf_complexity_level', 348, 198, {'multi': True}),
    ('wf_period_from', 576, 199, {'fs': 7}), ('wf_pct_completed', 230, 213, {}), ('wf_period_to', 576, 213, {'fs': 7}),
    ('wf_prepared_name_position', 174, 246, {}), ('wf_submitted_datetime', 473, 258, {'multi': True}),
    ('wf_prepared_signature', 38, 276, {}), ('wf_prepared_datetime', 394, 276, {'fs': 7}),
    ('wf_approved_name_position', 174, 307, {}), ('wf_approved_signature', 38, 338, {}), ('wf_approved_datetime', 394, 338, {'fs': 7}),
    ('wf_sent_to', 473, 338, {'multi': True}),
    ('wf_province', 130, 383, {}), ('wf_county', 328, 383, {'fs': 7}), ('wf_city', 486, 383, {}),
    ('wf_unit_other', 130, 415, {'multi': True}), ('wf_jurisdiction', 313, 415, {'multi': True}), ('wf_ownership', 502, 415, {'multi': True}),
    ('wf_longitude', 38, 469, {}), ('wf_latitude', 131, 469, {}), ('wf_datum', 244, 469, {}), ('wf_legal_description', 442, 469, {'fs': 7}),
    ('wf_short_location', 38, 508, {'multi': True}), ('wf_utm', 442, 508, {}), ('wf_geospatial', 38, 546, {'multi': True}),
    ('wf_significant_events', 38, 616, {'multi': True, 'fs': 7}), ('wf_hazards', 38, 658, {'multi': True, 'fs': 7}),
]
for name, x, y, kw in P1:
    add(name, 1, pick(g, x, y), **kw)
for i, key in enumerate(['single_residences', 'multiple_residences', 'mixed_commercial_residential', 'nonres_commercial', 'other_minor_structures']):
    yy = [714, 734, 753, 774, 798][i]
    for j, col in enumerate(['threatened', 'damaged', 'destroyed']):
        add('wf_damage_%s_%s' % (key, col), 1, pick(g, [348, 441, 554][j], yy), q=1)
# report version radios and block 9 status boxes (not grey: placed from measured centres)
def box(cx, cy, d=9): return dict(x=cx - d / 2, y=cy - d / 2, w=d, h=d)
for nm, cy in (('initial', 146.3), ('update', 159.7), ('final', 172.8)):
    add('wf_version_' + nm, 1, box(61.2, cy), kind='check', tip='3. Report version: ' + nm)
for nm, cx in (('OC', 291.3), ('BH', 305.8), ('UC', 321.3), ('O', 337.8)):
    add('wf_status_' + nm, 1, box(cx, 218.7, 8.5), kind='check', tip='9. Status: ' + nm)

# ---------------- page 2 -----------------
g = comps(2, GRAY)
keys = ['fatalities', 'injuries_illness', 'trapped_rescue', 'missing', 'evacuated', 'sheltering_place', 'temporary_shelters',
        'received_immunizations', 'require_immunizations', 'quarantine', 'total_affected']
ys = [138, 154, 168, 182, 198, 212, 226, 242, 256, 270, 285]
for k, yy in zip(keys, ys):
    for grp, (xa, xb) in (('public', (214, 282)), ('responder', (534, 605))):
        add('wf_%s_%s_period' % (grp, k), 2, pick(g, xa, yy), q=1)
        add('wf_%s_%s_total' % (grp, k), 2, pick(g, xb, yy), q=1)
add('wf_life_safety_remarks', 2, pick(g, 38, 316), multi=True, fs=9)
add('wf_weather_concerns', 2, pick(g, 38, 448), multi=True, fs=9)
tys = [316, 331, 346, 360, 375, 390, 404, 419, 434, 448, 463, 478, 492, 507]
for i, yy in enumerate(tys):
    add('wf_threat_%d' % (i + 1), 2, pick(g, 605, yy), q=1, fs=9, tip='34. Management status %s: X if active' % 'ABCDEFGHIJKLMN'[i])
for i, yy in enumerate([522, 536, 551, 566]):
    add('wf_threat_extra_text_%d' % (i + 1), 2, pick(g, 348, yy), fs=7)
    add('wf_threat_extra_x_%d' % (i + 1), 2, pick(g, 605, yy), q=1, fs=9)
for k, yy in zip(['12h', '24h', '48h', '72h', 'after72h'], [608, 627, 646, 666, 686]):
    add('wf_projected_activity_' + k, 2, pick(g, 131, yy), multi=True, fs=7)
add('wf_objectives', 2, pick(g, 38, 722), multi=True, fs=9)

# ---------------- page 3 -----------------
g = comps(3, GRAY)
for k, yy in zip(['12h', '24h', '48h', '72h', 'after72h'], [137, 156, 176, 196, 216]):
    add('wf_threat_risk_' + k, 3, pick(g, 130, yy), multi=True, fs=7)
for k, yy in zip(['12h', '24h', '48h', '72h', 'after72h'], [260, 280, 300, 320, 339]):
    add('wf_critical_needs_' + k, 3, pick(g, 130, yy), multi=True, fs=7)
add('wf_strategic_discussion', 3, pick(g, 38, 420), multi=True, fs=9)
add('wf_planned_actions', 3, pick(g, 38, 552), multi=True, fs=9)
for nm, yy in (('projected_final_size', 685), ('completion_date', 702), ('demob_start_date', 719), ('costs_to_date', 736), ('final_cost', 752)):
    add('wf_' + nm, 3, pick(g, 282, yy))
add('wf_remarks', 3, pick(g, 38, 782), multi=True, fs=9)

# ---------------- page 4 -----------------
g = comps(4, GRAY, minarea=100)
beige = comps(4, (238, 236, 225), minarea=30)
pink = comps(4, (248, 224, 216), minarea=30)
blue = comps(4, (220, 230, 241), minarea=30)
labels = sorted([c for c in g if abs(c['y'] - 137) < 3 and abs(c['h'] - 45) < 3], key=lambda c: c['x'])
cols = [c['x'] for c in labels]
assert len(cols) == 25, len(cols)
for i, c in enumerate(labels):
    add('wf_res_label_%d' % (i + 1), 4, c, fs=7, q=0, rot=90, tip='49. Resource column %d (kind/type)' % (i + 1))
agencies = sorted([c for c in g if abs(c['x'] - 37.5) < 2 and abs(c['w'] - 113.5) < 2], key=lambda c: c['y'])
# the sheet draws each agency as two stacked grey cells of one row; merge pairs
rows = []
for a in agencies:
    if rows and abs(rows[-1]['y'] + rows[-1]['h'] - a['y']) < 1.5 and not rows[-1].get('merged') and False: pass
    rows.append(a)
print('agency cells', len(rows), file=sys.stderr)
beige_rows = sorted(set(round(c['y']) for c in beige if abs(c['x'] - cols[0]) < 3))
print('row ys', beige_rows, file=sys.stderr)
rowbox = []
for yy in beige_rows:
    c = [b for b in beige if abs(b['x'] - cols[0]) < 3 and abs(b['y'] - yy) < 2][0]
    rowbox.append((c['y'], c['h']))
rowbox = rowbox[:17]
for r, (y0, h) in enumerate(rowbox, 1):
    half = h / 2.0
    add('wf_agency_%d' % r, 4, dict(x=37.5, y=y0, w=113.5, h=h), multi=True, fs=7, tip='48. Agency or organization, row %d' % r)
    for ci, x0 in enumerate(cols, 1):
        wcol = [b for b in beige if abs(b['x'] - x0) < 3 and abs(b['y'] - y0) < 2][0]['w']
        add('wf_res_%d_%d' % (ci, 2 * r - 1), 4, dict(x=x0, y=y0, w=wcol, h=half), q=1, fs=6.5, tip='49. column %d row %d: number of resources' % (ci, r))
        add('wf_res_%d_%d' % (ci, 2 * r), 4, dict(x=x0, y=y0 + half, w=wcol, h=half), q=1, fs=6.5, tip='49. column %d row %d: personnel' % (ci, r))
    add_c = [b for b in beige if abs(b['x'] - 534) < 3 and abs(b['y'] - y0) < 2][0]
    add('wf_add_personnel_%d' % r, 4, add_c, q=1, fs=7, tip='50. Additional personnel, row %d' % r)
    pk = [p for p in pink if abs(p['y'] - y0) < 2][0]
    add('wf_total_personnel_%d' % r, 4, pk, q=1, fs=8, tip='51. Total personnel, row %d' % r)
pk = sorted(pink, key=lambda p: p['y'])[-1]
add('wf_total_personnel_18', 4, pk, q=1, fs=8, tip='51. Grand total personnel')
bl = sorted(blue, key=lambda b: b['x'])
for i, x0 in enumerate(cols, 1):
    add('wf_total_resources_%d' % i, 4, [b for b in bl if abs(b['x'] - x0) < 3][0], q=1, fs=6.5, tip='52. Total resources, column %d' % i)
add('wf_total_resources', 4, [b for b in bl if abs(b['x'] - 534) < 3][0], q=1, fs=7, tip='52. Grand total resources')
big = [c for c in g if c['w'] > 600 and c['h'] > 100][0]
add('wf_coop_orgs', 4, big, multi=True, fs=8, tip='53. Additional cooperating and assisting organizations')

# ---------------- write the PDF ---------------
pdf = pikepdf.open(SRC)
H = float(pdf.pages[0].MediaBox[3])
af = pdf.Root.AcroForm
fa = af.get('/Fields')
if fa is None:
    af.Fields = Array([]); fa = af.Fields
af.DA = String('/Helv 0 Tf 0 g ')
def stream(data, bbox):
    s = pikepdf.Stream(pdf, data)
    s.Type = Name.XObject; s.Subtype = Name.Form; s.BBox = Array(bbox)
    return s
for f in fields:
    page = pdf.pages[f['page'] - 1]
    x0 = f['x'] + 0.4; w = f['w'] - 0.8
    y1 = H - f['y'] - 0.4; h = f['h'] - 0.8
    rect = Array([round(x0, 2), round(y1 - h, 2), round(x0 + w, 2), round(y1, 2)])
    d = Dictionary(Type=Name.Annot, Subtype=Name.Widget, T=String(f['name']), TU=String(f['tip']), F=4, Rect=rect, P=page.obj)
    if f['kind'] == 'check':
        bw, bh = round(w, 2), round(h, 2)
        on = ('q 0 G 1.2 w %.2f %.2f m %.2f %.2f l S %.2f %.2f m %.2f %.2f l S Q' % (1.5, 1.5, bw - 1.5, bh - 1.5, 1.5, bh - 1.5, bw - 1.5, 1.5)).encode()
        d.FT = Name.Btn
        d.AP = Dictionary(N=Dictionary(Yes=stream(on, [0, 0, bw, bh]), Off=stream(b'', [0, 0, bw, bh])))
        d.AS = Name.Off
        d.DA = String('/ZaDb 0 Tf 0 g')
        d.MK = Dictionary()
    else:
        d.FT = Name.Tx
        d.DA = String('/Helv %s Tf 0 g' % ('%g' % f['fs']))
        ff = 0
        if f['multi']: ff |= 4096
        if ff: d.Ff = ff
        if f['q']: d.Q = f['q']
        mk = Dictionary()
        if f['rot']: mk.R = f['rot']
        d.MK = mk
    ref = pdf.make_indirect(d)
    if '/Annots' not in page: page.Annots = Array([])
    page.Annots.append(ref)
    fa.append(ref)
pdf.save(OUT)
json.dump([{k: (round(v, 2) if isinstance(v, float) else v) for k, v in f.items()} for f in fields], open(SPEC, 'w'), indent=0)
print('fields', len(fields), file=sys.stderr)
