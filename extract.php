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

// ১. Poppler-utils (pdftotext) দিয়ে টেক্সট এক্সট্রাক্ট করা (লেআউট ঠিক রেখে)
$textPath = $uploadDir . '/text.txt';
exec("pdftotext -layout " . escapeshellarg($pdfPath) . " " . escapeshellarg($textPath));
$text = file_exists($textPath) ? file_get_contents($textPath) : "";

// ২. Poppler-utils (pdfimages) দিয়ে ছবি ও সিগনেচার এক্সট্রাক্ট করা (-all ব্যবহার করে সরাসরি png/jpg পাওয়া যাবে)
exec("pdfimages -all " . escapeshellarg($pdfPath) . " " . escapeshellarg($uploadDir . '/img'));

$images = glob($uploadDir . '/img-*');
$userIMG = "";
$signIMG = "";

if (count($images) > 0) {
    sort($images); // নামের ক্রমানুসারে সাজানো (প্রথমে ইউজারের ছবি, পরে সিগনেচার)
    
    if (isset($images[0])) {
        $type = pathinfo($images[0], PATHINFO_EXTENSION);
        $data = file_get_contents($images[0]);
        $userIMG = 'data:image/' . $type . ';base64,' . base64_encode($data);
    }
    if (isset($images[1])) {
        $type = pathinfo($images[1], PATHINFO_EXTENSION);
        $data = file_get_contents($images[1]);
        $signIMG = 'data:image/' . $type . ';base64,' . base64_encode($data);
    }
}

// ৩. Regex দিয়ে বাংলা ও ইংরেজি ডাটা ফিল্টার করা (u মডিফায়ার ব্যবহার করা হয়েছে ইউনিকোডের জন্য)
function getMatch($pattern, $text) {
    if (preg_match($pattern, $text, $matches)) {
        return trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    return "";
}

$nameBangla = getMatch('/(?:নাম\s*\(বাংলা\)|\bনাম)\s*[:ঃ]?\s*(.*?)(?=\s*(?:Name|পিতা|মাতা|Date of Birth|National ID|Pin|$))/ui', $text);
$nameEnglish = getMatch('/(?:Name\s*\(English\)|\bName)\s*[:ঃ]?\s*([A-Za-z\s.\-]+?)(?=\s*(?:Date of Birth|National ID|Pin|Blood Group|পিতা|মাতা|$))/i', $text);

$nationalId = getMatch('/(?:National ID|NID|জাতীয় পরিচয়পত্র নম্বর)\s*[:ঃ]?\s*([0-9\s]{9,17})/ui', $text);
$nationalId = str_replace(' ', '', $nationalId);

$pin = getMatch('/(?:Pin|পিন)\s*[:ঃ]?\s*([0-9\s]{10,17})/ui', $text);
$pin = str_replace(' ', '', $pin);

$dateOfBirth = getMatch('/(?:Date of Birth|জন্ম তারিখ)\s*[:ঃ]?\s*([0-9]{2}[\/\s\-]+[A-Za-z0-9]+[\/\s\-]+[0-9]{4})/ui', $text);
$fatherName = getMatch('/(?:পিতা|Father Name)\s*[:ঃ]?\s*(.*?)(?=\s*(?:মাতা|Mother Name|Date of Birth|National ID|Pin|$))/ui', $text);
$motherName = getMatch('/(?:মাতা|Mother Name)\s*[:ঃ]?\s*(.*?)(?=\s*(?:ঠিকানা|Address|Date of Birth|National ID|Pin|$))/ui', $text);
$birthPlace = getMatch('/(?:জন্ম\s*স্থান|Place of Birth)\s*[:ঃ]?\s*(.*?)(?=\s*(?:ঠিকানা|Address|Blood Group|Date of Birth|$))/ui', $text);
$bloodGroup = getMatch('/(?:Blood Group|রক্তের গ্রুপ)\s*[:ঃ]?\s*([A-Z]{1,2}[+-])/ui', $text);
$address = getMatch('/(?:ঠিকানা|Address)\s*[:ঃ]?\s*(.*?)(?=\s*(?:Blood Group|Date of Birth|National ID|$))/ui', $text);

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
        "gender" => "male",
        "religion" => "Islam",
        "birthPlace" => $birthPlace,
        "bloodGroup" => $bloodGroup ?: "B+",
        "userIMG" => $userIMG,
        "signIMG" => $signIMG,
        "address" => $address
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// প্রসেস শেষে টেম্পোরারি ফাইলগুলো মুছে ফেলা (স্টোরেজ বাঁচানোর জন্য)
array_map('unlink', glob("$uploadDir/*.*"));
rmdir($uploadDir);
?>
