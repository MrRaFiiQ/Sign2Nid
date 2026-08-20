<?php
error_reporting(0);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    return true;
});
ini_set('display_errors', '0');
ob_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(["code" => 405, "message" => "Method Not Allowed"]);
    exit;
}

$fileKey = isset($_FILES['nid_pdf']) ? 'nid_pdf' : 'pdf';
if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
    ob_clean();
    echo json_encode([
        'code' => 400,
        'success' => false,
        'message' => 'No file uploaded or upload error occurred.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ইউনিক টেম্পোরারি ডিরেক্টরি তৈরি
$uploadDir = sys_get_temp_dir() . '/nid_extract_' . uniqid();
@mkdir($uploadDir, 0755, true);

$pdfPath = $uploadDir . '/uploaded.pdf';
@move_uploaded_file($_FILES[$fileKey]['tmp_name'], $pdfPath);

// ১. টেক্সট এক্সট্রাক্ট করা
$textPath = $uploadDir . '/text.txt';
@exec("pdftotext " . escapeshellarg($pdfPath) . " " . escapeshellarg($textPath));
$text = file_exists($textPath) ? file_get_contents($textPath) : "";

// ২. ছবি ও সিগনেচার এক্সট্রাক্ট করে শর্ট লিংক তৈরি করা
$imgDir = __DIR__ . '/uploads';
if (!file_exists($imgDir)) {
    @mkdir($imgDir, 0755, true);
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$baseUrl = $protocol . "://$host/uploads/";

@exec("pdfimages -all " . escapeshellarg($pdfPath) . " " . escapeshellarg($uploadDir . '/img'));

$images = glob($uploadDir . '/img-*');
$userIMG = "";
$signIMG = "";

if (count($images) > 0) {
    sort($images);
    if (isset($images[0])) {
        $ext = pathinfo($images[0], PATHINFO_EXTENSION);
        $filename = 'user_' . uniqid() . '.' . ($ext ?: 'png');
        @copy($images[0], $imgDir . '/' . $filename);
        $userIMG = $baseUrl . $filename;
    }
    if (isset($images[1])) {
        $ext = pathinfo($images[1], PATHINFO_EXTENSION);
        $filename = 'sign_' . uniqid() . '.' . ($ext ?: 'png');
        @copy($images[1], $imgDir . '/' . $filename);
        $signIMG = $baseUrl . $filename;
    }
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function extractBetween($text, $start, $end) {
    $pattern = '/' . preg_quote($start, '/') . '[\s\|:]+(.*?)(?=' . preg_quote($end, '/') . '|$)/uis';
    if (preg_match($pattern, $text, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function cleanText($text) {
    $text = preg_replace('/[\|\r\n]+/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = str_ireplace([
        'Village/Road', 'Home/Holding', 'Additional', 'No.', 'No', 
        'Post Office', 'Postal Code', 'Upozila', 'District', 
        'Union/Ward', 'Municipality', 'Smart Card Info', 
        'No Documents Available', 'Voter Area', 'RMO', 'Mouza/Moholla',
        'License Documents', 'Union Porishod', 'Permanent Address', 
        'Education', 'Region', 'Division', 'City Corporation', 'Ward For'
    ], '', $text);
    return trim($text);
}

function cleanName($text) {
    $unwanted = [
        'Smart Card Info', 
        'No Documents Available', 
        'Voter Area', 
        'Voter At', 
        'Death Date', 
        'Religion', 
        'Gender', 
        'Blood Group',
        'License Documents',
        'Union Porishod',
        'Mouza/Moholla',
        'Village/Road',
        'Pin',
        'Status'
    ];
    foreach ($unwanted as $word) {
        $pos = mb_stripos($text, $word);
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }
    }
    $text = preg_replace('/[\|]+/u', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function extractPostalCode($text) {
    preg_match('/(?:Postal Code|পোস্ট কোড)[^0-9]*([0-9]{4})/ui', $text, $m2);
    if(isset($m2[1])) return $m2[1];

    $raw = extractBetween($text, 'Postal Code', 'Region');
    if(!$raw) $raw = extractBetween($text, 'Postal Code', 'Upozila');
    preg_match('/([0-9]{4})/u', $raw, $m);
    return isset($m[1]) ? $m[1] : '';
}

function convertToBangla($number) {
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    return str_replace($en, $bn, $number);
}

function findValueByLabel($searchLabel, $text) {
    $pattern = '/' . preg_quote($searchLabel, '/') . '[\s\|:]+([^\r\n\|]+)/ui';
    if (preg_match($pattern, $text, $matches)) {
        return cleanName($matches[1]);
    }
    return "";
}

// ============================================================
// COMBINE ADDRESS LOGIC (ISOLATED PRESENT ADDRESS BLOCK)
// ============================================================

function combineAddress($fullText) {
    // শুধু 'Present Address' থেকে 'Permanent Address' পর্যন্ত অংশটুকু আলাদা করে নেওয়া
    $blockPattern = '/Present\s*Address(.*?)(?:Permanent\s*Address|$)/uis';
    $text = $fullText;
    if (preg_match($blockPattern, $fullText, $mBlock)) {
        $text = $mBlock[1];
    }

    // গ্রাম/রাস্তা বা মৌজা/মহল্লা এক্সট্রাক্ট করা
    $villageRaw = extractBetween($text, 'Village/Road', 'Home/Holding');
    if (!$villageRaw) {
        $villageRaw = extractBetween($text, 'Village/Road', 'Post Office');
    }
    if (!$villageRaw) {
        $villageRaw = extractBetween($text, 'Mouza/Moholla', 'Post Office');
    }
    if (!$villageRaw) {
        $villageRaw = extractBetween($text, 'Mouza/Moholla', 'Home/Holding');
    }

    $village = str_ireplace(['Village/Road', 'Home/Holding', 'Additional', 'No.', 'No', 'Union/Ward', 'Mouza/Moholla'], '', $villageRaw);
    $village = cleanText($village);

    $homeRaw = extractBetween($text, 'Home/Holding', 'Post Office');
    if (!$homeRaw) {
        $homeRaw = extractBetween($text, 'Home/Holding', 'Postal Code');
    }

    $home = str_ireplace(['Home/Holding', 'Village/Road', 'Additional', 'No.', 'No', 'Union/Ward'], '', $homeRaw);
    $home = cleanText($home);

    $postOffice = cleanText(extractBetween($text, 'Post Office', 'Postal Code'));
    if (!$postOffice) {
        $postOffice = cleanText(extractBetween($text, 'Post Office', 'Upozila'));
    }

    $postalCode = extractPostalCode($text);
    if (!$postalCode) {
        $postalCode = extractPostalCode($fullText); // ফেইলব্যাক
    }
    $postalCodeBangla = convertToBangla($postalCode);

    $upozila = cleanText(extractBetween($text, 'Upozila', 'Union'));
    if (!$upozila) {
        $upozila = cleanText(extractBetween($text, 'Upozila', 'Municipality'));
    }
    if (!$upozila) {
        $upozila = cleanText(extractBetween($text, 'Upozila', 'District'));
    }

    $district = cleanText(extractBetween($text, 'District', 'RMO'));
    if (!$district) {
        $district = cleanText(extractBetween($text, 'District', 'City'));
    }

    $parts = [];

    if (!empty($home) && $home !== 'Additional' && mb_stripos($home, 'No') === false) {
        $parts[] = 'বাসা/হোল্ডিং: ' . $home;
    }

    if (!empty($village) && $village !== 'Additional' && mb_stripos($village, 'No') === false) {
        $parts[] = 'গ্রাম/রাস্তা: ' . $village;
    }

    if (!empty($postOffice)) {
        $postOfficeClean = str_ireplace(['Post Office', 'Postal Code'], '', $postOffice);
        $parts[] = 'ডাকঘর: ' . trim($postOfficeClean) . ($postalCodeBangla ? ' - ' . $postalCodeBangla : '');
    }

    if (!empty($upozila)) {
        $parts[] = $upozila;
    }

    if (!empty($district) && $district !== 'RMO') {
        $parts[] = $district;
    }

    return implode(', ', $parts);
}

// ৩. ডাটা এক্সট্রাকশন
$nameBangla = findValueByLabel('Name(Bangla)', $text);
if(!$nameBangla) $nameBangla = findValueByLabel('Name (Bangla)', $text);

$nameEnglish = findValueByLabel('Name(English)', $text);
if(!$nameEnglish) $nameEnglish = findValueByLabel('Name (English)', $text);

$fatherName = findValueByLabel('Father Name', $text);
$motherName = findValueByLabel('Mother Name', $text);
$birthPlace = findValueByLabel('Birth Place', $text);

$bloodGroupRaw = findValueByLabel('Blood Group', $text);
if (preg_match('/^(A|B|AB|O)[+-]$/ui', trim($bloodGroupRaw), $match)) {
    $bloodGroup = strtoupper($match[0]);
} else {
    $bloodGroup = ""; 
}

$gender = findValueByLabel('Gender', $text);
$religion = findValueByLabel('Religion', $text);

$nationalId = findValueByLabel('National ID', $text);
$nationalId = str_replace(' ', '', $nationalId);

$pin = findValueByLabel('Pin', $text);
$pin = str_replace(' ', '', $pin);

$dateOfBirth = findValueByLabel('Date of Birth', $text);

$address = combineAddress($text);

$en = ['0','1','2','3','4','5','6','7','8','9'];
$bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
$dateOfToday = str_replace($en, $bn, date('d-m-Y'));

$response = [
    "code" => 200,
    "success" => true,
    "message" => "Data fetched successfully",
    "data" => [
        "nameBangla" => $nameBangla,
        "nameEnglish" => $nameEnglish,
        "nationalId" => $nationalId,
        "pin" => $pin,
        "dateOfBirth" => $dateOfBirth,
        "dateOfToday" => $dateOfToday,
        "fatherName" => $fatherName,
        "motherName" => $motherName,
        "gender" => $gender,
        "religion" => $religion,
        "birthPlace" => $birthPlace,
        "bloodGroup" => $bloodGroup,
        "userIMG" => $userIMG,
        "signIMG" => $signIMG,
        "address" => $address
    ]
];

ob_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
ob_end_flush();

@array_map('unlink', glob("$uploadDir/*.*"));
@rmdir($uploadDir);
?>
