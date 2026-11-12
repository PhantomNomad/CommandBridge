<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

class Ics208PdfRenderer {
    public function renderFilled(array $context): void {
        if (!command_bridge_require_tcpdf()) { throw new RuntimeException('TCPDF is not installed on this server.'); }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';
        $incident=(array)($context['incident']??[]); $op=(array)($context['operational_period']??[]); $h=(array)($context['header']??[]);
        $pdf = new class([], 'ICS 208 Safety Message / Plan') extends BaseTcpdfRenderer {
            public bool $inline = false;
            public function opText($op): string { if(!$op) return ''; $parts=[]; if(!empty($op['period_number'])) $parts[]='OP '.(int)$op['period_number']; if(!empty($op['period_name'])) $parts[]=(string)$op['period_name']; return ($parts?implode(' - ',$parts).' | ':'') . (string)($op['op_start']??$op['start_time']??'') . ' to ' . (string)($op['op_end']??$op['end_time']??''); }
            public function box($x,$y,$w,$h,$label,$value=''){ $this->drawLabeledBox($x,$y,$w,$h,$label,(string)$value,7.5); }
            public function draw208($incident,$op,$h): void {
                $this->AddPage('P','LETTER'); $m=$this->margin(); $w=$this->pageWidth()-2*$m; $y=$m;
                $this->SetFont('helvetica','B',14); $this->Cell($w,8,'Safety Message / Plan (ICS 208)',0,1,'C'); $y+=11;
                $this->box($m,$y,$w*.55,13,'1. Incident Name',$incident['incident_name']??$incident['name']??'');
                $this->box($m+$w*.55,$y,$w*.45,13,'2. Operational Period',$this->opText($op)); $y+=16;
                $this->SetXY($m,$y); $this->SetFont('helvetica','B',8); $this->Cell($w,6,'3. Safety Message / Plan',1,1,'L');
                $this->SetFont('helvetica','',8); $this->MultiCell($w,150,(string)($h['safety_message']??''),1,'L',false,1,$m,$y+6,true,0,false,true,150,'T'); $y+=158;
                $this->box($m,$y,$w*.25,12,'Site Safety Plan Required',!empty($h['site_safety_plan_required'])?'Yes':'No');
                $this->box($m+$w*.25,$y,$w*.75,12,'Site Safety Plan Location',$h['site_safety_plan_location']??''); $y+=15;
                $this->box($m,$y,$w*.5,14,'4. Prepared By / Safety Officer',$h['prepared_by_name']??'');
                $this->box($m+$w*.5,$y,$w*.5,14,'Position / Title',$h['prepared_by_position_title']??'');
                $this->Output('ICS208_Safety_Message_Plan.pdf', $this->inline ? 'I' : 'D');
            }
        };
        $pdf->inline = !empty($context['inline']);
        $pdf->draw208($incident,$op,$h);
    }
}
