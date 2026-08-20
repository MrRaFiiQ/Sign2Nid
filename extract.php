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

// লেআউট ছাড়া সাধারণ টেক্সট এক্সট্রাক্ট করে দেখা
$textPath = $uploadDir . '/text.txt';
exec("pdftotext " . escapeshellarg($pdfPath) . " " . escapeshellarg($textPath));
$rawText = file_exists($textPath) ? file_get_contents($textPath) : "";

// ছবি এক্সট্রাকশন
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

// রেজেক্স বাদ দিয়ে সরাসরি র টেক্সট এবং ছবি চেক করার জন্য আউটপুট পাঠানো হচ্ছে
$response = [
    "code" => 200,
    "success" => true,
    "message" => "Debug Mode",
    "raw_pdf_text" => $rawText, // এটি দেখলে বোঝা যাবে পিডিএফের ভেতরের লেখাগুলো কীভাবে সাজানো আছে
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
