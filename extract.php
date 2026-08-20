<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["code" => 405, "message" => "Method Not Allowed"]);
    exit;
}

$fileKey = isset($_FILES['nid_pdf']) ? 'nid_pdf' : 'pdf';
if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(["code" => 400, "message" => "No file uploaded or upload error occurred."], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ইউনিক টেম্পোরারি ডিরেক্টরি তৈরি
$uploadDir = sys_get_temp_dir() . '/nid_extract_' . uniqid();
mkdir($uploadDir);

$pdfPath = $uploadDir . '/uploaded.pdf';
move_uploaded_file($_FILES[$fileKey]['tmp_name'], $pdfPath);

// ১. টেক্সট এক্সট্রাক্ট করা
$textPath = $uploadDir . '/text.txt';
exec("pdftotext -layout " . escapeshellarg($pdfPath) . " " . escapeshellarg($textPath));
$text = file_exists($textPath) ? file_get_contents($textPath) : "";

// ২. ছবি ও সিগনেচার এক্সট্রাক্ট করে আলাদা ফোল্ডারে শর্ট লিংক তৈরি করা
$imgDir = __DIR__ . '/uploads';
if (!file_exists($imgDir)) {
    mkdir($imgDir, 0755, true);
}

// প্রটোকল এবং হোস্ট বের করা শর্ট লিংকের জন্য
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . "://$host/uploads/";

exec("pdfimages -all " . escapeshellarg($pdfPath) . " " . escapeshellarg($uploadDir . '/img'));

$images = glob($uploadDir . '/img-*');
$userIMG = "";
$signIMG = "";

if (count($images) > 0) {
    sort($images);
    if (isset($images[0])) {
        $ext = pathinfo($images[0], PATHINFO_EXTENSION);
        $filename = 'user_' . uniqid() . '.' . $ext;
        copy($images[0], $imgDir . '/' . $filename);
        $userIMG = $baseUrl . $filename;
    }
    if (isset($images[1])) {
        $ext = pathinfo($images[1], PATHINFO_EXTENSION);
        $filename = 'sign_' . uniqid() . '.' . $ext;
        copy($images[1], $imgDir . '/' . $filename);
        $signIMG = $baseUrl . $filename;
    }
}

// ============================================================
// ADDRESS & TEXT HELPER FUNCTIONS
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
    // অতিরিক্ত সিস্টেম লেবেলগুলো ফিল্টার করে বাদ দেওয়া
    $text = str_ireplace([
        'Village/Road', 'Home/Holding', 'Additional', 'No.', 'No', 
        'Post Office', 'Postal Code', 'Upozila', 'District', 
        'Union/Ward', 'Municipality', 'Smart Card Info', 
        'No Documents Available', 'Voter Area', 'RMO'
    ], '', $text);
    return trim($text);
}

// নামের শেষ থেকে অতিরিক্ত সিস্টেম টেক্সট (যেমন: Smart Card Info, No Documents Available) কেটে পরিষ্কার করার ফাংশন
function cleanName($text) {
    $unwanted = [
        'Smart Card Info', 
        'No Documents Available', 
        'Voter Area', 
        'Voter At', 
        'Death Date', 
        'Religion', 
        'Gender', 
        'Blood Group'
    ];
    foreach ($unwanted as $word) {
        $pos = mb_stripos($text, $word);
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }
    }
    // পাইপ বা অতিরিক্ত স্পেস ক্লিন করা
    $text = preg_replace('/[\|]+/u', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function extractPostalCode($text) {
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
// COMBINE ADDRESS LOGIC
// ============================================================

function combineAddress($text) {
    $villageRaw = extractBetween($text, 'Village/Road', 'Home/Holding');
    if (!$villageRaw) {
        $villageRaw = extractBetween($text, 'Village/Road', 'Post Office');
    }

    $village = str_ireplace(['Village/Road', 'Home/Holding', 'Additional', 'No.', 'No', 'Union/Ward'], '', $villageRaw);
    // যদি গ্রামের নামের ভেতর ইউনিয়ন বা অন্য কিছু চলে আসে তা কেটে ফেলা
    $partsVillage = explode('Union', $village);
    $village = cleanText($partsVillage[0]);

    $homeRaw = extractBetween($text, 'Home/Holding', 'Post Office');
    if (!$homeRaw) {
        $homeRaw = extractBetween($text, 'Home/Holding', 'Postal Code');
    }

    $home = str_ireplace(['Home/Holding', 'Village/Road', 'Additional', 'No.', 'No', 'Union/Ward'], '', $homeRaw);
    $partsHome = explode('Union', $home);
    $home = cleanText($partsHome[0]);

    $postOffice = cleanText(extractBetween($text, 'Post Office', 'Postal Code'));
    $postalCode = extractPostalCode($text);
    $postalCodeBangla = convertToBangla($postalCode);

    $upozila = cleanText(extractBetween($text, 'Upozila', 'Union'));
    if (!$upozila) {
        $upozila = cleanText(extractBetween($text, 'Upozila', 'Municipality'));
    }

    $district = cleanText(extractBetween($text, 'District', 'RMO'));
    if (!$district) {
        $district = cleanText(extractBetween($text, 'District', 'City'));
    }

    $parts = [];

    if (!empty($home) && $home !== 'Additional') {
        $parts[] = 'বাসা/হোল্ডিং: ' . $home;
    }

    if (!empty($village) && $village !== 'Additional') {
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

// ব্লাড গ্রুপে TIN আসা রোধ করার জন্য ভ্যালিডেশন
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

// ঠিকানা কম্বাইন ফাংশন কল
$address = combineAddress($text);

// আজকের বাংলা তারিখ তৈরি
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

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// টেম্পোরারি ফাইল ক্লিনআপ (আপলোড করা ছবিগুলো /uploads ফোল্ডারে সংরক্ষিত থাকবে শর্ট লিংকের জন্য)
array_map('unlink', glob("$uploadDir/*.*"));
rmdir($uploadDir);
?>        "address" => $address
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// টেম্পোরারি ফাইল ক্লিনআপ (আপলোড করা ছবিগুলো /uploads ফোল্ডারে সংরক্ষিত থাকবে শর্ট লিংকের জন্য)
array_map('unlink', glob("$uploadDir/*.*"));
rmdir($uploadDir);
?>
