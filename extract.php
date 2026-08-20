<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NID PDF EXTRACTION API
|--------------------------------------------------------------------------
| Render + Docker + PHP 8.3
|
| Required:
|   - poppler-utils
|   - PHP GD
|   - PHP mbstring
|   - smalot/pdfparser
|
| Endpoint:
|   POST /extract.php
|
| Accepted upload fields:
|   pdf
|   nid_pdf
|--------------------------------------------------------------------------
*/

error_reporting(0);
ini_set('display_errors', '0');

ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

date_default_timezone_set('Asia/Dhaka');


/*=========================================================================
  OPTIONS
=========================================================================*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();

    echo json_encode(
        [
            'code' => 200,
            'success' => true,
            'message' => 'OK'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*=========================================================================
  METHOD
=========================================================================*/

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


/*=========================================================================
  DIRECTORIES
=========================================================================*/

$uploadDir = __DIR__ . '/uploads';
$imageDir  = __DIR__ . '/images';

ensureDirectory($uploadDir);
ensureDirectory($imageDir);


/*=========================================================================
  UPLOAD FIELD
=========================================================================*/

$fileKey = null;

if (
    isset($_FILES['nid_pdf']) &&
    $_FILES['nid_pdf']['error'] === UPLOAD_ERR_OK
) {
    $fileKey = 'nid_pdf';
}
elseif (
    isset($_FILES['pdf']) &&
    $_FILES['pdf']['error'] === UPLOAD_ERR_OK
) {
    $fileKey = 'pdf';
}


if ($fileKey === null) {

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


/*=========================================================================
  FILE VALIDATION
=========================================================================*/

$file = $_FILES[$fileKey];

$tmpPath = $file['tmp_name'];
$originalName = basename($file['name']);

if (!is_uploaded_file($tmpPath)) {

    ob_clean();

    http_response_code(400);

    echo json_encode(
        [
            'code' => 400,
            'success' => false,
            'message' => 'Invalid uploaded file.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


$extension = strtolower(
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
            'message' => 'Only PDF files are allowed.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*=========================================================================
  SIZE LIMIT
=========================================================================*/

$maxSize = 30 * 1024 * 1024;

if (
    isset($file['size']) &&
    $file['size'] > $maxSize
) {

    ob_clean();

    http_response_code(413);

    echo json_encode(
        [
            'code' => 413,
            'success' => false,
            'message' => 'PDF file is too large. Maximum 30MB allowed.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*=========================================================================
  UNIQUE WORK DIRECTORY
=========================================================================*/

$workDir =
    sys_get_temp_dir() .
    '/nid_' .
    bin2hex(random_bytes(8));

ensureDirectory($workDir);

$pdfPath =
    $workDir .
    '/document.pdf';


/*=========================================================================
  MOVE PDF
=========================================================================*/

if (!move_uploaded_file($tmpPath, $pdfPath)) {

    removeDirectory($workDir);

    ob_clean();

    http_response_code(500);

    echo json_encode(
        [
            'code' => 500,
            'success' => false,
            'message' => 'Failed to save uploaded PDF.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*=========================================================================
  PROCESS
=========================================================================*/

try {

    /*
     * ---------------------------------------------------------------
     * TEXT EXTRACTION
     * ---------------------------------------------------------------
     */

    $text = extractPdfText($pdfPath);

    if (trim($text) === '') {

        throw new Exception(
            'Could not extract text from PDF.'
        );
    }


    /*
     * ---------------------------------------------------------------
     * BASIC DATA
     * ---------------------------------------------------------------
     */

    $nameBangla =
        extractBanglaName($text);

    $nameEnglish =
        extractEnglishName($text);

    $nid =
        extractNid($text);

    $pin =
        extractPin($text);

    $dob =
        extractDob($text);

    $fatherName =
        extractField(
            $text,
            'Father Name',
            [
                'Mother Name',
                'Mother'
            ]
        );

    $motherName =
        extractField(
            $text,
            'Mother Name',
            [
                'Spouse Name',
                'Gender'
            ]
        );

    $gender =
        cleanSimpleField(
            extractField(
                $text,
                'Gender',
                [
                    'Marital',
                    'Religion'
                ]
            )
        );

    $religion =
        cleanSimpleField(
            extractField(
                $text,
                'Religion',
                [
                    'Religion Other',
                    'Birth Place',
                    'Blood Group'
                ]
            )
        );

    $birthPlace =
        cleanSimpleField(
            extractField(
                $text,
                'Birth Place',
                [
                    'Birth Other',
                    'Blood Group',
                    'Gender'
                ]
            )
        );

    $bloodGroup =
        extractBloodGroup($text);


    /*
     * ---------------------------------------------------------------
     * ADDRESS
     * ---------------------------------------------------------------
     */

    $address =
        combineAddress($text);


    /*
     * ---------------------------------------------------------------
     * IMAGES
     * ---------------------------------------------------------------
     */

    $imageResult =
        extractImages(
            $pdfPath,
            $imageDir,
            $workDir
        );


    $userIMG =
        $imageResult['userIMG'];

    $signIMG =
        $imageResult['signIMG'];


    /*
     * ---------------------------------------------------------------
     * DATE
     * ---------------------------------------------------------------
     */

    $dateOfToday =
        convertToBangla(
            date('d-m-Y')
        );


    /*
     * ---------------------------------------------------------------
     * RESPONSE
     * ---------------------------------------------------------------
     */

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
                $nid,

            'pin' =>
                $pin,

            'dateOfBirth' =>
                $dob,

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


}
catch (Throwable $e) {

    ob_clean();

    http_response_code(500);

    echo json_encode(
        [
            'code' => 500,
            'success' => false,
            'message' =>
                'Error processing the PDF.',
            'error' =>
                $e->getMessage()
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
}
finally {

    if (file_exists($pdfPath)) {
        @unlink($pdfPath);
    }

    removeDirectory($workDir);
}

ob_end_flush();


/*=========================================================================

  DIRECTORY

=========================================================================*/

function ensureDirectory(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }

    return @mkdir(
        $dir,
        0777,
        true
    );
}


/*=========================================================================

  REMOVE DIRECTORY

=========================================================================*/

function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = @scandir($dir);

    if (!$items) {
        @rmdir($dir);
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

        if (is_dir($path)) {

            removeDirectory($path);

        }
        else {

            @unlink($path);
        }
    }

    @rmdir($dir);
}


/*=========================================================================

  BASE URL

=========================================================================*/

function getBaseUrl(): string
{
    $configured =
        getenv('NID_BASE_URL');

    if ($configured) {

        return rtrim(
            $configured,
            '/'
        );
    }

    $host =
        $_SERVER['HTTP_HOST']
        ?? 'api.itsheba.store';

    /*
     * Render reverse proxy
     */

    $forwarded =
        $_SERVER['HTTP_X_FORWARDED_PROTO']
        ?? '';

    if (
        strtolower($forwarded) === 'https'
    ) {

        $scheme = 'https';

    }
    elseif (
        isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ) {

        $scheme = 'https';

    }
    else {

        /*
         * This API is deployed behind HTTPS.
         */

        $scheme = 'https';
    }

    return
        $scheme .
        '://' .
        $host;
}


/*=========================================================================

  TEXT EXTRACTION

=========================================================================*/

function extractPdfText(string $pdfPath): string
{
    $output = '';

    if (function_exists('exec')) {

        $textFile =
            sys_get_temp_dir() .
            '/pdftext_' .
            bin2hex(random_bytes(5)) .
            '.txt';

        $command =
            'pdftotext -layout -enc UTF-8 ' .
            escapeshellarg($pdfPath) .
            ' ' .
            escapeshellarg($textFile) .
            ' 2>/dev/null';

        @exec(
            $command,
            $lines,
            $returnCode
        );

        if (
            file_exists($textFile)
        ) {

            $output =
                @file_get_contents(
                    $textFile
                ) ?: '';

            @unlink($textFile);
        }
    }


    /*
     * Smalot fallback
     */

    if (
        trim($output) === '' &&
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
                new \Smalot\PdfParser\Parser();

            $pdf =
                $parser->parseFile(
                    $pdfPath
                );

            $output =
                $pdf->getText();

        }
        catch (Throwable $e) {

            $output = '';
        }
    }


    return normalizePdfText($output);
}


/*=========================================================================

  NORMALIZE TEXT

=========================================================================*/

function normalizePdfText(string $text): string
{
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

    $text =
        preg_replace(
            '/\n{3,}/u',
            "\n\n",
            $text
        );

    return trim($text);
}


/*=========================================================================

  FIELD EXTRACTION

=========================================================================*/

function extractField(
    string $text,
    string $start,
    array $ends
): string
{
    $endPattern =
        implode(
            '|',
            array_map(
                fn($v) =>
                    preg_quote(
                        $v,
                        '/'
                    ),
                $ends
            )
        );

    $pattern =
        '/' .
        preg_quote(
            $start,
            '/'
        ) .
        '\s*(?:[:|])?\s*' .
        '(.*?)' .
        '(?=\s*(?:' .
        $endPattern .
        ')\b|$)' .
        '/isu';

    if (
        preg_match(
            $pattern,
            $text,
            $m
        )
    ) {

        return cleanSimpleField(
            $m[1]
        );
    }


    /*
     * Layout fallback
     */

    $pattern2 =
        '/' .
        preg_quote(
            $start,
            '/'
        ) .
        '[^\n]*?\n?' .
        '([^\n]+)/iu';

    if (
        preg_match(
            $pattern2,
            $text,
            $m
        )
    ) {

        return cleanSimpleField(
            $m[1]
        );
    }

    return '';
}


/*=========================================================================

  BANGLA NAME

=========================================================================*/

function extractBanglaName(string $text): string
{
    $value =
        extractField(
            $text,
            'Name(Bangla)',
            [
                'Name(English)'
            ]
        );

    if (!$value) {

        $value =
            extractField(
                $text,
                'Name (Bangla)',
                [
                    'Name (English)'
                ]
            );
    }

    $value =
        preg_replace(
            '/halnagad[_\s]*\d+/iu',
            '',
            $value
        );

    $value =
        preg_replace(
            '/\bTag\b/iu',
            '',
            $value
        );

    return cleanSimpleField($value);
}


/*=========================================================================

  ENGLISH NAME

=========================================================================*/

function extractEnglishName(string $text): string
{
    $value =
        extractField(
            $text,
            'Name(English)',
            [
                'Date of Birth'
            ]
        );

    if (!$value) {

        $value =
            extractField(
                $text,
                'Name (English)',
                [
                    'Date of Birth'
                ]
            );
    }

    return strtoupper(
        cleanSimpleField($value)
    );
}


/*=========================================================================

  NID

=========================================================================*/

function extractNid(string $text): string
{
    $patterns = [

        '/National\s*ID[^\d০-৯]*(\d{10,17})/iu',

        '/National\s*ID[^\d০-৯]*([0-9০-৯]{10,17})/u',

        '/NID[^\d০-৯]*(\d{10,17})/iu'
    ];

    foreach ($patterns as $pattern) {

        if (
            preg_match(
                $pattern,
                $text,
                $m
            )
        ) {

            return normalizeDigits(
                $m[1]
            );
        }
    }

    return '';
}


/*=========================================================================

  PIN

=========================================================================*/

function extractPin(string $text): string
{
    $patterns = [

        '/Pin[^\d০-৯]*(\d{10,20})/iu',

        '/PIN[^\d০-৯]*(\d{10,20})/iu'
    ];

    foreach ($patterns as $pattern) {

        if (
            preg_match(
                $pattern,
                $text,
                $m
            )
        ) {

            return normalizeDigits(
                $m[1]
            );
        }
    }

    return '';
}


/*=========================================================================

  DIGIT NORMALIZATION

=========================================================================*/

function normalizeDigits(string $value): string
{
    $bn = [
        '০','১','২','৩','৪',
        '৫','৬','৭','৮','৯'
    ];

    $en = [
        '0','1','2','3','4',
        '5','6','7','8','9'
    ];

    return str_replace(
        $bn,
        $en,
        preg_replace(
            '/\s+/u',
            '',
            $value
        )
    );
}


/*=========================================================================

  DOB

=========================================================================*/

function extractDob(string $text): string
{
    $raw =
        extractField(
            $text,
            'Date of Birth',
            [
                'Birth Place'
            ]
        );

    if (!$raw) {
        return '';
    }

    $raw =
        cleanSimpleField($raw);

    $timestamp =
        strtotime($raw);

    if (
        $timestamp !== false
    ) {

        return date(
            'd M Y',
            $timestamp
        );
    }

    /*
     * Common formats
     */

    if (
        preg_match(
            '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/',
            $raw,
            $m
        )
    ) {

        $timestamp =
            strtotime(
                $m[1] .
                '-' .
                $m[2] .
                '-' .
                $m[3]
            );

        if (
            $timestamp !== false
        ) {

            return date(
                'd M Y',
                $timestamp
            );
        }
    }

    return $raw;
}


/*=========================================================================

  BLOOD GROUP

=========================================================================*/

function extractBloodGroup(string $text): string
{
    if (
        preg_match(
            '/Blood\s*Group.*?\b(AB|A|B|O)\s*([+-])/iu',
            $text,
            $m
        )
    ) {

        return
            strtoupper(
                $m[1] .
                $m[2]
            );
    }

    return '';
}


/*=========================================================================

  CLEAN SIMPLE FIELD

=========================================================================*/

function cleanSimpleField(string $value): string
{
    $value =
        str_replace(
            [
                "\r",
                "\n",
                "\t",
                '|',
                '"',
                ','
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

    $value =
        trim($value);

    $remove = [

        'Smart Card Info',

        'No Documents Available',

        'License Documents',

        'Voter Area',

        'Voter At',

        'Death Date',

        'Status',

        'Additional',

        'Union Porishod',

        'Union/Ward',

        'Mouza/Moholla'
    ];

    foreach ($remove as $word) {

        $value =
            preg_replace(
                '/\b' .
                preg_quote(
                    $word,
                    '/'
                ) .
                '\b/iu',
                '',
                $value
            );
    }

    $value =
        preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

    return trim($value);
}


/*=========================================================================

  ADDRESS

=========================================================================*/

function combineAddress(string $text): string
{
    /*
     * Prefer Present Address block
     */

    $addressText =
        $text;

    if (
        preg_match(
            '/Present\s*Address(.*?)(?:Permanent\s*Address|$)/isu',
            $text,
            $m
        )
    ) {

        $addressText =
            $m[1];
    }


    /*
     * Village
     */

    $villageRaw =
        extractBetween(
            $addressText,
            'Village/Road',
            [
                'Home/Holding',
                'Post Office',
                'Postal Code'
            ]
        );

    if (!$villageRaw) {

        $villageRaw =
            extractBetween(
                $addressText,
                'Mouza/Moholla',
                [
                    'Home/Holding',
                    'Post Office',
                    'Postal Code'
                ]
            );
    }

    $village =
        cleanAddressPart(
            $villageRaw
        );


    /*
     * Home
     */

    $homeRaw =
        extractBetween(
            $addressText,
            'Home/Holding',
            [
                'Post Office',
                'Postal Code'
            ]
        );

    $home =
        cleanAddressPart(
            $homeRaw
        );


    /*
     * Post Office
     */

    $postOffice =
        extractBetween(
            $addressText,
            'Post Office',
            [
                'Postal Code',
                'Upozila',
                'Upazila'
            ]
        );

    $postOffice =
        cleanAddressPart(
            $postOffice
        );


    /*
     * Postal
     */

    $postal =
        extractPostalCode(
            $addressText
        );

    if (!$postal) {

        $postal =
            extractPostalCode(
                $text
            );
    }


    /*
     * Upozila
     */

    $upozila =
        extractBetween(
            $addressText,
            'Upozila',
            [
                'Union',
                'Union/Ward',
                'Municipality',
                'District'
            ]
        );

    if (!$upozila) {

        $upozila =
            extractBetween(
                $addressText,
                'Upazila',
                [
                    'Union',
                    'Union/Ward',
                    'Municipality',
                    'District'
                ]
            );
    }

    $upozila =
        cleanAddressPart(
            $upozila
        );


    /*
     * District
     */

    $district =
        extractBetween(
            $addressText,
            'District',
            [
                'RMO',
                'City',
                'City Corporation',
                'Division'
            ]
        );

    $district =
        cleanAddressPart(
            $district
        );


    /*
     * Build
     */

    $parts = [];


    if (
        $home !== '' &&
        !isGarbageAddress($home)
    ) {

        $parts[] =
            'বাসা/হোল্ডিং: ' .
            $home;
    }


    if (
        $village !== '' &&
        !isGarbageAddress($village)
    ) {

        $parts[] =
            'গ্রাম/রাস্তা: ' .
            $village;
    }


    if ($postOffice !== '') {

        $post =
            'ডাকঘর: ' .
            $postOffice;

        if ($postal !== '') {

            $post .=
                ' -' .
                convertToBangla(
                    $postal
                );
        }

        $parts[] =
            $post;
    }


    if (
        $upozila !== '' &&
        !isGarbageAddress($upozila)
    ) {

        $parts[] =
            $upozila;
    }


    if (
        $district !== '' &&
        !isGarbageAddress($district)
    ) {

        $parts[] =
            $district;
    }


    return implode(
        ', ',
        $parts
    );
}


/*=========================================================================

  ADDRESS BETWEEN

=========================================================================*/

function extractBetween(
    string $text,
    string $start,
    array $ends
): string
{
    $endPattern =
        implode(
            '|',
            array_map(
                fn($v) =>
                    preg_quote(
                        $v,
                        '/'
                    ),
                $ends
            )
        );

    $pattern =
        '/' .
        preg_quote(
            $start,
            '/'
        ) .
        '\s*(?:[:|])?\s*' .
        '(.*?)' .
        '(?=\s*(?:' .
        $endPattern .
        ')\b|$)' .
        '/isu';

    if (
        preg_match(
            $pattern,
            $text,
            $m
        )
    ) {

        return trim(
            $m[1]
        );
    }

    return '';
}


/*=========================================================================

  ADDRESS CLEAN

=========================================================================*/

function cleanAddressPart(string $value): string
{
    $value =
        cleanSimpleField(
            $value
        );

    $garbage = [

        'No',

        'No.',

        'No Documents Available',

        'Additional',

        'Union Porishod',

        'Union/Ward',

        'Mouza/Moholla',

        'Village/Road',

        'Home/Holding',

        'Post Office',

        'Postal Code',

        'Upozila',

        'Upazila',

        'District',

        'RMO'
    ];

    foreach ($garbage as $word) {

        $value =
            preg_replace(
                '/(?:^|[\s,])' .
                preg_quote(
                    $word,
                    '/'
                ) .
                '(?=$|[\s,])/iu',
                ' ',
                $value
            );
    }

    $value =
        preg_replace(
            '/\s*,\s*/u',
            ', ',
            $value
        );

    $value =
        preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

    return trim(
        $value,
        " ,.-"
    );
}


/*=========================================================================

  GARBAGE ADDRESS

=========================================================================*/

function isGarbageAddress(string $value): bool
{
    if ($value === '') {
        return true;
    }

    return (bool)preg_match(
        '/^(No|No\.|Additional|Union Porishod|RMO)$/iu',
        trim($value)
    );
}


/*=========================================================================

  POSTAL CODE

=========================================================================*/

function extractPostalCode(string $text): string
{
    if (
        preg_match(
            '/Postal\s*Code[^\d০-৯]*([0-9০-৯]{4})/iu',
            $text,
            $m
        )
    ) {

        return normalizeDigits(
            $m[1]
        );
    }

    return '';
}


/*=========================================================================

  BANGLA DIGITS

=========================================================================*/

function convertToBangla(string $number): string
{
    $en = [
        '0','1','2','3','4',
        '5','6','7','8','9'
    ];

    $bn = [
        '০','১','২','৩','৪',
        '৫','৬','৭','৮','৯'
    ];

    return str_replace(
        $en,
        $bn,
        $number
    );
}


/*=========================================================================
  IMAGE EXTRACTION
=========================================================================*/

function extractImages(
    string $pdfPath,
    string $imageDir,
    string $workDir
): array
{
    $result = [

        'userIMG' => '',

        'signIMG' => ''
    ];


    $unique =
        'nid_' .
        bin2hex(
            random_bytes(7)
        );


    /*
     * ---------------------------------------------------------------
     * METHOD 1
     * pdfimages -png
     * ---------------------------------------------------------------
     */

    $rawPrefix =
        $workDir .
        '/' .
        $unique .
        '_img';


    if (function_exists('exec')) {

        $command =
            'pdfimages -png -f 1 -l 1 ' .
            escapeshellarg($pdfPath) .
            ' ' .
            escapeshellarg($rawPrefix) .
            ' 2>/dev/null';

        @exec(
            $command
        );
    }


    $files =
        glob(
            $rawPrefix .
            '-*.png'
        );


    if (!$files) {

        $files = [];
    }


    usort(
        $files,
        function ($a, $b) {

            return filesize($b)
                <=>
                filesize($a);
        }
    );


    $photoCandidate = null;
    $signCandidate  = null;


    foreach ($files as $file) {

        if (!file_exists($file)) {
            continue;
        }

        $info =
            @getimagesize(
                $file
            );

        if (!$info) {
            continue;
        }

        $w =
            (int)$info[0];

        $h =
            (int)$info[1];


        if (
            $w < 40 ||
            $h < 30
        ) {
            continue;
        }


        $ratio =
            $w / max(
                1,
                $h
            );


        /*
         * PHOTO
         *
         * Accept square and portrait images.
         */

        if (
            $ratio >= 0.45 &&
            $ratio <= 1.35 &&
            $w >= 80 &&
            $h >= 80
        ) {

            if (
                $photoCandidate === null
            ) {

                $photoCandidate =
                    $file;
            }
        }


        /*
         * SIGNATURE
         */

        if (
            $ratio >= 1.70 &&
            $w >= 100 &&
            $h >= 25
        ) {

            if (
                $signCandidate === null
            ) {

                $signCandidate =
                    $file;
            }
        }
    }


    /*
     * ---------------------------------------------------------------
     * SAVE PHOTO
     * ---------------------------------------------------------------
     */

    if ($photoCandidate) {

        $photoName =
            'user_' .
            $unique .
            '.png';

        $photoPath =
            $imageDir .
            '/' .
            $photoName;


        if (
           convertImageToPng(
                $photoCandidate,
                $photoPath
            )
        ) {

            if (
                !isBlankOrSolidImage(
                    $photoPath
                )
            ) {

                $result['userIMG'] =
                    getImageUrl(
                        $photoName
                    );
            }
            else {

                @unlink(
                    $photoPath
                );
            }
        }
    }


    /*
     * ---------------------------------------------------------------
     * SAVE SIGNATURE
     * ---------------------------------------------------------------
     */

    if ($signCandidate) {

        $signName =
            'sign_' .
            $unique .
            '.png';

        $signPath =
            $imageDir .
            '/' .
            $signName;


        if (
            normalizeSignatureImage(
                $signCandidate,
                $signPath
            )
        ) {

            if (
                !isBlankOrSolidImage(
                    $signPath
                )
            ) {

                $result['signIMG'] =
                    getImageUrl(
                        $signName
                    );
            }
            else {

                @unlink(
                    $signPath
                );
            }
        }
    }


    /*
     * ---------------------------------------------------------------
     * METHOD 2
     * RENDER PAGE FALLBACK
     * ---------------------------------------------------------------
     */

    if (
        !$result['userIMG'] ||
        !$result['signIMG']
    ) {

        $rendered =
            renderFirstPage(
                $pdfPath,
                $workDir,
                $unique
            );

        if ($rendered) {

            $fallback =
                extractFromRenderedPage(
                    $rendered,
                    $imageDir,
                    $unique,
                    $result
                );

            $result =
                array_merge(
                    $result,
                    $fallback
                );
        }
    }


    /*
     * Cleanup raw files
     */

    foreach ($files as $file) {

        if (file_exists($file)) {
            @unlink($file);
        }
    }


    /*
     * IMPORTANT
     *
     * Do not return fake placeholder URL.
     */

    if (!$result['userIMG']) {
        $result['userIMG'] = '';
    }

    if (!$result['signIMG']) {
        $result['signIMG'] = '';
    }


    return $result;
}


/*=========================================================================

  IMAGE URL

=========================================================================*/

function getImageUrl(string $filename): string
{
    return
        getBaseUrl() .
        '/images/' .
        rawurlencode(
            $filename
        );
}


/*=========================================================================

  CONVERT IMAGE TO PNG

=========================================================================*/

function convertImageToPng(
    string $source,
    string $destination
): bool
{
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


    if (
        $w < 20 ||
        $h < 20
    ) {

        imagedestroy($img);

        return false;
    }


    $canvas =
        imagecreatetruecolor(
            $w,
            $h
        );

    imagealphablending(
        $canvas,
        true
    );

    imagesavealpha(
        $canvas,
        false
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


    $ok =
        @imagepng(
            $canvas,
            $destination,
            6
        );


    imagedestroy($img);
    imagedestroy($canvas);


    return $ok;
}


/*=========================================================================

  SIGNATURE NORMALIZE

=========================================================================*/

function normalizeSignatureImage(
    string $source,
    string $destination
): bool
{
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
        $w < 30 ||
        $h < 10
    ) {

        imagedestroy($src);

        return false;
    }


    /*
     * Convert to clean black/white.
     */

    $clean =
        imagecreatetruecolor(
            $w,
            $h
        );


    $white =
        imagecolorallocate(
            $clean,
            255,
            255,
            255
        );

    $black =
        imagecolorallocate(
            $clean,
            0,
            0,
            0
        );


    imagefill(
        $clean,
        0,
        0,
        $white
    );


    $minX = $w;
    $minY = $h;
    $maxX = -1;
    $maxY = -1;


    /*
     * Threshold
     */

    for (
        $y = 0;
        $y < $h;
        $y++
    ) {

        for (
            $x = 0;
            $x < $w;
            $x++
        ) {

            $rgb =
                imagecolorat(
                    $src,
                    $x,
                    $y
                );


            $r =
                ($rgb >> 16) &
                255;

            $g =
                ($rgb >> 8) &
                255;

            $b =
                $rgb &
                255;


            $gray =
                (int)(
                    0.299 * $r +
                    0.587 * $g +
                    0.114 * $b
                );


            /*
             * Signature ink
             */

            if ($gray < 185) {

                imagesetpixel(
                    $clean,
                    $x,
                    $y,
                    $black
                );


                if ($x < $minX) {
                    $minX = $x;
                }

                if ($y < $minY) {
                    $minY = $y;
                }

                if ($x > $maxX) {
                    $maxX = $x;
                }

                if ($y > $maxY) {
                    $maxY = $y;
                }
            }
        }
    }


    imagedestroy($src);


    if (
        $maxX < 0 ||
        $maxY < 0
    ) {

        imagedestroy($clean);

        return false;
    }


    $inkW =
        $maxX -
        $minX +
        1;

    $inkH =
        $maxY -
        $minY +
        1;


    if (
        $inkW < 15 ||
        $inkH < 4
    ) {

        imagedestroy($clean);

        return false;
    }


    /*
     * Padding
     */

    $paddingX =
        max(
            8,
            (int)($inkW * 0.08)
        );

    $paddingY =
        max(
            5,
            (int)($inkH * 0.20)
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

    $cropRight =
        min(
            $w - 1,
            $maxX + $paddingX
        );

    $cropBottom =
        min(
            $h - 1,
            $maxY + $paddingY
        );


    $cropW =
        $cropRight -
        $cropX +
        1;

    $cropH =
        $cropBottom -
        $cropY +
        1;


    $final =
        imagecreatetruecolor(
            $cropW,
            $cropH
        );


    imagefill(
        $final,
        0,
        0,
        $white
    );


    imagecopy(
        $final,
        $clean,
        0,
        0,
        $cropX,
        $cropY,
        $cropW,
        $cropH
    );


    $ok =
        @imagepng(
            $final,
            $destination,
            6
        );


    imagedestroy($final);
    imagedestroy($clean);


    return $ok;
}


/*=========================================================================

  BLANK IMAGE CHECK

=========================================================================*/

function isBlankOrSolidImage(
    string $filePath
): bool
{
    if (!file_exists($filePath)) {
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
        $w < 2 ||
        $h < 2
    ) {

        imagedestroy($img);

        return true;
    }


    $different = 0;
    $samples = 0;


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


    $first = null;


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
                ($rgb >> 16) &
                255;

            $g =
                ($rgb >> 8) &
                255;

            $b =
                $rgb &
                255;


            $gray =
                (
                    0.299 * $r +
                    0.587 * $g +
                    0.114 * $b
                );


            if ($first === null) {
                $first = $gray;
            }


            if (
                abs(
                    $first -
                    $gray
                ) > 20
            ) {

                $different++;
            }

            $samples++;
        }
    }


    imagedestroy($img);


    if ($samples === 0) {
        return true;
    }


    /*
     * If more than 1% differs,
     * image is not solid.
     */

    return
        ($different / $samples)
        < 0.01;
}


/*=========================================================================

  RENDER FIRST PAGE

=========================================================================*/

function renderFirstPage(
    string $pdfPath,
    string $workDir,
    string $unique
): ?string
{
    $output =
        $workDir .
        '/' .
        $unique .
        '_page.png';


    if (
        function_exists('exec')
    ) {

        $prefix =
            $workDir .
            '/' .
            $unique .
            '_render';


        $command =
            'pdftoppm ' .
            '-f 1 ' .
            '-l 1 ' .
            '-singlefile ' .
            '-png ' .
            '-r 180 ' .
            escapeshellarg($pdfPath) .
            ' ' .
            escapeshellarg($prefix) .
            ' 2>/dev/null';


        @exec(
            $command
        );


        $rendered =
            $prefix .
            '.png';


        if (
            file_exists(
                $rendered
            )
        ) {

            return $rendered;
        }
    }


    /*
     * Imagick fallback
     */

    if (
        extension_loaded('imagick')
    ) {

        try {

            $im =
                new Imagick();

            $im->setResolution(
                180,
                180
            );

            $im->readImage(
                $pdfPath .
                '[0]'
            );

            $im->setImageFormat(
                'png'
            );

            $im->writeImage(
                $output
            );

            $im->clear();
            $im->destroy();


            if (
                file_exists(
                    $output
                )
            ) {

                return $output;
            }

        }
        catch (Throwable $e) {
        }
    }


    return null;
}


/*=========================================================================

  RENDERED PAGE FALLBACK

=========================================================================*/

function extractFromRenderedPage(
    string $pagePath,
    string $imageDir,
    string $unique,
    array $current
): array
{
    $result = $current;


    $img =
        @imagecreatefrompng(
            $pagePath
        );

    if (!$img) {
        return $result;
    }


    $w =
        imagesx($img);

    $h =
        imagesy($img);


    /*
     * ---------------------------------------------------------------
     * PHOTO CANDIDATES
     * ---------------------------------------------------------------
     */

    if (!$result['userIMG']) {

        $photoCandidates = [

            [
                0.58,
                0.00,
                0.40,
                0.25
            ],

            [
                0.60,
                0.01,
                0.36,
                0.23
            ],

            [
                0.55,
                0.03,
                0.42,
                0.25
            ]
        ];


        foreach (
            $photoCandidates as $c
        ) {

            $rect = [

                'x' =>
                    (int)(
                        $w * $c[0]
                    ),

                'y' =>
                    (int)(
                        $h * $c[1]
                    ),

                'width' =>
                    (int)(
                        $w * $c[2]
                    ),

                'height' =>
                    (int)(
                        $h * $c[3]
                    )
            ];


            $crop =
                @imagecrop(
                    $img,
                    $rect
                );


            if (
                $crop === false
            ) {
                continue;
            }


            $path =
                $imageDir .
                '/user_' .
                $unique .
                '.png';


            imagepng(
                $crop,
                $path,
                6
            );


            imagedestroy(
                $crop
            );


            if (
                !isBlankOrSolidImage(
                    $path
                )
            ) {

                $result['userIMG'] =
                    getImageUrl(
                        'user_' .
                        $unique .
                        '.png'
                    );

                break;
            }


            @unlink($path);
        }
    }


    /*
     * ---------------------------------------------------------------
     * SIGNATURE CANDIDATES
     * ---------------------------------------------------------------
     */

    if (!$result['signIMG']) {

        $signCandidates = [

            [
                0.42,
                0.18,
                0.54,
                0.13
            ],

            [
                0.48,
                0.22,
                0.47,
                0.11
            ],

            [
                0.38,
                0.24,
                0.58,
                0.13
            ],

            [
                0.45,
                0.27,
                0.52,
                0.12
            ]
        ];


        foreach (
            $signCandidates as $c
        ) {

            $rect = [

                'x' =>
                    (int)(
                        $w * $c[0]
                    ),

                'y' =>
                    (int)(
                        $h * $c[1]
                    ),

                'width' =>
                    (int)(
                        $w * $c[2]
                    ),

                'height' =>
                    (int)(
                        $h * $c[3]
                    )
            ];


            $crop =
                @imagecrop(
                    $img,
                    $rect
                );


            if (
                $crop === false
            ) {
                continue;
            }


            $temp =
                $imageDir .
                '/sign_' .
                $unique .
                '_temp.png';


            imagepng(
                $crop,
                $temp,
                6
            );


            imagedestroy(
                $crop
            );


            $final =
                $imageDir .
                '/sign_' .
                $unique .
                '.png';


            if (
                normalizeSignatureImage(
                    $temp,
                    $final
                )
            ) {

                @unlink($temp);


                if (
                    !isBlankOrSolidImage(
                        $final
                    )
                ) {

                    $result['signIMG'] =
                        getImageUrl(
                            'sign_' .
                            $unique .
                            '.png'
                        );

                    break;
                }
            }


            @unlink($temp);
            @unlink($final);
        }
    }


    imagedestroy($img);


    return $result;
}
?>
