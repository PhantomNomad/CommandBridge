<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

class Ics204PdfRenderer {
    public function renderFilled(array $context): void {
        if (!command_bridge_require_tcpdf()) { throw new RuntimeException('TCPDF is not installed on this server.'); }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';
        $incident=(array)($context['incident']??[]); $op=(array)($context['operational_period']??[]); $h=(array)($context['header']??[]); $resources=(array)($context['resources']??[]);
        $pdf = new class([], 'ICS 204 Assignment List') extends BaseTcpdfRenderer {
            public function textBox($x,$y,$w,$h,$label,$value=''){ $this->drawLabeledBox($x,$y,$w,$h,$label,(string)$value,7.5); }
            public function draw204($incident,$op,$h,$resources): void {
                $this->AddPage('L','LETTER'); $m=$this->margin(); $w=$this->pageWidth()-2*$m; $y=$m;
                $this->SetFont('helvetica','B',14); $this->Cell($w,7,'Assignment List (ICS 204)',0,1,'C'); $y+=10;
                $opText='';
                if (!empty($op)) {
                    $parts=[];
                    if (!empty($op['period_number'])) { $parts[]='OP '.(int)$op['period_number']; }
                    if (!empty($op['period_name'])) { $parts[]=(string)$op['period_name']; }
                    $opText=($parts?implode(' - ',$parts).' | ':'') . (string)($op['op_start']??$op['start_time']??'') . ' to ' . (string)($op['op_end']??$op['end_time']??'');
                }
                $this->textBox($m,$y,$w*.25,13,'1. Branch',$h['branch_name']??'');
                $this->textBox($m+$w*.25,$y,$w*.25,13,'2. Division/Group/Staging',$h['division_group']??'');
                $this->textBox($m+$w*.50,$y,$w*.25,13,'3. Incident Name',$incident['incident_name']??$incident['name']??'');
                $this->textBox($m+$w*.75,$y,$w*.25,13,'4. Operational Period',$opText); $y+=15;
                $this->textBox($m,$y,$w,18,'5. Operational Personnel',$h['operations_personnel']??''); $y+=20;
                $this->SetFont('helvetica','B',8); $this->Cell($w,5,'6. Resources Assigned to this Period',1,1,'L',false); $y=$this->GetY();
                $cols=[50,40,22,45,$w-157]; $heads=['Resource Identifier','Leader','No. of Persons','Contact','Reporting Location / Special Equipment / Remarks'];
                foreach($heads as $i=>$head){$this->Cell($cols[$i],6,$head,1,0,'C');} $this->Ln(); $y=$this->GetY(); $this->SetFont('helvetica','',7);
                $minRows=max(1,count($resources)); for($r=0;$r<$minRows;$r++){ $row=$resources[$r]??[]; $vals=[$row['resource_identifier']??'',$row['leader_name']??'',$row['number_persons']??'',$row['contact_info']??'',$row['reporting_location']??'']; foreach($vals as $i=>$v){$this->Cell($cols[$i],8,(string)$v,1,0,'L');} $this->Ln(); }
                $y=$this->GetY()+2; $half=($w-2)/2;
                $this->textBox($m,$y,$half,28,'7. Work Assignments',$h['work_assignments']??'');
                $this->textBox($m+$half+2,$y,$half,28,'8. Special Instructions',$h['special_instructions']??''); $y+=30;
                $this->textBox($m,$y,$w,16,'9. Division/Group Communications Summary',$h['medical_plan_summary']??''); $y+=18;
                $this->textBox($m,$y,$w*.5,14,'Prepared By (Resource Unit Leader)',($h['prepared_by_name']??'').' '.($h['prepared_by_position_title']??''));
                $this->textBox($m+$w*.5,$y,$w*.5,14,'Approved By (Planning Section Chief)',$h['approved_by_name']??'');
                $this->SetY($this->pageHeight()-$m-6); $this->SetFont('helvetica','',7); $this->Cell($w,4,'ICS 204-CAN',0,0,'L');
            }
        };
        $pdf->draw204($incident,$op,$h,$resources);
        $pdf->Output('ICS204_Assignment_List.pdf', !empty($context['inline']) ? 'I' : 'D');
    }
}
