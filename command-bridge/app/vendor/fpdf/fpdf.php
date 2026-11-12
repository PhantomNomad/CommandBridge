<?php
/*******************************************************************************
* FPDF                                                                         *
* Version: 1.86                                                                *
* Date:    2023-06-25                                                          *
* Author:  Olivier Plathey                                                     *
*******************************************************************************/

class FPDF
{
const VERSION = '1.86';
protected $page;               // current page number
protected $n;                  // current object number
protected $offsets;            // array of object offsets
protected $buffer;             // buffer holding in-memory PDF
protected $pages;              // array containing pages
protected $state;              // current document state
protected $compress;
protected $iconv;
protected $k;                  // scale factor (number of points in user unit)
protected $DefOrientation;     // default orientation
protected $CurOrientation;     // current orientation
protected $StdPageSizes;       // standard page sizes
protected $DefPageSize;        // default page size
protected $CurPageSize;        // current page size
protected $CurRotation;        // current page rotation
protected $PageInfo;           // page-related data
protected $wPt, $hPt;          // dimensions of current page in points
protected $w, $h;              // dimensions of current page in user unit
protected $lMargin;            // left margin
protected $tMargin;            // top margin
protected $rMargin;            // right margin
protected $bMargin;            // page break margin
protected $cMargin;            // cell margin
protected $x, $y;              // current position in user unit
protected $lasth;              // height of last printed cell
protected $LineWidth;          // line width in user unit
protected $fontpath;
protected $CoreFonts;          // array of core font names
protected $fonts;              // array of used fonts
protected $FontFiles;          // array of font files
protected $encodings;          // array of encoding differences
protected $cmaps;              // array of ToUnicode CMaps
protected $FontFamily;         // current font family
protected $FontStyle;          // current font style
protected $underline;          // underlining flag
protected $CurrentFont;        // current font info
protected $FontSizePt;         // current font size in points
protected $FontSize;           // current font size in user unit
protected $DrawColor;          // commands for drawing color
protected $FillColor;          // commands for filling color
protected $TextColor;          // commands for text color
protected $ColorFlag;          // indicates whether fill and text colors are different
protected $WithAlpha;          // indicates whether alpha channel is used
protected $ws;                 // word spacing
protected $images;             // array of used images
protected $PageLinks;          // array of links in pages
protected $links;              // array of internal links
protected $AutoPageBreak;      // automatic page breaking
protected $PageBreakTrigger;   // threshold used to trigger page breaks
protected $InHeader;           // flag set when processing header
protected $InFooter;           // flag set when processing footer
protected $AliasNbPages;       // alias for total number of pages
protected $PDFVersion;         // PDF version number

function __construct($orientation='P', $unit='mm', $size='A4')
{
    $this->state = 0;
    $this->page = 0;
    $this->n = 2;
    $this->buffer = '';
    $this->pages = array();
    $this->PageInfo = array();
    $this->fonts = array();
    $this->FontFiles = array();
    $this->encodings = array();
    $this->cmaps = array();
    $this->images = array();
    $this->links = array();
    $this->InHeader = false;
    $this->InFooter = false;
    $this->lasth = 0;
    $this->FontFamily = '';
    $this->FontStyle = '';
    $this->FontSizePt = 12;
    $this->underline = false;
    $this->DrawColor = '0 G';
    $this->FillColor = '0 g';
    $this->TextColor = '0 g';
    $this->ColorFlag = false;
    $this->WithAlpha = false;
    $this->ws = 0;
    if(defined('FPDF_FONTPATH'))
        $this->fontpath = FPDF_FONTPATH;
    else
        $this->fontpath = dirname(__FILE__).'/font/';
    $this->CoreFonts = array('courier'=>'Courier','courierB'=>'Courier-Bold','courierI'=>'Courier-Oblique','courierBI'=>'Courier-BoldOblique',
        'helvetica'=>'Helvetica','helveticaB'=>'Helvetica-Bold','helveticaI'=>'Helvetica-Oblique','helveticaBI'=>'Helvetica-BoldOblique',
        'times'=>'Times-Roman','timesB'=>'Times-Bold','timesI'=>'Times-Italic','timesBI'=>'Times-BoldItalic',
        'symbol'=>'Symbol','zapfdingbats'=>'ZapfDingbats');
    if($unit=='pt')
        $this->k = 1;
    elseif($unit=='mm')
        $this->k = 72/25.4;
    elseif($unit=='cm')
        $this->k = 72/2.54;
    elseif($unit=='in')
        $this->k = 72;
    else
        $this->Error('Incorrect unit: '.$unit);
    $this->StdPageSizes = array('a3'=>array(841.89,1190.55), 'a4'=>array(595.28,841.89), 'a5'=>array(420.94,595.28),
        'letter'=>array(612,792), 'legal'=>array(612,1008));
    $size = $this->_getpagesize($size);
    $this->DefPageSize = $size;
    $this->CurPageSize = $size;
    $orientation = strtolower($orientation);
    if($orientation=='p' || $orientation=='portrait')
    {
        $this->DefOrientation = 'P';
        $this->w = $size[0];
        $this->h = $size[1];
    }
    elseif($orientation=='l' || $orientation=='landscape')
    {
        $this->DefOrientation = 'L';
        $this->w = $size[1];
        $this->h = $size[0];
    }
    else
        $this->Error('Incorrect orientation: '.$orientation);
    $this->CurOrientation = $this->DefOrientation;
    $this->wPt = $this->w*$this->k;
    $this->hPt = $this->h*$this->k;
    $margin = 28.35/$this->k;
    $this->SetMargins($margin,$margin);
    $this->cMargin = $margin/10;
    $this->LineWidth = .567/$this->k;
    $this->SetAutoPageBreak(true,2*$margin);
    $this->SetDisplayMode('default');
    $this->SetCompression(true);
    $this->PDFVersion = '1.3';
}
function SetMargins($left, $top, $right=null){$this->lMargin=$left;$this->tMargin=$top;if($right===null)$right=$left;$this->rMargin=$right;}
function SetLeftMargin($margin){$this->lMargin=$margin;if($this->page>0 && $this->x<$margin)$this->x=$margin;}
function SetTopMargin($margin){$this->tMargin=$margin;}
function SetRightMargin($margin){$this->rMargin=$margin;}
function SetAutoPageBreak($auto, $margin=0){$this->AutoPageBreak=$auto;$this->bMargin=$margin;$this->PageBreakTrigger=$this->h-$margin;}
function SetDisplayMode($zoom, $layout='continuous'){}
function SetCompression($compress){$this->compress=$compress && function_exists('gzcompress');}
function SetTitle($title, $isUTF8=false){}
function SetAuthor($author, $isUTF8=false){}
function SetCreator($creator, $isUTF8=false){}
function AliasNbPages($alias='{nb}'){$this->AliasNbPages=$alias;}
function Error($msg){throw new Exception('FPDF error: '.$msg);}
function Open(){if($this->state==0)$this->state=1;}
function Close(){if($this->state==3)return;if($this->page==0)$this->AddPage();$this->InFooter=true;$this->Footer();$this->InFooter=false;$this->_endpage();$this->_enddoc();}
function AddPage($orientation='', $size='', $rotation=0){
    if($this->state==0)$this->Open();
    $family = $this->FontFamily; $style = $this->FontStyle.($this->underline?'U':''); $fontsize = $this->FontSizePt;
    $lw = $this->LineWidth; $dc = $this->DrawColor; $fc = $this->FillColor; $tc = $this->TextColor; $cf = $this->ColorFlag;
    if($this->page>0){$this->InFooter=true;$this->Footer();$this->InFooter=false;$this->_endpage();}
    $this->_beginpage($orientation,$size,$rotation);
    $this->_out('2 J'); $this->LineWidth=$lw; $this->_out(sprintf('%.2F w',$lw*$this->k));
    if($family){$this->SetFont($family,$style,$fontsize);}
    $this->DrawColor=$dc; if($dc!='0 G')$this->_out($dc);
    $this->FillColor=$fc; if($fc!='0 g')$this->_out($fc);
    $this->TextColor=$tc; $this->ColorFlag=$cf;
    $this->InHeader=true; $this->Header(); $this->InHeader=false;
    if($this->AutoPageBreak)$this->PageBreakTrigger=$this->h-$this->bMargin;
}
function Header(){}
function Footer(){}
function PageNo(){return $this->page;}
function SetDrawColor($r, $g=null, $b=null){if(($r==0 && $g==0 && $b==0) || $g===null)$this->DrawColor=sprintf('%.3F G',$r/255); else $this->DrawColor=sprintf('%.3F %.3F %.3F RG',$r/255,$g/255,$b/255); if($this->page>0)$this->_out($this->DrawColor);}
function SetFillColor($r, $g=null, $b=null){if(($r==0 && $g==0 && $b==0) || $g===null)$this->FillColor=sprintf('%.3F g',$r/255); else $this->FillColor=sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255); $this->ColorFlag=($this->FillColor!=$this->TextColor); if($this->page>0)$this->_out($this->FillColor);}
function SetTextColor($r, $g=null, $b=null){if(($r==0 && $g==0 && $b==0) || $g===null)$this->TextColor=sprintf('%.3F g',$r/255); else $this->TextColor=sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255); $this->ColorFlag=($this->FillColor!=$this->TextColor);}
function GetStringWidth($s){$s=(string)$s;$cw=$this->CurrentFont['cw'];$w=0;$l=strlen($s);for($i=0;$i<$l;$i++)$w+=$cw[$s[$i]] ?? 600;return $w*$this->FontSize/1000;}
function SetLineWidth($width){$this->LineWidth=$width;if($this->page>0)$this->_out(sprintf('%.2F w',$width*$this->k));}
function Line($x1,$y1,$x2,$y2){$this->_out(sprintf('%.2F %.2F m %.2F %.2F l S',$x1*$this->k,($this->h-$y1)*$this->k,$x2*$this->k,($this->h-$y2)*$this->k));}
function Rect($x,$y,$w,$h,$style=''){ $op=$style=='F'?'f':($style=='FD'||$style=='DF'?'B':'S'); $this->_out(sprintf('%.2F %.2F %.2F %.2F re %s',$x*$this->k,($this->h-$y)*$this->k,-$w*$this->k,-$h*$this->k,$op));}
function AddFont($family, $style='', $file='', $dir=''){}
function SetFont($family, $style='', $size=0){
    $family=strtolower($family); if($family=='arial')$family='helvetica'; elseif($family=='symbol' || $family=='zapfdingbats')$style='';
    $style=strtoupper($style); if(strpos($style,'U')!==false){$this->underline=true;$style=str_replace('U','',$style);} else $this->underline=false;
    if($style=='IB')$style='BI'; if($size==0)$size=$this->FontSizePt;
    if($this->FontFamily==$family && $this->FontStyle==$style && $this->FontSizePt==$size)return;
    $fontkey=$family.$style;
    if(!isset($this->fonts[$fontkey])){
        if(isset($this->CoreFonts[$fontkey])) $i = count($this->fonts)+1;
        elseif(isset($this->CoreFonts[$family])) $i = count($this->fonts)+1;
        else $this->Error('Undefined font: '.$family.' '.$style);
        $name = $this->CoreFonts[$fontkey] ?? $this->CoreFonts[$family];
        $cw = array_fill_keys(array_map("chr", range(0,255)), 600);
        foreach (range(32,126) as $c) { $cw[chr($c)] = in_array(chr($c), ['i','l','I','.',',','!',';',';',':','\'']) ? 280 : (in_array(chr($c), ['m','w','M','W']) ? 880 : 600); }
        $this->fonts[$fontkey]=array('i'=>$i,'type'=>'core','name'=>$name,'up'=>-100,'ut'=>50,'cw'=>$cw);
    }
    $this->FontFamily=$family;$this->FontStyle=$style;$this->FontSizePt=$size;$this->FontSize=$size/$this->k;$this->CurrentFont=$this->fonts[$fontkey];
    if($this->page>0)$this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
}
function SetFontSize($size){if($this->FontSizePt==$size)return;$this->FontSizePt=$size;$this->FontSize=$size/$this->k;if($this->page>0 && isset($this->CurrentFont))$this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));}
function AddLink(){ $n=count($this->links)+1; $this->links[$n]=array(0,0); return $n; }
function SetLink($link, $y=0, $page=-1){ if($y==-1)$y=$this->y; if($page==-1)$page=$this->page; $this->links[$link]=array($page,$y); }
function Link($x, $y, $w, $h, $link){ $this->PageLinks[$this->page][] = array($x*$this->k, $this->hPt-$y*$this->k, $w*$this->k, $h*$this->k, $link); }
function Text($x,$y,$txt){$s=sprintf('BT %.2F %.2F Td (%s) Tj ET',$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt)); if($this->underline && $txt!=='')$s.=' '.$this->_dounderline($x,$y,$txt); if($this->ColorFlag)$s='q '.$this->TextColor.' '.$s.' Q'; $this->_out($s);}
function AcceptPageBreak(){return $this->AutoPageBreak;}
function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link=''){
    $k=$this->k;
    if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()){ $x=$this->x; $ws=$this->ws; if($ws>0){$this->ws=0;$this->_out('0 Tw');} $this->AddPage($this->CurOrientation,$this->CurPageSize,$this->CurRotation); $this->x=$x; if($ws>0){$this->ws=$ws;$this->_out(sprintf('%.3F Tw',$ws*$k));}}
    if($w==0)$w=$this->w-$this->rMargin-$this->x;
    $s='';
    if($fill || $border==1){$op=$fill?($border==1?'B':'f'):'S';$s=sprintf('%.2F %.2F %.2F %.2F re %s',$this->x*$k,($this->h-$this->y)*$k,$w*$k,-$h*$k,$op);}
    if(is_string($border)){
        $x=$this->x;$y=$this->y;
        if(str_contains($border,'L'))$s.=sprintf(' %.2F %.2F m %.2F %.2F l S',$x*$k,($this->h-$y)*$k,$x*$k,($this->h-($y+$h))*$k);
        if(str_contains($border,'T'))$s.=sprintf(' %.2F %.2F m %.2F %.2F l S',$x*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-$y)*$k);
        if(str_contains($border,'R'))$s.=sprintf(' %.2F %.2F m %.2F %.2F l S',($x+$w)*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
        if(str_contains($border,'B'))$s.=sprintf(' %.2F %.2F m %.2F %.2F l S',$x*$k,($this->h-($y+$h))*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
    }
    if($txt!==''){
        if($align=='R')$dx=$w-$this->cMargin-$this->GetStringWidth($txt);
        elseif($align=='C')$dx=($w-$this->GetStringWidth($txt))/2;
        else $dx=$this->cMargin;
        $txt=str_replace(')','\\)',str_replace('(','\\(',str_replace('\\','\\\\',$txt)));
        if($this->ColorFlag)$s.=' q '.$this->TextColor.' ';
        $s.=sprintf('BT %.2F %.2F Td (%s) Tj ET',($this->x+$dx)*$k,($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k,$txt);
        if($this->underline)$s.=' '.$this->_dounderline($this->x+$dx,$this->y+.5*$h+.3*$this->FontSize,$txt);
        if($this->ColorFlag)$s.=' Q';
        if($link)$this->Link($this->x+$dx,$this->y+.5*$h-.5*$this->FontSize,$this->GetStringWidth($txt),$this->FontSize,$link);
    }
    if($s)$this->_out($s);
    $this->lasth=$h;
    if($ln>0){$this->y+=$h;if($ln==1)$this->x=$this->lMargin;}
    else $this->x+=$w;
}
function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false){
    $cw=$this->CurrentFont['cw']; if($w==0)$w=$this->w-$this->rMargin-$this->x; $wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
    $s=str_replace("\r",'',(string)$txt); $nb=strlen($s); if($nb>0 && $s[$nb-1]=="\n")$nb--;
    $b=0; if($border){ if($border==1){$border='LTRB';$b='LRT';$b2='LR';} else {$b2=''; if(str_contains($border,'L'))$b2.='L'; if(str_contains($border,'R'))$b2.='R'; $b=(str_contains($border,'T')) ? $b2.'T' : $b2; }} $sep=-1; $i=0; $j=0; $l=0; $ns=0; $nl=1;
    while($i<$nb){
        $c=$s[$i];
        if($c=="\n"){ $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill); $i++; $sep=-1; $j=$i; $l=0; $ns=0; $nl++; if($border && $nl==2)$b=$b2; continue; }
        if($c==' '){$sep=$i;$ls=$l;$ns++;}
        $l += $cw[$c] ?? 600;
        if($l>$wmax){
            if($sep==-1){ if($i==$j)$i++; $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill); }
            else { $this->Cell($w,$h,substr($s,$j,$sep-$j),$b,2,$align,$fill); $i=$sep+1; }
            $sep=-1; $j=$i; $l=0; $ns=0; $nl++; if($border && $nl==2)$b=$b2;
        } else $i++;
    }
    if($border && str_contains($border,'B'))$b .= 'B';
    $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
    $this->x=$this->lMargin;
}
function Ln($h=null){$this->x=$this->lMargin; if($h===null)$this->y+=$this->lasth; else $this->y+=$h;}
function Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link=''){
    if($file=='')$this->Error('Image file name is empty');
    if(!isset($this->images[$file])){
        if($type==''){ $pos=strrpos($file,'.'); if(!$pos)$this->Error('Image file has no extension and no type was specified: '.$file); $type=strtolower(substr($file,$pos+1)); }
        $type = strtolower($type);
        if($type=='jpeg')$type='jpg';
        $mtd='_parse'.$type;
        if(!method_exists($this,$mtd))$this->Error('Unsupported image type: '.$type);
        $info=$this->$mtd($file); $info['i']=count($this->images)+1; $this->images[$file]=$info;
    } else $info=$this->images[$file];
    if($w==0 && $h==0){$w=-96;$h=-96;}
    if($w<0)$w=-$info['w']*72/$w/$this->k;
    if($h<0)$h=-$info['h']*72/$h/$this->k;
    if($w==0)$w=$h*$info['w']/$info['h'];
    if($h==0)$h=$w*$info['h']/$info['w'];
    if($x===null)$x=$this->x;
    if($y===null){$y=$this->y; $this->y+=$h;}
    $this->_out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q',$w*$this->k,$h*$this->k,$x*$this->k,($this->h-($y+$h))*$this->k,$info['i']));
    if($link)$this->Link($x,$y,$w,$h,$link);
}
function Output($dest='', $name='', $isUTF8=false){
    $this->Close();
    if($dest=='')$dest='I';
    if($dest=='I'){ if(ob_get_length()) ob_end_clean(); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="'.$name.'"'); echo $this->buffer; }
    elseif($dest=='D'){ if(ob_get_length()) ob_end_clean(); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.$name.'"'); echo $this->buffer; }
    elseif($dest=='F'){ file_put_contents($name,$this->buffer); }
    elseif($dest=='S'){ return $this->buffer; }
    else $this->Error('Incorrect output destination: '.$dest);
    return '';
}
protected function _dochecks(){ if(1.4!=1.4) $this->Error('Missing or incorrect PHP version'); }
protected function _getpagesize($size){ if(is_string($size)){ $size=strtolower($size); if(!isset($this->StdPageSizes[$size])) $this->Error('Unknown page size: '.$size); $a=$this->StdPageSizes[$size]; return array($a[0]/$this->k,$a[1]/$this->k);} else return $size; }
protected function _beginpage($orientation, $size, $rotation){ $this->page++; $this->pages[$this->page]=''; $this->PageLinks[$this->page]=array(); $this->state=2; $this->x=$this->lMargin; $this->y=$this->tMargin; $this->FontFamily=''; if($orientation=='')$orientation=$this->DefOrientation; else {$orientation=strtoupper($orientation[0]); if($orientation!=$this->DefOrientation || $size!=$this->DefPageSize)$this->OrientationChanges[$this->page]=true;} if($size=='')$size=$this->DefPageSize; else $size=$this->_getpagesize($size); if($orientation!=$this->CurOrientation || $size[0]!=$this->CurPageSize[0] || $size[1]!=$this->CurPageSize[1]){ if($orientation=='P'){$this->w=$size[0];$this->h=$size[1];} else {$this->w=$size[1];$this->h=$size[0];} $this->wPt=$this->w*$this->k; $this->hPt=$this->h*$this->k; $this->PageBreakTrigger=$this->h-$this->bMargin; $this->CurOrientation=$orientation; $this->CurPageSize=$size; } if($rotation!=0){ if($rotation%90!=0)$this->Error('Incorrect rotation value: '.$rotation); $this->CurRotation=$rotation; } else $this->CurRotation=0; }
protected function _endpage(){ $this->state=1; }
protected function _loadfont($path){ include($path); return get_defined_vars(); }
protected function _escape($s){ return str_replace(array('\\','(',')',"\r"),array('\\\\','\\(','\\)',''),$s); }
protected function _textstring($s){ return '('.$this->_escape($s).')'; }
protected function _UTF8toUTF16($s){ return $s; }
protected function _dounderline($x,$y,$txt){ $up=$this->CurrentFont['up']; $ut=$this->CurrentFont['ut']; $w=$this->GetStringWidth($txt)+$this->ws*substr_count($txt,' '); return sprintf('%.2F %.2F %.2F %.2F re f',$x*$this->k,($this->h-($y-$up/1000*$this->FontSize))*$this->k,$w*$this->k,-$ut/1000*$this->FontSizePt); }
protected function _parsejpg($file){ $a = getimagesize($file); if(!$a) $this->Error('Missing or incorrect image file: '.$file); if($a[2]!=2) $this->Error('Not a JPEG file: '.$file); return array('w'=>$a[0],'h'=>$a[1],'cs'=>$a['channels']==4?'DeviceCMYK':'DeviceRGB','bpc'=>8,'f'=>'DCTDecode','data'=>file_get_contents($file)); }
protected function _parsepng($file){
    $f=fopen($file,'rb'); if(!$f)$this->Error('Can\'t open image file: '.$file); if(fread($f,8)!="\x89PNG\r\n\x1a\n")$this->Error('Not a PNG file: '.$file);
    fread($f,4); if(fread($f,4)!='IHDR')$this->Error('Incorrect PNG file: '.$file); $w=$this->_freadint($f); $h=$this->_freadint($f); $bpc=ord(fread($f,1)); if($bpc>8)$this->Error('16-bit depth not supported: '.$file); $ct=ord(fread($f,1)); if($ct==0||$ct==4)$colspace='DeviceGray'; elseif($ct==2||$ct==6)$colspace='DeviceRGB'; elseif($ct==3)$colspace='Indexed'; else $this->Error('Unknown color type: '.$file); if(ord(fread($f,1))!=0)$this->Error('Unknown compression method: '.$file); if(ord(fread($f,1))!=0)$this->Error('Unknown filter method: '.$file); if(ord(fread($f,1))!=0)$this->Error('Interlacing not supported: '.$file); fread($f,4);
    $pal=''; $trns=''; $data=''; do{ $n=$this->_freadint($f); $type=fread($f,4); if($type=='PLTE')$pal=fread($f,$n); elseif($type=='tRNS')$trns=fread($f,$n); elseif($type=='IDAT')$data.=fread($f,$n); elseif($type=='IEND')break; else fread($f,$n); fread($f,4);} while($n);
    fclose($f); if($colspace=='Indexed' && empty($pal))$this->Error('Missing palette in '.$file); return array('w'=>$w,'h'=>$h,'cs'=>$colspace,'bpc'=>$bpc,'f'=>'FlateDecode','pal'=>$pal,'trns'=>$trns,'data'=>$data);
}
protected function _parsebmp($file){ $img = @imagecreatefrombmp($file); if(!$img)$this->Error('Unsupported BMP file: '.$file); $tmp = tempnam(sys_get_temp_dir(),'bmpjpg'); imagejpeg($img,$tmp,90); imagedestroy($img); $info = $this->_parsejpg($tmp); @unlink($tmp); return $info; }
protected function _readstream($f,$n){ $res=''; while($n>0 && !feof($f)){ $s=fread($f,$n); if($s===false)$this->Error('Error while reading stream'); $n-=strlen($s); $res.=$s;} return $res; }
protected function _freadint($f){ $a=unpack('Ni',$this->_readstream($f,4)); return $a['i']; }
protected function _newobj(){ $this->n++; $this->offsets[$this->n]=strlen($this->buffer); $this->_out($this->n.' 0 obj'); }
protected function _putstream($s){ $this->_out('stream'); $this->_out($s); $this->_out('endstream'); }
protected function _out($s){ if($this->state==2) $this->pages[$this->page].=$s."\n"; else $this->buffer.=$s."\n"; }
protected function _putpages(){
    $nb=$this->page;
    if(!empty($this->AliasNbPages)){ for($n=1;$n<=$nb;$n++) $this->pages[$n]=str_replace($this->AliasNbPages,$nb,$this->pages[$n]); }
    if($this->DefOrientation=='P'){ $wPt=$this->DefPageSize[0]*$this->k; $hPt=$this->DefPageSize[1]*$this->k; }
    else { $wPt=$this->DefPageSize[1]*$this->k; $hPt=$this->DefPageSize[0]*$this->k; }
    $filter = ($this->compress) ? '/Filter /FlateDecode ' : '';
    for($n=1;$n<=$nb;$n++){
        $this->_newobj();
        $this->_out('<</Type /Page');
        $this->_out('/Parent 1 0 R');
        if(isset($this->OrientationChanges[$n])) $this->_out(sprintf('/MediaBox [0 0 %.2F %.2F]',$hPt,$wPt));
        $this->_out('/Resources 2 0 R');
        if(isset($this->PageLinks[$n])){ $annots='/Annots ['; foreach($this->PageLinks[$n] as $pl){ $rect=sprintf('%.2F %.2F %.2F %.2F',$pl[0],$pl[1],$pl[0]+$pl[2],$pl[1]-$pl[3]); $annots .= '<</Type /Annot /Subtype /Link /Rect ['.$rect.'] /Border [0 0 0] '; if(is_string($pl[4])) $annots .= '/A <</S /URI /URI '.$this->_textstring($pl[4]).'>>>>'; else { $l=$this->links[$pl[4]]; $annots .= sprintf('/Dest [%d 0 R /XYZ 0 %.2F null]>>',1+2*$l[0],$hPt-$l[1]*$this->k);} } $this->_out($annots.']'); }
        $this->_out('/Contents '.($this->n+1).' 0 R>>');
        $this->_out('endobj');
        $p = ($this->compress) ? gzcompress($this->pages[$n]) : $this->pages[$n];
        $this->_newobj();
        $this->_out('<<'.$filter.'/Length '.strlen($p).'>>');
        $this->_putstream($p);
        $this->_out('endobj');
    }
    $this->offsets[1]=strlen($this->buffer);
    $this->_out('1 0 obj');
    $this->_out('<</Type /Pages');
    $kids='/Kids ['; for($i=0;$i<$nb;$i++) $kids .= (3+2*$i).' 0 R '; $this->_out($kids.']');
    $this->_out('/Count '.$nb);
    $this->_out(sprintf('/MediaBox [0 0 %.2F %.2F]',$wPt,$hPt));
    $this->_out('>>');
    $this->_out('endobj');
}
protected function _putfonts(){
    foreach($this->fonts as $k=>$font){
        $this->fonts[$k]['n']=$this->n+1;
        $this->_newobj();
        $this->_out('<</Type /Font');
        $this->_out('/BaseFont /'.$font['name']);
        $this->_out('/Subtype /Type1');
        if($font['name']!='Symbol' && $font['name']!='ZapfDingbats') $this->_out('/Encoding /WinAnsiEncoding');
        $this->_out('>>');
        $this->_out('endobj');
    }
}
protected function _putimages(){
    foreach($this->images as $file=>$info){
        $this->_newobj();
        $this->images[$file]['n']=$this->n;
        $this->_out('<</Type /XObject');
        $this->_out('/Subtype /Image');
        $this->_out('/Width '.$info['w']);
        $this->_out('/Height '.$info['h']);
        if($info['cs']=='Indexed') $this->_out('/ColorSpace [/Indexed /DeviceRGB '.(strlen($info['pal'])/3-1).' '.($this->n+1).' 0 R]');
        else { $this->_out('/ColorSpace /'.$info['cs']); if($info['cs']=='DeviceCMYK') $this->_out('/Decode [1 0 1 0 1 0 1 0]'); }
        $this->_out('/BitsPerComponent '.$info['bpc']);
        if(isset($info['f'])) $this->_out('/Filter /'.$info['f']);
        if(isset($info['trns']) && is_array($info['trns'])){ $trns=''; for($i=0;$i<count($info['trns']);$i++) $trns .= $info['trns'][$i].' '.$info['trns'][$i].' '; $this->_out('/Mask ['.$trns.']'); }
        $this->_out('/Length '.strlen($info['data']).'>>');
        $this->_putstream($info['data']);
        $this->_out('endobj');
        if($info['cs']=='Indexed'){ $this->_newobj(); $pal = $this->compress ? gzcompress($info['pal']) : $info['pal']; $filter = $this->compress ? '/Filter /FlateDecode ' : ''; $this->_out('<<'.$filter.'/Length '.strlen($pal).'>>'); $this->_putstream($pal); $this->_out('endobj'); }
    }
}
protected function _putresources(){
    $this->_putfonts();
    $this->_putimages();
    $this->offsets[2]=strlen($this->buffer);
    $this->_out('2 0 obj');
    $this->_out('<<');
    $this->_putresourcedict();
    $this->_out('>>');
    $this->_out('endobj');
}
protected function _putresourcedict(){
    $this->_out('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
    $this->_out('/Font <<');
    foreach($this->fonts as $font) $this->_out('/F'.$font['i'].' '.$font['n'].' 0 R');
    $this->_out('>>');
    if(count($this->images)){
        $this->_out('/XObject <<');
        foreach($this->images as $image) $this->_out('/I'.$image['i'].' '.$image['n'].' 0 R');
        $this->_out('>>');
    }
}
protected function _putinfo(){ $this->_out('/Producer '.$this->_textstring('FPDF '.self::VERSION)); }
protected function _putcatalog(){ $this->_out('/Type /Catalog'); $this->_out('/Pages 1 0 R'); if($this->ZoomMode=='fullpage')$this->_out('/OpenAction [3 0 R /Fit]'); if($this->LayoutMode=='single')$this->_out('/PageLayout /SinglePage'); }
protected function _putheader(){ $this->_out('%PDF-'.$this->PDFVersion); }
protected function _puttrailer(){ $this->_out('/Size '.($this->n+1)); $this->_out('/Root '.($this->n).' 0 R'); $this->_out('/Info '.($this->n-1).' 0 R'); }
protected function _enddoc(){
    $this->_putheader();
    $this->_putpages();
    $this->_putresources();
    $this->_newobj(); $this->_out('<<'); $this->_putinfo(); $this->_out('>>'); $this->_out('endobj');
    $this->_newobj(); $this->_out('<<'); $this->_putcatalog(); $this->_out('>>'); $this->_out('endobj');
    $o = strlen($this->buffer);
    $this->_out('xref'); $this->_out('0 '.($this->n+1)); $this->_out('0000000000 65535 f ');
    for($i=1;$i<=$this->n;$i++) $this->_out(sprintf('%010d 00000 n ',$this->offsets[$i]));
    $this->_out('trailer'); $this->_out('<<'); $this->_puttrailer(); $this->_out('>>'); $this->_out('startxref'); $this->_out($o); $this->_out('%%EOF');
    $this->state=3;
}
}
?>