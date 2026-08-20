<?php

// ============================================================
// NID PDF EXTRACTION API
// Render + Docker + PHP 8.x
// ============================================================

error_reporting(0);
ini_set('display_errors', '0');

ob_start();

header('Content-Type: application/json; charset=utf-8');


// ============================================================
// METHOD CHECK
// ============================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    ob_clean();

    echo json_encode([
        'code' => 405,
        'success' => false,
        'message' => 'Method Not Allowed'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}


// ============================================================
// FILE FIELD
// ============================================================

$fileKey = isset($_FILES['nid_pdf'])
    ? 'nid_pdf'
    : 'pdf';


if (
    !isset($_FILES[$fileKey]) ||
    $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK
) {

    ob_clean();

    echo json_encode([
        'code' => 400,
        'success' => false,
        'message' => 'No file uploaded or upload error occurred.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}


// ============================================================
// TEMP DIRECTORY
// ============================================================

$uploadDir =
    sys_get_temp_dir() .
    '/nid_extract_' .
    bin2hex(random_bytes(8));

@mkdir($uploadDir, 0755, true);


$pdfPath =
    $uploadDir .
    '/uploaded.pdf';


if (
    !move_uploaded_file(
        $_FILES[$fileKey]['tmp_name'],
        $pdfPath
    )
) {

    ob_clean();

    echo json_encode([
        'code' => 500,
        'success' => false,
        'message' => 'Failed to move uploaded PDF.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}


// ============================================================
// OUTPUT IMAGE DIRECTORY
// ============================================================

$imageDir =
    __DIR__ .
    '/images/';


if (!is_dir($imageDir)) {
    @mkdir($imageDir, 0755, true);
}


// ============================================================
// BASE URL
// ============================================================

function getBaseUrl()
{
    $https =
        (
            isset($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        );

    // Render / reverse proxy
    if (
        isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
        strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
    ) {
        $https = true;
    }

    $protocol =
        $https
            ? 'https'
            : 'http';

    $host =
        $_SERVER['HTTP_HOST'] ??
        'localhost';

    return
        $protocol .
        '://' .
        $host;
}


// ============================================================
// TEXT EXTRACTION
// ============================================================

function extractPdfText($pdfPath)
{
    $textFile =
        dirname($pdfPath) .
        '/text.txt';

    $command =
        'pdftotext -layout ' .
        escapeshellarg($pdfPath) .
        ' ' .
        escapeshellarg($textFile) .
        ' 2>/dev/null';

    if (function_exists('exec')) {
        @exec($command);
    }

    if (
        file_exists($textFile) &&
        filesize($textFile) > 0
    ) {

        $text =
            @file_get_contents(
                $textFile
            );

        if ($text !== false) {
            return normalizePdfText($text);
        }
    }

    // ========================================================
    // FALLBACK: SMALOT PDF PARSER
    // ========================================================

    if (
        file_exists(
            __DIR__ .
            '/vendor/autoload.php'
        )
    ) {

        try {

            require_once
                __DIR__ .
                '/vendor/autoload.php';

            $parser =
                new Smalot\PdfParser\Parser();

            $pdf =
                $parser->parseFile(
                    $pdfPath
                );

            return normalizePdfText(
                $pdf->getText()
            );

        } catch (Throwable $e) {
            // ignore
        }
    }

    return '';
}


// ============================================================
// NORMALIZE PDF TEXT
// ============================================================

function normalizePdfText($text)
{
    if (!$text) {
        return '';
    }

    $text =
        str_replace(
            "\r",
            '',
            $text
        );

    // Remove form-feed
    $text =
        str_replace(
            "\f",
            "\n",
            $text
        );

    return $text;
}


// ============================================================
// CLEAN SIMPLE FIELD
// ============================================================

function cleanField($value)
{
    if (!$value) {
        return '';
    }

    $value =
        str_replace(
            [
                "\r",
                "\n",
                "\t",
                '|'
            ],
            ' ',
            $value
        );

    $value =
        preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

    return trim($value);
}


// ============================================================
// EXTRACT FIELD BETWEEN LABELS
// IMPORTANT:
// NO \b AFTER LABEL
// Because Name(English) ends with )
// ============================================================

function extractBetweenLabels(
    $text,
    $start,
    $ends = []
) {

    if (!$text) {
        return '';
    }

    $endPattern = '';

    if (!empty($ends)) {

        $escaped = [];

        foreach ($ends as $end) {

            $escaped[] =
                preg_quote(
                    $end,
                    '/'
                );
        }

        $endPattern =
            '(?=\s*(?:' .
            implode('|', $escaped) .
            '))';
    }

    $pattern =
        '/' .
        preg_quote(
            $start,
            '/'
        ) .
        '\s*(?:[:|])?\s*' .
        '(.*?)' .
        (
            $endPattern
            ?: '(?=$)'
        ) .
        '/isu';

    if (
        preg_match(
            $pattern,
            $text,
            $m
        )
    ) {

        return cleanField(
            $m[1]
        );
    }

    return '';
}


// ============================================================
// NAME BANGLA
// ============================================================

function extractBanglaName($text)
{
    $value =
        extractBetweenLabels(
            $text,
            'Name(Bangla)',
            [
                'Name(English)'
            ]
        );

    if (!$value) {

        $value =
            extractBetweenLabels(
                $text,
                'Name (Bangla)',
                [
                    'Name(English)',
                    'Name (English)'
                ]
            );
    }

    // Remove accidental Tag
    $value =
        preg_replace(
            '/\bTag\b/i',
            '',
            $value
        );

    return cleanField($value);
}


// ============================================================
// NAME ENGLISH
// ============================================================

function extractEnglishName($text)
{
    $value =
        extractBetweenLabels(
            $text,
            'Name(English)',
            [
                'Date of Birth'
            ]
        );

    if (!$value) {

        $value =
            extractBetweenLabels(
                $text,
                'Name (English)',
                [
                    'Date of Birth'
                ]
            );
    }

    return strtoupper(
        cleanField($value)
    );
}


// ============================================================
// NATIONAL ID
// ============================================================

function extractNationalId($text)
{
    if (
        preg_match(
            '/National\s*ID\s+([0-9]{10,17})/iu',
            $text,
            $m
        )
    ) {
        return $m[1];
    }

    return '';
}


// ============================================================
// PIN
// ============================================================

function extractPin($text)
{
    if (
        preg_match(
            '/Pin\s+([0-9]{10,20})/iu',
            $text,
            $m
        )
    ) {
        return $m[1];
    }

    return '';
}


// ============================================================
// DATE OF BIRTH
// ============================================================

function extractDob($text)
{
    $raw =
        extractBetweenLabels(
            $text,
            'Date of Birth',
            [
                'Birth Place'
            ]
        );

    $raw =
        cleanField($raw);

    if (
        preg_match(
            '/(\d{4})-(\d{2})-(\d{2})/',
            $raw,
            $m
        )
    ) {

        return date(
            'd M Y',
            strtotime(
                $m[1] .
                '-' .
                $m[2] .
                '-' .
                $m[3]
            )
        );
    }

    return $raw;
}


// ============================================================
// BASIC FIELD HELPER
// ============================================================

function getField(
    $text,
    $start,
    $ends
) {

    return cleanField(
        extractBetweenLabels(
            $text,
            $start,
            $ends
        )
    );
}


// ============================================================
// POSTAL CODE
// ============================================================

function extractPostalCode($text)
{
    if (
        preg_match(
            '/Postal\s*Code\s+([0-9০-৯]{4})/u',
            $text,
            $m
        )
    ) {

        return convertToEnglishDigits(
            $m[1]
        );
    }

    return '';
}


// ============================================================
// ENGLISH DIGITS
// ============================================================

function convertToEnglishDigits($number)
{
    return strtr(
        $number,
        [
            '০' => '0',
            '১' => '1',
            '২' => '2',
            '৩' => '3',
            '৪' => '4',
            '৫' => '5',
            '৬' => '6',
            '৭' => '7',
            '৮' => '8',
            '৯' => '9'
        ]
    );
}


// ============================================================
// BANGLA DIGITS
// ============================================================

function convertToBangla($number)
{
    return strtr(
        $number,
        [
            '0' => '০',
            '1' => '১',
            '2' => '২',
            '3' => '৩',
            '4' => '৪',
            '5' => '৫',
            '6' => '৬',
            '7' => '৭',
            '8' => '৮',
            '9' => '৯'
        ]
    );
}


// ============================================================
// ADDRESS VALUE FROM SAME LINE
// ============================================================

function extractAddressValue(
    $text,
    $label,
    $nextLabels = []
) {

    $endPattern = '';

    if (!empty($nextLabels)) {

        $escaped = [];

        foreach ($nextLabels as $next) {

            $escaped[] =
                preg_quote(
                    $next,
                    '/'
                );
        }

        $endPattern =
            '(?=\s{2,}(?:' .
            implode('|', $escaped) .
            ')\b|\s*(?:' .
            implode('|', $escaped) .
            ')\s|$)';
    } else {

        $endPattern = '$';
    }

    $pattern =
        '/' .
        preg_quote(
            $label,
            '/'
        ) .
        '\s+' .
        '(.*?)' .
        $endPattern .
        '/imu';

    if (
        preg_match(
            $pattern,
            $text,
            $m
        )
    ) {

        return cleanField(
            $m[1]
        );
    }

    return '';
}


// ============================================================
// ADDRESS
// ============================================================

function combineAddress($text)
{
    // ========================================================
    // PRESENT ADDRESS BLOCK
    // ========================================================

    $presentText =
        $text;

    if (
        preg_match(
            '/Present Address(.*?)(?:Permanent Address|Education|Identification|$)/isu',
            $text,
            $m
        )
    ) {

        $presentText =
            $m[1];
    }


    // ========================================================
    // DISTRICT
    // ========================================================

    $district =
        extractAddressValue(
            $presentText,
            'District',
            [
                'RMO',
                'City'
            ]
        );


    // ========================================================
    // UPOZILA
    // ========================================================

    $upozila =
        extractAddressValue(
            $presentText,
            'Upozila',
            [
                'Union/Ward',
                'Mouza/Moholla'
            ]
        );


    // ========================================================
    // VILLAGE / ROAD
    // ========================================================

    $village =
        extractAddressValue(
            $presentText,
            'Village/Road',
            [
                'Additional',
                'Home/Holding',
                'Post Office'
            ]
        );


    // ========================================================
    // HOME / HOLDING
    // ========================================================

    $home =
        extractAddressValue(
            $presentText,
            'Home/Holding',
            [
                'Post Office',
                'Postal Code'
            ]
        );


    // ========================================================
    // POST OFFICE
    // ========================================================

    $postOffice =
        extractAddressValue(
            $presentText,
            'Post Office',
            [
                'Postal Code',
                'Region'
            ]
        );


    // ========================================================
    // POSTAL CODE
    // ========================================================

    $postalCode =
        extractPostalCode(
            $presentText
        );


    // ========================================================
    // FALLBACK FROM FULL TEXT
    // ========================================================

    if (!$district) {

        $district =
            extractAddressValue(
                $text,
                'District',
                [
                    'RMO',
                    'City'
                ]
            );
    }


    if (!$upozila) {

        $upozila =
            extractAddressValue(
                $text,
                'Upozila',
                [
                    'Union/Ward',
                    'Mouza/Moholla'
                ]
            );
    }


    if (!$village) {

        $village =
            extractAddressValue(
                $text,
                'Village/Road',
                [
                    'Additional',
                    'Home/Holding',
                    'Post Office'
                ]
            );
    }


    if (!$home) {

        $home =
            extractAddressValue(
                $text,
                'Home/Holding',
                [
                    'Post Office',
                    'Postal Code'
                ]
            );
    }


    if (!$postOffice) {

        $postOffice =
            extractAddressValue(
                $text,
                'Post Office',
                [
                    'Postal Code',
                    'Region'
                ]
            );
    }


    if (!$postalCode) {

        $postalCode =
            extractPostalCode(
                $text
            );
    }


    // ========================================================
    // REMOVE UNWANTED WORDS
    // ========================================================

    $village =
        preg_replace(
            '/\b(?:Additional|Village\/Road|No|Union|Porishod)\b/iu',
            '',
            $village
        );

    $home =
        preg_replace(
            '/\b(?:Additional|Village\/Road|No)\b/iu',
            '',
            $home
        );

    $postOffice =
        preg_replace(
            '/\b(?:Post Office|Postal Code)\b/iu',
            '',
            $postOffice
        );


    $village =
        cleanField($village);

    $home =
        cleanField($home);

    $postOffice =
        cleanField($postOffice);

    $district =
        cleanField($district);

    $upozila =
        cleanField($upozila);


    // ========================================================
    // BUILD ADDRESS
    // ========================================================

    $parts = [];


    if ($home !== '') {

        $parts[] =
            'বাসা/হোল্ডিং: ' .
            $home;
    }


    if ($village !== '') {

        $parts[] =
            'গ্রাম/রাস্তা: ' .
            $village;
    }


    if ($postOffice !== '') {

        $po =
            'ডাকঘর: ' .
            $postOffice;

        if ($postalCode !== '') {

            $po .=
                ' -' .
                convertToBangla(
                    $postalCode
                );
        }

        $parts[] =
            $po;
    }


    if ($upozila !== '') {
        $parts[] = $upozila;
    }


    if ($district !== '') {
        $parts[] = $district;
    }


    return implode(
        ', ',
        $parts
    );
}


// ============================================================
// IMAGE EXTRACTION
// ============================================================

function extractImages(
    $pdfPath,
    $imageDir
) {

    $unique =
        bin2hex(
            random_bytes(8)
        );


    $rawPrefix =
        sys_get_temp_dir() .
        '/pdfimg_' .
        $unique .
        '/img';


    $rawDir =
        dirname(
            $rawPrefix
        );


    @mkdir(
        $rawDir,
        0755,
        true
    );


    // ========================================================
    // PDFIMAGES
    // ========================================================

    $command =
        'pdfimages -all ' .
        escapeshellarg($pdfPath) .
        ' ' .
        escapeshellarg($rawPrefix) .
        ' 2>/dev/null';


    if (function_exists('exec')) {
        @exec($command);
    }


    $files =
        glob(
            $rawDir .
            '/img-*'
        );


    if (!$files) {

        removeDirectory(
            $rawDir
        );

        return [
            '',
            ''
        ];
    }


    $candidates = [];


    foreach ($files as $file) {

        if (!is_file($file)) {
            continue;
        }


        $size =
            @getimagesize(
                $file
            );


        if (!$size) {
            continue;
        }


        $width =
            (int)$size[0];

        $height =
            (int)$size[1];


        $fileSize =
            @filesize(
                $file
            );


        if (
            $width < 20 ||
            $height < 10 ||
            $fileSize < 1000
        ) {
            continue;
        }


        $area =
            $width *
            $height;


        $ratio =
            $width /
            max(
                1,
                $height
            );


        $candidates[] = [
            'file' => $file,
            'width' => $width,
            'height' => $height,
            'area' => $area,
            'ratio' => $ratio,
            'size' => $fileSize,
            'mime' => $size['mime'] ?? ''
        ];
    }


    // ========================================================
    // SORT BY AREA
    // ========================================================

    usort(
        $candidates,
        function ($a, $b) {

            return
                $b['area'] <=>
                $a['area'];
        }
    );


    $photo =
        null;

    $signature =
        null;


    // ========================================================
    // FIND PHOTO
    // ========================================================

    foreach (
        $candidates as $candidate
    ) {

        $ratio =
            $candidate['ratio'];

        $width =
            $candidate['width'];

        $height =
            $candidate['height'];


        /*
         * NID photo:
         * approximately portrait
         */

        if (
            $height > $width &&
            $width >= 150 &&
            $height >= 200 &&
            $ratio >= 0.45 &&
            $ratio <= 0.95
        ) {

            $photo =
                $candidate;

            break;
        }
    }


    // ========================================================
    // FIND SIGNATURE
    // ========================================================

    foreach (
        $candidates as $candidate
    ) {

        $ratio =
            $candidate['ratio'];

        $width =
            $candidate['width'];

        $height =
            $candidate['height'];


        /*
         * Signature:
         * wide rectangular image
         */

        if (
            $width >= 150 &&
            $height >= 30 &&
            $ratio >= 2.2
        ) {

            if (
                !$signature ||
                $candidate['size'] >
                $signature['size']
            ) {

                $signature =
                    $candidate;
            }
        }
    }


    $userName =
        '';

    $signName =
        '';


    // ========================================================
    // SAVE PHOTO
    // ========================================================

    if ($photo) {

        $userName =
            'user_' .
            $unique .
            '.jpg';

        $destination =
            $imageDir .
            $userName;


        if (
            !copy(
                $photo['file'],
                $destination
            )
        ) {

            $userName =
                '';
        }
    }


    // ========================================================
    // SAVE SIGNATURE
    // ========================================================

    if ($signature) {

        $signName =
            'sign_' .
            $unique .
            '.png';

        $destination =
            $imageDir .
            $signName;


        if (
            !normalizeSignature(
                $signature['file'],
                $destination
            )
        ) {

            // If normalization fails,
            // copy original PNG/JPEG

            if (
                !copy(
                    $signature['file'],
                    $destination
                )
            ) {

                $signName =
                    '';
            }
        }
    }


    // ========================================================
    // CLEAN TEMP
    // ========================================================

    removeDirectory(
        $rawDir
    );


    return [
        $userName,
        $signName
    ];
}


// ============================================================
// SIGNATURE NORMALIZE
// ============================================================

function normalizeSignature(
    $source,
    $destination
) {

    $data =
        @file_get_contents(
            $source
        );

    if ($data === false) {
        return false;
    }


    $src =
        @imagecreatefromstring(
            $data
        );


    if (!$src) {
        return false;
    }


    $w =
        imagesx($src);

    $h =
        imagesy($src);


    if (
        $w < 20 ||
        $h < 10
    ) {

        imagedestroy($src);

        return false;
    }


    // ========================================================
    // WHITE CANVAS
    // ========================================================

    $canvas =
        imagecreatetruecolor(
            $w,
            $h
        );


    $white =
        imagecolorallocate(
            $canvas,
            255,
            255,
            255
        );


    imagefill(
        $canvas,
        0,
        0,
        $white
    );


    /*
     * Signature PDF image already contains the signature.
     * Preserve it rather than aggressive thresholding.
     */

    imagecopy(
        $canvas,
        $src,
        0,
        0,
        0,
        0,
        $w,
        $h
    );


    imagepng(
        $canvas,
        $destination,
        6
    );


    imagedestroy($src);
    imagedestroy($canvas);


    return file_exists(
        $destination
    );
}


// ============================================================
// REMOVE DIRECTORY
// ============================================================

function removeDirectory($dir)
{
    if (
        !is_dir($dir)
    ) {
        return;
    }


    $files =
        glob(
            $dir .
            '/*'
        );


    if ($files) {

        foreach ($files as $file) {

            if (
                is_dir($file)
            ) {

                removeDirectory(
                    $file
                );

            } else {

                @unlink($file);
            }
        }
    }


    @rmdir($dir);
}


// ============================================================
// MAIN PROCESS
// ============================================================

try {

    // ========================================================
    // TEXT
    // ========================================================

    $text =
        extractPdfText(
            $pdfPath
        );


    if (!$text) {

        throw new Exception(
            'Unable to extract PDF text.'
        );
    }


    // ========================================================
    // BASIC DATA
    // ========================================================

    $nameBangla =
        extractBanglaName(
            $text
        );


    $nameEnglish =
        extractEnglishName(
            $text
        );


    $nationalId =
        extractNationalId(
            $text
        );


    $pin =
        extractPin(
            $text
        );


    $dateOfBirth =
        extractDob(
            $text
        );


    $fatherName =
        getField(
            $text,
            'Father Name',
            [
                'Mother Name'
            ]
        );


    $motherName =
        getField(
            $text,
            'Mother Name',
            [
                'Spouse Name'
            ]
        );


    $gender =
        getField(
            $text,
            'Gender',
            [
                'Marital'
            ]
        );


    $religion =
        getField(
            $text,
            'Religion',
            [
                'Religion Other'
            ]
        );


    $birthPlace =
        getField(
            $text,
            'Birth Place',
            [
                'Birth Other'
            ]
        );


    $bloodGroup =
        getField(
            $text,
            'Blood Group',
            [
                'TIN'
            ]
        );


    $bloodGroup =
        strtoupper(
            trim(
                $bloodGroup
            )
        );


    // ========================================================
    // ADDRESS
    // ========================================================

    $address =
        combineAddress(
            $text
        );


    // ========================================================
    // IMAGES
    // ========================================================

    [
        $userFile,
        $signFile
    ] =
        extractImages(
            $pdfPath,
            $imageDir
        );


    $baseUrl =
        getBaseUrl();


    $userIMG =
        $userFile
            ? $baseUrl .
              '/images/' .
              $userFile
            : '';


    $signIMG =
        $signFile
            ? $baseUrl .
              '/images/' .
              $signFile
            : '';


    // ========================================================
    // TODAY
    // ========================================================

    date_default_timezone_set(
        'Asia/Dhaka'
    );


    $dateOfToday =
        convertToBangla(
            date('d-m-Y')
        );


    // ========================================================
    // FINAL RESPONSE
    // ========================================================

    $response = [

        'code' =>
            200,

        'success' =>
            true,

        'message' =>
            'Data fetched successfully',

        'data' => [

            'nameBangla' =>
                $nameBangla,

            'nameEnglish' =>
                $nameEnglish,

            'nationalId' =>
                $nationalId,

            'pin' =>
                $pin,

            'dateOfBirth' =>
                $dateOfBirth,

            'dateOfToday' =>
                $dateOfToday,

            'fatherName' =>
                $fatherName,

            'motherName' =>
                $motherName,

            'gender' =>
                $gender,

            'religion' =>
                $religion,

            'birthPlace' =>
                $birthPlace,

            'bloodGroup' =>
                $bloodGroup,

            'userIMG' =>
                $userIMG,

            'signIMG' =>
                $signIMG,

            'address' =>
                $address
        ]
    ];


    ob_clean();


    echo json_encode(
        $response,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );


} catch (Throwable $e) {

    ob_clean();


    http_response_code(500);


    echo json_encode(
        [
            'code' =>
                500,

            'success' =>
                false,

            'message' =>
                'Error processing the PDF: ' .
                $e->getMessage()
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
}


// ============================================================
// CLEAN TEMP PDF
// ============================================================

if (
    isset($pdfPath) &&
    file_exists($pdfPath)
) {

    @unlink($pdfPath);
}


if (
    isset($uploadDir) &&
    is_dir($uploadDir)
) {

    removeDirectory(
        $uploadDir
    );
}


ob_end_flush();

?>
