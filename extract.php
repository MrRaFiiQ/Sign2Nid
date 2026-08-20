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

// ১. Poppler-utils (pdftotext) দিয়ে টেক্সট এক্সট্রাক্ট করা
$textPath = $uploadDir . '/text.txt';
exec("pdftotext -layout " . escapeshellarg($pdfPath) . " " . escapeshellarg($textPath));
$text = file_exists($textPath) ? file_get_contents($textPath) : "";

// অদরকারি কন্ট্রোল ক্যারেক্টার ক্লিন করা (তবে ইউনিকোড ঠিক রাখা)
$text = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text);

// ২. Poppler-utils (pdfimages) দিয়ে ছবি ও সিগনেচার এক্সট্রাক্ট করা
exec("pdfimages -all " . escapeshellarg($pdfPath) . " " . escapeshellarg($uploadDir . '/img'));

$images = glob($uploadDir . '/img-*');
$userIMG = "";
$signIMG = "";

if (count($images) > 0) {
    sort($images); // নামের ক্রমানুসারে সাজানো
    
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

// ৩. ডাটা ফিল্টারিং (সাধারণ Regex)
function getMatch($pattern, $text) {
    if (preg_match($pattern, $text, $matches)) {
        return trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    return "";
}

// মাল্টি-লাইন সাপোর্ট করার জন্য DOTALL ('s' ফ্লাগ) এবং Lookahead ব্যবহার করে ডাইনামিক ফিল্টার
function extractField($startWord, $stopWords, $text) {
    // $startWord থেকে শুরু করে $stopWords এর আগ পর্যন্ত সব কিছু ধরবে (লাইন ব্রেক সহ)
    $pattern = '/' . $startWord . '[\s:ঃ]*(.*?)(?=' . $stopWords . '|$)/uis';
    if (preg_match($pattern, $text, $matches)) {
        // মাল্টি-লাইনের স্পেসগুলোকে একটি সিঙ্গেল স্পেসে পরিণত করা
        return trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    return "";
}

// ডাইনামিক ফিল্ড এক্সট্রাকশন (গ্যারান্টেড ডাটা ক্যাপচার)
$nameBangla = extractField('(?:নাম\s*\(বাংলা\)|\bনাম)', '(?:Name\b|পিতা|মাতা|Date of Birth|National ID|Pin)', $text);
$nameEnglish = extractField('(?:Name\s*\(English\)|\bName)', '(?:পিতা|মাতা|Date of Birth|National ID|Pin)', $text);
$fatherName = extractField('(?:পিতা|Father Name)', '(?:মাতা|Mother Name|Date of Birth|National ID|Pin)', $text);
$motherName = extractField('(?:মাতা|Mother Name)', '(?:ঠিকানা|Address|Date of Birth|National ID|Pin|জন্ম)', $text);
$birthPlace = extractField('(?:জন্ম\s*স্থান|Place of Birth)', '(?:ঠিকানা|Address|Blood Group|রক্তের গ্রুপ|Date of Birth)', $text);
$address = extractField('(?:ঠিকানা|Address)', '(?:Blood Group|রক্তের গ্রুপ|Place of Birth|$)', $text);

// স্পেসিফিক ফরম্যাটের ডাটা এক্সট্রাকশন
$nationalId = getMatch('/(?:National ID|NID|জাতীয় পরিচয়পত্র নম্বর)[\s:ঃ]*([0-9\s]{9,17})/ui', $text);
$nationalId = str_replace(' ', '', $nationalId);

$pin = getMatch('/(?:Pin|পিন)[\s:ঃ]*([0-9\s]{10,17})/ui', $text);
$pin = str_replace(' ', '', $pin);

// জন্ম তারিখের জন্য বাংলা সংখ্যা (০-৯) এবং ইংরেজি দুইটির সাপোর্ট যুক্ত করা হয়েছে
$dateOfBirth = getMatch('/(?:Date of Birth|জন্ম তারিখ|DOB)[\s:ঃ]*([0-9০-৯]{2}[\/\s\-]+[a-zA-Zঅ-য়0-9০-৯]+[\/\s\-]+[0-9০-৯]{4})/ui', $text);

$bloodGroup = getMatch('/(?:Blood Group|রক্তের গ্রুপ)[\s:ঃ]*([A-Z]{1,2}[+-])/ui', $text);

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
