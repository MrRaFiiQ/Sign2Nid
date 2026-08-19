const express = require('express');
const multer = require('multer');
const pdfParse = require('pdf-parse');
const pdfjsLib = require('pdfjs-dist/legacy/build/pdf.js');
const { createCanvas } = require('@napi-rs/canvas');
const sharp = require('sharp');
const cors = require('cors');

if (pdfjsLib.GlobalWorkerOptions) {
  pdfjsLib.GlobalWorkerOptions.workerSrc = '';
}

const app = express();
app.use(cors());

const upload = multer({ storage: multer.memoryStorage() });

// Clean Text preserving Bangla Glyphs
function cleanText(text) {
  if (!text) return '';
  return text
    .normalize('NFC')
    .replace(/[\0\r\n\t]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

// Ultra Robust Key-Value Extractor (Regex + Line-by-Line Search)
function getValue(text, key) {
  if (!text) return '';
  
  // Method 1: Flexible Regex match
  const escapedKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const regex = new RegExp(`${escapedKey}\\s*(?:\\r?\\n)?\\s*\\|\\s*([^\\r\\n|]+)`, 'i');
  const match = text.match(regex);
  if (match && match[1] && match[1].trim()) {
    return cleanText(match[1]);
  }

  // Method 2: Line-by-Line Fallback Search
  const lines = text.split(/\r?\n/);
  for (let i = 0; i < lines.length; i++) {
    if (lines[i].toLowerCase().includes(key.toLowerCase())) {
      if (lines[i].includes('|')) {
        const val = lines[i].split('|')[1];
        if (val && val.trim()) return cleanText(val);
      } else if (i + 1 < lines.length && lines[i + 1].includes('|')) {
        const val = lines[i + 1].split('|')[1];
        if (val && val.trim()) return cleanText(val);
      }
    }
  }
  return '';
}

function convertToBangla(numberStr) {
  if (!numberStr) return '';
  const englishDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
  const banglaDigits = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
  return numberStr.toString().replace(/[0-9]/g, (w) => banglaDigits[englishDigits.indexOf(w)]);
}

function formatDateOfBirth(dobRaw) {
  if (!dobRaw) return '';
  const dateObj = new Date(dobRaw);
  if (isNaN(dateObj.getTime())) return dobRaw;
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const day = String(dateObj.getDate()).padStart(2, '0');
  const month = months[dateObj.getMonth()];
  const year = dateObj.getFullYear();
  return `${day} ${month} ${year}`;
}

function formatTodayBangla() {
  const today = new Date();
  const day = String(today.getDate()).padStart(2, '0');
  const month = String(today.getMonth() + 1).padStart(2, '0');
  const year = today.getFullYear();
  return convertToBangla(`${day}-${month}-${year}`);
}

function combineAddress(text) {
  let home = getValue(text, 'Home/Holding No') || getValue(text, 'Home/Holding');
  let village = getValue(text, 'Additional Village/Road') || getValue(text, 'Village/Road') || getValue(text, 'Additional Mouza/Moholla');
  let postOffice = getValue(text, 'Post Office');
  let postalCode = getValue(text, 'Postal Code') || '1324';
  let upozila = getValue(text, 'Upozila');
  let district = getValue(text, 'District');

  let parts = [];
  if (home) parts.push('বাসা/হোল্ডিং: ' + home);
  if (village) parts.push('গ্রাম/রাস্তা: ' + village);
  if (postOffice) parts.push('ডাকঘর: ' + postOffice + (postalCode ? ' -' + convertToBangla(postalCode) : ''));
  if (upozila) parts.push(upozila);
  if (district) parts.push(district);

  return parts.join(', ');
}

// Fast Embedded Image Extractor (Lower threshold for small signatures)
function extractJpegsFromBuffer(buffer) {
  const jpegs = [];
  const soi = Buffer.from([0xFF, 0xD8, 0xFF]);
  const eoi = Buffer.from([0xFF, 0xD9]);
  let offset = 0;

  while (offset < buffer.length) {
    const start = buffer.indexOf(soi, offset);
    if (start === -1) break;
    const end = buffer.indexOf(eoi, start + 3);
    if (end === -1) break;

    const jpegBuffer = buffer.subarray(start, end + 2);
    if (jpegBuffer.length > 300) { // Reduced threshold to catch small signature bytes
      jpegs.push(jpegBuffer);
    }
    offset = end + 2;
  }
  return jpegs;
}

// Render PDF Page Canvas
async function renderPdfPageToBuffer(pdfBuffer) {
  const loadingTask = pdfjsLib.getDocument({
    data: new Uint8Array(pdfBuffer),
    verbosity: 0,
    stopAtErrors: false
  });
  const pdfDocument = await loadingTask.promise;
  const page = await pdfDocument.getPage(1);

  const viewport = page.getViewport({ scale: 2.0 });
  const canvas = createCanvas(viewport.width, viewport.height);
  const context = canvas.getContext('2d');

  await page.render({
    canvasContext: context,
    viewport: viewport
  }).promise;

  return canvas.toBuffer('image/png');
}

// WEB UI (GET /)
app.get('/', (req, res) => {
  const html = `
  <!DOCTYPE html>
  <html lang="bn">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NID PDF Data Extractor</title>
    <style>
      body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 20px; text-align: center; }
      .container { max-width: 600px; background: #fff; padding: 25px; margin: auto; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
      h2 { color: #333; margin-bottom: 20px; }
      input[type="file"] { margin: 15px 0; padding: 10px; width: 100%; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
      button { background: #0070f3; color: white; border: none; padding: 12px 20px; font-size: 16px; border-radius: 5px; cursor: pointer; width: 100%; font-weight: bold; }
      button:hover { background: #0051a2; }
      #result { margin-top: 25px; text-align: left; display: none; }
      .img-box { display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap; }
      .img-item { text-align: center; flex: 1; min-width: 120px; }
      .img-item img { max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 5px; padding: 5px; background: #fafafa; }
      pre { background: #1e1e1e; color: #00ffcc; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 13px; line-height: 1.4; }
      .loading { color: #ff9800; font-weight: bold; margin-top: 15px; display: none; }
    </style>
  </head>
  <body>
    <div class="container">
      <h2>NID PDF Extraction System</h2>
      <form id="uploadForm">
        <input type="file" id="pdfFile" name="pdf" accept="application/pdf" required />
        <button type="submit">ডাটা এক্সট্র্যাক্ট করুন</button>
      </form>
      <div id="loading" class="loading">প্রসেসিং হচ্ছে, অনুগ্রহ করে অপেক্ষা করুন...</div>
      
      <div id="result">
        <h3>এক্সট্র্যাক্ট করা ছবি:</h3>
        <div class="img-box">
          <div class="img-item" id="userBox">
            <p><b>User Photo</b></p>
            <img id="userImg" src="" alt="User Photo">
          </div>
          <div class="img-item" id="signBox">
            <p><b>Signature</b></p>
            <img id="signImg" src="" alt="Signature">
          </div>
        </div>

        <h3>এক্সট্র্যাক্ট করা ডাটা (JSON):</h3>
        <pre id="jsonOutput"></pre>
      </div>
    </div>

    <script>
      document.getElementById('uploadForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fileInput = document.getElementById('pdfFile');
        if (!fileInput.files[0]) return alert('দয়া করে একটি PDF ফাইল সিলেক্ট করুন');

        const formData = new FormData();
        formData.append('pdf', fileInput.files[0]);

        document.getElementById('loading').style.display = 'block';
        document.getElementById('result').style.display = 'none';

        try {
          const res = await fetch('/', { method: 'POST', body: formData });
          const data = await res.json();
          
          document.getElementById('loading').style.display = 'none';
          
          if (data.success) {
            document.getElementById('result').style.display = 'block';
            
            if (data.data.userIMG) {
              document.getElementById('userImg').src = data.data.userIMG;
              document.getElementById('userBox').style.display = 'block';
            } else {
              document.getElementById('userBox').style.display = 'none';
            }

            if (data.data.signIMG) {
              document.getElementById('signImg').src = data.data.signIMG;
              document.getElementById('signBox').style.display = 'block';
            } else {
              document.getElementById('signBox').style.display = 'none';
            }

            document.getElementById('jsonOutput').textContent = JSON.stringify(data, null, 2);
          } else {
            alert('Error: ' + data.message);
          }
        } catch (err) {
          document.getElementById('loading').style.display = 'none';
          alert('সার্ভারে সমস্যা হয়েছে: ' + err.message);
        }
      });
    </script>
  </body>
  </html>
  `;
  res.send(html);
});

// MAIN API POST ENDPOINT
app.post('/', upload.single('pdf'), async (req, res) => {
  try {
    if (!req.file || req.file.mimetype !== 'application/pdf') {
      return res.status(400).json({
        code: 400,
        success: false,
        message: 'Invalid file type. Only PDF files are allowed.'
      });
    }

    const pdfBuffer = req.file.buffer;

    // 1. Text Parsing
    const parsedPdf = await pdfParse(pdfBuffer);
    const text = parsedPdf.text;

    const nameBangla = getValue(text, 'Name(Bangla)');
    const nameEnglish = getValue(text, 'Name(English)').toUpperCase();
    const nid = getValue(text, 'National ID');
    const pin = getValue(text, 'Pin');
    const dobRaw = getValue(text, 'Date of Birth');
    const dob = formatDateOfBirth(dobRaw);

    // 2. Extract Embedded Images
    let userImgBase64 = '';
    let signImgBase64 = '';

    const extractedJpegs = extractJpegsFromBuffer(pdfBuffer);
    if (extractedJpegs.length > 0) {
      userImgBase64 = `data:image/jpeg;base64,${extractedJpegs[0].toString('base64')}`;
    }
    if (extractedJpegs.length > 1) {
      signImgBase64 = `data:image/jpeg;base64,${extractedJpegs[1].toString('base64')}`;
    }

    // 3. Fallback Canvas Rendering
    if (!userImgBase64 || !signImgBase64) {
      try {
        const page1Buffer = await renderPdfPageToBuffer(pdfBuffer);
        const metadata = await sharp(page1Buffer).metadata();
        const w = metadata.width;
        const h = metadata.height;

        if (!userImgBase64) {
          const userCropRect = {
            left: Math.floor(w * 0.60),
            top: Math.floor(h * 0.005),
            width: Math.floor(w * 0.36),
            height: Math.floor(h * 0.22)
          };
          const croppedUser = await sharp(page1Buffer).extract(userCropRect).png().toBuffer();
          userImgBase64 = `data:image/png;base64,${croppedUser.toString('base64')}`;
        }

        if (!signImgBase64) {
          const signCropRect = {
            left: Math.floor(w * 0.05),
            top: Math.floor(h * 0.23),
            width: Math.floor(w * 0.50),
            height: Math.floor(h * 0.08)
          };
          const croppedSign = await sharp(page1Buffer)
            .extract(signCropRect)
            .png()
            .toBuffer();
          signImgBase64 = `data:image/png;base64,${croppedSign.toString('base64')}`;
        }
      } catch (imgError) {
        console.error('Canvas Crop Error:', imgError);
      }
    }

    // Response Structure
    const responseData = {
      nameBangla: nameBangla,
      nameEnglish: nameEnglish,
      nationalId: nid,
      pin: pin,
      dateOfBirth: dob,
      dateOfToday: formatTodayBangla(),
      fatherName: getValue(text, 'Father Name'),
      motherName: getValue(text, 'Mother Name'),
      gender: getValue(text, 'Gender'),
      religion: getValue(text, 'Religion'),
      birthPlace: getValue(text, 'Birth Place'),
      bloodGroup: getValue(text, 'Blood Group'),
      userIMG: userImgBase64,
      signIMG: signImgBase64,
      address: combineAddress(text)
    };

    return res.status(200).json({
      code: 200,
      success: true,
      message: 'Data fetched successfully',
      data: responseData
    });

  } catch (error) {
    return res.status(500).json({
      code: 500,
      success: false,
      message: 'Error processing PDF: ' + error.message
    });
  }
});

module.exports = app;
