<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

class Ics206PdfRenderer {
    public function renderFilled(array $context): void {
        if (!command_bridge_require_tcpdf()) { throw new RuntimeException('TCPDF is not installed on this server.'); }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';
        $incident=(array)($context['incident']??[]); $op=(array)($context['operational_period']??[]); $h=(array)($context['header']??[]);
        $aid=(array)($context['aid_stations']??[]); $transport=(array)($context['transportation']??[]); $hospitals=(array)($context['hospitals']??[]);
        $pdf = new class([], 'ICS 206 Medical Plan') extends BaseTcpdfRenderer {
            public function opText($op): string { if(!$op) return ''; $parts=[]; if(!empty($op['period_number'])) $parts[]='OP '.(int)$op['period_number']; if(!empty($op['period_name'])) $parts[]=(string)$op['period_name']; return ($parts?implode(' - ',$parts).' | ':'') . (string)($op['op_start']??$op['start_time']??'') . ' to ' . (string)($op['op_end']??$op['end_time']??''); }
            public function box($x,$y,$w,$h,$label,$value=''){ $this->drawLabeledBox($x,$y,$w,$h,$label,(string)$value,7.2); }
            public function sectionTitle($txt,$w){ $this->SetFont('helvetica','B',8); $this->Cell($w,5,$txt,1,1,'L'); $this->SetFont('helvetica','',6.5); }
            public function row(array $cols,array $vals,$h=7){ foreach($vals as $i=>$v){ $this->Cell($cols[$i],$h,(string)$v,1,0,'L'); } $this->Ln(); }
            public function draw206($incident,$op,$h,$aid,$transport,$hospitals): void {
                $this->AddPage('L','LETTER'); $m=$this->margin(); $w=$this->pageWidth()-2*$m; $y=$m;
                $this->SetFont('helvetica','B',14); $this->Cell($w,7,'Medical Plan (ICS 206)',0,1,'C'); $y+=10;
                $this->box($m,$y,$w*.42,13,'1. Incident Name',$incident['incident_name']??$incident['name']??'');
                $this->box($m+$w*.42,$y,$w*.18,13,'2. Date/Time Prepared',date('Y-m-d H:i'));
                $this->box($m+$w*.60,$y,$w*.40,13,'3. Operational Period',$this->opText($op)); $y+=16; $this->SetY($y);
                $this->sectionTitle('4. Incident Medical Aid Station',$w);
                $cols=[55,60,55,25,$w-195]; $heads=['Medical Aid Stations','Location','Contact (number or frequency)','Paramedics',''];
                $this->row($cols,$heads,6); $min=max(1,count($aid)); for($i=0;$i<$min;$i++){ $r=$aid[$i]??[]; $this->row($cols,[$r['name']??'',$r['location']??'',$r['contact']??'',!empty($r['paramedics'])?'Yes':'No',''],7); }
                $this->sectionTitle('5. Transportation (indicate air or ground)',$w);
                $cols=[70,65,65,35,$w-235]; $this->row($cols,['Ambulance Service','Location','Contact (number or frequency)','Level of Serv.',''],6);
                $min=max(1,count($transport)); for($i=0;$i<$min;$i++){ $r=$transport[$i]??[]; $this->row($cols,[$r['ambulance_service']??'',$r['location']??'',$r['contact']??'',$r['level_of_service']??'',''],7); }
                $this->sectionTitle('6. Hospitals',$w);
                $cols=[50,70,22,28,45,22,22,$w-259]; $this->row($cols,['Hospital Name','Address (Lat. and Long. if Helipad)','Travel Air Time','Travel Ground Time','Contact','Helipad','Burn Ctr.',''],6);
                $min=max(1,count($hospitals)); for($i=0;$i<$min;$i++){ $r=$hospitals[$i]??[]; $this->row($cols,[$r['hospital_name']??'',$r['address']??'',$r['travel_time_air']??'',$r['travel_time_ground']??'',$r['contact']??'',!empty($r['helipad'])?'Yes':'No',!empty($r['burn_centre'])?'Yes':'No',''],7); }
                $this->box($m,$this->GetY()+2,$w,18,'7. Special Medical Emergency Procedures',$h['medical_emergency_procedures']??'');
                $y=$this->GetY()+22; $half=($w-2)/2;
                $this->box($m,$y,$half,14,'8. Prepared By (Medical Unit Leader)',trim((string)($h['prepared_by_name']??'').' '.(string)($h['prepared_by_position_title']??'')));
                $this->box($m+$half+2,$y,$half,14,'9. Approved By (Safety Officer)','');
                $this->SetY($this->pageHeight()-$m-6); $this->SetFont('helvetica','',7); $this->Cell($w,4,'ICS 206-CAN',0,0,'L');
            }
        };
        $pdf->draw206($incident,$op,$h,$aid,$transport,$hospitals);
        $pdf->Output('ICS206_Medical_Plan.pdf', !empty($context['inline']) ? 'I' : 'D');
    }
}
