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
$lines = file_exists($textPath) ? file($textPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

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

// ৩. লাইন বাই লাইন পার্স করে নির্ভুলভাবে ডাটা খোঁজার ফাংশন
function findValueByLabel($searchLabel, $lines) {
    for ($i = 0; $i < count($lines); $i++) {
        // লেবেল মিলে গেলে
        if (mb_stripos($lines[$i], $searchLabel) !== false) {
            $currentLine = trim($lines[$i]);
            // একই লাইনে পাইপ থাকলে তার পরের অংশ চেক করি
            if (strpos($currentLine, '|') !== false) {
                $parts = explode('|', $currentLine);
                if (isset($parts[1]) && trim($parts[1]) !== '' && mb_stripos(trim($parts[1]), $searchLabel) === false) {
                    return trim($parts[1]);
                }
            }
            // অথবা পরবর্তী ১ থেকে ৩ লাইনের মধ্যে মানটি থাকতে পারে
            for ($j = $i + 1; $j <= $i + 3 && $j < count($lines); $j++) {
                $nextLine = trim($lines[$j]);
                if ($nextLine !== '' && $nextLine !== '|') {
                    if (strpos($nextLine, '|') !== false) {
                        $valParts = explode('|', $nextLine);
                        if (isset($valParts[1]) && trim($valParts[1]) !== '') {
                            return trim($valParts[1]);
                        }
                    } else {
                        return $nextLine;
                    }
                }
            }
        }
    }
    return "";
}

// ডাইনামিক ফিল্ড এক্সট্রাকশন
$nameBangla = findValueByLabel('Name(Bangla)', $lines);
if(!$nameBangla) $nameBangla = findValueByLabel('Name (Bangla)', $lines);

$nameEnglish = findValueByLabel('Name(English)', $lines);
if(!$nameEnglish) $nameEnglish = findValueByLabel('Name (English)', $lines);

$fatherName = findValueByLabel('Father Name', $lines);
$motherName = findValueByLabel('Mother Name', $lines);
$birthPlace = findValueByLabel('Birth Place', $lines);
$bloodGroup = findValueByLabel('Blood Group', $lines);
$gender = findValueByLabel('Gender', $lines);
$religion = findValueByLabel('Religion', $lines);

$nationalId = findValueByLabel('National ID', $lines);
$nationalId = str_replace(' ', '', $nationalId);

$pin = findValueByLabel('Pin', $lines);
$pin = str_replace(' ', '', $pin);

$dateOfBirth = findValueByLabel('Date of Birth', $lines);

// ঠিকানার অংশগুলো সঠিকভাবে সংগ্রহ করা
$holding = findValueByLabel('Home/Holding No', $lines);
$village = findValueByLabel('Additional Village/Road', $lines);
if(!$village) $village = findValueByLabel('Village/Road', $lines);
$postOffice = findValueByLabel('Post Office', $lines);
$postalCode = findValueByLabel('Postal Code', $lines);
$upozila = findValueByLabel('Upozila', $lines);
$district = findValueByLabel('District', $lines);

$addressParts = [];
if($holding) $addressParts[] = "বাসা/হোল্ডিং: " . $holding;
if($village) $addressParts[] = "গ্রাম/রাস্তা: " . $village;
if($postOffice) $addressParts[] = "ডাকঘর: " . $postOffice;
if($postalCode) $addressParts[] = "পোস্ট কোড: " . $postalCode;
if($upozila) $addressParts[] = "উপজেলা: " . $upozila;
if($district && mb_stripos($district, 'RMO') === false) $addressParts[] = "জেলা: " . $district;

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
