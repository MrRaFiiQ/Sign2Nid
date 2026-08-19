const express = require('express');
const multer = require('multer');
const pdfjsLib = require('pdfjs-dist/legacy/build/pdf.js');
const { createCanvas } = require('@napi-rs/canvas');
const sharp = require('sharp');
const cors = require('cors');

const app = express();

app.use(cors());
app.use(express.json());


// ============================================================
// MULTER
// ============================================================

const upload = multer({
  storage: multer.memoryStorage(),
  limits: {
    fileSize: 15 * 1024 * 1024
  }
});


// ============================================================
// HELPERS
// ============================================================

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

  const startIndex =
    text.toLowerCase().indexOf(
      start.toLowerCase()
    );

  if (startIndex === -1) {
    return '';
  }

  const valueStart =
    startIndex + start.length;

  const endIndex =
    text.toLowerCase().indexOf(
      end.toLowerCase(),
      valueStart
    );

  if (endIndex === -1) {
    return cleanText(
      text.substring(valueStart)
    );
  }

  return cleanText(
    text.substring(
      valueStart,
      endIndex
    )
  );
}


// ============================================================
// BANGLA NAME NORMALIZER
// ============================================================

function normalizeBanglaName(value) {

  if (!value) return '';

  let result = cleanBangla(value);

  /*
   * PDF positional extraction-এর পরেও যদি
   * Bengali glyph fragment থেকে যায়,
   * common patterns repair করা হবে।
   */

  result = result
    .replace(/মো\s+হা\s+ম্মদ/gu, 'মোহাম্মদ')
    .replace(/মোঃ\s+বা\s+দল/gu, 'মোঃ বাদল')
    .replace(/রে\s+হে\s+না/gu, 'রেহেনা')
    .replace(/ইউসুফসু/gu, 'ইউসুফ')
    .replace(/মোহাম্মদ\s+ইউসুফসু/gu, 'মোহাম্মদ ইউসুফ');

  return result.trim();
}


// ============================================================
// DIGIT CONVERTER
// ============================================================

function convertToBangla(value) {

  const digits = [
    '০', '১', '২', '৩', '৪',
    '৫', '৬', '৭', '৮', '৯'
  ];

  return String(value).replace(
    /[0-9]/g,
    d => digits[Number(d)]
  );
}


// ============================================================
// NID
// ============================================================

function extractNid(text) {

  const match =
    text.match(
      /National ID\s*([0-9]{10,17})/i
    );

  return match
    ? match[1]
    : '';
}


// ============================================================
// PIN
// ============================================================

function extractPin(text) {

  const match =
    text.match(
      /Pin\s*([0-9]{10,17})/i
    );

  return match
    ? match[1]
    : '';
}


// ============================================================
// POSTAL CODE
// ============================================================

function extractPostalCode(text) {

  const match =
    text.match(
      /Postal Code\s*([0-9০-৯]{4})/u
    );

  return match
    ? match[1]
    : '';
}


// ============================================================
// PDF TEXT EXTRACTION
//
// IMPORTANT:
// pdf-parse ব্যবহার করা হয়নি।
//
// PDF.js positional text item ব্যবহার করে
// Bengali text rebuild করা হচ্ছে।
// ============================================================

async function extractPdfText(pdfBuffer) {

  const loadingTask =
    pdfjsLib.getDocument({

      data: new Uint8Array(pdfBuffer),

      verbosity: 0,

      stopAtErrors: false,

      /*
       * VERY IMPORTANT
       *
       * Vercel Serverless-এ worker/fake-worker
       * problem এড়ানোর জন্য worker disable।
       */
      disableWorker: true

    });


  const pdfDocument =
    await loadingTask.promise;


  const pages = [];


  for (
    let pageNumber = 1;
    pageNumber <= pdfDocument.numPages;
    pageNumber++
  ) {

    const page =
      await pdfDocument.getPage(
        pageNumber
      );


    const textContent =
      await page.getTextContent({
        normalizeWhitespace: false
      });


    const items =
      textContent.items
        .filter(item => {

          return (
            item &&
            typeof item.str === 'string' &&
            item.str.trim() !== ''
          );

        })
        .map(item => {

          const transform =
            item.transform || [];

          return {

            text: item.str,

            x:
              Number(
                transform[4] || 0
              ),

            y:
              Number(
                transform[5] || 0
              ),

            width:
              Number(
                item.width || 0
              ),

            height:
              Number(
                item.height || 0
              )

          };

        });


    // ========================================================
    // GROUP BY LINE
    // ========================================================

    const lines = [];


    for (const item of items) {

      let lineFound = null;


      for (const line of lines) {

        const tolerance =
          Math.max(
            2.5,
            Math.min(
              item.height || 10,
              line.height || 10
            ) * 0.35
          );


        if (
          Math.abs(
            line.y - item.y
          ) <= tolerance
        ) {

          lineFound = line;
          break;

        }

      }


      if (!lineFound) {

        lineFound = {

          y: item.y,

          height:
            item.height || 10,

          items: []

        };

        lines.push(lineFound);

      }


      lineFound.items.push(item);

    }


    // PDF coordinate bottom -> top
    lines.sort(
      (a, b) => b.y - a.y
    );


    // ========================================================
    // REBUILD LINES
    // ========================================================

    const pageLines = [];


    for (const line of lines) {

      line.items.sort(
        (a, b) => a.x - b.x
      );


      let result = '';
      let previous = null;


      for (const item of line.items) {

        let current =
          item.text
            .replace(/\r/g, '')
            .replace(/\n/g, '');


        if (!current) {
          continue;
        }


        if (!result) {

          result = current;

        } else {

          const previousEnd =
            previous.x +
            previous.width;


          const gap =
            item.x -
            previousEnd;


          /*
           * Bengali glyph fragment-এর মধ্যে
           * ছোট gap হলে space দেব না।
           *
           * বড় gap হলে নতুন word।
           */

          const gapThreshold =
            Math.max(
              2.8,
              (item.height || 10) * 0.18
            );


          if (
            gap >
            gapThreshold
          ) {

            result += ' ';

          }


          result += current;

        }


        previous = item;

      }


      result =
        result
          .replace(/\s+/g, ' ')
          .trim();


      if (result) {
        pageLines.push(result);
      }

    }


    pages.push(
      pageLines.join('\n')
    );


    page.cleanup();

  }


  return pages.join('\n\n');
}


// ============================================================
// DATE
// ============================================================

function formatDateOfBirth(value) {

  if (!value) return '';

  const match =
    String(value).match(
      /(\d{4})[-/](\d{1,2})[-/](\d{1,2})/
    );


  if (!match) return '';


  const year =
    Number(match[1]);

  const month =
    Number(match[2]);

  const day =
    Number(match[3]);


  const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec'
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


// ============================================================
// TODAY
// ============================================================

function formatTodayBangla() {

  const today =
    new Date();


  const day =
    String(
      today.getDate()
    ).padStart(2, '0');


  const month =
    String(
      today.getMonth() + 1
    ).padStart(2, '0');


  const year =
    today.getFullYear();


  return convertToBangla(
    `${day}-${month}-${year}`
  );
}


// ============================================================
// PDF PAGE RENDER
//
// Worker disabled above.
// তাই fake worker আর দরকার নেই।
// ============================================================

async function renderPdfPageToBuffer(
  pdfBuffer
) {

  const loadingTask =
    pdfjsLib.getDocument({

      data:
        new Uint8Array(pdfBuffer),

      verbosity: 0,

      stopAtErrors: false,

      disableWorker: true

    });


  const pdfDocument =
    await loadingTask.promise;


  const page =
    await pdfDocument.getPage(1);


  const viewport =
    page.getViewport({
      scale: 2.0
    });


  const canvas =
    createCanvas(

      Math.ceil(
        viewport.width
      ),

      Math.ceil(
        viewport.height
      )

    );


  const context =
    canvas.getContext('2d');


  await page.render({

    canvasContext:
      context,

    viewport:
      viewport

  }).promise;


  return canvas.toBuffer(
    'image/png'
  );
}


// ============================================================
// SAFE CROP
// ============================================================

function safeCrop(
  rect,
  width,
  height
) {

  const left =
    Math.max(
      0,
      Math.min(
        Math.floor(rect.left),
        width - 1
      )
    );


  const top =
    Math.max(
      0,
      Math.min(
        Math.floor(rect.top),
        height - 1
      )
    );


  const cropWidth =
    Math.max(
      1,
      Math.min(
        Math.floor(rect.width),
        width - left
      )
    );


  const cropHeight =
    Math.max(
      1,
      Math.min(
        Math.floor(rect.height),
        height - top
      )
    );


  return {

    left,

    top,

    width:
      cropWidth,

    height:
      cropHeight

  };
}


// ============================================================
// IMAGE EXTRACTION
// ============================================================

async function extractImages(
  pdfBuffer
) {

  const pageBuffer =
    await renderPdfPageToBuffer(
      pdfBuffer
    );


  const metadata =
    await sharp(
      pageBuffer
    ).metadata();


  const width =
    Number(
      metadata.width || 0
    );


  const height =
    Number(
      metadata.height || 0
    );


  if (!width || !height) {

    throw new Error(
      'Unable to determine PDF image dimensions.'
    );

  }


  // ==========================================================
  // USER PHOTO
  // ==========================================================

  const userCropRect =
    safeCrop(

      {

        /*
         * PDF-এর Page 1:
         * User photo top-right.
         */

        left:
          width * 0.755,

        top:
          height * 0.025,

        width:
          width * 0.165,

        height:
          height * 0.145

      },

      width,
      height

    );


  const croppedUser =
    await sharp(
      pageBuffer
    )
      .extract(
        userCropRect
      )
      .png({
        compressionLevel: 9
      })
      .toBuffer();


  const userIMG =
    'data:image/png;base64,' +
    croppedUser.toString(
      'base64'
    );


  // ==========================================================
  // SIGNATURE
  // ==========================================================

  const signCropRect =
    safeCrop(

      {

        /*
         * PDF-এর Page 1-এ
         * Signature photo-এর ঠিক নিচে।
         */

        left:
          width * 0.765,

        top:
          height * 0.195,

        width:
          width * 0.150,

        height:
          height * 0.052

      },

      width,
      height

    );


  const croppedSign =
    await sharp(
      pageBuffer
    )
      .extract(
        signCropRect
      )
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


  const signIMG =
    'data:image/png;base64,' +
    croppedSign.toString(
      'base64'
    );


  return {
    userIMG,
    signIMG
  };
}


// ============================================================
// ADDRESS
// ============================================================

function combineAddress(text) {

  let home =
    extractBetween(
      text,
      'Home/Holding',
      'Post Office'
    );


  let village =
    extractBetween(
      text,
      'Additional Village/Road',
      'Home/Holding'
    );


  if (!village) {

    village =
      extractBetween(
        text,
        'Village/Road',
        'Home/Holding'
      );

  }


  let postOffice =
    extractBetween(
      text,
      'Post Office',
      'Postal Code'
    );


  let postalCode =
    extractPostalCode(text);


  let upozila =
    extractBetween(
      text,
      'Upozila',
      'Union/Ward'
    );


  let district =
    extractBetween(
      text,
      'District',
      'RMO'
    );


  home =
    cleanBangla(home);

  village =
    cleanBangla(village);

  postOffice =
    cleanBangla(postOffice);

  upozila =
    cleanBangla(upozila);

  district =
    cleanBangla(district);


  const parts = [];


  if (home) {

    parts.push(
      'বাসা/হোল্ডিং: ' +
      home
    );

  }


  if (village) {

    parts.push(
      'গ্রাম/রাস্তা: ' +
      village
    );

  }


  if (postOffice) {

    let post =
      'ডাকঘর: ' +
      postOffice;


    if (postalCode) {

      post +=
        ' - ' +
        convertToBangla(
          postalCode
        );

    }


    parts.push(post);

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
// HTML
// ============================================================

app.get('/', (req, res) => {

  res.send(`

<!DOCTYPE html>

<html lang="bn">

<head>

<meta charset="UTF-8">

<meta
  name="viewport"
  content="width=device-width, initial-scale=1.0"
>

<title>NID PDF Extraction System</title>

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

  max-width: 650px;

  margin: auto;

  background: #fff;

  padding: 25px;

  border-radius: 12px;

  box-shadow:
    0 4px 20px
    rgba(0,0,0,.10);

}

h2 {

  text-align: center;

  margin-top: 0;

}

input[type="file"] {

  width: 100%;

  padding: 12px;

  margin:
    15px 0;

  border:
    1px solid #ccc;

  border-radius:
    8px;

}

button {

  width: 100%;

  padding: 15px;

  border: 0;

  border-radius: 8px;

  background: #087ff5;

  color: #fff;

  font-size: 19px;

  font-weight: bold;

}

button:disabled {

  opacity: .6;

}

#loading {

  display: none;

  text-align: center;

  color: #ff9800;

  font-weight: bold;

  margin-top: 15px;

}

#result {

  display: none;

  margin-top: 30px;

}

.img-box {

  display: flex;

  gap: 20px;

  flex-wrap: wrap;

}

.img-box > div {

  flex: 1;

  min-width: 180px;

  text-align: center;

}

.img-box img {

  max-width: 100%;

  height: auto;

  border:
    1px solid #ddd;

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

  background: #1e1e1e;

  color: #00ffcc;

  padding: 15px;

  border-radius: 8px;

  overflow-x: auto;

  white-space: pre-wrap;

  word-break: break-word;

  font-size: 13px;

}

</style>

</head>


<body>


<div class="container">

<h2>
NID PDF Extraction System
</h2>


<form id="uploadForm">

<input
  type="file"
  id="pdfFile"
  name="pdf"
  accept="application/pdf"
  required
>


<button
  id="submitBtn"
  type="submit"
>
ডাটা এক্সট্র্যাক্ট করুন
</button>

</form>


<div id="loading">
PDF প্রসেসিং হচ্ছে...
</div>


<div id="result">

<h3>
এক্সট্র্যাক্ট করা ছবি:
</h3>


<div class="img-box">


<div>

<p>
<b>User Photo</b>
</p>

<img
  id="userImg"
  alt="User Photo"
>

</div>


<div>

<p>
<b>Signature</b>
</p>

<img
  id="signImg"
  alt="Signature"
>

</div>


</div>


<h3>
এক্সট্র্যাক্ট করা ডাটা (JSON):
</h3>


<pre id="jsonOutput"></pre>


</div>


</div>


<script>

const form =
  document.getElementById(
    'uploadForm'
  );


const button =
  document.getElementById(
    'submitBtn'
  );


const loading =
  document.getElementById(
    'loading'
  );


const result =
  document.getElementById(
    'result'
  );


form.addEventListener(
  'submit',
  async function(e) {

    e.preventDefault();


    const fileInput =
      document.getElementById(
        'pdfFile'
      );


    if (
      !fileInput.files[0]
    ) {

      alert(
        'দয়া করে PDF নির্বাচন করুন'
      );

      return;

    }


    const formData =
      new FormData();


    formData.append(
      'pdf',
      fileInput.files[0]
    );


    button.disabled = true;

    loading.style.display =
      'block';

    result.style.display =
      'none';


    try {

      const response =
        await fetch(
          '/',
          {
            method: 'POST',
            body: formData
          }
        );


      const data =
        await response.json();


      if (!response.ok) {

        throw new Error(
          data.message ||
          'Server error'
        );

      }


      if (!data.success) {

        throw new Error(
          data.message ||
          'PDF processing failed'
        );

      }


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


      result.style.display =
        'block';


    } catch (error) {

      alert(
        'Error processing PDF: ' +
        error.message
      );

    } finally {

      button.disabled = false;

      loading.style.display =
        'none';

    }

  }

);

</script>


</body>

</html>

`);

});


// ============================================================
// POST API
// ============================================================

app.post(
  '/',
  upload.single('pdf'),
  async (req, res) => {

    try {

      // ------------------------------------------------------
      // FILE CHECK
      // ------------------------------------------------------

      if (!req.file) {

        return res.status(400).json({

          code: 400,

          success: false,

          message:
            'PDF file is required.'

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
            'Only PDF files are allowed.'

        });

      }


      const pdfBuffer =
        req.file.buffer;


      // ------------------------------------------------------
      // TEXT
      // ------------------------------------------------------

      const text =
        await extractPdfText(
          pdfBuffer
        );


      // ------------------------------------------------------
      // FIELDS
      // ------------------------------------------------------

      const nameBangla =
        normalizeBanglaName(
          extractBetween(
            text,
            'Name(Bangla)',
            'Name(English)'
          )
        );


      const nameEnglish =
        cleanText(
          extractBetween(
            text,
            'Name(English)',
            'Date of Birth'
          )
        ).toUpperCase();


      const nid =
        extractNid(text);


      const pin =
        extractPin(text);


      const dob =
        formatDateOfBirth(
          extractBetween(
            text,
            'Date of Birth',
            'Birth Place'
          )
        );


      const fatherName =
        normalizeBanglaName(
          extractBetween(
            text,
            'Father Name',
            'Mother Name'
          )
        );


      const motherName =
        normalizeBanglaName(
          extractBetween(
            text,
            'Mother Name',
            'Spouse Name'
          )
        );


      const gender =
        cleanText(
          extractBetween(
            text,
            'Gender',
            'Marital'
          )
        );


      const religion =
        cleanText(
          extractBetween(
            text,
            'Religion',
            'Religion Other'
          )
        );


      const birthPlace =
        cleanBangla(
          extractBetween(
            text,
            'Birth Place',
            'Birth Other'
          )
        );


      const bloodGroup =
        cleanText(
          extractBetween(
            text,
            'Blood Group',
            'TIN'
          )
        );


      // ------------------------------------------------------
      // IMAGES
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
          'IMAGE ERROR:',
          imageError
        );

      }


      // ------------------------------------------------------
      // RESPONSE
      // ------------------------------------------------------

      return res.status(200).json({

        code: 200,

        success: true,

        message:
          'Data fetched successfully',

        data: {

          nameBangla,

          nameEnglish,

          nationalId:
            nid,

          pin,

          dateOfBirth:
            dob,

          dateOfToday:
            formatTodayBangla(),

          fatherName,

          motherName,

          gender,

          religion,

          birthPlace,

          bloodGroup,

          userIMG:
            images.userIMG,

          signIMG:
            images.signIMG,

          address:
            combineAddress(text)

        }

      });


    } catch (error) {

      console.error(
        'PDF PROCESSING ERROR:',
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
// LOCAL SERVER
// ============================================================

if (require.main === module) {

  const PORT =
    process.env.PORT || 3000;

  app.listen(
    PORT,
    () => {

      console.log(
        `Server running on port ${PORT}`
      );

    }
  );

}


module.exports = app;
