<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

class Ics205PdfRenderer {
    public function renderFilled(array $context): void {
        if (!command_bridge_require_tcpdf()) { throw new RuntimeException('TCPDF is not installed on this server.'); }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';
        $incident=(array)($context['incident']??[]); $op=(array)($context['operational_period']??[]); $h=(array)($context['header']??[]); $channels=(array)($context['channels']??[]);
        $pdf = new class([], 'ICS 205 Radio Communications Plan') extends BaseTcpdfRenderer {
            public function textBox($x,$y,$w,$h,$label,$value=''){ $this->drawLabeledBox($x,$y,$w,$h,$label,(string)$value,7.2); }
            public function opText($op): string {
                if(!$op) return '';
                $parts=[]; if(!empty($op['period_number'])) $parts[]='OP '.(int)$op['period_number']; if(!empty($op['period_name'])) $parts[]=(string)$op['period_name'];
                return ($parts?implode(' - ',$parts).' | ':'') . (string)($op['op_start']??$op['start_time']??'') . ' to ' . (string)($op['op_end']??$op['end_time']??'');
            }
            public function draw205($incident,$op,$h,$channels): void {
                $this->AddPage('L','LETTER'); $m=$this->margin(); $w=$this->pageWidth()-2*$m; $y=$m;
                $this->SetFont('helvetica','B',14); $this->Cell($w,7,'Radio Communications Plan (ICS 205)',0,1,'C'); $y+=10;
                $this->textBox($m,$y,$w*.50,13,'1. Incident Name',$incident['incident_name']??$incident['name']??'');
                $this->textBox($m+$w*.50,$y,$w*.50,13,'2. Operational Period',$this->opText($op)); $y+=15;
                $this->textBox($m,$y,$w,12,'3. Special Instructions',$h['special_instructions']??''); $y+=14;
                $this->SetFont('helvetica','B',8); $this->Cell($w,5,'4. Basic Radio Channel Utilization',1,1,'L'); 
                $cols=[32,28,38,42,48,$w-188];
                $heads=['System/Type','Channel','Function','Frequency/Tone','Assignment','Remarks'];
                foreach($heads as $i=>$head){$this->Cell($cols[$i],6,$head,1,0,'C');} $this->Ln(); $this->SetFont('helvetica','',6.5);
                $minRows=max(1,count($channels));
                for($r=0;$r<$minRows;$r++){
                    $row=$channels[$r]??[];
                    $vals=[$row['system_type']??'',$row['channel']??'',$row['function']??'',$row['frequency_tone']??'',$row['assignment']??'',$row['remarks']??''];
                    foreach($vals as $i=>$v){$this->Cell($cols[$i],8,(string)$v,1,0,'L');} $this->Ln();
                }
                $y=$this->GetY()+3; $half=($w-2)/2;
                $this->textBox($m,$y,$half,14,'Prepared By', trim((string)($h['prepared_by_name']??'').' '.(string)($h['prepared_by_position_title']??'')));
                $this->textBox($m+$half+2,$y,$half,14,'Date/Time Prepared', (string)($h['updated_at']??$h['created_at']??''));
                $this->SetY($this->pageHeight()-$m-6); $this->SetFont('helvetica','',7); $this->Cell($w,4,'ICS 205-CAN',0,0,'L');
            }
        };
        $pdf->draw205($incident,$op,$h,$channels);
        $pdf->Output('ICS205_Radio_Communications_Plan.pdf', !empty($context['inline']) ? 'I' : 'D');
    }
}
