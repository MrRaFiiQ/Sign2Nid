<?php

// ============================================================
// NID PDF EXTRACTION API
// Robust Version
//
// Supports:
// - nid_pdf / pdf upload field
// - pdftotext
// - pdfimages
// - pdftoppm
// - Smalot PDF Parser fallback
// - GD optional
// - Imagick optional
// - Photo / Signature detection
// - Signature cleanup when GD is available
// - Page crop fallback
// - Dependency diagnostics
// ============================================================


error_reporting(0);

ini_set('display_errors', '0');

ob_start();

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Dhaka');


// ============================================================
// ERROR HANDLER
// ============================================================

set_error_handler(function ($errno, $errstr, $errfile, $errline) {

    return true;

});


// ============================================================
// RESPONSE HELPER
// ============================================================

function jsonResponse(
    $data,
    $httpCode = 200
) {

    while (ob_get_level()) {
        @ob_end_clean();
    }

    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


// ============================================================
// REQUEST METHOD
// ============================================================

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
) {

    jsonResponse(
        [
            'code'    => 405,
            'success' => false,
            'message' => 'Method Not Allowed'
        ],
        405
    );
}


// ============================================================
// UPLOAD FIELD
// ============================================================

$fileKey =
    isset($_FILES['nid_pdf'])
        ? 'nid_pdf'
        : 'pdf';


if (
    !isset($_FILES[$fileKey])
) {

    jsonResponse(
        [
            'code'    => 400,
            'success' => false,
            'message' => 'No PDF file uploaded.'
        ],
        400
    );
}


if (
    $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK
) {

    jsonResponse(
        [
            'code'    => 400,
            'success' => false,
            'message' =>
                uploadErrorMessage(
                    $_FILES[$fileKey]['error']
                )
        ],
        400
    );
}


// ============================================================
// FILE SIZE
// ============================================================

$maxFileSize =
    15 * 1024 * 1024;


if (
    !empty($_FILES[$fileKey]['size']) &&
    $_FILES[$fileKey]['size'] > $maxFileSize
) {

    jsonResponse(
        [
            'code'    => 400,
            'success' => false,
            'message' =>
                'Maximum PDF size is 15 MB.'
        ],
        400
    );
}


// ============================================================
// ORIGINAL FILE NAME
// ============================================================

$originalName =
    basename(
        $_FILES[$fileKey]['name'] ??
        'document.pdf'
    );


$extension =
    strtolower(
        pathinfo(
            $originalName,
            PATHINFO_EXTENSION
        )
    );


if (
    $extension !== 'pdf'
) {

    jsonResponse(
        [
            'code'    => 400,
            'success' => false,
            'message' =>
                'Invalid file type. Only PDF files are allowed.'
        ],
        400
    );
}


// ============================================================
// TEMP DIRECTORY
// ============================================================

$tempDir =
    sys_get_temp_dir() .
    '/nid_extract_' .
    bin2hex(
        random_bytes(8)
    );


if (
    !@mkdir(
        $tempDir,
        0755,
        true
    )
) {

    jsonResponse(
        [
            'code'    => 500,
            'success' => false,
            'message' =>
                'Unable to create temporary directory.'
        ],
        500
    );
}


$pdfPath =
    $tempDir .
    '/uploaded.pdf';


// ============================================================
// MOVE UPLOADED FILE
// ============================================================

if (
    !@move_uploaded_file(
        $_FILES[$fileKey]['tmp_name'],
        $pdfPath
    )
) {

    removeDirectory($tempDir);

    jsonResponse(
        [
            'code'    => 500,
            'success' => false,
            'message' =>
                'Failed to move uploaded PDF.'
        ],
        500
    );
}


// ============================================================
// BASIC PDF SIGNATURE CHECK
// ============================================================

$header =
    @file_get_contents(
        $pdfPath,
        false,
        null,
        0,
        5
    );


if (
    $header !== '%PDF-'
) {

    removeDirectory($tempDir);

    jsonResponse(
        [
            'code'    => 400,
            'success' => false,
            'message' =>
                'Uploaded file is not a valid PDF.'
        ],
        400
    );
}


// ============================================================
// IMAGE DIRECTORY
// ============================================================

$imgDir =
    __DIR__ .
    '/uploads';


if (
    !is_dir($imgDir)
) {

    @mkdir(
        $imgDir,
        0755,
        true
    );
}


if (
    !is_dir($imgDir) ||
    !is_writable($imgDir)
) {

    removeDirectory($tempDir);

    jsonResponse(
        [
            'code'    => 500,
            'success' => false,
            'message' =>
                'uploads directory does not exist or is not writable.',
            'path' =>
                $imgDir
        ],
        500
    );
}


// ============================================================
// DEPENDENCY INFORMATION
// ============================================================

$dependencies =
    getDependencyStatus();


// ============================================================
// MAIN PROCESS
// ============================================================

try {

    // ========================================================
    // TEXT EXTRACTION
    // ========================================================

    $text =
        extractPdfText(
            $pdfPath,
            $tempDir
        );


    if (
        trim($text) === ''
    ) {

        removeDirectory($tempDir);

        jsonResponse(
            [
                'code'    => 500,
                'success' => false,
                'message' =>
                    'Unable to extract text from the PDF.',
                'dependencies' =>
                    $dependencies
            ],
            500
        );
    }


    // ========================================================
    // DATA
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


    if (
        !$gender
    ) {

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


    if (
        !$religion
    ) {

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


    if (
        !$birthPlace
    ) {

        $birthPlace =
            findValueByLabel(
                'Birth Place',
                $text
            );
    }


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
    // IMAGE EXTRACTION
    // ========================================================

    $images =
        extractImagesFromPdf(
            $pdfPath,
            $tempDir,
            $imgDir
        );


    // ========================================================
    // URL
    // ========================================================

    $uploadsUrl =
        getUploadsUrl();


    $userIMG =
        '';


    $signIMG =
        '';


    if (
        !empty($images['user'])
    ) {

        $userIMG =
            $uploadsUrl .
            $images['user'];
    }


    if (
        !empty($images['signature'])
    ) {

        $signIMG =
            $uploadsUrl .
            $images['signature'];
    }


    // ========================================================
    // TODAY
    // ========================================================

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
        ],

        'system' => [

            'gd' =>
                $dependencies['gd'],

            'imagick' =>
                $dependencies['imagick'],

            'pdftotext' =>
                $dependencies['pdftotext'],

            'pdfimages' =>
                $dependencies['pdfimages'],

            'pdftoppm' =>
                $dependencies['pdftoppm']
        ]
    ];


    removeDirectory(
        $tempDir
    );


    jsonResponse(
        $response,
        200
    );


} catch (Throwable $e) {

    $errorMessage =
        $e->getMessage();


    $errorFile =
        basename(
            $e->getFile()
        );


    $errorLine =
        $e->getLine();


    removeDirectory(
        $tempDir
    );


    jsonResponse(
        [
            'code' =>
                500,

            'success' =>
                false,

            'message' =>
                'Error processing the PDF.',

            'error' =>
                $errorMessage,

            'file' =>
                $errorFile,

            'line' =>
                $errorLine,

            'dependencies' =>
                $dependencies
        ],
        500
    );
}


// ============================================================
// GET DEPENDENCY STATUS
// ============================================================

function getDependencyStatus()
{

    return [

        'gd' =>
            function_exists(
                'imagecreatetruecolor'
            ),

        'imagick' =>
            extension_loaded(
                'imagick'
            ),

        'pdftotext' =>
            commandExists(
                'pdftotext'
            ),

        'pdfimages' =>
            commandExists(
                'pdfimages'
            ),

        'pdftoppm' =>
            commandExists(
                'pdftoppm'
            ),

        'curl' =>
            function_exists(
                'curl_init'
            )
    ];
}


// ============================================================
// CHECK COMMAND
// ============================================================

function commandExists(
    $command
)
{

    if (
        !function_exists('exec')
    ) {
        return false;
    }


    $output = [];

    $returnCode = 1;


    @exec(
        'command -v ' .
        escapeshellarg($command) .
        ' 2>/dev/null',
        $output,
        $returnCode
    );


    return
        $returnCode === 0 &&
        !empty($output);
}


// ============================================================
// EXTRACT PDF TEXT
// ============================================================

function extractPdfText(
    $pdfPath,
    $tempDir
)
{

    $text = '';


    // ========================================================
    // METHOD 1: PDFTOTEXT
    // ========================================================

    if (
        commandExists('pdftotext')
    ) {

        $textPath =
            $tempDir .
            '/text.txt';


        $command =
            'pdftotext ' .
            '-layout ' .
            '-enc UTF-8 ' .
            escapeshellarg($pdfPath) .
            ' ' .
            escapeshellarg($textPath) .
            ' 2>/dev/null';


        @exec($command);


        if (
            file_exists($textPath)
        ) {

            $text =
                @file_get_contents(
                    $textPath
                );
        }
    }


    // ========================================================
    // METHOD 2: SMALOT
    // ========================================================

    if (
        trim($text) === ''
    ) {

        $autoload =
            __DIR__ .
            '/vendor/autoload.php';


        if (
            file_exists($autoload)
        ) {

            try {

                require_once $autoload;


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
    }


    return $text;
}


// ============================================================
// EXTRACT IMAGES
// ============================================================

function extractImagesFromPdf(
    $pdfPath,
    $tempDir,
    $imgDir
)
{

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

    if (
        commandExists('pdfimages')
    ) {

        $prefix =
            $tempDir .
            '/raw_' .
            $uniqueId;


        $command =
            'pdfimages ' .
            '-png ' .
            escapeshellarg($pdfPath) .
            ' ' .
            escapeshellarg($prefix) .
            ' 2>/dev/null';


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

                processExtractedImage(
                    $file,
                    $result,
                    $imgDir,
                    $uniqueId
                );


                if (
                    $result['user'] &&
                    $result['signature']
                ) {

                    break;
                }
            }


            foreach (
                $files as $file
            ) {

                if (
                    file_exists($file)
                ) {

                    @unlink($file);
                }
            }
        }
    }


    // ========================================================
    // METHOD 2: SMALOT IMAGE OBJECTS
    // ========================================================

    if (
        !$result['user'] ||
        !$result['signature']
    ) {

        processSmalotImages(
            $pdfPath,
            $tempDir,
            $imgDir,
            $result,
            $uniqueId
        );
    }


    // ========================================================
    // METHOD 3: PAGE RENDER FALLBACK
    // ========================================================

    if (
        !$result['user'] ||
        !$result['signature']
    ) {

        processRenderedPage(
            $pdfPath,
            $tempDir,
            $imgDir,
            $result,
            $uniqueId
        );
    }


    // ========================================================
    // PLACEHOLDERS
    // ========================================================

    if (
        !$result['user']
    ) {

        $result['user'] =
            createPlaceholderImage(
                'user',
                $imgDir
            );
    }


    if (
        !$result['signature']
    ) {

        $result['signature'] =
            createPlaceholderImage(
                'signature',
                $imgDir
            );
    }


    return $result;
}


// ============================================================
// PROCESS EXTRACTED IMAGE
// ============================================================

function processExtractedImage(
    $file,
    &$result,
    $imgDir,
    $uniqueId
)
{

    if (
        !file_exists($file)
    ) {
        return;
    }


    if (
        @filesize($file) < 100
    ) {
        return;
    }


    $size =
        @getimagesize(
            $file
        );


    if (!$size) {
        return;
    }


    $w =
        (int)$size[0];


    $h =
        (int)$size[1];


    if (
        $w <= 0 ||
        $h <= 0
    ) {
        return;
    }


    $ratio =
        $w / $h;


    // ========================================================
    // SIGNATURE
    // ========================================================

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
            function_exists(
                'imagecreatefromstring'
            )
        ) {

            if (
                normalizeSignatureImage(
                    $file,
                    $destination
                )
            ) {

                $result['signature'] =
                    $fileName;

                return;
            }

        } else {

            // GD unavailable:
            // copy original signature image

            if (
                @copy(
                    $file,
                    $destination
                )
            ) {

                $result['signature'] =
                    $fileName;

                return;
            }
        }


        @unlink(
            $destination
        );
    }


    // ========================================================
    // USER PHOTO
    // ========================================================

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
                !function_exists(
                    'imagecreatefromstring'
                ) ||
                !isBlankOrSolidImage(
                    $destination
                )
            ) {

                $result['user'] =
                    $fileName;

                return;
            }
        }


        @unlink(
            $destination
        );
    }
}


// ============================================================
// SMALOT IMAGES
// ============================================================

function processSmalotImages(
    $pdfPath,
    $tempDir,
    $imgDir,
    &$result,
    $uniqueId
)
{

    $autoload =
        __DIR__ .
        '/vendor/autoload.php';


    if (
        !file_exists($autoload)
    ) {
        return;
    }


    try {

        require_once $autoload;


        if (
            !class_exists(
                'Smalot\\PdfParser\\Parser'
            )
        ) {
            return;
        }


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


            processExtractedImage(
                $tmp,
                $result,
                $imgDir,
                $uniqueId .
                '_' .
                $index
            );


            @unlink($tmp);


            if (
                $result['user'] &&
                $result['signature']
            ) {
                break;
            }


            $index++;
        }

    } catch (Throwable $e) {

        // Ignore and use next fallback
    }
}


// ============================================================
// RENDER PAGE
// ============================================================

function processRenderedPage(
    $pdfPath,
    $tempDir,
    $imgDir,
    &$result,
    $uniqueId
)
{

    $rendered =
        renderPdfPageOne(
            $pdfPath,
            $tempDir,
            $uniqueId
        );


    if (
        !$rendered ||
        !file_exists($rendered)
    ) {
        return;
    }


    // GD is required for crop
    if (
        !function_exists(
            'imagecreatefrompng'
        )
    ) {

        @unlink($rendered);

        return;
    }


    $img =
        @imagecreatefrompng(
            $rendered
        );


    if (!$img) {

        @unlink($rendered);

        return;
    }


    $w =
        imagesx($img);


    $h =
        imagesy($img);


    // ========================================================
    // USER PHOTO CROP
    // ========================================================

    if (
        !$result['user']
    ) {

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


    // ========================================================
    // SIGNATURE CROP
    // ========================================================

    if (
        !$result['signature']
    ) {

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


    imagedestroy(
        $img
    );


    @unlink(
        $rendered
    );
}


// ============================================================
// RENDER PDF PAGE 1
// ============================================================

function renderPdfPageOne(
    $pdfPath,
    $tempDir,
    $uniqueId
)
{

    $savePath =
        $tempDir .
        '/page_' .
        $uniqueId .
        '.png';


    $prefix =
        $tempDir .
        '/page_' .
        $uniqueId;


    // ========================================================
    // PDFTOPPM
    // ========================================================

    if (
        commandExists('pdftoppm')
    ) {

        $command =
            'pdftoppm ' .
            '-f 1 ' .
            '-singlefile ' .
            '-png ' .
            '-r 150 ' .
            escapeshellarg($pdfPath) .
            ' ' .
            escapeshellarg($prefix) .
            ' 2>/dev/null';


        @exec($command);


        if (
            file_exists($savePath)
        ) {

            return $savePath;
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

            // Ignore
        }
    }


    return null;
}


// ============================================================
// NORMALIZE SIGNATURE
// ============================================================

function normalizeSignatureImage(
    $sourcePath,
    $destinationPath
)
{

    if (
        !function_exists(
            'imagecreatefromstring'
        )
    ) {

        return false;
    }


    if (
        !file_exists($sourcePath)
    ) {

        return false;
    }


    $data =
        @file_get_contents(
            $sourcePath
        );


    if (
        $data === false
    ) {

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
    // BACKGROUND
    // ========================================================

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
        $points as $p
    ) {

        $rgb =
            imagecolorat(
                $src,
                $p[0],
                $p[1]
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
        count($samples);


    $invert =
        $background < 110;


    // ========================================================
    // PIXEL PROCESS
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


    imagedestroy(
        $src
    );


    @imagepng(
        $canvas,
        $destinationPath,
        6
    );


    imagedestroy(
        $canvas
    );


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
)
{

    if (
        !function_exists(
            'imagecreatefrompng'
        )
    ) {

        return false;
    }


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


    imagedestroy(
        $cropped
    );


    imagedestroy(
        $img
    );


    return true;
}


// ============================================================
// BLANK IMAGE CHECK
// ============================================================

function isBlankOrSolidImage(
    $filePath
)
{

    if (
        !function_exists(
            'imagecreatefromstring'
        )
    ) {

        // Cannot inspect without GD.
        // Do not reject the image.
        return false;
    }


    if (
        !file_exists($filePath)
    ) {

        return true;
    }


    $data =
        @file_get_contents(
            $filePath
        );


    if (
        $data === false
    ) {

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


    $hasDiff =
        false;


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

                $hasDiff =
                    true;

                break 2;
            }
        }
    }


    imagedestroy(
        $img
    );


    return !$hasDiff;
}


// ============================================================
// PLACEHOLDER
// ============================================================

function createPlaceholderImage(
    $type,
    $imgDir
)
{

    $fileName =
        'placeholder_' .
        $type .
        '.png';


    $filePath =
        $imgDir .
        '/' .
        $fileName;


    if (
        file_exists($filePath)
    ) {

        return $fileName;
    }


    // ========================================================
    // GD AVAILABLE
    // ========================================================

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


        imagedestroy(
            $im
        );


        return $fileName;
    }


    // ========================================================
    // GD MISSING
    // ========================================================

    // Return empty string instead of causing API crash.
    return '';
}


// ============================================================
// NAME BANGLA
// ============================================================

function extractNameBangla(
    $text
)
{

    $value =
        extractBetween(
            $text,
            'Name(Bangla)',
            'Name(English)'
        );


    if (
        !$value
    ) {

        $value =
            extractBetween(
                $text,
                'Name (Bangla)',
                'Name (English)'
            );
    }


    $value =
        preg_replace(
            '/halnagad_\d+/iu',
            '',
            $value
        );


    $value =
        preg_replace(
            '/Tag/iu',
            '',
            $value
        );


    return cleanText(
        $value
    );
}


// ============================================================
// NAME ENGLISH
// ============================================================

function extractNameEnglish(
    $text
)
{

    $value =
        extractBetween(
            $text,
            'Name(English)',
            'Date of Birth'
        );


    if (
        !$value
    ) {

        $value =
            extractBetween(
                $text,
                'Name (English)',
                'Date of Birth'
            );
    }


    return strtoupper(
        cleanText(
            $value
        )
    );
}


// ============================================================
// EXTRACT NID
// ============================================================

function extractNid(
    $text
)
{

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


    if (
        preg_match(
            '/[0-9০-৯]{10,17}/u',
            $value,
            $m
        )
    ) {

        return normalizeDigits(
            $m[0]
        );
    }


    return '';
}


// ============================================================
// EXTRACT PIN
// ============================================================

function extractPin(
    $text
)
{

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


    if (
        preg_match(
            '/[0-9০-৯]{10,17}/u',
            $value,
            $m
        )
    ) {

        return normalizeDigits(
            $m[0]
        );
    }


    return '';
}


// ============================================================
// NORMALIZE DIGITS
// ============================================================

function normalizeDigits(
    $value
)
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
        trim($value)
    );
}


// ============================================================
// BLOOD GROUP
// ============================================================

function extractBloodGroup(
    $text
)
{

    $value =
        findValueByLabel(
            'Blood Group',
            $text
        );


    if (
        preg_match(
            '/\b(AB|A|B|O)\s*([+-])\b/iu',
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
            '/\b(AB|A|B|O)\s*([+-])\b/iu',
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
// FIND LABEL VALUE
// ============================================================

function findValueByLabel(
    $label,
    $text
)
{

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
// EXTRACT BETWEEN
// ============================================================

function extractBetween(
    $text,
    $start,
    $end
)
{

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
// CLEAN TEXT
// ============================================================

function cleanText(
    $text
)
{

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
// DATE OF BIRTH
// ============================================================

function formatDateOfBirth(
    $raw
)
{

    $raw =
        cleanText(
            $raw
        );


    if (
        !$raw
    ) {
        return '';
    }


    $timestamp =
        strtotime(
            $raw
        );


    if (
        $timestamp !== false
    ) {

        return date(
            'd M Y',
            $timestamp
        );
    }


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
// POSTAL CODE
// ============================================================

function extractPostalCode(
    $text
)
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
// BANGLA DIGITS
// ============================================================

function convertToBangla(
    $number
)
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
// ADDRESS
// ============================================================

function combineAddress(
    $fullText
)
{

    $text =
        $fullText;


    // ========================================================
    // PRESENT ADDRESS
    // ========================================================

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


    // ========================================================
    // VILLAGE
    // ========================================================

    $villageRaw =
        extractBetween(
            $text,
            'Village/Road',
            'Home/Holding'
        );


    if (
        !$villageRaw
    ) {

        $villageRaw =
            extractBetween(
                $text,
                'Village/Road',
                'Post Office'
            );
    }


    if (
        !$villageRaw
    ) {

        $villageRaw =
            extractBetween(
                $text,
                'Mouza/Moholla',
                'Post Office'
            );
    }


    if (
        !$villageRaw
    ) {

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


    // ========================================================
    // HOME
    // ========================================================

    $homeRaw =
        extractBetween(
            $text,
            'Home/Holding',
            'Post Office'
        );


    if (
        !$homeRaw
    ) {

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


    // ========================================================
    // POST OFFICE
    // ========================================================

    $postOffice =
        cleanText(
            extractBetween(
                $text,
                'Post Office',
                'Postal Code'
            )
        );


    if (
        !$postOffice
    ) {

        $postOffice =
            cleanText(
                extractBetween(
                    $text,
                    'Post Office',
                    'Upozila'
                )
            );
    }


    // ========================================================
    // POSTAL CODE
    // ========================================================

    $postalCode =
        extractPostalCode(
            $text
        );


    if (
        !$postalCode
    ) {

        $postalCode =
            extractPostalCode(
                $fullText
            );
    }


    $postalCodeBangla =
        convertToBangla(
            $postalCode
        );


    // ========================================================
    // UPOZILA
    // ========================================================

    $upozila =
        cleanText(
            extractBetween(
                $text,
                'Upozila',
                'Union'
            )
        );


    if (
        !$upozila
    ) {

        $upozila =
            cleanText(
                extractBetween(
                    $text,
                    'Upozila',
                    'Municipality'
                )
            );
    }


    if (
        !$upozila
    ) {

        $upozila =
            cleanText(
                extractBetween(
                    $text,
                    'Upozila',
                    'District'
                )
            );
    }


    // ========================================================
    // DISTRICT
    // ========================================================

    $district =
        cleanText(
            extractBetween(
                $text,
                'District',
                'RMO'
            )
        );


    if (
        !$district
    ) {

        $district =
            cleanText(
                extractBetween(
                    $text,
                    'District',
                    'City'
                )
            );
    }


    // ========================================================
    // FINAL
    // ========================================================

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
            trim(
                $postOffice
            );


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
// UPLOAD ERROR MESSAGE
// ============================================================

function uploadErrorMessage(
    $error
)
{

    switch ($error) {

        case UPLOAD_ERR_INI_SIZE:
            return 'Uploaded file exceeds server upload_max_filesize.';

        case UPLOAD_ERR_FORM_SIZE:
            return 'Uploaded file exceeds form MAX_FILE_SIZE.';

        case UPLOAD_ERR_PARTIAL:
            return 'The uploaded file was only partially uploaded.';

        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded.';

        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing temporary folder on server.';

        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write uploaded file to disk.';

        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the file upload.';

        default:
            return 'Unknown upload error.';
    }
}


// ============================================================
// UPLOAD URL
// ============================================================

function getUploadsUrl()
{

    $https =
        isset($_SERVER['HTTPS']) &&
        (
            $_SERVER['HTTPS'] === 'on' ||
            $_SERVER['HTTPS'] == 1
        );


    $protocol =
        $https
            ? 'https'
            : 'http';


    $host =
        $_SERVER['HTTP_HOST'] ??
        'localhost';


    $script =
        $_SERVER['SCRIPT_NAME'] ??
        '';


    $directory =
        str_replace(
            '\\',
            '/',
            dirname($script)
        );


    $directory =
        rtrim(
            $directory,
            '/'
        );


    return
        $protocol .
        '://' .
        $host .
        $directory .
        '/uploads/';
}


// ============================================================
// REMOVE DIRECTORY
// ============================================================

function removeDirectory(
    $directory
)
{

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

        @rmdir(
            $directory
        );

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
