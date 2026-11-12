<?php
declare(strict_types=1);
abstract class BaseTcpdfRenderer extends TCPDF
{
    protected array $layout;
    protected string $documentTitle = '';

    public function __construct(array $layout, string $documentTitle = '')
    {
        $this->layout = $layout;
        $this->documentTitle = $documentTitle;

        parent::__construct('P', 'mm', 'LETTER', true, 'UTF-8', false);

        $this->SetCreator('GovAI for Command Bridge');
        $this->SetAuthor('GovAI for Command Bridge');
        $this->SetTitle($documentTitle !== '' ? $documentTitle : ($layout['title'] ?? 'PDF'));
        $this->SetSubject($layout['form_code'] ?? 'Form PDF');
        $this->SetMargins(0, 0, 0, true);
        $this->SetAutoPageBreak(false, 0);
        $this->setPrintHeader(false);
        $this->setPrintFooter(false);
        $this->SetCellPadding(0.8);
    }

    protected function pageWidth(): float
    {
        return (float)($this->layout['page']['width_mm'] ?? 215.9);
    }

    protected function pageHeight(): float
    {
        return (float)($this->layout['page']['height_mm'] ?? 279.4);
    }

    protected function margin(): float
    {
        return (float)($this->layout['page']['margin_mm'] ?? 6.35);
    }

    public function drawHeaderBlock(string $pageLabel = ''): void
    {
        $m = $this->margin();
        $w = $this->pageWidth() - (2 * $m);

        $this->SetLineWidth(0.35);
        $this->Rect($m, $m, $w, 16.5);

        $logoPath = (string)($this->layout['logo_path'] ?? '');
        if ($logoPath !== '' && is_file($logoPath)) {
            [$logoW, $logoH, $logoX, $logoY] = $this->fitImageInBox($logoPath, $m + 1.2, $m + 1.2, 13.5, 14.0);
            $imageType = strtoupper((string)pathinfo($logoPath, PATHINFO_EXTENSION));
            if ($imageType === 'JPG') {
                $imageType = 'JPEG';
            }
            $this->Image($logoPath, $logoX, $logoY, $logoW, $logoH, $imageType, '', '', true, 300, '', false, false, 0, false, false, false);
        }

        $this->SetFont('helvetica', 'B', 12);
        $this->SetXY($m + 24, $m + 2.2);
        $this->Cell($w - 48, 5.5, (string)($this->layout['title'] ?? ''), 0, 0, 'C');

        $this->SetFont('helvetica', '', 8.5);
        $this->SetXY($m + 24, $m + 8.8);
        $this->Cell($w - 48, 4.5, (string)($this->layout['form_code'] ?? ''), 0, 0, 'C');

        if ($pageLabel !== '') {
            $this->SetFont('helvetica', '', 8);
            $this->SetXY($m + $w - 32, $m + 2.0);
            $this->Cell(28, 4.5, $pageLabel, 0, 0, 'R');
        }
    }



    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    protected function fitImageInBox(string $imagePath, float $boxX, float $boxY, float $boxW, float $boxH): array
    {
        $targetW = $boxW;
        $targetH = $boxH;

        $imageSize = @getimagesize($imagePath);
        if (is_array($imageSize) && !empty($imageSize[0]) && !empty($imageSize[1])) {
            $srcW = (float)$imageSize[0];
            $srcH = (float)$imageSize[1];

            if ($srcW > 0.0 && $srcH > 0.0) {
                $scale = min($boxW / $srcW, $boxH / $srcH);
                $targetW = $srcW * $scale;
                $targetH = $srcH * $scale;
            }
        }

        $targetX = $boxX + (($boxW - $targetW) / 2.0);
        $targetY = $boxY + (($boxH - $targetH) / 2.0);

        return [$targetW, $targetH, $targetX, $targetY];
    }

    public function drawFooterSignature(string $preparedBy, string $signatureName, int $pageNumber, int $pageCount): void
    {
        $m = $this->margin();
        $y = $this->pageHeight() - $m - 15.5;
        $usableW = $this->pageWidth() - (2 * $m);

        $this->SetLineWidth(0.25);
        $this->Rect($m, $y, $usableW * 0.62, 11.0);
        $this->Rect($m + ($usableW * 0.62), $y, $usableW * 0.38, 11.0);

        $this->SetFont('helvetica', '', 7.2);
        $this->SetXY($m + 1.4, $y + 0.8);
        $this->Cell(40, 3.5, 'Prepared By (Name and Position)', 0, 0, 'L');
        $this->SetXY($m + ($usableW * 0.62) + 1.4, $y + 0.8);
        $this->Cell(20, 3.5, 'Signature', 0, 0, 'L');

        $this->SetFont('helvetica', '', 8.8);
        $this->SetXY($m + 1.4, $y + 4.6);
        $this->Cell(($usableW * 0.62) - 2.8, 4.5, $preparedBy, 0, 0, 'L');
        $this->SetXY($m + ($usableW * 0.62) + 1.4, $y + 4.6);
        $this->Cell(($usableW * 0.38) - 2.8, 4.5, $signatureName, 0, 0, 'L');

        $this->SetFont('helvetica', '', 7.2);
        $this->SetXY($m, $this->pageHeight() - $m - 3.5);
        $this->Cell($usableW, 3, sprintf('Page %d of %d', $pageNumber, $pageCount), 0, 0, 'R');
    }

    public function drawSectionTitle(float $x, float $y, float $w, string $title): void
    {
        $this->SetFont('helvetica', 'B', 8.5);
        $this->SetXY($x + 1, $y + 0.8);
        $this->Cell($w - 2, 4.2, $title, 0, 0, 'L');
    }

    public function drawLabeledBox(float $x, float $y, float $w, float $h, string $label, string $value = '', float $fontSize = 9.0, string $align = 'L'): void
    {
        $this->SetLineWidth(0.25);
        $this->Rect($x, $y, $w, $h);
        $this->SetFont('helvetica', '', 7.2);
        $this->SetXY($x + 1, $y + 0.8);
        $this->Cell($w - 2, 3.2, $label, 0, 0, 'L');
        if ($value !== '') {
            $this->SetFont('helvetica', '', $fontSize);
            $this->SetXY($x + 1.2, $y + 4.8);
            $this->Cell($w - 2.4, max(4, $h - 5.5), $value, 0, 0, $align);
        }
    }

    public function drawWrappedTextBox(float $x, float $y, float $w, float $h, string $label, string $value = '', float $fontSize = 8.6): void
    {
        $this->SetLineWidth(0.25);
        $this->Rect($x, $y, $w, $h);
        $this->SetFont('helvetica', '', 7.2);
        $this->SetXY($x + 1, $y + 0.8);
        $this->Cell($w - 2, 3.2, $label, 0, 0, 'L');

        $this->SetFont('helvetica', '', $fontSize);
        $this->SetXY($x + 1.2, $y + 5.0);
        $this->MultiCell($w - 2.4, 4.0, $value, 0, 'L', false, 1, '', '', true, 0, false, true, $h - 6.0, 'T', false);
    }

    public function drawAttachmentPageFromImage(string $imagePath, float $origWidthMm, float $origHeightMm): void
    {
        $pageW = $this->pageWidth();
        $pageH = $this->pageHeight();
        $margin = $this->margin();
        $availW = $pageW - (2 * $margin);
        $availH = $pageH - (2 * $margin);

        $drawW = max(1.0, $origWidthMm);
        $drawH = max(1.0, $origHeightMm);

        if ($drawW > $availW || $drawH > $availH) {
            $scale = min($availW / $drawW, $availH / $drawH);
            $drawW *= $scale;
            $drawH *= $scale;
        }

        $x = $margin + (($availW - $drawW) / 2);
        $y = $margin + (($availH - $drawH) / 2);

        $this->AddPage('P', 'LETTER');
        $ext = strtolower((string)pathinfo($imagePath, PATHINFO_EXTENSION));
        $type = $ext === 'jpg' ? 'JPEG' : strtoupper($ext);
        $this->Image($imagePath, $x, $y, $drawW, $drawH, $type, '', '', true, 300, '', false, false, 0, false, false, false);
    }

    public function outputTextAttachment(string $filename, string $text): void
    {
        $pageW = $this->pageWidth();
        $pageH = $this->pageHeight();
        $margin = $this->margin();
        $boxW = $pageW - (2 * $margin);
        $boxH = $pageH - (2 * $margin);

        $remaining = trim($text);
        if ($remaining === '') {
            $remaining = 'Text preview unavailable.';
        }

        while ($remaining !== '') {
            $this->AddPage('P', 'LETTER');
            $this->SetFont('courier', 'B', 9);
            $this->SetXY($margin, $margin);
            $this->Cell($boxW, 4, $filename, 0, 1, 'L');

            $this->SetFont('courier', '', 8.5);
            $startY = $margin + 6;
            $charsPerPage = 3600;
            $chunk = mb_substr($remaining, 0, $charsPerPage);
            $remaining = mb_substr($remaining, mb_strlen($chunk));
            $this->SetXY($margin, $startY);
            $this->MultiCell($boxW, 3.8, $chunk, 0, 'L', false, 1, '', '', true, 0, false, true, $boxH - 6, 'T', false);
            if (trim($remaining) === '') {
                break;
            }
        }
    }
}
