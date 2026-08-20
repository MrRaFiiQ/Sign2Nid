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

// সমস্ত নতুন লাইন এবং অতিরিক্ত স্পেসকে একটি সিঙ্গেল স্পেসে রূপান্তর করে টেক্সট ফ্লাট করা
$flatText = preg_replace('/\s+/', ' ', $text);

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

// ৩. নিরাপদ ও নির্ভুল ফিল্ড এক্সট্রাকশন ফাংশন
function extractFlatField($label, $text) {
    // এখানে সরাসরি পরিষ্কার লেবেল পাঠানো হবে, preg_quote নিজে থেকেই সব স্পেশাল চরিত্র সুরক্ষিত করবে
    $pattern = '/' . preg_quote($label, '/') . '\s*\|\s*([^\|]+)/ui';
    if (preg_match($pattern, $text, $matches)) {
        // অতিরিক্ত ট্রেইলিং লেবেল বা স্পেস কেটে ফেলা
        $val = preg_replace('/\s+[A-Za-z\(\)]+\s*$/u', '', trim($matches[1]));
        return trim($val);
    }
    return "";
}

// ডাইনামিক ফিল্ড এক্সট্রাকশন (কোনো অতিরিক্ত ব্যাকস্লাশ ছাড়া সঠিক লেবেল পাস করা হয়েছে)
$nameBangla = extractFlatField('Name(Bangla)', $flatText);
$nameEnglish = extractFlatField('Name(English)', $flatText);
$fatherName = extractFlatField('Father Name', $flatText);
$motherName = extractFlatField('Mother Name', $flatText);
$birthPlace = extractFlatField('Birth Place', $flatText);
$bloodGroup = extractFlatField('Blood Group', $flatText);
$gender = extractFlatField('Gender', $flatText);
$religion = extractFlatField('Religion', $flatText);

$nationalId = extractFlatField('National ID', $flatText);
$nationalId = str_replace(' ', '', $nationalId);

$pin = extractFlatField('Pin', $flatText);
$pin = str_replace(' ', '', $pin);

$dateOfBirth = extractFlatField('Date of Birth', $flatText);

// ঠিকানার অংশগুলো সঠিকভাবে সংগ্রহ করা
$holding = extractFlatField('Home/Holding No', $flatText);
$village = extractFlatField('Additional Village/Road', $flatText);
if(!$village) $village = extractFlatField('Village/Road', $flatText);
$postOffice = extractFlatField('Post Office', $flatText);
$postalCode = extractFlatField('Postal Code', $flatText);
$upozila = extractFlatField('Upozila', $flatText);
$district = extractFlatField('District', $flatText);

$addressParts = [];
if($holding) $addressParts[] = "বাসা/হোল্ডিং: " . $holding;
if($village) $addressParts[] = "গ্রাম/রাস্তা: " . $village;
if($postOffice) $addressParts[] = "ডাকঘর: " . $postOffice;
if($postalCode) $addressParts[] = "পোস্ট কোড: " . $postalCode;
if($upozila) $addressParts[] = "উপজেলা: " . $upozila;
if($district && $district !== 'RMO') $addressParts[] = "জেলা: " . $district;

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
