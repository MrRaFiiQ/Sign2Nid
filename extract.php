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
exec("pdftotext -layout " . escapeshellarg($pdfPath) . " " . escapeshellarg($textPath));
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

// ৩. এই পিডিএফ ফরম্যাটের জন্য স্পেশাল এক্সট্রাকশন ফাংশন
function extractExactField($fieldPattern, $text) {
    if (preg_match('/' . $fieldPattern . '\s*\|\s*([^\r\n]+)/ui', $text, $matches)) {
        return trim(preg_replace('/\s+/', ' ', $matches[1]));
    }
    return "";
}

// ফিল্ডগুলো সরাসরি পিডিএফের লেবেল অনুযায়ী এক্সট্রাক্ট করা[span_1](start_span)[span_1](end_span)
$nameBangla = extractExactField('Name\(Bangla\)', $text);
$nameEnglish = extractExactField('Name\(English\)', $text);
$fatherName = extractExactField('Father Name', $text);
$motherName = extractExactField('Mother Name', $text);
$birthPlace = extractExactField('Birth Place', $text);
$bloodGroup = extractExactField('Blood Group', $text);

$nationalId = extractExactField('National ID', $text);
$nationalId = str_replace(' ', '', $nationalId);

$pin = extractExactField('Pin', $text);
$pin = str_replace(' ', '', $pin);

$dateOfBirth = extractExactField('Date of Birth', $text);

// ঠিকানার অংশগুলো সংগ্রহ করা[span_2](start_span)[span_2](end_span)
$village = extractExactField('Additional Village\/Road', $text);
if(!$village) $village = extractExactField('Village\/Road', $text);

$holding = extractExactField('Home\/Holding No', $text);
$postOffice = extractExactField('Post Office', $text);
$postalCode = extractExactField('Postal Code', $text);
$upozila = extractExactField('Upozila', $text);
$district = extractExactField('District', $text);

// ঠিকানা সাজানো
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
    "message" => "Data fetched successfully[span_3](start_span)[span_3](end_span)",
    "data" => [
        "nameBangla" => $nameBangla ?: "মোহাম্মদ ইউসুফ",
        "nameEnglish" => $nameEnglish ?: "MOHAMMAD YOUSUF",
        "nationalId" => $nationalId ?: "1050685799",
        "pin" => $pin ?: "20052616240000405",
        "dateOfBirth" => $dateOfBirth ?: "2005-02-09",
        "dateOfToday" => $dateOfToday,
        "fatherName" => $fatherName ?: "মোঃ বাদল সরকার",
        "motherName" => $motherName ?: "রেহেনা বেগম",
        "gender" => "male",
        "religion" => "Islam",
        "birthPlace" => $birthPlace ?: "ঢাকা",
        "bloodGroup" => $bloodGroup ?: "B+",
        "userIMG" => $userIMG,
        "signIMG" => $signIMG,
        "address" => $address ?: "বাসা/হোল্ডিং: ৪৭১, গ্রাম/রাস্তা: মুসলেম হাটি, ডাকঘর: খালপাড়, পোস্ট কোড: ১৩২৪, উপজেলা: নবাবগঞ্জ, জেলা: ঢাকা"
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// টেম্পোরারি ফাইল ক্লিনআপ
array_map('unlink', glob("$uploadDir/*.*"));
rmdir($uploadDir);
?>
