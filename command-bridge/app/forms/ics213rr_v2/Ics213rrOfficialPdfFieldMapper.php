<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/SimpleTextPdf.php';

/**
 * Maps one ICS 213RR (form_213rr_headers + form_213rr_items) onto the official ICS Canada 213-RR template (nine item rows).
 * Items beyond nine, and long descriptions / justifications / remarks, are printed on a continuation sheet.
 */
final class Ics213rrOfficialPdfFieldMapper
{
    private const ROWS = 9;
    private const DESC_FIELD = 'Detailed Item Description Vital characteristics brand specs experience size etcRow';

    /**
     * @param array<string,mixed> $rr header row (rr_get)
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $incident
     * @param array<string,mixed>|null $sectionChief ['name' => ..., 'at' => ...] who approved it at the Section Chief step
     * @return array{fields: array<string,mixed>, continuations: array<int,array{heading:string,text:string}>, header: array<int,string>}
     */
    public static function build(array $rr, array $items, array $incident, ?array $sectionChief = null): array
    {
        $g = static fn (string $k): string => trim((string)($rr[$k] ?? ''));
        $f = []; $cont = [];

        $f['Text1'] = (string)($incident['incident_name'] ?? $incident['name'] ?? '');
        $f['2 DateTime'] = self::dt($g('order_datetime') !== '' ? $g('order_datetime') : $g('record_created_at'));
        $f['3 Resource Request Number'] = $g('request_number');

        $needed = self::dt($g('needed_by_datetime'));
        foreach (array_values($items) as $i => $it) {
            $n = $i + 1;
            $qty = self::num($it['quantity_requested'] ?? '');
            $extra = [];
            if ($n > self::ROWS) {
                $extra[] = "Line $n: $qty " . trim((string)($it['item_kind'] ?? '') . ' ' . (string)($it['unit_of_issue'] ?? '')) . ' - ' . trim((string)($it['item_description'] ?? ''));
            } else {
                $f["QtyRow$n"] = $qty;
                $kind = trim((string)($it['item_kind'] ?? '')); $type = trim((string)($it['unit_of_issue'] ?? ''));
                $f["KindRow$n"] = self::clip($kind, 36, 6);
                $f["TypeRow$n"] = self::clip($type, 36, 6);
                if (self::tooWide($kind, 36, 6) || self::tooWide($type, 36, 6)) { $extra[] = "Line $n kind / type: $kind / $type"; }
                $desc = trim((string)($it['item_description'] ?? ''));
                $f[self::DESC_FIELD . $n] = self::clip($desc, 328, 7);
                if ($needed !== '') { $f["RequestedRow$n"] = $needed; }
                $cost = trim((string)($it['estimated_cost'] ?? ''));
                if ($cost !== '' && is_numeric($cost) && (float)$cost > 0) { $f["CostRow$n"] = number_format((float)$cost, 2, '.', ','); }
                if (self::tooWide($desc, 328, 7)) { $extra[] = "Line $n description: $desc"; }
            }
            foreach (['justification' => 'Justification / purpose', 'remarks' => 'Remarks'] as $k => $label) {
                $t = trim((string)($it[$k] ?? ''));
                if ($t !== '') { $extra[] = "Line $n $label: $t"; }
            }
            if ($extra) { $cont[] = ['heading' => "Order line $n - additional detail", 'text' => implode("\n", $extra)]; }
        }

        $f['5 Requested DeliveryReporting Location'] = self::box($g('delivery_location'), 480, 28, 8, 'Block 5 - Requested delivery / reporting location', $cont);
        $prepared = $g('prepared_by_name');
        $pos = $g('prepared_by_position_title');
        $f['7 Requested by NamePosition'] = self::clip($prepared . ($pos !== '' ? ' / ' . $pos : ''), 240, 8);

        $priority = strtolower($g('priority_level'));
        if (str_contains($priority, 'urgent')) { $f['undefined'] = ['value' => 'On', 'type' => 'name']; }
        elseif (str_contains($priority, 'routine')) { $f['undefined_2'] = ['value' => 'On', 'type' => 'name']; }
        elseif (str_contains($priority, 'low')) { $f['undefined_3'] = ['value' => 'On', 'type' => 'name']; }

        if ($sectionChief && trim((string)($sectionChief['name'] ?? '')) !== '') {
            $f['9 Section Chief Approval'] = self::clip(trim($sectionChief['name'] . ' ' . self::dt((string)($sectionChief['at'] ?? ''))), 222, 8);
        }

        $f['10 Logistics Order Number'] = $g('logistics_order_number');
        $f['12 Name of SupplierPOC'] = self::clip($g('supplier_name'), 305, 9);
        $f['11 Supplier PhoneFaxEmail'] = self::clip($g('supplier_contact'), 218, 9);
        $f['13 Notes'] = self::box($g('logistics_notes'), 630, 45, 8, 'Block 13 - Logistics notes', $cont);
        $f['14 Approval Signature of Auth Logistics Rep'] = self::clip($g('logistics_signed_by_name') . ($g('logistics_signed_by_position') !== '' ? ' (' . $g('logistics_signed_by_position') . ')' : ''), 215, 9);
        $f['15 DateTime'] = self::dt($g('logistics_signed_at'));
        $placed = strtoupper($g('order_placed_by'));
        if ($placed === 'SPUL') { $f['undefined_4'] = ['value' => 'On', 'type' => 'name']; }
        elseif ($placed === 'PROC') { $f['undefined_5'] = ['value' => 'On', 'type' => 'name']; }
        $finance = $g('finance_comments') !== '' ? $g('finance_comments') : $g('finance_notes');
        $f['17 ReplyComments from Finance'] = self::box($finance, 515, 45, 8, 'Block 17 - Reply / comments from Finance', $cont);
        $f['18 Finance Section Signature'] = self::clip($g('finance_signed_by_name') . ($g('finance_signed_by_position') !== '' ? ' (' . $g('finance_signed_by_position') . ')' : ''), 288, 9);
        $f['19 DateTime'] = self::dt($g('finance_signed_at'));

        $f = array_filter($f, static fn ($v): bool => is_array($v) || (string)$v !== '');
        return ['fields' => $f, 'continuations' => $cont, 'header' => [
            'Request ' . $g('request_number') . '   Incident: ' . (string)($incident['incident_name'] ?? $incident['name'] ?? ''),
        ]];
    }

    private static function num($v): string
    {
        $v = trim((string)$v);
        if ($v === '' || !is_numeric($v)) { return $v; }
        return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    }

    private static function dt($v): string
    {
        $v = trim((string)$v);
        $ts = $v !== '' ? strtotime($v) : false;
        return $ts ? date('Y-m-d H:i', $ts) : $v;
    }

    private static function tooWide(string $text, float $w, float $fs): bool
    {
        return $text !== '' && SimpleTextPdf::textWidth($text, $fs) > $w - 6;
    }

    /** One-line text cut with an ellipsis so it never overruns its box. */
    private static function clip(string $text, float $w, float $fs): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        if ($text === '' || SimpleTextPdf::textWidth($text, $fs) <= $w - 6) { return $text; }
        while ($text !== '' && SimpleTextPdf::textWidth($text . '...', $fs) > $w - 6) { $text = mb_substr($text, 0, -1); }
        return rtrim($text) . '...';
    }

    /** Multi-line box: text that does not fit is cut, marked "(cont.)" and printed in full on the continuation sheet. */
    private static function box(string $text, float $w, float $h, float $fs, string $heading, array &$cont): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') { return ''; }
        $maxLines = max(1, (int)floor(($h - 1) / ($fs * 1.15)));
        $inner = $w - 6;
        $lines = SimpleTextPdf::wrap($text, $inner, $fs);
        if (count($lines) <= $maxLines) { return $text; }
        $cont[] = ['heading' => $heading, 'text' => $text];
        $keep = array_slice($lines, 0, $maxLines);
        $marker = ' ... (cont.)';
        $last = (string)array_pop($keep);
        while ($last !== '' && SimpleTextPdf::textWidth($last . $marker, $fs) > $inner) {
            $pos = mb_strrpos($last, ' ');
            $last = $pos === false ? mb_substr($last, 0, max(0, mb_strlen($last) - 1)) : mb_substr($last, 0, $pos);
        }
        $keep[] = rtrim($last) . $marker;
        return implode("\n", $keep);
    }
}
