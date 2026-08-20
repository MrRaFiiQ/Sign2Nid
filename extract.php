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

$uploadDir = sys_get_temp_dir() . '/nid_extract_' . uniqid();
mkdir($uploadDir);

$pdfPath = $uploadDir . '/uploaded.pdf';
move_uploaded_file($_FILES['nid_pdf']['tmp_name'], $pdfPath);

// ১. টেক্সট এক্সট্রাক্ট করা
$textPath = $uploadDir . '/text.txt';
exec("pdftotext -layout " . escapeshellarg($pdfPath) . " " . escapeshellarg($textPath));
$rawText = file_exists($textPath) ? file_get_contents($textPath) : "";

// ২. ছবি এক্সট্রাক্ট করা
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

// ৩. রেজেক্স ছাড়াই সরাসরি ডায়াগনস্টিক আউটপুট
$response = [
    "code" => 200,
    "success" => true,
    "message" => "Text Length: " . mb_strlen($rawText),
    "raw_text_sample" => mb_substr($rawText, 0, 400), // পিডিএফ থেকে পড়া প্রথম ৪০০ ক্যারেক্টার
    "data" => [
        "nameBangla" => "",
        "nameEnglish" => "",
        "nationalId" => "",
        "pin" => "",
        "dateOfBirth" => "",
        "dateOfToday" => date('d-m-Y'),
        "fatherName" => "",
        "motherName" => "",
        "gender" => "male",
        "religion" => "Islam",
        "birthPlace" => "",
        "bloodGroup" => "B+",
        "userIMG" => $userIMG,
        "signIMG" => $signIMG,
        "address" => ""
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

array_map('unlink', glob("$uploadDir/*.*"));
rmdir($uploadDir);
?>
