<?php

// ============================================================
// NID PDF EXTRACTION API
// Render / Docker / Apache Compatible
// Final Improved Version
// ============================================================

error_reporting(0);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    return true;
});

ob_start();

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Dhaka');


// ============================================================
// METHOD CHECK
// ============================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    ob_clean();

    http_response_code(405);

    echo json_encode(
        [
            'code' => 405,
            'success' => false,
            'message' => 'Method Not Allowed'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// COMPOSER
// ============================================================

$autoload = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
}


// ============================================================
// DIRECTORY
// ============================================================

$imageDir = __DIR__ . '/images/';

if (!is_dir($imageDir)) {
    @mkdir($imageDir, 0777, true);
}


// ============================================================
// FILE FIELD
// ============================================================

$fileKey = null;

if (
    isset($_FILES['nid_pdf'])
) {
    $fileKey = 'nid_pdf';

} elseif (
    isset($_FILES['pdf'])
) {
    $fileKey = 'pdf';
}


if (
    !$fileKey ||
    !isset($_FILES[$fileKey]) ||
    $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK
) {

    ob_clean();

    http_response_code(400);

    echo json_encode(
        [
            'code' => 400,
            'success' => false,
            'message' => 'No file uploaded or upload error occurred.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// FILE SIZE CHECK
// ============================================================

$maxFileSize = 20 * 1024 * 1024;

if (
    isset($_FILES[$fileKey]['size']) &&
    $_FILES[$fileKey]['size'] > $maxFileSize
) {

    ob_clean();

    http_response_code(413);

    echo json_encode(
        [
            'code' => 413,
            'success' => false,
            'message' => 'PDF file is too large. Maximum size is 20MB.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// FILE INFO
// ============================================================

$originalName =
    basename(
        $_FILES[$fileKey]['name'] ?? 'document.pdf'
    );

$extension =
    strtolower(
        pathinfo(
            $originalName,
            PATHINFO_EXTENSION
        )
    );


if ($extension !== 'pdf') {

    ob_clean();

    http_response_code(400);

    echo json_encode(
        [
            'code' => 400,
            'success' => false,
            'message' => 'Invalid file type. Only PDF files are allowed.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// TEMP DIRECTORY
// ============================================================

$tempDir =
    sys_get_temp_dir() .
    '/nid_extract_' .
    bin2hex(random_bytes(8));

if (!@mkdir($tempDir, 0755, true)) {

    ob_clean();

    http_response_code(500);

    echo json_encode(
        [
            'code' => 500,
            'success' => false,
            'message' => 'Unable to create temporary directory.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


$pdfPath =
    $tempDir .
    '/uploaded.pdf';


// ============================================================
// MOVE UPLOAD
// ============================================================

if (
    !@move_uploaded_file(
        $_FILES[$fileKey]['tmp_name'],
        $pdfPath
    )
) {

    removeDirectoryRecursive($tempDir);

    ob_clean();

    http_response_code(500);

    echo json_encode(
        [
            'code' => 500,
            'success' => false,
            'message' => 'Failed to move uploaded PDF.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// PROCESS PDF
// ============================================================

try {

    $response =
        processPdf(
            $pdfPath,
            $imageDir
        );

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
            'code' => 500,
            'success' => false,
            'message' => 'Error processing the PDF.'
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

} finally {

    removeDirectoryRecursive($tempDir);
}

exit;


// ============================================================
// MAIN PROCESSOR
// ============================================================

function processPdf(
    $pdfPath,
    $imageDir
) {

    if (!file_exists($pdfPath)) {

        throw new Exception(
            'PDF file not found.'
        );
    }


    // ========================================================
    // TEXT
    // ========================================================

    $text =
        extractPdfText(
            $pdfPath
        );


    if (!$text) {

        throw new Exception(
            'Unable to extract text from PDF.'
        );
    }


    // ========================================================
    // BASIC DATA
    // ========================================================

    $nameBangla =
        extractNameBangla(
            $text
        );


    $nameEnglish =
        extractNameEnglish(
            $text
        );


    $nationalId =
        extractNid(
            $text
        );


    $pin =
        extractPin(
            $text
        );


    $dob =
        extractDateOfBirth(
            $text
        );


    $fatherName =
        findValueByLabel(
            'Father Name',
            $text
        );


    $motherName =
        findValueByLabel(
            'Mother Name',
            $text
        );


    $gender =
        findValueByLabel(
            'Gender',
            $text
        );


    $religion =
        findValueByLabel(
            'Religion',
            $text
        );


    $birthPlace =
        findValueByLabel(
            'Birth Place',
            $text
        );


    $bloodGroup =
        extractBloodGroup(
            $text
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

    $images =
        extractImagesFromPdf(
            $pdfPath,
            $imageDir
        );


    $userIMG =
        buildImageUrl(
            $images['user']
        );


    $signIMG =
        buildImageUrl(
            $images['signature']
        );


    // ========================================================
    // FINAL
    // ========================================================

    return [

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
                $dob,

            'dateOfToday' =>
                convertToBangla(
                    date('d-m-Y')
                ),

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
}


// ============================================================
// PDF TEXT EXTRACTION
// ============================================================

function extractPdfText($pdfPath)
{
    $text = '';


    // ========================================================
    // METHOD 1: PDFTOTEXT
    // ========================================================

    if (function_exists('exec')) {

        $textFile =
            tempnam(
                sys_get_temp_dir(),
                'pdftext_'
            );


        if ($textFile) {

            $command =
                'pdftotext -layout ' .
                escapeshellarg($pdfPath) .
                ' ' .
                escapeshellarg($textFile) .
                ' 2>/dev/null';


            @exec(
                $command,
                $output,
                $returnCode
            );


            if (
                file_exists($textFile)
            ) {

                $text =
                    @file_get_contents(
                        $textFile
                    );
            }


            @unlink(
                $textFile
            );
        }
    }


    if (
        trim((string)$text) !== ''
    ) {

        return normalizePdfText(
            $text
        );
    }


    // ========================================================
    // METHOD 2: SMALOT PDF PARSER
    // ========================================================

    if (
        class_exists(
            'Smalot\\PdfParser\\Parser'
        )
    ) {

        try {

            $parser =
                new Smalot\PdfParser\Parser();


            $pdf =
                $parser->parseFile(
                    $pdfPath
                );


            $text =
                $pdf->getText();


            if (
                trim((string)$text) !== ''
            ) {

                return normalizePdfText(
                    $text
                );
            }

        } catch (Throwable $e) {
            // Continue
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
            [
                "\xC2\xA0",
                "\r"
            ],
            [
                ' ',
                "\n"
            ],
            $text
        );


    $text =
        preg_replace(
            '/[ \t]+/u',
            ' ',
            $text
        );


    return trim($text);
}


// ============================================================
// NAME BANGLA
// ============================================================

function extractNameBangla($text)
{
    $value =
        extractBetweenLabels(
            $text,
            'Name(Bangla)',
            [
                'Name(English)',
                'Name (English)',
                'Date of Birth'
            ]
        );


    if (!$value) {

        $value =
            extractBetweenLabels(
                $text,
                'Name (Bangla)',
                [
                    'Name(English)',
                    'Name (English)',
                    'Date of Birth'
                ]
            );
    }


    return cleanName(
        $value
    );
}


// ============================================================
// NAME ENGLISH
// ============================================================

function extractNameEnglish($text)
{
    $value =
        extractBetweenLabels(
            $text,
            'Name(English)',
            [
                'Date of Birth',
                'Birth Place'
            ]
        );


    if (!$value) {

        $value =
            extractBetweenLabels(
                $text,
                'Name (English)',
                [
                    'Date of Birth',
                    'Birth Place'
                ]
            );
    }


    return strtoupper(
        cleanName(
            $value
        )
    );
}


// ============================================================
// NID
// ============================================================

function extractNid($text)
{
    $patterns = [

        '/National\s*ID[^\d০-৯]*([0-9০-৯]{10,17})/iu',

        '/National\s*ID\s*No[^\d০-৯]*([0-9০-৯]{10,17})/iu',

        '/NID[^\d০-৯]*([0-9০-৯]{10,17})/iu'
    ];


    foreach ($patterns as $pattern) {

        if (
            preg_match(
                $pattern,
                $text,
                $m
            )
        ) {

            return convertToEnglishDigits(
                $m[1]
            );
        }
    }


    return '';
}


// ============================================================
// PIN
// ============================================================

function extractPin($text)
{
    $patterns = [

        '/\bPin[^\d০-৯]*([0-9০-৯]{10,17})/iu',

        '/\bPIN[^\d০-৯]*([0-9০-৯]{10,17})/iu'
    ];


    foreach ($patterns as $pattern) {

        if (
            preg_match(
                $pattern,
                $text,
                $m
            )
        ) {

            return convertToEnglishDigits(
                $m[1]
            );
        }
    }


    return '';
}


// ============================================================
// DOB
// ============================================================

function extractDateOfBirth($text)
{
    $value =
        extractBetweenLabels(
            $text,
            'Date of Birth',
            [
                'Birth Place',
                'Birth Other',
                'Birth Registration',
                'Father Name'
            ]
        );


    $value =
        cleanText(
            $value
        );


    if (!$value) {
        return '';
    }


    $timestamp =
        strtotime(
            $value
        );


    if (
        $timestamp !== false
    ) {

        return date(
            'd M Y',
            $timestamp
        );
    }


    // YYYY-MM-DD
    if (
        preg_match(
            '/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/',
            $value,
            $m
        )
    ) {

        return date(
            'd M Y',
            mktime(
                0,
                0,
                0,
                (int)$m[2],
                (int)$m[3],
                (int)$m[1]
            )
        );
    }


    return $value;
}


// ============================================================
// BLOOD GROUP
// ============================================================

function extractBloodGroup($text)
{
    $value =
        extractBetweenLabels(
            $text,
            'Blood Group',
            [
                'TIN',
                'Driving',
                'Passport',
                'Laptop ID',
                'NID Father',
                'NID Mother'
            ]
        );


    $value =
        strtoupper(
            trim(
                cleanText(
                    $value
                )
            )
        );


    if (
        preg_match(
            '/^(A|B|AB|O)[+-]$/i',
            $value,
            $m
        )
    ) {

        return strtoupper(
            $m[0]
        );
    }


    if (
        preg_match(
            '/\b(AB|A|B|O)[+-]\b/i',
            $value,
            $m
        )
    ) {

        return strtoupper(
            $m[1]
        );
    }


    return '';
}


// ============================================================
// GENERIC LABEL VALUE
// ============================================================

function findValueByLabel(
    $label,
    $text
) {

    $knownNextLabels = [

        'Name(Bangla)',
        'Name (Bangla)',
        'Name(English)',
        'Name (English)',
        'Date of Birth',
        'Birth Place',
        'Birth Other',
        'Birth Registration',
        'Father Name',
        'Mother Name',
        'Spouse Name',
        'Gender',
        'Marital',
        'Occupation',
        'Disability',
        'Disability Other',
        'Present Address',
        'Permanent Address',
        'Division',
        'District',
        'RMO',
        'City Corporation / Municipality',
        'Upozila',
        'Union',
        'Union/Ward',
        'Ward For',
        'Village/Road',
        'Home/Holding',
        'Post Office',
        'Postal Code',
        'Region',
        'Education',
        'Education Other',
        'Sub Identification',
        'Blood Group',
        'TIN',
        'Driving',
        'Passport',
        'Laptop ID',
        'NID Father',
        'NID Mother',
        'Nid Spouse',
        'Voter No Father',
        'Voter No Mother',
        'Voter No Spouse',
        'Phone',
        'Mobile',
        'Email',
        'Religion',
        'Religion Other',
        'Of Father',
        'Of Mother',
        'Of Spouse',
        'No Finger',
        'No Finger Print'
    ];


    $value =
        extractBetweenLabels(
            $text,
            $label,
            $knownNextLabels
        );


    return cleanName(
        $value
    );
}


// ============================================================
// EXTRACT BETWEEN LABELS
// ============================================================

function extractBetweenLabels(
    $text,
    $start,
    $ends = []
) {

    $startPattern =
        preg_quote(
            $start,
            '/'
        );


    if (
        empty($ends)
    ) {

        $pattern =
            '/' .
            $startPattern .
            '[\s\|:]*(.*)$/uis';

    } else {

        $endPattern =
            implode(
                '|',
                array_map(
                    function ($item) {
                        return preg_quote(
                            $item,
                            '/'
                        );
                    },
                    $ends
                )
            );


        $pattern =
            '/' .
            $startPattern .
            '[\s\|:]*(.*?)' .
            '(?=\s+(?:' .
            $endPattern .
            ')\b|$)/uis';
    }


    if (
        preg_match(
            $pattern,
            $text,
            $matches
        )
    ) {

        return trim(
            $matches[1]
        );
    }


    return '';
}


// ============================================================
// CLEAN NAME
// ============================================================

function cleanName($text)
{
    if (!$text) {
        return '';
    }


    // Important: unwanted PDF UI/status text
    $stopWords = [

        'No Documents Available',

        'No Documents',

        'Smart Card Info',

        'Smart Card',

        'No Documents Available',

        'Voter Area',

        'Voter At',

        'Death Date',

        'License Documents',

        'Union Porishod',

        'Mouza/Moholla',

        'Present Address',

        'Permanent Address',

        'Education Other',

        'Sub Identification',

        'Disability Other',

        'Religion Other',

        'Status'
    ];


    foreach ($stopWords as $word) {

        $pos =
            mb_stripos(
                $text,
                $word
            );


        if (
            $pos !== false
        ) {

            $text =
                mb_substr(
                    $text,
                    0,
                    $pos
                );
        }
    }


    $text =
        str_replace(
            [
                '|',
                '"',
                "\r",
                "\n",
                "\t"
            ],
            ' ',
            $text
        );


    $text =
        preg_replace(
            '/\s+/u',
            ' ',
            $text
        );


    return trim(
        $text,
        " \t\n\r\0\x0B,"
    );
}


// ============================================================
// CLEAN GENERAL TEXT
// ============================================================

function cleanText($text)
{
    if (!$text) {
        return '';
    }


    $text =
        str_replace(
            [
                '"',
                "\r",
                "\n",
                "\t",
                '|'
            ],
            ' ',
            $text
        );


    $text =
        preg_replace(
            '/\s+/u',
            ' ',
            $text
        );


    return trim($text);
}


// ============================================================
// ADDRESS
// ============================================================

function combineAddress($fullText)
{
    $text =
        $fullText;


    // ========================================================
    // PRESENT ADDRESS BLOCK
    // ========================================================

    if (
        preg_match(
            '/Present\s*Address(.*?)(?=Permanent\s*Address|Education|Sub\s*Identification|$)/uis',
            $fullText,
            $m
        )
    ) {

        $text =
            $m[1];
    }


    // ========================================================
    // VILLAGE
    // ========================================================

    $villageRaw =
        extractBetweenLabels(
            $text,
            'Village/Road',
            [
                'Home/Holding',
                'Post Office',
                'Ward For'
            ]
        );


    if (!$villageRaw) {

        $villageRaw =
            extractBetweenLabels(
                $text,
                'Mouza/Moholla',
                [
                    'Home/Holding',
                    'Post Office'
                ]
            );
    }


    $village =
        cleanAddressPart(
            $villageRaw
        );


    // ========================================================
    // HOME
    // ========================================================

    $homeRaw =
        extractBetweenLabels(
            $text,
            'Home/Holding',
            [
                'Village/Road',
                'Post Office',
                'Postal Code'
            ]
        );


    $home =
        cleanAddressPart(
            $homeRaw
        );


    // ========================================================
    // POST OFFICE
    // ========================================================

    $postOffice =
        extractBetweenLabels(
            $text,
            'Post Office',
            [
                'Postal Code',
                'Region',
                'Upozila',
                'District'
            ]
        );


    $postOffice =
        cleanAddressPart(
            $postOffice
        );


    // ========================================================
    // POSTAL CODE
    // ========================================================

    $postalCode =
        extractPostalCode(
            $text
        );


    if (!$postalCode) {

        $postalCode =
            extractPostalCode(
                $fullText
            );
    }


    // ========================================================
    // UPOZILA
    // ========================================================

    $upozila =
        extractBetweenLabels(
            $text,
            'Upozila',
            [
                'Union',
                'Union/Ward',
                'Ward For',
                'Village/Road',
                'Home/Holding',
                'District'
            ]
        );


    $upozila =
        cleanAddressPart(
            $upozila
        );


    // ========================================================
    // DISTRICT
    // ========================================================

    $district =
        extractBetweenLabels(
            $text,
            'District',
            [
                'RMO',
                'City Corporation / Municipality',
                'Upozila',
                'Region'
            ]
        );


    $district =
        cleanAddressPart(
            $district
        );


    // ========================================================
    // FALLBACK DISTRICT
    // ========================================================

    if (!$district) {

        $district =
            extractBetweenLabels(
                $fullText,
                'District',
                [
                    'RMO',
                    'City Corporation / Municipality',
                    'Upozila',
                    'Region'
                ]
            );


        $district =
            cleanAddressPart(
                $district
            );
    }


    // ========================================================
    // BUILD ADDRESS
    // ========================================================

    $parts = [];


    if (
        isValidAddressPart(
            $home
        )
    ) {

        $parts[] =
            'বাসা/হোল্ডিং: ' .
            $home;
    }


    if (
        isValidAddressPart(
            $village
        )
    ) {

        $parts[] =
            'গ্রাম/রাস্তা: ' .
            $village;
    }


    if (
        isValidAddressPart(
            $postOffice
        )
    ) {

        $postText =
            $postOffice;


        if ($postalCode) {

            $postText .=
                ' -' .
                convertToBangla(
                    $postalCode
                );
        }


        $parts[] =
            'ডাকঘর: ' .
            $postText;
    }


    if (
        isValidAddressPart(
            $upozila
        )
    ) {

        $parts[] =
            $upozila;
    }


    if (
        isValidAddressPart(
            $district
        )
    ) {

        $parts[] =
            $district;
    }


    return implode(
        ', ',
        $parts
    );
}


// ============================================================
// ADDRESS CLEAN
// ============================================================

function cleanAddressPart($text)
{
    if (!$text) {
        return '';
    }


    $text =
        str_ireplace(
            [
                'Village/Road',
                'Home/Holding',
                'Post Office',
                'Postal Code',
                'Additional',
                'Union/Ward',
                'Union Porishod',
                'Mouza/Moholla',
                'No Documents Available',
                'Smart Card Info'
            ],
            '',
            $text
        );


    $text =
        preg_replace(
            '/\bNo\.?\b/iu',
            '',
            $text
        );


    $text =
        preg_replace(
            '/\s+/u',
            ' ',
            $text
        );


    return trim(
        $text,
        " \t\n\r\0\x0B,-"
    );
}


// ============================================================
// ADDRESS VALIDATION
// ============================================================

function isValidAddressPart($value)
{
    if (!$value) {
        return false;
    }


    $lower =
        mb_strtolower(
            trim($value)
        );


    $invalid = [

        'no',

        'additional',

        'union porishod',

        'smart card info',

        'no documents available',

        'rmo'
    ];


    if (
        in_array(
            $lower,
            $invalid,
            true
        )
    ) {

        return false;
    }


    return true;
}


// ============================================================
// POSTAL CODE
// ============================================================

function extractPostalCode($text)
{
    $patterns = [

        '/Postal\s*Code[^\d০-৯]*([0-9০-৯]{4})/iu',

        '/পোস্ট\s*কোড[^\d০-৯]*([0-9০-৯]{4})/u'
    ];


    foreach ($patterns as $pattern) {

        if (
            preg_match(
                $pattern,
                $text,
                $m
            )
        ) {

            return convertToEnglishDigits(
                $m[1]
            );
        }
    }


    return '';
}


// ============================================================
// DIGIT CONVERSION
// ============================================================

function convertToEnglishDigits($number)
{
    return str_replace(

        [
            '০',
            '১',
            '২',
            '৩',
            '৪',
            '৫',
            '৬',
            '৭',
            '৮',
            '৯'
        ],

        [
            '0',
            '1',
            '2',
            '3',
            '4',
            '5',
            '6',
            '7',
            '8',
            '9'
        ],

        $number
    );
}


// ============================================================
// ENGLISH TO BANGLA DIGITS
// ============================================================

function convertToBangla($number)
{
    return str_replace(

        [
            '0',
            '1',
            '2',
            '3',
            '4',
            '5',
            '6',
            '7',
            '8',
            '9'
        ],

        [
            '০',
            '১',
            '২',
            '৩',
            '৪',
            '৫',
            '৬',
            '৭',
            '৮',
            '৯'
        ],

        $number
    );
}


// ============================================================
// IMAGE EXTRACTION
// ============================================================

function extractImagesFromPdf(
    $pdfPath,
    $imageDir
) {

    $uniqueId =
        bin2hex(
            random_bytes(8)
        );


    $userName =
        null;


    $signatureName =
        null;


    $tempFiles = [];


    // ========================================================
    // METHOD 1: PDFIMAGES
    // ========================================================

    if (
        function_exists('exec')
    ) {

        $prefix =
            sys_get_temp_dir() .
            '/nidimg_' .
            $uniqueId;


        $command =
            'pdfimages -all ' .
            escapeshellarg($pdfPath) .
            ' ' .
            escapeshellarg($prefix) .
            ' 2>/dev/null';


        @exec(
            $command
        );


        $files =
            glob(
                $prefix . '-*'
            );


        if ($files) {

            sort(
                $files,
                SORT_NATURAL
            );


            foreach ($files as $file) {

                if (
                    !file_exists($file)
                ) {
                    continue;
                }


                $size =
                    @getimagesize(
                        $file
                    );


                if (!$size) {

                    @unlink($file);

                    continue;
                }


                $w =
                    (int)$size[0];

                $h =
                    (int)$size[1];


                if (
                    $w < 20 ||
                    $h < 10
                ) {

                    @unlink($file);

                    continue;
                }


                $ratio =
                    $w / max(
                        1,
                        $h
                    );


                // ============================================
                // SIGNATURE
                // ============================================

                if (
                    !$signatureName &&
                    $ratio >= 1.5 &&
                    $w >= 100 &&
                    $w > $h
                ) {

                    $newName =
                        'sign_' .
                        $uniqueId .
                        '.png';


                    $destination =
                        $imageDir .
                        $newName;


                    if (
                        normalizeSignatureImage(
                            $file,
                            $destination
                        )
                    ) {

                        if (
                            !isBlankOrSolidImage(
                                $destination
                            )
                        ) {

                            $signatureName =
                                $newName;

                            @unlink($file);

                            continue;
                        }


                        @unlink(
                            $destination
                        );
                    }
                }


                // ============================================
                // USER PHOTO
                // ============================================

                if (
                    !$userName &&
                    $ratio < 1.5 &&
                    $h >= $w * 0.8 &&
                    $h >= 100
                ) {

                    $newName =
                        'user_' .
                        $uniqueId .
                        '.jpg';


                    $destination =
                        $imageDir .
                        $newName;


                    if (
                        convertImageToJpeg(
                            $file,
                            $destination
                        )
                    ) {

                        if (
                            !isBlankOrSolidImage(
                                $destination
                            )
                        ) {

                            $userName =
                                $newName;

                            @unlink($file);

                            continue;
                        }


                        @unlink(
                            $destination
                        );
                    }
                }


                @unlink(
                    $file
                );
            }
        }
    }


    // ========================================================
    // METHOD 2: SMALOT IMAGE OBJECTS
    // ========================================================

    if (
        !$userName ||
        !$signatureName
    ) {

        if (
            class_exists(
                'Smalot\\PdfParser\\Parser'
            )
        ) {

            try {

                $parser =
                    new Smalot\PdfParser\Parser();


                $pdf =
                    $parser->parseFile(
                        $pdfPath
                    );


                $objects =
                    $pdf->getObjectsByType(
                        'XObject',
                        'Image'
                    );


                $index = 0;


                foreach (
                    $objects as $object
                ) {

                    $content =
                        $object->getContent();


                    if (
                        empty($content)
                    ) {

                        $index++;

                        continue;
                    }


                    $tmp =
                        sys_get_temp_dir() .
                        '/nidobj_' .
                        $uniqueId .
                        '_' .
                        $index;


                    @file_put_contents(
                        $tmp,
                        $content
                    );


                    if (
                        !file_exists($tmp)
                    ) {

                        $index++;

                        continue;
                    }


                    $size =
                        @getimagesize(
                            $tmp
                        );


                    if (!$size) {

                        @unlink($tmp);

                        $index++;

                        continue;
                    }


                    $w =
                        (int)$size[0];

                    $h =
                        (int)$size[1];


                    $ratio =
                        $w / max(
                            1,
                            $h
                        );


                    // SIGNATURE
                    if (
                        !$signatureName &&
                        $ratio >= 1.5 &&
                        $w >= 100 &&
                        $w > $h
                    ) {

                        $newName =
                            'sign_' .
                            $uniqueId .
                            '.png';


                        $destination =
                            $imageDir .
                            $newName;


                        if (
                            normalizeSignatureImage(
                                $tmp,
                                $destination
                            ) &&
                            !isBlankOrSolidImage(
                                $destination
                            )
                        ) {

                            $signatureName =
                                $newName;

                            @unlink($tmp);

                            $index++;

                            continue;
                        }


                        @unlink(
                            $destination
                        );
                    }


                    // USER PHOTO
                    if (
                        !$userName &&
                        $ratio < 1.5 &&
                        $h >= $w * 0.8 &&
                        $h >= 100
                    ) {

                        $newName =
                            'user_' .
                            $uniqueId .
                            '.jpg';


                        $destination =
                            $imageDir .
                            $newName;


                        if (
                            convertImageToJpeg(
                                $tmp,
                                $destination
                            ) &&
                            !isBlankOrSolidImage(
                                $destination
                            )
                        ) {

                            $userName =
                                $newName;

                            @unlink($tmp);

                            $index++;

                            continue;
                        }


                        @unlink(
                            $destination
                        );
                    }


                    @unlink(
                        $tmp
                    );


                    $index++;
                }

            } catch (Throwable $e) {
                // Continue
            }
        }
    }


    // ========================================================
    // METHOD 3: RENDER PDF PAGE
    // ========================================================

    if (
        !$userName ||
        !$signatureName
    ) {

        $rendered =
            renderPdfPageOne(
                $pdfPath,
                $uniqueId
            );


        if (
            $rendered &&
            file_exists($rendered)
        ) {

            $img =
                @imagecreatefrompng(
                    $rendered
                );


            if ($img) {

                $w =
                    imagesx($img);

                $h =
                    imagesy($img);


                // ============================================
                // PHOTO FALLBACK
                // ============================================

                if (!$userName) {

                    $photoRects = [

                        [
                            'x' => 0.58,
                            'y' => 0.00,
                            'width' => 0.40,
                            'height' => 0.25
                        ],

                        [
                            'x' => 0.60,
                            'y' => 0.01,
                            'width' => 0.36,
                            'height' => 0.22
                        ]
                    ];


                    foreach (
                        $photoRects as $rect
                    ) {

                        $crop =
                            @imagecrop(
                                $img,
                                [
                                    'x' =>
                                        (int)(
                                            $w *
                                            $rect['x']
                                        ),

                                    'y' =>
                                        (int)(
                                            $h *
                                            $rect['y']
                                        ),

                                    'width' =>
                                        (int)(
                                            $w *
                                            $rect['width']
                                        ),

                                    'height' =>
                                        (int)(
                                            $h *
                                            $rect['height']
                                        )
                                ]
                            );


                        if (
                            $crop !== false
                        ) {

                            $name =
                                'user_' .
                                $uniqueId .
                                '.jpg';


                            $path =
                                $imageDir .
                                $name;


                            imagejpeg(
                                $crop,
                                $path,
                                90
                            );


                            imagedestroy(
                                $crop
                            );


                            if (
                                !isBlankOrSolidImage(
                                    $path
                                )
                            ) {

                                $userName =
                                    $name;

                                break;
                            }


                            @unlink(
                                $path
                            );
                        }
                    }
                }


                // ============================================
                // SIGNATURE FALLBACK
                // ============================================

                if (!$signatureName) {

                    $signatureRects = [

                        [
                            'x' => 0.42,
                            'y' => 0.18,
                            'width' => 0.55,
                            'height' => 0.14
                        ],

                        [
                            'x' => 0.45,
                            'y' => 0.20,
                            'width' => 0.50,
                            'height' => 0.12
                        ],

                        [
                            'x' => 0.50,
                            'y' => 0.24,
                            'width' => 0.45,
                            'height' => 0.10
                        ]
                    ];


                    foreach (
                        $signatureRects as $rect
                    ) {

                        $crop =
                            @imagecrop(
                                $img,
                                [
                                    'x' =>
                                        (int)(
                                            $w *
                                            $rect['x']
                                        ),

                                    'y' =>
                                        (int)(
                                            $h *
                                            $rect['y']
                                        ),

                                    'width' =>
                                        (int)(
                                            $w *
                                            $rect['width']
                                        ),

                                    'height' =>
                                        (int)(
                                            $h *
                                            $rect['height']
                                        )
                                ]
                            );


                        if (
                            $crop === false
                        ) {
                            continue;
                        }


                        $name =
                            'sign_' .
                            $uniqueId .
                            '.png';


                        $path =
                            $imageDir .
                            $name;


                        imagepng(
                            $crop,
                            $path,
                            6
                        );


                        imagedestroy(
                            $crop
                        );


                        if (
                            trimSignatureImage(
                                $path,
                                $path
                            ) &&
                            !isBlankOrSolidImage(
                                $path
                            )
                        ) {

                            $signatureName =
                                $name;

                            break;
                        }


                        @unlink(
                            $path
                        );
                    }
                }


                imagedestroy(
                    $img
                );
            }


            @unlink(
                $rendered
            );
        }
    }


    // ========================================================
    // PLACEHOLDERS
    // ========================================================

    if (!$userName) {

        $userName =
            createPlaceholderImage(
                'user',
                $imageDir
            );
    }


    if (!$signatureName) {

        $signatureName =
            createPlaceholderImage(
                'signature',
                $imageDir
            );
    }


    return [

        'user' =>
            $userName,

        'signature' =>
            $signatureName
    ];
}


// ============================================================
// CONVERT IMAGE TO JPEG
// ============================================================

function convertImageToJpeg(
    $source,
    $destination
) {

    if (
        !file_exists($source)
    ) {
        return false;
    }


    $data =
        @file_get_contents(
            $source
        );


    if ($data === false) {
        return false;
    }


    $img =
        @imagecreatefromstring(
            $data
        );


    if (!$img) {
        return false;
    }


    $w =
        imagesx($img);

    $h =
        imagesy($img);


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


    imagecopy(
        $canvas,
        $img,
        0,
        0,
        0,
        0,
        $w,
        $h
    );


    $result =
        imagejpeg(
            $canvas,
            $destination,
            92
        );


    imagedestroy($canvas);
    imagedestroy($img);


    return $result;
}


// ============================================================
// NORMALIZE SIGNATURE
// ============================================================

function normalizeSignatureImage(
    $sourcePath,
    $destinationPath
) {

    if (
        !file_exists($sourcePath)
    ) {
        return false;
    }


    $data =
        @file_get_contents(
            $sourcePath
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
    // REMOVE SMALL OUTER BORDER
    // ========================================================

    $borderX =
        max(
            2,
            (int)($w * 0.04)
        );


    $borderY =
        max(
            2,
            (int)($h * 0.06)
        );


    $innerW =
        $w -
        ($borderX * 2);


    $innerH =
        $h -
        ($borderY * 2);


    if (
        $innerW < 20 ||
        $innerH < 10
    ) {

        imagedestroy($src);

        return false;
    }


    $canvas =
        imagecreatetruecolor(
            $innerW,
            $innerH
        );


    $white =
        imagecolorallocate(
            $canvas,
            255,
            255,
            255
        );


    $black =
        imagecolorallocate(
            $canvas,
            0,
            0,
            0
        );


    imagefill(
        $canvas,
        0,
        0,
        $white
    );


    // ========================================================
    // BACKGROUND DETECTION
    // ========================================================

    $samples = [];


    $points = [

        [0, 0],

        [$w - 1, 0],

        [0, $h - 1],

        [$w - 1, $h - 1],

        [(int)($w / 2), 0],

        [(int)($w / 2), $h - 1]
    ];


    foreach ($points as $point) {

        $rgb =
            imagecolorat(
                $src,
                $point[0],
                $point[1]
            );


        $r =
            ($rgb >> 16) & 255;

        $g =
            ($rgb >> 8) & 255;

        $b =
            $rgb & 255;


        $gray =
            (int)(
                0.299 * $r +
                0.587 * $g +
                0.114 * $b
            );


        $samples[] =
            $gray;
    }


    $background =
        array_sum($samples) /
        max(
            1,
            count($samples)
        );


    $invert =
        $background < 110;


    // ========================================================
    // CREATE CLEAN SIGNATURE
    // ========================================================

    for (
        $y = 0;
        $y < $innerH;
        $y++
    ) {

        for (
            $x = 0;
            $x < $innerW;
            $x++
        ) {

            $rgb =
                imagecolorat(
                    $src,
                    $x + $borderX,
                    $y + $borderY
                );


            $r =
                ($rgb >> 16) & 255;

            $g =
                ($rgb >> 8) & 255;

            $b =
                $rgb & 255;


            $gray =
                (int)(
                    0.299 * $r +
                    0.587 * $g +
                    0.114 * $b
                );


            if ($invert) {

                $gray =
                    255 - $gray;
            }


            if ($gray < 160) {

                imagesetpixel(
                    $canvas,
                    $x,
                    $y,
                    $black
                );

            } else {

                imagesetpixel(
                    $canvas,
                    $x,
                    $y,
                    $white
                );
            }
        }
    }


    imagedestroy($src);


    imagepng(
        $canvas,
        $destinationPath,
        6
    );


    imagedestroy($canvas);


    return trimSignatureImage(
        $destinationPath,
        $destinationPath
    );
}


// ============================================================
// TRIM SIGNATURE
// ============================================================

function trimSignatureImage(
    $sourcePath,
    $destinationPath
) {

    if (
        !file_exists($sourcePath)
    ) {
        return false;
    }


    $img =
        @imagecreatefrompng(
            $sourcePath
        );


    if (!$img) {
        return false;
    }


    $w =
        imagesx($img);

    $h =
        imagesy($img);


    $ignoreX =
        max(
            2,
            (int)($w * 0.04)
        );


    $ignoreY =
        max(
            2,
            (int)($h * 0.04)
        );


    $minX =
        $w;

    $minY =
        $h;

    $maxX =
        -1;

    $maxY =
        -1;


    for (
        $y = $ignoreY;
        $y < ($h - $ignoreY);
        $y++
    ) {

        for (
            $x = $ignoreX;
            $x < ($w - $ignoreX);
            $x++
        ) {

            $rgb =
                imagecolorat(
                    $img,
                    $x,
                    $y
                );


            $r =
                ($rgb >> 16) & 255;

            $g =
                ($rgb >> 8) & 255;

            $b =
                $rgb & 255;


            $gray =
                (int)(
                    0.299 * $r +
                    0.587 * $g +
                    0.114 * $b
                );


            if (
                $gray < 120
            ) {

                $minX =
                    min(
                        $minX,
                        $x
                    );

                $minY =
                    min(
                        $minY,
                        $y
                    );

                $maxX =
                    max(
                        $maxX,
                        $x
                    );

                $maxY =
                    max(
                        $maxY,
                        $y
                    );
            }
        }
    }


    if (
        $maxX < 0 ||
        $maxY < 0
    ) {

        imagedestroy($img);

        return false;
    }


    $signatureWidth =
        $maxX -
        $minX +
        1;


    $signatureHeight =
        $maxY -
        $minY +
        1;


    if (
        $signatureWidth < 10 ||
        $signatureHeight < 4
    ) {

        imagedestroy($img);

        return false;
    }


    $paddingX =
        max(
            8,
            (int)($signatureWidth * 0.08)
        );


    $paddingY =
        max(
            6,
            (int)($signatureHeight * 0.25)
        );


    $cropX =
        max(
            0,
            $minX - $paddingX
        );


    $cropY =
        max(
            0,
            $minY - $paddingY
        );


    $right =
        min(
            $w - 1,
            $maxX + $paddingX
        );


    $bottom =
        min(
            $h - 1,
            $maxY + $paddingY
        );


    $cropWidth =
        $right -
        $cropX +
        1;


    $cropHeight =
        $bottom -
        $cropY +
        1;


    $cropped =
        imagecreatetruecolor(
            $cropWidth,
            $cropHeight
        );


    $white =
        imagecolorallocate(
            $cropped,
            255,
            255,
            255
        );


    imagefill(
        $cropped,
        0,
        0,
        $white
    );


    imagecopy(
        $cropped,
        $img,
        0,
        0,
        $cropX,
        $cropY,
        $cropWidth,
        $cropHeight
    );


    $result =
        imagepng(
            $cropped,
            $destinationPath,
            6
        );


    imagedestroy($cropped);
    imagedestroy($img);


    return $result;
}


// ============================================================
// BLANK IMAGE CHECK
// ============================================================

function isBlankOrSolidImage(
    $filePath
) {

    if (
        !file_exists($filePath)
    ) {
        return true;
    }


    $data =
        @file_get_contents(
            $filePath
        );


    if ($data === false) {
        return true;
    }


    $img =
        @imagecreatefromstring(
            $data
        );


    if (!$img) {
        return true;
    }


    $w =
        imagesx($img);

    $h =
        imagesy($img);


    if (
        $w <= 0 ||
        $h <= 0
    ) {

        imagedestroy($img);

        return true;
    }


    $differentPixels =
        0;


    $totalSamples =
        0;


    $stepX =
        max(
            1,
            (int)($w / 20)
        );


    $stepY =
        max(
            1,
            (int)($h / 20)
        );


    for (
        $y = 0;
        $y < $h;
        $y += $stepY
    ) {

        for (
            $x = 0;
            $x < $w;
            $x += $stepX
        ) {

            $rgb =
                imagecolorat(
                    $img,
                    $x,
                    $y
                );


            $r =
                ($rgb >> 16) & 255;

            $g =
                ($rgb >> 8) & 255;

            $b =
                $rgb & 255;


            $gray =
                (
                    0.299 * $r +
                    0.587 * $g +
                    0.114 * $b
                );


            if (
                $gray < 230
            ) {

                $differentPixels++;
            }


            $totalSamples++;
        }
    }


    imagedestroy($img);


    if (
        $totalSamples === 0
    ) {
        return true;
    }


    return (
        $differentPixels <
        max(
            2,
            $totalSamples * 0.002
        )
    );
}


// ============================================================
// RENDER PDF PAGE 1
// ============================================================

function renderPdfPageOne(
    $pdfPath,
    $uniqueId
) {

    $outputBase =
        sys_get_temp_dir() .
        '/nidpage_' .
        $uniqueId;


    $outputFile =
        $outputBase .
        '.png';


    // ========================================================
    // PDFTOPPM
    // ========================================================

    if (
        function_exists('exec')
    ) {

        $command =
            'pdftoppm ' .
            '-f 1 ' .
            '-singlefile ' .
            '-png ' .
            '-r 150 ' .
            escapeshellarg($pdfPath) .
            ' ' .
            escapeshellarg($outputBase) .
            ' 2>/dev/null';


        @exec(
            $command
        );


        if (
            file_exists($outputFile)
        ) {

            return $outputFile;
        }
    }


    // ========================================================
    // IMAGICK
    // ========================================================

    if (
        extension_loaded('imagick')
    ) {

        try {

            $im =
                new Imagick();


            $im->setResolution(
                150,
                150
            );


            $im->readImage(
                $pdfPath . '[0]'
            );


            $im->setImageFormat(
                'png'
            );


            $im->writeImage(
                $outputFile
            );


            $im->clear();
            $im->destroy();


            if (
                file_exists($outputFile)
            ) {

                return $outputFile;
            }

        } catch (Throwable $e) {
            // Continue
        }
    }


    return null;
}


// ============================================================
// PLACEHOLDER
// ============================================================

function createPlaceholderImage(
    $type,
    $imageDir
) {

    $fileName =
        'placeholder_' .
        $type .
        '.png';


    $filePath =
        $imageDir .
        $fileName;


    if (
        file_exists($filePath)
    ) {

        return $fileName;
    }


    if (
        !function_exists(
            'imagecreatetruecolor'
        )
    ) {

        return '';
    }


    $width =
        300;

    $height =
        180;


    $im =
        imagecreatetruecolor(
            $width,
            $height
        );


    $white =
        imagecolorallocate(
            $im,
            255,
            255,
            255
        );


    $gray =
        imagecolorallocate(
            $im,
            120,
            120,
            120
        );


    imagefill(
        $im,
        0,
        0,
        $white
    );


    $text =
        (
            $type === 'user'
        )
        ? 'User Photo'
        : 'Signature';


    imagestring(
        $im,
        5,
        90,
        80,
        $text,
        $gray
    );


    imagepng(
        $im,
        $filePath
    );


    imagedestroy(
        $im
    );


    return $fileName;
}


// ============================================================
// IMAGE URL
// ============================================================

function buildImageUrl($fileName)
{
    if (!$fileName) {
        return '';
    }


    $proto =
        'http';


    if (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ) {

        $proto =
            'https';
    }


    if (
        isset(
            $_SERVER['HTTP_X_FORWARDED_PROTO']
        )
    ) {

        $forwarded =
            strtolower(
                trim(
                    explode(
                        ',',
                        $_SERVER['HTTP_X_FORWARDED_PROTO']
                    )[0]
                )
            );


        if (
            $forwarded === 'https'
        ) {

            $proto =
                'https';
        }
    }


    $host =
        $_SERVER['HTTP_HOST'] ??
        'localhost';


    $host =
        preg_replace(
            '/[^a-zA-Z0-9\.\-:]/',
            '',
            $host
        );


    return
        $proto .
        '://' .
        $host .
        '/images/' .
        rawurlencode(
            $fileName
        );
}


// ============================================================
// REMOVE TEMP DIRECTORY
// ============================================================

function removeDirectoryRecursive($dir)
{
    if (
        !$dir ||
        !is_dir($dir)
    ) {
        return;
    }


    $items =
        @scandir(
            $dir
        );


    if (!$items) {
        return;
    }


    foreach ($items as $item) {

        if (
            $item === '.' ||
            $item === '..'
        ) {
            continue;
        }


        $path =
            $dir .
            DIRECTORY_SEPARATOR .
            $item;


        if (
            is_dir($path)
        ) {

            removeDirectoryRecursive(
                $path
            );

        } else {

            @unlink(
                $path
            );
        }
    }


    @rmdir(
        $dir
    );
}

?>
