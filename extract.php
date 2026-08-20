<?php

// ============================================================
// NID PDF DATA EXTRACTION API
// Improved Text + Photo + Signature Extraction
// ============================================================

error_reporting(0);

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    return true;
});

ini_set('display_errors', '0');

ob_start();

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Dhaka');


// ============================================================
// REQUEST METHOD
// ============================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    ob_clean();

    http_response_code(405);

    echo json_encode(
        [
            'code'    => 405,
            'success' => false,
            'message' => 'Method Not Allowed'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// FIND UPLOADED PDF
// ============================================================

$fileKey = isset($_FILES['nid_pdf'])
    ? 'nid_pdf'
    : 'pdf';


if (
    !isset($_FILES[$fileKey]) ||
    $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK
) {

    ob_clean();

    http_response_code(400);

    echo json_encode(
        [
            'code'    => 400,
            'success' => false,
            'message' => 'No file uploaded or upload error occurred.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// FILE SIZE LIMIT
// ============================================================

$maxFileSize = 15 * 1024 * 1024; // 15 MB

if (
    isset($_FILES[$fileKey]['size']) &&
    $_FILES[$fileKey]['size'] > $maxFileSize
) {

    ob_clean();

    http_response_code(400);

    echo json_encode(
        [
            'code'    => 400,
            'success' => false,
            'message' => 'PDF file is too large. Maximum 15 MB allowed.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// TEMP DIRECTORY
// ============================================================

$uploadDir =
    sys_get_temp_dir() .
    '/nid_extract_' .
    uniqid('', true);


@mkdir(
    $uploadDir,
    0755,
    true
);


$pdfPath =
    $uploadDir .
    '/uploaded.pdf';


// ============================================================
// MOVE UPLOADED PDF
// ============================================================

if (
    !@move_uploaded_file(
        $_FILES[$fileKey]['tmp_name'],
        $pdfPath
    )
) {

    ob_clean();

    http_response_code(500);

    echo json_encode(
        [
            'code'    => 500,
            'success' => false,
            'message' => 'Failed to move uploaded file.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    removeDirectory($uploadDir);

    exit;
}


// ============================================================
// PDF MIME / EXTENSION CHECK
// ============================================================

$fileName =
    basename(
        $_FILES[$fileKey]['name'] ?? 'document.pdf'
    );

$extension =
    strtolower(
        pathinfo(
            $fileName,
            PATHINFO_EXTENSION
        )
    );


if ($extension !== 'pdf') {

    ob_clean();

    http_response_code(400);

    echo json_encode(
        [
            'code'    => 400,
            'success' => false,
            'message' => 'Invalid file type. Only PDF files are allowed.'
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    removeDirectory($uploadDir);

    exit;
}


// ============================================================
// MAIN PROCESS
// ============================================================

try {

    // --------------------------------------------------------
    // TEXT EXTRACTION
    // --------------------------------------------------------

    $textPath =
        $uploadDir .
        '/text.txt';


    $textCommand =
        'pdftotext -layout ' .
        escapeshellarg($pdfPath) .
        ' ' .
        escapeshellarg($textPath);


    if (function_exists('exec')) {
        @exec($textCommand);
    }


    $text = '';


    if (
        file_exists($textPath)
    ) {

        $text =
            @file_get_contents(
                $textPath
            );
    }


    // --------------------------------------------------------
    // FALLBACK: SMALOT PDF PARSER
    // --------------------------------------------------------

    if (
        trim($text) === '' &&
        file_exists(__DIR__ . '/vendor/autoload.php')
    ) {

        try {

            require_once __DIR__ . '/vendor/autoload.php';

            if (
                class_exists(
                    'Smalot\\PdfParser\\Parser'
                )
            ) {

                $parser =
                    new \Smalot\PdfParser\Parser();

                $pdf =
                    $parser->parseFile(
                        $pdfPath
                    );

                $text =
                    $pdf->getText();
            }

        } catch (Throwable $e) {

            // Continue
        }
    }


    // ========================================================
    // DATA EXTRACTION
    // ========================================================

    $nameBangla =
        extractNameBangla($text);


    $nameEnglish =
        extractNameEnglish($text);


    $nationalId =
        extractNid($text);


    $pin =
        extractPin($text);


    $dobRaw =
        extractBetween(
            $text,
            'Date of Birth',
            'Birth Place'
        );


    $dateOfBirth =
        formatDateOfBirth(
            $dobRaw
        );


    $fatherName =
        cleanText(
            extractBetween(
                $text,
                'Father Name',
                'Mother Name'
            )
        );


    $motherName =
        cleanText(
            extractBetween(
                $text,
                'Mother Name',
                'Spouse Name'
            )
        );


    $gender =
        cleanText(
            extractBetween(
                $text,
                'Gender',
                'Marital'
            )
        );


    if (!$gender) {

        $gender =
            findValueByLabel(
                'Gender',
                $text
            );
    }


    $religion =
        cleanText(
            extractBetween(
                $text,
                'Religion',
                'Religion Other'
            )
        );


    if (!$religion) {

        $religion =
            findValueByLabel(
                'Religion',
                $text
            );
    }


    $birthPlace =
        cleanText(
            extractBetween(
                $text,
                'Birth Place',
                'Birth Other'
            )
        );


    $bloodGroup =
        extractBloodGroup(
            $text
        );


    // ========================================================
    // IMAGE DIRECTORY
    // ========================================================

    $imgDir =
        __DIR__ .
        '/uploads';


    if (!is_dir($imgDir)) {

        @mkdir(
            $imgDir,
            0755,
            true
        );
    }


    // ========================================================
    // BASE URL
    // ========================================================

    $protocol =
        (
            isset($_SERVER['HTTPS']) &&
            (
                $_SERVER['HTTPS'] === 'on' ||
                $_SERVER['HTTPS'] == 1
            )
        )
        ? 'https'
        : 'http';


    $host =
        $_SERVER['HTTP_HOST'] ??
        'localhost';


    $baseUrl =
        $protocol .
        '://' .
        $host;


    $scriptDir =
        dirname(
            $_SERVER['SCRIPT_NAME'] ?? ''
        );


    $scriptDir =
        str_replace(
            '\\',
            '/',
            $scriptDir
        );


    $scriptDir =
        rtrim(
            $scriptDir,
            '/'
        );


    $uploadsUrl =
        $baseUrl .
        $scriptDir .
        '/uploads/';


    // ========================================================
    // IMAGE EXTRACTION
    // ========================================================

    $imageResult =
        extractImagesFromPdf(
            $pdfPath,
            $uploadDir,
            $imgDir
        );


    $userIMG = '';

    $signIMG = '';


    if (
        !empty(
            $imageResult['user']
        )
    ) {

        $userIMG =
            $uploadsUrl .
            $imageResult['user'];
    }


    if (
        !empty(
            $imageResult['signature']
        )
    ) {

        $signIMG =
            $uploadsUrl .
            $imageResult['signature'];
    }


    // ========================================================
    // TODAY
    // ========================================================

    $dateOfToday =
        convertToBangla(
            date('d-m-Y')
        );


    // ========================================================
    // ADDRESS
    // ========================================================

    $address =
        combineAddress(
            $text
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
            'code'    => 500,
            'success' => false,
            'message' => 'Error processing the PDF.'
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

} finally {

    // ========================================================
    // CLEAN TEMP DIRECTORY
    // ========================================================

    removeDirectory(
        $uploadDir
    );

}


if (ob_get_level()) {
    @ob_end_flush();
}

exit;


// ============================================================
// IMAGE EXTRACTION
// ============================================================

function extractImagesFromPdf(
    $pdfPath,
    $tempDir,
    $imgDir
) {

    $result = [

        'user' =>
            null,

        'signature' =>
            null
    ];


    $uniqueId =
        uniqid(
            'nid_',
            true
        );


    // ========================================================
    // METHOD 1: PDFIMAGES
    // ========================================================

    if (function_exists('exec')) {

        $prefix =
            $tempDir .
            '/raw_' .
            $uniqueId;


        $command =
            'pdfimages -png ' .
            escapeshellarg($pdfPath) .
            ' ' .
            escapeshellarg($prefix);


        @exec($command);


        $files =
            glob(
                $prefix .
                '-*.png'
            );


        if (
            !empty($files)
        ) {

            sort($files);


            foreach (
                $files as $file
            ) {

                if (
                    !file_exists($file)
                ) {
                    continue;
                }


                if (
                    @filesize($file) < 100
                ) {

                    @unlink($file);

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
                    $w <= 0 ||
                    $h <= 0
                ) {

                    @unlink($file);

                    continue;
                }


                $ratio =
                    $w / $h;


                // ------------------------------------------------
                // SIGNATURE
                // ------------------------------------------------

                if (
                    !$result['signature'] &&
                    $ratio >= 1.7
                ) {

                    $fileName =
                        'sign_' .
                        $uniqueId .
                        '.png';


                    $destination =
                        $imgDir .
                        '/' .
                        $fileName;


                    if (
                        normalizeSignatureImage(
                            $file,
                            $destination
                        )
                    ) {

                        $result['signature'] =
                            $fileName;


                        @unlink($file);

                        continue;
                    }


                    @unlink(
                        $destination
                    );
                }


                // ------------------------------------------------
                // USER PHOTO
                // ------------------------------------------------

                if (
                    !$result['user'] &&
                    $ratio < 1.7 &&
                    $h > ($w * 0.85)
                ) {

                    $fileName =
                        'user_' .
                        $uniqueId .
                        '.png';


                    $destination =
                        $imgDir .
                        '/' .
                        $fileName;


                    if (
                        @copy(
                            $file,
                            $destination
                        )
                    ) {

                        if (
                            !isBlankOrSolidImage(
                                $destination
                            )
                        ) {

                            $result['user'] =
                                $fileName;


                            @unlink($file);

                            continue;
                        }
                    }


                    @unlink(
                        $destination
                    );
                }


                @unlink($file);
            }
        }
    }


    // ========================================================
    // METHOD 2: SMALOT IMAGE OBJECTS
    // ========================================================

    if (
        (!$result['user'] ||
        !$result['signature']) &&
        file_exists(
            __DIR__ .
            '/vendor/autoload.php'
        )
    ) {

        try {

            require_once __DIR__ .
                '/vendor/autoload.php';


            if (
                class_exists(
                    'Smalot\\PdfParser\\Parser'
                )
            ) {

                $parser =
                    new \Smalot\PdfParser\Parser();


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

                    if (
                        !method_exists(
                            $object,
                            'getContent'
                        )
                    ) {

                        $index++;

                        continue;
                    }


                    $content =
                        $object->getContent();


                    if (
                        empty($content)
                    ) {

                        $index++;

                        continue;
                    }


                    $tmp =
                        $tempDir .
                        '/object_' .
                        $index .
                        '.img';


                    @file_put_contents(
                        $tmp,
                        $content
                    );


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


                    if (
                        $w <= 0 ||
                        $h <= 0
                    ) {

                        @unlink($tmp);

                        $index++;

                        continue;
                    }


                    $ratio =
                        $w / $h;


                    // ------------------------------------------------
                    // SIGNATURE
                    // ------------------------------------------------

                    if (
                        !$result['signature'] &&
                        $ratio >= 1.7
                    ) {

                        $fileName =
                            'sign_' .
                            $uniqueId .
                            '.png';


                        $destination =
                            $imgDir .
                            '/' .
                            $fileName;


                        if (
                            normalizeSignatureImage(
                                $tmp,
                                $destination
                            )
                        ) {

                            $result['signature'] =
                                $fileName;


                            @unlink($tmp);

                            $index++;

                            continue;
                        }


                        @unlink(
                            $destination
                        );
                    }


                    // ------------------------------------------------
                    // USER PHOTO
                    // ------------------------------------------------

                    if (
                        !$result['user'] &&
                        $ratio < 1.7 &&
                        $h > ($w * 0.85)
                    ) {

                        $fileName =
                            'user_' .
                            $uniqueId .
                            '.png';


                        $destination =
                            $imgDir .
                            '/' .
                            $fileName;


                        if (
                            @copy(
                                $tmp,
                                $destination
                            )
                        ) {

                            if (
                                !isBlankOrSolidImage(
                                    $destination
                                )
                            ) {

                                $result['user'] =
                                    $fileName;


                                @unlink($tmp);

                                $index++;

                                continue;
                            }
                        }


                        @unlink(
                            $destination
                        );
                    }


                    @unlink($tmp);

                    $index++;
                }
            }

        } catch (Throwable $e) {

            // Continue to fallback
        }
    }


    // ========================================================
    // METHOD 3: RENDER PAGE 1 + CROP
    // ========================================================

    if (
        !$result['user'] ||
        !$result['signature']
    ) {

        $rendered =
            renderPdfPageOne(
                $pdfPath,
                $tempDir,
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


                // ------------------------------------------------
                // USER PHOTO FALLBACK
                // ------------------------------------------------

                if (!$result['user']) {

                    $userRect = [

                        'x' =>
                            (int)($w * 0.60),

                        'y' =>
                            (int)($h * 0.005),

                        'width' =>
                            (int)($w * 0.36),

                        'height' =>
                            (int)($h * 0.22)
                    ];


                    $crop =
                        @imagecrop(
                            $img,
                            $userRect
                        );


                    if (
                        $crop !== false
                    ) {

                        $fileName =
                            'user_' .
                            $uniqueId .
                            '.png';


                        $destination =
                            $imgDir .
                            '/' .
                            $fileName;


                        @imagepng(
                            $crop,
                            $destination,
                            6
                        );


                        imagedestroy(
                            $crop
                        );


                        if (
                            !isBlankOrSolidImage(
                                $destination
                            )
                        ) {

                            $result['user'] =
                                $fileName;

                        } else {

                            @unlink(
                                $destination
                            );
                        }
                    }
                }


                // ------------------------------------------------
                // SIGNATURE FALLBACK
                // ------------------------------------------------

                if (!$result['signature']) {

                    $signRect = [

                        'x' =>
                            (int)($w * 0.50),

                        'y' =>
                            (int)($h * 0.25),

                        'width' =>
                            (int)($w * 0.45),

                        'height' =>
                            (int)($h * 0.07)
                    ];


                    $crop =
                        @imagecrop(
                            $img,
                            $signRect
                        );


                    if (
                        $crop !== false
                    ) {

                        $fileName =
                            'sign_' .
                            $uniqueId .
                            '.png';


                        $destination =
                            $imgDir .
                            '/' .
                            $fileName;


                        @imagepng(
                            $crop,
                            $destination,
                            6
                        );


                        imagedestroy(
                            $crop
                        );


                        if (
                            trimSignatureImage(
                                $destination,
                                $destination
                            ) &&
                            !isBlankOrSolidImage(
                                $destination
                            )
                        ) {

                            $result['signature'] =
                                $fileName;

                        } else {

                            @unlink(
                                $destination
                            );
                        }
                    }
                }


                imagedestroy($img);
            }


            @unlink($rendered);
        }
    }


    // ========================================================
    // PLACEHOLDER
    // ========================================================

    if (!$result['user']) {

        $result['user'] =
            createPlaceholderImage(
                'user',
                $imgDir
            );
    }


    if (!$result['signature']) {

        $result['signature'] =
            createPlaceholderImage(
                'signature',
                $imgDir
            );
    }


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


    // --------------------------------------------------------
    // REMOVE OUTER BORDER
    // --------------------------------------------------------

    $borderX =
        max(
            2,
            (int)($w * 0.05)
        );


    $borderY =
        max(
            2,
            (int)($h * 0.06)
        );


    $innerX =
        $borderX;


    $innerY =
        $borderY;


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


    // --------------------------------------------------------
    // BACKGROUND DETECTION
    // --------------------------------------------------------

    $points = [

        [0, 0],

        [$w - 1, 0],

        [0, $h - 1],

        [$w - 1, $h - 1],

        [(int)($w / 2), 0],

        [(int)($w / 2), $h - 1]
    ];


    $samples = [];


    foreach (
        $points as $point
    ) {

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


    // --------------------------------------------------------
    // CREATE CLEAN SIGNATURE
    // --------------------------------------------------------

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
                    $x + $innerX,
                    $y + $innerY
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
                    255 -
                    $gray;
            }


            if (
                $gray < 150
            ) {

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


    @imagepng(
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


    if (
        $w < 10 ||
        $h < 10
    ) {

        imagedestroy($img);

        return false;
    }


    $ignoreX =
        (int)($w * 0.04);


    $ignoreY =
        (int)($h * 0.04);


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

                if (
                    $x < $minX
                ) {
                    $minX = $x;
                }


                if (
                    $x > $maxX
                ) {
                    $maxX = $x;
                }


                if (
                    $y < $minY
                ) {
                    $minY = $y;
                }


                if (
                    $y > $maxY
                ) {
                    $maxY = $y;
                }
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
        $signatureHeight < 5
    ) {

        imagedestroy($img);

        return false;
    }


    $paddingX =
        15;


    $paddingY =
        10;


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


    $cropWidth =
        $cropRight -
        $cropX +
        1;


    $cropHeight =
        $cropBottom -
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


    @imagepng(
        $cropped,
        $destinationPath,
        6
    );


    imagedestroy($cropped);

    imagedestroy($img);


    return true;
}


// ============================================================
// RENDER FIRST PDF PAGE
// ============================================================

function renderPdfPageOne(
    $pdfPath,
    $tempDir,
    $uniqueId
) {

    $savePath =
        $tempDir .
        '/page_' .
        $uniqueId .
        '.png';


    $withoutExtension =
        $tempDir .
        '/page_' .
        $uniqueId;


    // --------------------------------------------------------
    // PDFTOPPM
    // --------------------------------------------------------

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
            escapeshellarg($withoutExtension);


        @exec($command);


        if (
            file_exists($savePath)
        ) {

            return $savePath;
        }
    }


    // --------------------------------------------------------
    // IMAGICK
    // --------------------------------------------------------

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
                $pdfPath .
                '[0]'
            );


            $im->setImageFormat(
                'png'
            );


            $im->writeImage(
                $savePath
            );


            $im->clear();

            $im->destroy();


            if (
                file_exists($savePath)
            ) {

                return $savePath;
            }

        } catch (Throwable $e) {

            // Continue
        }
    }


    return null;
}


// ============================================================
// CHECK BLANK / SOLID IMAGE
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


    $first =
        imagecolorat(
            $img,
            0,
            0
        );


    $firstGray =
        (
            (($first >> 16) & 255) +
            (($first >> 8) & 255) +
            ($first & 255)
        ) / 3;


    $hasDifference =
        false;


    $stepX =
        max(
            1,
            (int)($w / 12)
        );


    $stepY =
        max(
            1,
            (int)($h / 12)
        );


    for (
        $x = 0;
        $x < $w;
        $x += $stepX
    ) {

        for (
            $y = 0;
            $y < $h;
            $y += $stepY
        ) {

            $color =
                imagecolorat(
                    $img,
                    $x,
                    $y
                );


            $gray =
                (
                    (($color >> 16) & 255) +
                    (($color >> 8) & 255) +
                    ($color & 255)
                ) / 3;


            if (
                abs(
                    $firstGray -
                    $gray
                ) > 25
            ) {

                $hasDifference =
                    true;

                break 2;
            }
        }
    }


    imagedestroy($img);


    return !$hasDifference;
}


// ============================================================
// CREATE PLACEHOLDER
// ============================================================

function createPlaceholderImage(
    $type,
    $imgDir
) {

    $fileName =
        'placeholder_' .
        $type .
        '.png';


    $filePath =
        $imgDir .
        '/' .
        $fileName;


    if (
        !file_exists($filePath)
    ) {

        if (
            function_exists(
                'imagecreatetruecolor'
            )
        ) {

            $im =
                imagecreatetruecolor(
                    150,
                    150
                );


            $bg =
                imagecolorallocate(
                    $im,
                    240,
                    240,
                    240
                );


            $textColor =
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
                $bg
            );


            $text =
                $type === 'user'
                    ? 'User Photo'
                    : 'Signature';


            imagestring(
                $im,
                3,
                30,
                65,
                $text,
                $textColor
            );


            @imagepng(
                $im,
                $filePath
            );


            imagedestroy($im);
        }
    }


    return $fileName;
}


// ============================================================
// EXTRACT NAME BANGLA
// ============================================================

function extractNameBangla(
    $text
) {

    $value =
        extractBetween(
            $text,
            'Name(Bangla)',
            'Name(English)'
        );


    if (!$value) {

        $value =
            extractBetween(
                $text,
                'Name (Bangla)',
                'Name (English)'
            );
    }


    return cleanBanglaName(
        $value
    );
}


// ============================================================
// EXTRACT NAME ENGLISH
// ============================================================

function extractNameEnglish(
    $text
) {

    $value =
        extractBetween(
            $text,
            'Name(English)',
            'Date of Birth'
        );


    if (!$value) {

        $value =
            extractBetween(
                $text,
                'Name (English)',
                'Date of Birth'
            );
    }


    return strtoupper(
        cleanText($value)
    );
}


// ============================================================
// CLEAN BANGLA NAME
// ============================================================

function cleanBanglaName(
    $text
) {

    $text =
        preg_replace(
            '/halnagad_\d+/iu',
            '',
            $text
        );


    $text =
        preg_replace(
            '/Tag/iu',
            '',
            $text
        );


    $text =
        preg_replace(
            '/Name\s*\(\s*Bangla\s*\)/iu',
            '',
            $text
        );


    return cleanText($text);
}


// ============================================================
// CLEAN TEXT
// ============================================================

function cleanText(
    $text
) {

    if (
        !$text
    ) {
        return '';
    }


    $text =
        str_replace(
            [
                '"',
                ',',
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
// EXTRACT BETWEEN
// ============================================================

function extractBetween(
    $text,
    $start,
    $end
) {

    $pattern =
        '/' .
        preg_quote(
            $start,
            '/'
        ) .
        '(.*?)' .
        preg_quote(
            $end,
            '/'
        ) .
        '/isu';


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
// FIND VALUE BY LABEL
// ============================================================

function findValueByLabel(
    $label,
    $text
) {

    $pattern =
        '/' .
        preg_quote(
            $label,
            '/'
        ) .
        '[\s\|:]+' .
        '([^\r\n\|]+)/iu';


    if (
        preg_match(
            $pattern,
            $text,
            $matches
        )
    ) {

        return cleanText(
            $matches[1]
        );
    }


    return '';
}


// ============================================================
// EXTRACT NID
// ============================================================

function extractNid(
    $text
) {

    if (
        preg_match(
            '/National\s*ID[^\d০-৯]*([0-9০-৯]{10,17})/iu',
            $text,
            $m
        )
    ) {

        return normalizeDigits(
            $m[1]
        );
    }


    $value =
        findValueByLabel(
            'National ID',
            $text
        );


    return normalizeDigits(
        $value
    );
}


// ============================================================
// EXTRACT PIN
// ============================================================

function extractPin(
    $text
) {

    if (
        preg_match(
            '/Pin[^\d০-৯]*([0-9০-৯]{10,17})/iu',
            $text,
            $m
        )
    ) {

        return normalizeDigits(
            $m[1]
        );
    }


    $value =
        findValueByLabel(
            'Pin',
            $text
        );


    return normalizeDigits(
        $value
    );
}


// ============================================================
// NORMALIZE DIGITS
// ============================================================

function normalizeDigits(
    $value
) {

    $bn =
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
        ];


    $en =
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
        ];


    return str_replace(
        $bn,
        $en,
        trim($value)
    );
}


// ============================================================
// EXTRACT BLOOD GROUP
// ============================================================

function extractBloodGroup(
    $text
) {

    $value =
        findValueByLabel(
            'Blood Group',
            $text
        );


    if (
        preg_match(
            '/\b(A|B|AB|O)\s*([+-])\b/iu',
            $value,
            $m
        )
    ) {

        return strtoupper(
            $m[1] .
            $m[2]
        );
    }


    if (
        preg_match(
            '/\b(A|B|AB|O)\s*([+-])\b/iu',
            $text,
            $m
        )
    ) {

        return strtoupper(
            $m[1] .
            $m[2]
        );
    }


    return '';
}


// ============================================================
// FORMAT DATE OF BIRTH
// ============================================================

function formatDateOfBirth(
    $raw
) {

    $raw =
        cleanText($raw);


    if (!$raw) {
        return '';
    }


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


    // fallback: dd-mm-yyyy
    if (
        preg_match(
            '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/',
            $raw,
            $m
        )
    ) {

        $timestamp =
            @strtotime(
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


// ============================================================
// EXTRACT POSTAL CODE
// ============================================================

function extractPostalCode(
    $text
) {

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


    if (
        preg_match(
            '/পোস্ট\s*কোড[^\d০-৯]*([0-9০-৯]{4})/u',
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


// ============================================================
// CONVERT TO BANGLA DIGITS
// ============================================================

function convertToBangla(
    $number
) {

    $en =
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
        ];


    $bn =
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
        ];


    return str_replace(
        $en,
        $bn,
        $number
    );
}


// ============================================================
// COMBINE ADDRESS
// ============================================================

function combineAddress(
    $fullText
) {

    // --------------------------------------------------------
    // PRESENT ADDRESS BLOCK
    // --------------------------------------------------------

    $text =
        $fullText;


    if (
        preg_match(
            '/Present\s*Address(.*?)(?:Permanent\s*Address|$)/isu',
            $fullText,
            $m
        )
    ) {

        $text =
            $m[1];
    }


    // --------------------------------------------------------
    // VILLAGE
    // --------------------------------------------------------

    $villageRaw =
        extractBetween(
            $text,
            'Village/Road',
            'Home/Holding'
        );


    if (!$villageRaw) {

        $villageRaw =
            extractBetween(
                $text,
                'Village/Road',
                'Post Office'
            );
    }


    if (!$villageRaw) {

        $villageRaw =
            extractBetween(
                $text,
                'Mouza/Moholla',
                'Post Office'
            );
    }


    if (!$villageRaw) {

        $villageRaw =
            extractBetween(
                $text,
                'Mouza/Moholla',
                'Home/Holding'
            );
    }


    $village =
        str_ireplace(
            [
                'Village/Road',
                'Home/Holding',
                'Additional',
                'No.',
                'No',
                'Union/Ward',
                'Mouza/Moholla'
            ],
            '',
            $villageRaw
        );


    $village =
        cleanText(
            $village
        );


    // --------------------------------------------------------
    // HOME / HOLDING
    // --------------------------------------------------------

    $homeRaw =
        extractBetween(
            $text,
            'Home/Holding',
            'Post Office'
        );


    if (!$homeRaw) {

        $homeRaw =
            extractBetween(
                $text,
                'Home/Holding',
                'Postal Code'
            );
    }


    $home =
        str_ireplace(
            [
                'Home/Holding',
                'Village/Road',
                'Additional',
                'No.',
                'No',
                'Union/Ward'
            ],
            '',
            $homeRaw
        );


    $home =
        cleanText(
            $home
        );


    // --------------------------------------------------------
    // POST OFFICE
    // --------------------------------------------------------

    $postOffice =
        cleanText(
            extractBetween(
                $text,
                'Post Office',
                'Postal Code'
            )
        );


    if (!$postOffice) {

        $postOffice =
            cleanText(
                extractBetween(
                    $text,
                    'Post Office',
                    'Upozila'
                )
            );
    }


    // --------------------------------------------------------
    // POSTAL CODE
    // --------------------------------------------------------

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


    $postalCodeBangla =
        convertToBangla(
            $postalCode
        );


    // --------------------------------------------------------
    // UPOZILA
    // --------------------------------------------------------

    $upozila =
        cleanText(
            extractBetween(
                $text,
                'Upozila',
                'Union'
            )
        );


    if (!$upozila) {

        $upozila =
            cleanText(
                extractBetween(
                    $text,
                    'Upozila',
                    'Municipality'
                )
            );
    }


    if (!$upozila) {

        $upozila =
            cleanText(
                extractBetween(
                    $text,
                    'Upozila',
                    'District'
                )
            );
    }


    // --------------------------------------------------------
    // DISTRICT
    // --------------------------------------------------------

    $district =
        cleanText(
            extractBetween(
                $text,
                'District',
                'RMO'
            )
        );


    if (!$district) {

        $district =
            cleanText(
                extractBetween(
                    $text,
                    'District',
                    'City'
                )
            );
    }


    // --------------------------------------------------------
    // FINAL ADDRESS
    // --------------------------------------------------------

    $parts = [];


    if (
        !empty($home) &&
        $home !== 'Additional' &&
        stripos($home, 'No') === false
    ) {

        $parts[] =
            'বাসা/হোল্ডিং: ' .
            $home;
    }


    if (
        !empty($village) &&
        $village !== 'Additional' &&
        stripos($village, 'No') === false
    ) {

        $parts[] =
            'গ্রাম/রাস্তা: ' .
            $village;
    }


    if (
        !empty($postOffice)
    ) {

        $postOffice =
            str_ireplace(
                [
                    'Post Office',
                    'Postal Code'
                ],
                '',
                $postOffice
            );


        $postOffice =
            trim($postOffice);


        $postPart =
            'ডাকঘর: ' .
            $postOffice;


        if (
            !empty($postalCodeBangla)
        ) {

            $postPart .=
                ' - ' .
                $postalCodeBangla;
        }


        $parts[] =
            $postPart;
    }


    if (
        !empty($upozila)
    ) {

        $parts[] =
            $upozila;
    }


    if (
        !empty($district) &&
        $district !== 'RMO'
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
// REMOVE TEMP DIRECTORY
// ============================================================

function removeDirectory(
    $directory
) {

    if (
        !is_dir($directory)
    ) {
        return;
    }


    $items =
        @scandir(
            $directory
        );


    if (!$items) {
        @rmdir($directory);
        return;
    }


    foreach (
        $items as $item
    ) {

        if (
            $item === '.' ||
            $item === '..'
        ) {
            continue;
        }


        $path =
            $directory .
            '/' .
            $item;


        if (
            is_dir($path)
        ) {

            removeDirectory(
                $path
            );

        } else {

            @unlink($path);
        }
    }


    @rmdir(
        $directory
    );
}

?>
