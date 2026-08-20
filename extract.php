<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["code" => 405, "message" => "Method Not Allowed"]);
    exit;
}

if (!isset($_FILES['nid_pdf']) || $_FILES['nid_pdf']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(["code" => 400, "message" => "No PDF file uploaded"]);
    exit;
}

// ইউনিক টেম্পোরারি ডিরেক্টরি তৈরি
$uploadDir = sys_get_temp_dir() . '/nid_extract_' . uniqid();
mkdir($uploadDir);

$pdfPath = $uploadDir . '/uploaded.pdf';
move_uploaded_file($_FILES['nid_pdf']['tmp_name'], $pdfPath);

// ১. টেক্সট এক্সট্রাক্ট করা
$textPath = $uploadDir . '/text.txt';
exec("pdftotext " . escapeshellarg($pdfPath) . " " . escapeshellarg($textPath));
$text = file_exists($textPath) ? file_get_contents($textPath) : "";

// ২. ছবি ও সিগনেচার এক্সট্রাক্ট করা
exec("pdfimages -all " . escapeshellarg($pdfPath) . " " . escapeshellarg($uploadDir . '/img'));

$images = glob($uploadDir . '/img-*');
$userIMG = "";
$signIMG = "";

if (count($images) > 0) {
    sort($images);
    if (isset($images[0])) {
        $userIMG = 'data:image/' . pathinfo($images[0], PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($images[0]));
    }
    if (isset($images[1])) {
        $signIMG = 'data:image/' . pathinfo($images[1], PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($images[1]));
    }
}

// ৩. অ্যাডভান্সড ডাইনামিক ফিল্ড এক্সট্রাকশন ফাংশন (মাল্টি-লাইন এবং পাইপ হ্যান্ডেল করতে সক্ষম)
function extractPdfField($label, $text) {
    $escapedLabel = preg_quote($label, '/');
    // লেবেলের পর স্পেস, নতুন লাইন বা পাইপ পেরিয়ে সঠিক ডাটা ক্যাপচার করবে
    $pattern = '/' . $escapedLabel . '[\s\|:\r\n]+(?:\|\s*)*([^\r\n\|]+)/ui';
    if (preg_match($pattern, $text, $matches)) {
        return trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    return "";
}

// ডাইনামিক ফিল্ড রিডিং
$nameBangla = extractPdfField('Name\(Bangla\)', $text);
$nameEnglish = extractPdfField('Name\(English\)', $text);
$fatherName = extractPdfField('Father Name', $text);
$motherName = extractPdfField('Mother Name', $text);
$birthPlace = extractPdfField('Birth Place', $text);
$bloodGroup = extractPdfField('Blood Group', $text);
$gender = extractPdfField('Gender', $text);
$religion = extractPdfField('Religion', $text);

$nationalId = extractPdfField('National ID', $text);
$nationalId = str_replace(' ', '', $nationalId);

$pin = extractPdfField('Pin', $text);
$pin = str_replace(' ', '', $pin);

$dateOfBirth = extractPdfField('Date of Birth', $text);

// ঠিকানার অংশগুলো নিখুঁতভাবে সংগ্রহ করা
$holding = extractPdfField('Home\/Holding No', $text);
$village = extractPdfField('Additional Village\/Road', $text);
if(!$village) $village = extractPdfField('Village\/Road', $text);
$postOffice = extractPdfField('Post Office', $text);
$postalCode = extractPdfField('Postal Code', $text);
$upozila = extractPdfField('Upozila', $text);
$district = extractPdfField('District', $text);

$addressParts = [];
if($holding) $addressParts[] = "বাসা/হোল্ডিং: " . $holding;
if($village) $addressParts[] = "গ্রাম/রাস্তা: " . $village;
if($postOffice) $addressParts[] = "ডাকঘর: " . $postOffice;
if($postalCode) $addressParts[] = "পোস্ট কোড: " . $postalCode;
if($upozila) $addressParts[] = "উপজেলা: " . $upozila;
if($district) $addressParts[] = "জেলা: " . $district;

$address = implode(', ', $addressParts);

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
        "gender" => $gender ?: "male",
        "religion" => $religion ?: "Islam",
        "birthPlace" => $birthPlace,
        "bloodGroup" => $bloodGroup,
        "userIMG" => $userIMG,
        "signIMG" => $signIMG,
        "address" => $address
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// টেম্পোরারি ফাইল ক্লিনআপ
array_map('unlink', glob("$uploadDir/*.*"));
rmdir($uploadDir);
?>
