const express = require('express');
const multer = require('multer');
const pdfjsLib = require('pdfjs-dist/legacy/build/pdf.js');
const { createCanvas } = require('@napi-rs/canvas');
const sharp = require('sharp');
const cors = require('cors');

if (pdfjsLib.GlobalWorkerOptions) {
  pdfjsLib.GlobalWorkerOptions.workerSrc = '';
}

const app = express();

app.use(cors());
app.use(express.json());

const upload = multer({
  storage: multer.memoryStorage(),
  limits: {
    fileSize: 15 * 1024 * 1024
  }
});


// ============================================================
// BASIC HELPERS
// ============================================================

function escapeRegExp(string) {
  return String(string).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}


function cleanText(text) {
  if (!text) return '';

  return String(text)
    .replace(/\0/g, '')
    .replace(/[\u0000-\u001F\u007F-\u009F]/g, ' ')
    .replace(/\r/g, ' ')
    .replace(/\t/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}


function cleanBangla(text) {
  if (!text) return '';

  return String(text)
    .replace(/\0/g, '')
    .replace(/[\u0000-\u001F\u007F-\u009F]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}


function extractBetween(text, start, end) {
  const regex = new RegExp(
    `${escapeRegExp(start)}([\\s\\S]*?)${escapeRegExp(end)}`,
    'i'
  );

  const match = text.match(regex);

  return match ? cleanText(match[1]) : '';
}


function convertToBangla(numberStr) {
  const banglaDigits = [
    '০', '১', '২', '৩', '৪',
    '৫', '৬', '৭', '৮', '৯'
  ];

  return String(numberStr).replace(/[0-9]/g, d => {
    return banglaDigits[Number(d)];
  });
}


// ============================================================
// PDF TEXT EXTRACTION
//
// IMPORTANT:
// Do NOT use pdf-parse here.
//
// We rebuild the text using:
// X position + Y position + item width.
//
// This prevents Bengali glyphs from becoming:
// "মো হা ম্মদ ইউসুফসু"
// ============================================================

async function extractPdfText(pdfBuffer) {

  const loadingTask = pdfjsLib.getDocument({
    data: new Uint8Array(pdfBuffer),
    verbosity: 0,
    stopAtErrors: false
  });

  const pdfDocument = await loadingTask.promise;

  let allPages = [];

  for (let pageNumber = 1; pageNumber <= pdfDocument.numPages; pageNumber++) {

    const page = await pdfDocument.getPage(pageNumber);

    const textContent = await page.getTextContent({
      normalizeWhitespace: false
    });

    const items = textContent.items
      .filter(item => {
        return item &&
          typeof item.str === 'string' &&
          item.str.trim() !== '';
      })
      .map(item => {

        const transform = item.transform || [];

        return {
          text: item.str,
          x: Number(transform[4] || 0),
          y: Number(transform[5] || 0),
          width: Number(item.width || 0),
          height: Number(item.height || 0)
        };
      });

    // --------------------------------------------------------
    // GROUP ITEMS INTO LINES
    // --------------------------------------------------------

    const lines = [];

    for (const item of items) {

      let matchedLine = null;

      for (const line of lines) {

        const tolerance = Math.max(
          2.5,
          Math.min(item.height || 10, line.height || 10) * 0.35
        );

        if (Math.abs(line.y - item.y) <= tolerance) {
          matchedLine = line;
          break;
        }
      }

      if (!matchedLine) {

        matchedLine = {
          y: item.y,
          height: item.height || 10,
          items: []
        };

        lines.push(matchedLine);
      }

      matchedLine.items.push(item);
    }


    // PDF coordinates are normally bottom-up.
    // Therefore sort Y descending for top-to-bottom reading.
    lines.sort((a, b) => b.y - a.y);


    // --------------------------------------------------------
    // REBUILD EACH LINE
    // --------------------------------------------------------

    const pageLines = [];

    for (const line of lines) {

      line.items.sort((a, b) => a.x - b.x);

      let result = '';
      let previous = null;

      for (const item of line.items) {

        let currentText = item.text;

        if (!currentText) continue;

        currentText = currentText
          .replace(/\r/g, '')
          .replace(/\n/g, '');

        if (!result) {

          result = currentText;

        } else {

          const previousEnd =
            previous.x + previous.width;

          const gap =
            item.x - previousEnd;

          /*
           * Small gap:
           * same word / Bengali glyph fragments
           *
           * Large gap:
           * new word / table column
           */

          const gapThreshold = Math.max(
            2.8,
            (item.height || 10) * 0.18
          );

          if (gap > gapThreshold) {
            result += ' ';
          }

          result += currentText;
        }

        previous = item;
      }

      result = result
        .replace(/\s+/g, ' ')
        .trim();

      if (result) {
        pageLines.push(result);
      }
    }

    allPages.push(pageLines.join('\n'));

    page.cleanup();
  }

  return allPages.join('\n\n');
}


// ============================================================
// FIELD EXTRACTION
// ============================================================

function extractNid(text) {

  const match = text.match(
    /National ID\s*([0-9]{10,17})/i
  );

  return match ? match[1] : '';
}


function extractPin(text) {

  const match = text.match(
    /Pin\s*([0-9]{10,17})/i
  );

  return match ? match[1] : '';
}


function extractPostalCode(text) {

  const match = text.match(
    /Postal Code\s*([0-9০-৯]{4})/u
  );

  if (!match) return '';

  return match[1];
}


// ============================================================
// BANGLA FIELD CLEANUP
// ============================================================

function normalizeBanglaName(value) {

  if (!value) return '';

  let result = cleanBangla(value);

  // Fix common duplicated colon produced by PDF text extraction.
  result = result.replace(/মোঃঃ/g, 'মোঃ');

  // Remove accidental leading/trailing separators.
  result = result
    .replace(/^[,:;\-]+/, '')
    .replace(/[,:;\-]+$/, '')
    .trim();

  return result;
}


function normalizePersonName(value) {

  let result = normalizeBanglaName(value);

  /*
   * Some PDF text extractors may produce:
   *
   * মো হা ম্মদ ইউসুফসু
   *
   * The positional extractor above normally fixes this.
   *
   * These fallback repairs are only applied when the
   * old fragmented pattern is still detected.
   */

  result = result
    .replace(
      /মো\s+হা\s+ম্মদ/gu,
      'মোহাম্মদ'
    )
    .replace(
      /ইউসুফসু$/gu,
      'ইউসুফ'
    );

  return result;
}


// ============================================================
// ADDRESS
// ============================================================

function getAddressPart(text, start, end) {

  let value = extractBetween(text, start, end);

  value = cleanBangla(value);

  return value;
}


function combineAddress(text) {

  let home =
    getAddressPart(
      text,
      'Home/Holding',
      'Post Office'
    );

  let village =
    getAddressPart(
      text,
      'Village/Road',
      'Home/Holding'
    );

  /*
   * This PDF contains Additional Village/Road.
   * If normal Village/Road is empty, use Additional.
   */

  if (!village) {

    village =
      getAddressPart(
        text,
        'Additional Village/Road',
        'Home/Holding'
      );
  }


  let postOffice =
    getAddressPart(
      text,
      'Post Office',
      'Postal Code'
    );


  let postalCode =
    extractPostalCode(text);

  let postalCodeBangla =
    convertToBangla(postalCode);


  let upozila =
    getAddressPart(
      text,
      'Upozila',
      'Union/Ward'
    );


  let district =
    getAddressPart(
      text,
      'District',
      'RMO'
    );


  const parts = [];

  if (home) {
    parts.push('বাসা/হোল্ডিং: ' + home);
  }

  if (village) {
    parts.push('গ্রাম/রাস্তা: ' + village);
  }

  if (postOffice) {

    let postText =
      'ডাকঘর: ' + postOffice;

    if (postalCodeBangla) {
      postText += ' - ' + postalCodeBangla;
    }

    parts.push(postText);
  }

  if (upozila) {
    parts.push(upozila);
  }

  if (district) {
    parts.push(district);
  }

  return parts.join(', ');
}


// ============================================================
// DATE
// ============================================================

function formatDateOfBirth(dobRaw) {

  if (!dobRaw) return '';

  const match =
    String(dobRaw).match(
      /(\d{4})[-/](\d{1,2})[-/](\d{1,2})/
    );

  if (!match) return '';

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);

  const months = [
    'Jan', 'Feb', 'Mar',
    'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep',
    'Oct', 'Nov', 'Dec'
  ];

  if (
    month < 1 ||
    month > 12 ||
    day < 1 ||
    day > 31
  ) {
    return '';
  }

  return (
    String(day).padStart(2, '0') +
    ' ' +
    months[month - 1] +
    ' ' +
    year
  );
}


function formatTodayBangla() {

  const today = new Date();

  const day =
    String(today.getDate()).padStart(2, '0');

  const month =
    String(today.getMonth() + 1).padStart(2, '0');

  const year =
    today.getFullYear();

  return convertToBangla(
    `${day}-${month}-${year}`
  );
}


// ============================================================
// RENDER PDF PAGE
// ============================================================

async function renderPdfPageToBuffer(pdfBuffer) {

  const loadingTask =
    pdfjsLib.getDocument({
      data: new Uint8Array(pdfBuffer),
      verbosity: 0,
      stopAtErrors: false
    });

  const pdfDocument =
    await loadingTask.promise;

  const page =
    await pdfDocument.getPage(1);


  /*
   * 2.0 is better than 1.5 for the small
   * signature/photo area.
   */

  const viewport =
    page.getViewport({
      scale: 2.0
    });


  const canvas =
    createCanvas(
      Math.ceil(viewport.width),
      Math.ceil(viewport.height)
    );


  const context =
    canvas.getContext('2d');


  await page.render({
    canvasContext: context,
    viewport: viewport
  }).promise;


  return canvas.toBuffer('image/png');
}


// ============================================================
// SAFE CROP
// ============================================================

function safeCrop(rect, width, height) {

  let left =
    Math.max(
      0,
      Math.min(rect.left, width - 1)
    );

  let top =
    Math.max(
      0,
      Math.min(rect.top, height - 1)
    );

  let cropWidth =
    Math.max(
      1,
      Math.min(
        rect.width,
        width - left
      )
    );

  let cropHeight =
    Math.max(
      1,
      Math.min(
        rect.height,
        height - top
      )
    );

  return {
    left: Math.floor(left),
    top: Math.floor(top),
    width: Math.floor(cropWidth),
    height: Math.floor(cropHeight)
  };
}


// ============================================================
// IMAGE EXTRACTION
//
// THIS PDF LAYOUT:
//
// User Photo  -> top right
// Signature   -> directly below photo
//
// ============================================================

async function extractImages(pdfBuffer) {

  const pageBuffer =
    await renderPdfPageToBuffer(pdfBuffer);


  const metadata =
    await sharp(pageBuffer).metadata();


  const width =
    Number(metadata.width || 0);

  const height =
    Number(metadata.height || 0);


  if (!width || !height) {
    throw new Error(
      'Unable to determine rendered PDF dimensions.'
    );
  }


  // ----------------------------------------------------------
  // USER PHOTO
  // ----------------------------------------------------------

  const userCropRect =
    safeCrop(
      {
        left: Math.floor(width * 0.755),
        top: Math.floor(height * 0.025),
        width: Math.floor(width * 0.165),
        height: Math.floor(height * 0.145)
      },
      width,
      height
    );


  const croppedUser =
    await sharp(pageBuffer)
      .extract(userCropRect)
      .trim({
        background: {
          r: 255,
          g: 255,
          b: 255
        }
      })
      .png({
        compressionLevel: 9
      })
      .toBuffer();


  const userImgBase64 =
    'data:image/png;base64,' +
    croppedUser.toString('base64');


  // ----------------------------------------------------------
  // SIGNATURE
  // ----------------------------------------------------------

  /*
   * IMPORTANT:
   *
   * Old code:
   *
   * left = 0.05
   *
   * That was completely wrong for this PDF.
   *
   * Signature is around:
   *
   * X = 0.765 -> 0.915
   * Y = 0.195 -> 0.245
   */

  const signCropRect =
    safeCrop(
      {
        left: Math.floor(width * 0.765),
        top: Math.floor(height * 0.195),
        width: Math.floor(width * 0.150),
        height: Math.floor(height * 0.052)
      },
      width,
      height
    );


  const croppedSign =
    await sharp(pageBuffer)
      .extract(signCropRect)
      .grayscale()
      .normalize()
      .threshold(210)
      .trim({
        background: {
          r: 255,
          g: 255,
          b: 255
        }
      })
      .extend({
        top: 8,
        bottom: 8,
        left: 8,
        right: 8,
        background: {
          r: 255,
          g: 255,
          b: 255
        }
      })
      .png({
        compressionLevel: 9
      })
      .toBuffer();


  const signImgBase64 =
    'data:image/png;base64,' +
    croppedSign.toString('base64');


  return {
    userIMG: userImgBase64,
    signIMG: signImgBase64
  };
}


// ============================================================
// WEB UI
// ============================================================

app.get('/', (req, res) => {

  const html = `
<!DOCTYPE html>
<html lang="bn">

<head>

<meta charset="UTF-8">

<meta
  name="viewport"
  content="width=device-width, initial-scale=1.0"
>

<title>NID PDF Data Extractor</title>

<style>

* {
  box-sizing: border-box;
}

body {
  margin: 0;
  padding: 20px;
  background: #f4f6f9;
  font-family:
    Arial,
    "Noto Sans Bengali",
    sans-serif;
}

.container {
  width: 100%;
  max-width: 650px;
  margin: auto;
  background: #fff;
  padding: 25px;
  border-radius: 12px;
  box-shadow:
    0 4px 20px rgba(0,0,0,.10);
}

h2 {
  text-align: center;
  margin-top: 0;
  color: #222;
}

input[type="file"] {
  width: 100%;
  padding: 12px;
  margin: 15px 0;
  border: 1px solid #ccc;
  border-radius: 8px;
  background: #fff;
}

button {
  width: 100%;
  border: 0;
  padding: 15px;
  border-radius: 8px;
  background: #087ff5;
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  font-weight: bold;
}

button:hover {
  background: #0568cc;
}

#result {
  display: none;
  margin-top: 30px;
}

.img-box {
  display: flex;
  gap: 25px;
  flex-wrap: wrap;
  align-items: flex-start;
}

.img-box > div {
  flex: 1;
  min-width: 180px;
  text-align: center;
}

.img-box img {
  max-width: 100%;
  width: auto;
  height: auto;
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 5px;
  background: #fff;
}

#userImg {
  max-height: 300px;
}

#signImg {
  max-height: 120px;
}

pre {
  margin-top: 15px;
  background: #1e1e1e;
  color: #00ffcc;
  padding: 18px;
  border-radius: 8px;
  overflow-x: auto;
  font-size: 13px;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
}

.loading {
  display: none;
  text-align: center;
  margin-top: 15px;
  color: #ff9800;
  font-weight: bold;
}

.error {
  color: #d32f2f;
  font-weight: bold;
}

</style>

</head>

<body>

<div class="container">

<h2>NID PDF Extraction System</h2>

<form id="uploadForm">

<input
  type="file"
  id="pdfFile"
  name="pdf"
  accept="application/pdf"
  required
>

<button type="submit">
  ডাটা এক্সট্র্যাক্ট করুন
</button>

</form>


<div id="loading" class="loading">
  PDF প্রসেসিং হচ্ছে...
</div>


<div id="result">

<h3>এক্সট্র্যাক্ট করা ছবি:</h3>

<div class="img-box">

<div>

<p><b>User Photo</b></p>

<img
  id="userImg"
  src=""
  alt="User Photo"
>

</div>


<div>

<p><b>Signature</b></p>

<img
  id="signImg"
  src=""
  alt="Signature"
>

</div>

</div>


<h3>এক্সট্র্যাক্ট করা ডাটা (JSON):</h3>

<pre id="jsonOutput"></pre>

</div>

</div>


<script>

document
  .getElementById('uploadForm')
  .addEventListener('submit', async function(e) {

    e.preventDefault();

    const fileInput =
      document.getElementById('pdfFile');

    if (!fileInput.files[0]) {
      alert('দয়া করে PDF নির্বাচন করুন');
      return;
    }


    const formData =
      new FormData();

    formData.append(
      'pdf',
      fileInput.files[0]
    );


    const loading =
      document.getElementById('loading');

    const result =
      document.getElementById('result');


    loading.style.display = 'block';
    result.style.display = 'none';


    try {

      const response =
        await fetch('/', {
          method: 'POST',
          body: formData
        });


      const data =
        await response.json();


      loading.style.display = 'none';


      if (!data.success) {

        alert(
          data.message ||
          'PDF processing failed.'
        );

        return;
      }


      result.style.display = 'block';


      document.getElementById(
        'userImg'
      ).src =
        data.data.userIMG || '';


      document.getElementById(
        'signImg'
      ).src =
        data.data.signIMG || '';


      document.getElementById(
        'jsonOutput'
      ).textContent =
        JSON.stringify(
          data,
          null,
          2
        );

    } catch (error) {

      loading.style.display = 'none';

      alert(
        'সার্ভারে সমস্যা হয়েছে: ' +
        error.message
      );
    }

  });

</script>

</body>

</html>
`;

  res.send(html);
});


// ============================================================
// MAIN POST API
// ============================================================

app.post(
  '/',
  upload.single('pdf'),
  async (req, res) => {

    try {

      // ------------------------------------------------------
      // FILE VALIDATION
      // ------------------------------------------------------

      if (!req.file) {

        return res.status(400).json({
          code: 400,
          success: false,
          message: 'PDF file is required.'
        });
      }


      if (
        req.file.mimetype !==
        'application/pdf'
      ) {

        return res.status(400).json({
          code: 400,
          success: false,
          message:
            'Invalid file type. Only PDF is allowed.'
        });
      }


      const pdfBuffer =
        req.file.buffer;


      // ------------------------------------------------------
      // TEXT EXTRACTION
      // ------------------------------------------------------

      const text =
        await extractPdfText(pdfBuffer);


      // ------------------------------------------------------
      // MAIN DATA
      // ------------------------------------------------------

      const nameBanglaRaw =
        extractBetween(
          text,
          'Name(Bangla)',
          'Name(English)'
        );


      const nameEnglishRaw =
        extractBetween(
          text,
          'Name(English)',
          'Date of Birth'
        );


      const dobRaw =
        extractBetween(
          text,
          'Date of Birth',
          'Birth Place'
        );


      const fatherNameRaw =
        extractBetween(
          text,
          'Father Name',
          'Mother Name'
        );


      const motherNameRaw =
        extractBetween(
          text,
          'Mother Name',
          'Spouse Name'
        );


      const gender =
        extractBetween(
          text,
          'Gender',
          'Marital'
        );


      const religion =
        extractBetween(
          text,
          'Religion',
          'Religion Other'
        );


      const birthPlace =
        extractBetween(
          text,
          'Birth Place',
          'Birth Other'
        );


      const bloodGroup =
        extractBetween(
          text,
          'Blood Group',
          'TIN'
        );


      // ------------------------------------------------------
      // IMAGE EXTRACTION
      // ------------------------------------------------------

      let images = {
        userIMG: '',
        signIMG: ''
      };


      try {

        images =
          await extractImages(
            pdfBuffer
          );

      } catch (imageError) {

        console.error(
          'Image extraction error:',
          imageError
        );

        /*
         * Keep API working even if image
         * extraction fails.
         */
      }


      // ------------------------------------------------------
      // RESPONSE
      // ------------------------------------------------------

      const responseData = {

        nameBangla:
          normalizePersonName(
            nameBanglaRaw
          ),

        nameEnglish:
          cleanText(
            nameEnglishRaw
          ).toUpperCase(),

        nationalId:
          extractNid(text),

        pin:
          extractPin(text),

        dateOfBirth:
          formatDateOfBirth(
            dobRaw
          ),

        dateOfToday:
          formatTodayBangla(),

        fatherName:
          normalizeBanglaName(
            fatherNameRaw
          ),

        motherName:
          normalizeBanglaName(
            motherNameRaw
          ),

        gender:
          cleanText(gender),

        religion:
          cleanText(religion),

        birthPlace:
          cleanBangla(birthPlace),

        bloodGroup:
          cleanText(bloodGroup),

        userIMG:
          images.userIMG,

        signIMG:
          images.signIMG,

        address:
          combineAddress(text)
      };


      return res.status(200).json({

        code: 200,

        success: true,

        message:
          'Data fetched successfully',

        data:
          responseData
      });


    } catch (error) {

      console.error(
        'PDF Processing Error:',
        error
      );


      return res.status(500).json({

        code: 500,

        success: false,

        message:
          'Error processing PDF: ' +
          error.message
      });

    }

  }
);


// ============================================================
// EXPORT
// ============================================================

module.exports = app;
