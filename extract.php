<?php
declare(strict_types=1);

/*
 * Render-ready PDF extraction endpoint.
 * Accepts multipart field: pdf OR nid_pdf.
 *
 * Required server dependencies are installed by Dockerfile:
 * - PHP GD
 * - mbstring
 * - Poppler: pdftotext, pdfimages, pdftoppm
 * - Composer package: smalot/pdfparser
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Dhaka');

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function isHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function getBaseUrl(): string {
    $scheme = isHttps() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function cleanText(?string $text): string {
    if ($text === null || $text === '') return '';
    $text = str_replace(["\r", "\n", "\t", '"', ','], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function cleanBanglaName(string $text): string {
    $text = preg_replace('/halnagad_\d+/iu', '', $text) ?? $text;
    $text = preg_replace('/\bTag\b/iu', '', $text) ?? $text;
    $text = preg_replace('/Name\s*\(\s*Bangla\s*\)/iu', '', $text) ?? $text;
    return cleanText($text);
}

function extractBetween(string $text, string $start, string $end): string {
    $pattern = '/' . preg_quote($start, '/') . '(.*?)' . preg_quote($end, '/') . '/isu';
    if (preg_match($pattern, $text, $m)) return cleanText($m[1]);
    return '';
}

function extractValue(string $text, string $label, array $nextLabels = []): string {
    $allEnds = $nextLabels;
    if (!$allEnds) {
        $pattern = '/' . preg_quote($label, '/') . '\s*[:|]?\s*(.+?)(?=\r?\n|$)/iu';
        return preg_match($pattern, $text, $m) ? cleanText($m[1]) : '';
    }
    $end = implode('|', array_map(fn($x)=>preg_quote($x,'/'), $allEnds));
    $pattern = '/' . preg_quote($label, '/') . '\s*[:|]?\s*(.*?)(?=' . $end . '|$)/isu';
    return preg_match($pattern, $text, $m) ? cleanText($m[1]) : '';
}

function extractNid(string $text): string {
    if (preg_match('/National\s*ID[^\d০-৯]*(\d{10,17})/iu', $text, $m)) return $m[1];
    return '';
}

function extractPin(string $text): string {
    if (preg_match('/Pin[^\d০-৯]*(\d{10,17})/iu', $text, $m)) return $m[1];
    return '';
}

function extractPostalCode(string $text): string {
    if (preg_match('/Postal\s*Code[^\d০-৯]*([0-9০-৯]{4})/iu', $text, $m)) return $m[1];
    return '';
}

function convertToBangla(string $number): string {
    return strtr($number, [
        '0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪',
        '5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯'
    ]);
}

function normalizeDigits(string $s): string {
    return strtr($s, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
}

function formatDob(string $raw): string {
    $raw = cleanText($raw);
    if ($raw === '') return '';
    $raw2 = normalizeDigits($raw);
    $ts = strtotime($raw2);
    if ($ts !== false) return date('d M Y', $ts);
    if (preg_match('/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $raw2, $m)) {
        $ts = strtotime(sprintf('%02d-%02d-%04d', (int)$m[1], (int)$m[2], (int)$m[3]));
        if ($ts !== false) return date('d M Y', $ts);
    }
    return $raw;
}

function combineAddress(string $text): string {
    $block = $text;
    if (preg_match('/Present\s*Address(.*?)(?:Permanent\s*Address|$)/isu', $text, $m)) {
        $block = $m[1];
    }

    $village = extractBetween($block, 'Village/Road', 'Home/Holding');
    if (!$village) $village = extractBetween($block, 'Village/Road', 'Post Office');
    if (!$village) $village = extractBetween($block, 'Mouza/Moholla', 'Post Office');
    $village = cleanText(str_ireplace(['Village/Road','Home/Holding','Additional','No.','Union/Ward','Mouza/Moholla'], '', $village));

    $home = extractBetween($block, 'Home/Holding', 'Post Office');
    if (!$home) $home = extractBetween($block, 'Home/Holding', 'Postal Code');
    $home = cleanText(str_ireplace(['Home/Holding','Village/Road','Additional','No.','Union/Ward'], '', $home));

    $postOffice = extractBetween($block, 'Post Office', 'Postal Code');
    if (!$postOffice) $postOffice = extractBetween($block, 'Post Office', 'Upozila');
    $postOffice = cleanText(str_ireplace(['Post Office','Postal Code'], '', $postOffice));

    $postal = extractPostalCode($block);
    if (!$postal) $postal = extractPostalCode($text);

    $upozila = extractBetween($block, 'Upozila', 'Union');
    if (!$upozila) $upozila = extractBetween($block, 'Upozila', 'Municipality');
    if (!$upozila) $upozila = extractBetween($block, 'Upozila', 'District');
    $upozila = cleanText($upozila);

    $district = extractBetween($block, 'District', 'RMO');
    if (!$district) $district = extractBetween($block, 'District', 'City');
    if (!$district) $district = extractBetween($block, 'District', 'Division');
    $district = cleanText($district);

    $parts = [];
    if ($home && !preg_match('/^(Additional|No\.?)$/iu', $home)) $parts[] = 'বাসা/হোল্ডিং: ' . $home;
    if ($village && !preg_match('/^(Additional|No\.?)$/iu', $village)) $parts[] = 'গ্রাম/রাস্তা: ' . $village;
    if ($postOffice) $parts[] = 'ডাকঘর: ' . $postOffice . ($postal ? ' -' . convertToBangla(normalizeDigits($postal)) : '');
    if ($upozila) $parts[] = $upozila;
    if ($district && !preg_match('/^RMO$/iu', $district)) $parts[] = $district;

    return implode(', ', $parts);
}

function runCommand(string $command, ?int &$exitCode = null): string {
    $output = [];
    $code = 0;
    @exec($command . ' 2>&1', $output, $code);
    $exitCode = $code;
    return implode("\n", $output);
}

function extractPdfText(string $pdfPath, string $workDir): string {
    $textFile = $workDir . '/text.txt';
    $code = 0;
    runCommand('pdftotext -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($textFile), $code);
    if ($code === 0 && is_file($textFile)) {
        $text = @file_get_contents($textFile);
        if ($text !== false && trim($text) !== '') return $text;
    }

    try {
        if (class_exists('\\Smalot\\PdfParser\\Parser')) {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdfPath);
            return $pdf->getText();
        }
    } catch (Throwable $e) {
        error_log('PDF parser fallback failed: ' . $e->getMessage());
    }
    return '';
}

function validImageFile(string $file): bool {
    if (!is_file($file) || filesize($file) < 100) return false;
    $size = @getimagesize($file);
    return is_array($size) && $size[0] > 0 && $size[1] > 0;
}

function isBlankOrSolidImage(string $file): bool {
    if (!validImageFile($file)) return true;
    $img = @imagecreatefromstring((string)@file_get_contents($file));
    if (!$img) return true;
    $w = imagesx($img); $h = imagesy($img);
    $first = imagecolorat($img, 0, 0);
    $fr = ($first >> 16) & 255; $fg = ($first >> 8) & 255; $fb = $first & 255;
    $hasDiff = false;
    for ($ix=0; $ix<12 && !$hasDiff; $ix++) {
        for ($iy=0; $iy<12; $iy++) {
            $x = min($w-1, (int)(($w-1)*$ix/11));
            $y = min($h-1, (int)(($h-1)*$iy/11));
            $c = imagecolorat($img,$x,$y);
            $r=($c>>16)&255; $g=($c>>8)&255; $b=$c&255;
            if (abs($r-$fr)+abs($g-$fg)+abs($b-$fb) > 45) { $hasDiff=true; break; }
        }
    }
    imagedestroy($img);
    return !$hasDiff;
}

function trimSignatureImage(string $source, string $destination): bool {
    if (!validImageFile($source)) return false;
    $img = @imagecreatefromstring((string)@file_get_contents($source));
    if (!$img) return false;
    $w=imagesx($img); $h=imagesy($img);
    $ignoreX=max(2,(int)($w*0.04)); $ignoreY=max(2,(int)($h*0.04));
    $minX=$w; $minY=$h; $maxX=-1; $maxY=-1;

    for($y=$ignoreY;$y<$h-$ignoreY;$y++){
        for($x=$ignoreX;$x<$w-$ignoreX;$x++){
            $c=imagecolorat($img,$x,$y);
            $r=($c>>16)&255; $g=($c>>8)&255; $b=$c&255;
            $gray=(int)(0.299*$r+0.587*$g+0.114*$b);
            if($gray<120){
                $minX=min($minX,$x); $minY=min($minY,$y);
                $maxX=max($maxX,$x); $maxY=max($maxY,$y);
            }
        }
    }
    if($maxX<0 || $maxY<0){ imagedestroy($img); return false; }

    $padX=max(10,(int)($w*0.03)); $padY=max(6,(int)($h*0.06));
    $cropX=max(0,$minX-$padX); $cropY=max(0,$minY-$padY);
    $right=min($w-1,$maxX+$padX); $bottom=min($h-1,$maxY+$padY);
    $cw=$right-$cropX+1; $ch=$bottom-$cropY+1;
    if($cw<10 || $ch<5){imagedestroy($img);return false;}

    $out=imagecreatetruecolor($cw,$ch);
    $white=imagecolorallocate($out,255,255,255);
    imagefill($out,0,0,$white);
    imagecopy($out,$img,0,0,$cropX,$cropY,$cw,$ch);
    imagepng($out,$destination,6);
    imagedestroy($out); imagedestroy($img);
    return true;
}

function normalizeSignatureImage(string $source, string $destination): bool {
    if (!validImageFile($source)) return false;
    $src=@imagecreatefromstring((string)@file_get_contents($source));
    if(!$src) return false;
    $w=imagesx($src); $h=imagesy($src);
    if($w<20 || $h<10){imagedestroy($src);return false;}

    $borderX=max(2,(int)($w*0.05)); $borderY=max(2,(int)($h*0.06));
    $innerW=$w-2*$borderX; $innerH=$h-2*$borderY;
    if($innerW<20 || $innerH<10){imagedestroy($src);return false;}

    $samples=[];
    foreach([[0,0],[$w-1,0],[0,$h-1],[$w-1,$h-1],[(int)($w/2),0],[(int)($w/2),$h-1]] as $p){
        $c=imagecolorat($src,$p[0],$p[1]);
        $r=($c>>16)&255;$g=($c>>8)&255;$b=$c&255;
        $samples[]=(int)(0.299*$r+0.587*$g+0.114*$b);
    }
    $bg=array_sum($samples)/count($samples);
    $invert=$bg<110;

    $canvas=imagecreatetruecolor($innerW,$innerH);
    $white=imagecolorallocate($canvas,255,255,255);
    $black=imagecolorallocate($canvas,0,0,0);
    imagefill($canvas,0,0,$white);

    for($y=0;$y<$innerH;$y++){
        for($x=0;$x<$innerW;$x++){
            $c=imagecolorat($src,$x+$borderX,$y+$borderY);
            $r=($c>>16)&255;$g=($c>>8)&255;$b=$c&255;
            $gray=(int)(0.299*$r+0.587*$g+0.114*$b);
            if($invert)$gray=255-$gray;
            imagesetpixel($canvas,$x,$y,$gray<150?$black:$white);
        }
    }
    imagedestroy($src);
    imagepng($canvas,$destination,6);
    imagedestroy($canvas);
    return trimSignatureImage($destination,$destination);
}

function createPlaceholder(string $type, string $dir): string {
    $name='placeholder_'.$type.'.png';
    $path=$dir.'/'.$name;
    if(!is_file($path) && function_exists('imagecreatetruecolor')){
        $im=imagecreatetruecolor(180,100);
        $bg=imagecolorallocate($im,245,245,245);
        $fg=imagecolorallocate($im,100,100,100);
        imagefill($im,0,0,$bg);
        imagestring($im,4,25,42,$type==='user'?'User Photo':'Signature',$fg);
        imagepng($im,$path,6); imagedestroy($im);
    }
    return $name;
}

function renderPageOne(string $pdfPath,string $workDir): ?string {
    $out=$workDir.'/page1';
    $code=0;
    runCommand('pdftoppm -f 1 -singlefile -png -r 160 '.escapeshellarg($pdfPath).' '.escapeshellarg($out),$code);
    $file=$out.'.png';
    if($code===0 && is_file($file)) return $file;

    if(class_exists('Imagick')){
        try{
            $im=new Imagick();
            $im->setResolution(160,160);
            $im->readImage($pdfPath.'[0]');
            $im->setImageFormat('png');
            $im->writeImage($file);
            $im->clear();$im->destroy();
            if(is_file($file))return $file;
        }catch(Throwable $e){error_log('Imagick fallback: '.$e->getMessage());}
    }
    return null;
}

function extractImages(string $pdfPath,string $workDir,string $imageDir): array {
    $unique=bin2hex(random_bytes(8));
    $userName=''; $signName='';
    $prefix=$workDir.'/img';
    $code=0;
    runCommand('pdfimages -png '.escapeshellarg($pdfPath).' '.escapeshellarg($prefix),$code);

    $files=glob($prefix.'-*') ?: [];
    sort($files,SORT_NATURAL);

    foreach($files as $file){
        if(!validImageFile($file))continue;
        $size=@getimagesize($file); if(!$size)continue;
        $w=(int)$size[0];$h=(int)$size[1];$ratio=$w/$h;

        if(!$signName && $ratio>=2.0 && $w>=80){
            $name='sign_'.$unique.'.png'; $dest=$imageDir.'/'.$name;
            if(normalizeSignatureImage($file,$dest)){ $signName=$name; continue; }
            @unlink($dest);
        }
        if(!$userName && $ratio<2.0 && $h>($w*0.9) && $w>=50){
            $name='user_'.$unique.'.png'; $dest=$imageDir.'/'.$name;
            if(@copy($file,$dest) && !isBlankOrSolidImage($dest)){ $userName=$name; continue; }
            @unlink($dest);
        }
    }

    if(!$userName || !$signName){
        $page=renderPageOne($pdfPath,$workDir);
        if($page && validImageFile($page)){
            $img=@imagecreatefrompng($page);
            if($img){
                $w=imagesx($img);$h=imagesy($img);

                if(!$userName){
                    $rect=['x'=>(int)($w*0.60),'y'=>(int)($h*0.005),'width'=>(int)($w*0.36),'height'=>(int)($h*0.22)];
                    $crop=@imagecrop($img,$rect);
                    if($crop!==false){
                        $name='user_'.$unique.'.png';$dest=$imageDir.'/'.$name;
                        imagepng($crop,$dest,6);imagedestroy($crop);
                        if(!isBlankOrSolidImage($dest))$userName=$name;else @unlink($dest);
                    }
                }

                if(!$signName){
                    $rect=['x'=>(int)($w*0.50),'y'=>(int)($h*0.25),'width'=>(int)($w*0.63),'height'=>(int)($h*0.05)];
                    $crop=@imagecrop($img,$rect);
                    if($crop!==false){
                        $name='sign_'.$unique.'.png';$dest=$imageDir.'/'.$name;
                        imagepng($crop,$dest,6);imagedestroy($crop);
                        if(normalizeSignatureImage($dest,$dest) && !isBlankOrSolidImage($dest))$signName=$name;else @unlink($dest);
                    }
                }
                imagedestroy($img);
            }
        }
    }

    if(!$userName)$userName=createPlaceholder('user',$imageDir);
    if(!$signName)$signName=createPlaceholder('signature',$imageDir);
    return [$userName,$signName];
}

function processPdf(string $pdfPath,string $workDir,string $imageDir): array {
    $text=extractPdfText($pdfPath,$workDir);
    if(trim($text)==='') throw new RuntimeException('PDF text extraction failed.');

    $nid=extractNid($text);
    $pin=extractPin($text);

    $nameBangla=cleanBanglaName(extractBetween($text,'Name(Bangla)','Name(English)'));
    if(!$nameBangla)$nameBangla=cleanBanglaName(extractBetween($text,'Name (Bangla)','Name (English)'));

    $nameEnglish=strtoupper(extractBetween($text,'Name(English)','Date of Birth'));
    if(!$nameEnglish)$nameEnglish=strtoupper(extractBetween($text,'Name (English)','Date of Birth'));

    $dobRaw=extractBetween($text,'Date of Birth','Birth Place');
    $dob=formatDob($dobRaw);

    $father=extractBetween($text,'Father Name','Mother Name');
    $mother=extractBetween($text,'Mother Name','Spouse Name');
    $gender=extractBetween($text,'Gender','Marital');
    $religion=extractBetween($text,'Religion','Religion Other');
    $birthPlace=extractBetween($text,'Birth Place','Birth Other');
    $blood=extractBetween($text,'Blood Group','TIN');

    $blood=preg_match('/^(A|B|AB|O)\s*[+-]$/iu',trim($blood),$bm)?strtoupper(str_replace(' ','',$bm[0])):cleanText($blood);

    [$userName,$signName]=extractImages($pdfPath,$workDir,$imageDir);

    $base=getBaseUrl();
    return [
        'nameBangla'=>$nameBangla,
        'nameEnglish'=>$nameEnglish,
        'nationalId'=>$nid,
        'pin'=>$pin,
        'dateOfBirth'=>$dob,
        'dateOfToday'=>convertToBangla(date('d-m-Y')),
        'fatherName'=>cleanText($father),
        'motherName'=>cleanText($mother),
        'gender'=>cleanText($gender),
        'religion'=>cleanText($religion),
        'birthPlace'=>cleanText($birthPlace),
        'bloodGroup'=>$blood,
        'userIMG'=>$base.'/images/'.rawurlencode($userName),
        'signIMG'=>$base.'/images/'.rawurlencode($signName),
        'address'=>combineAddress($text)
    ];
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    jsonResponse(['code'=>405,'success'=>false,'message'=>'Method Not Allowed'],405);
}

$fileKey=isset($_FILES['nid_pdf'])?'nid_pdf':'pdf';
if(!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error']!==UPLOAD_ERR_OK){
    jsonResponse(['code'=>400,'success'=>false,'message'=>'No file uploaded or upload error occurred.'],400);
}

$f=$_FILES[$fileKey];
if(($f['size']??0)<=0 || ($f['size']??0)>30*1024*1024){
    jsonResponse(['code'=>400,'success'=>false,'message'=>'PDF must be between 1 byte and 30 MB.'],400);
}

$original=basename((string)$f['name']);
if(strtolower(pathinfo($original,PATHINFO_EXTENSION))!=='pdf'){
    jsonResponse(['code'=>400,'success'=>false,'message'=>'Invalid file type. Only PDF files are allowed.'],400);
}

if(!is_uploaded_file($f['tmp_name'])){
    jsonResponse(['code'=>400,'success'=>false,'message'=>'Invalid upload.'],400);
}

$workDir=sys_get_temp_dir().'/nid_extract_'.bin2hex(random_bytes(8));
$imageDir=__DIR__.'/images';
@mkdir($workDir,0700,true);
@mkdir($imageDir,0777,true);

$pdfPath=$workDir.'/uploaded.pdf';

try{
    if(!@move_uploaded_file($f['tmp_name'],$pdfPath)){
        throw new RuntimeException('Failed to move uploaded file.');
    }

    $data=processPdf($pdfPath,$workDir,$imageDir);

    jsonResponse([
        'code'=>200,
        'success'=>true,
        'message'=>'Data fetched successfully',
        'data'=>$data
    ],200);

}catch(Throwable $e){
    error_log('NID PDF extraction error: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    jsonResponse([
        'code'=>500,
        'success'=>false,
        'message'=>'Error processing the PDF.'
    ],500);

}finally{
    if(is_dir($workDir)){
        $it=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workDir,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach($it as $p){
            $p->isDir()?@rmdir($p->getPathname()):@unlink($p->getPathname());
        }
        @rmdir($workDir);
    }
}
?>
