const express = require('express');
const multer = require('multer');
const pdfjsLib = require('pdfjs-dist/legacy/build/pdf.js');
const { createCanvas } = require('@napi-rs/canvas');
const sharp = require('sharp');
const cors = require('cors');

// Disable PDF.js Worker requirement for Vercel Serverless Environment
if (pdfjsLib.GlobalWorkerOptions) {
  pdfjsLib.GlobalWorkerOptions.workerSrc = '';
}

const app = express();
app.use(cors());

const upload = multer({ storage: multer.memoryStorage() });

// Helper Functions
function escapeRegExp(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function extractBetween(text, start, end) {
  const regex = new RegExp(`${escapeRegExp(start)}([\\s\\S]*?)${escapeRegExp(end)}`, 'i');
  const match = text.match(regex);
  return match ? match[1] : '';
}

// Clean Text & Strip Null Characters (\u0000), Control Bytes, and Table Pipes (|)
function cleanText(text) {
  if (!text) return '';
  return String(text)
    .replace(/\0/g, '') // Remove Null Bytes
    .replace(/[\u0000-\u001F\u007F-\u009F]/g, '') // Remove Invisible Control Chars
    .replace(/[|"\r\n\t,]/g, ' ') // Replace table pipes and separators with space
    .replace(/\s+/g, ' ')
    .trim();
}

function cleanBanglaName(text) {
  if (!text) return '';
  let cleaned = String(text)
    .replace(/\0/g, '')
    .replace(/[\u0000-\u001F\u007F-\u009F]/g, '')
    .replace(/halnagad_\d+_\w+/gi, '')
    .replace(/halnagad_\d+/gi, '')
    .replace(/Tag/gi, '')
    .replace(/Name\(Bangla\)/gi, '');
  return cleanText(cleaned);
}

function convertToBangla(numberStr) {
  if (!numberStr) return '';
  const englishDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
  const banglaDigits = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
  return numberStr.toString().replace(/[0-9]/g, (w) => banglaDigits[englishDigits.indexOf(w)]);
}

function extractNid(text) {
  const match = text.match(/National ID[^\d]*(\d{10,17})/i);
  return match ? match[1] : '';
}

function extractPin(text) {
  const match = text.match(/Pin[^\d]*(\d{10,17})/i);
  return match ? match[1] : '';
}

function extractPostalCode(text) {
  const match = text.match(/Postal Code[^\d০-৯]*([0-9০-৯]{4})/u);
  return match ? match[1] : '';
}

function combineAddress(text) {
  let villageRaw = extractBetween(text, 'Additional Village/Road', 'Home/Holding');
  if (!villageRaw) villageRaw = extractBetween(text, 'Village/Road', 'Home/Holding');
  let village = cleanText(villageRaw.replace(/Village\/Road|Home\/Holding|Additional|No\.|No/gi, ''));

  let homeRaw = extractBetween(text, 'Home/Holding', 'Post Office');
  if (!homeRaw) homeRaw = extractBetween(text, 'Home/Holding No', 'Post Office');
  let home = cleanText(homeRaw.replace(/Home\/Holding|Village\/Road|Additional|No\.|No/gi, ''));

  let postOffice = cleanText(extractBetween(text, 'Post Office', 'Postal Code'));
  let postalCode = extractPostalCode(text);
  let postalCodeBangla = convertToBangla(postalCode);

  let upozila = cleanText(extractBetween(text, 'Upozila', 'Union'));
  if (!upozila) upozila = cleanText(extractBetween(text, 'Upozila', 'District'));

  let district = cleanText(extractBetween(text, 'District', 'RMO'));
  if (!district) district = cleanText(extractBetween(text, 'District', 'Region'));

  let parts = [];
  if (home) parts.push('বাসা/হোল্ডিং: ' + home);
  if (village) parts.push('গ্রাম/রাস্তা: ' + village);
  if (postOffice) parts.push('ডাকঘর: ' + postOffice + (postalCodeBangla ? ' - ' + postalCodeBangla : ''));
  if (upozila) parts.push(upozila);
  if (district) parts.push(district);

  return parts.join(', ');
}

function formatDateOfBirth(dobRaw) {
  if (!dobRaw) return '';
  const match = String(dobRaw).match(/(\d{4})[-/](\d{1,2})[-/](\d{1,2})/);
  if (match) {
    const year = match[1];
    const monthIdx = parseInt(match[2], 10) - 1;
    const day = match[3].padStart(2, '0');
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    if (monthIdx >= 0 && monthIdx < 12) {
      return `${day} ${months[monthIdx]} ${year}`;
    }
  }
  const dateObj = new Date(dobRaw);
  if (!isNaN(dateObj.getTime())) {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const day = String(dateObj.getDate()).padStart(2, '0');
    const month = months[dateObj.getMonth()];
    const year = dateObj.getFullYear();
    return `${day} ${month} ${year}`;
  }
  return '';
}

function formatTodayBangla() {
  const today = new Date();
  const day = String(today.getDate()).padStart(2, '0');
  const month = String(today.getMonth() + 1).padStart(2, '0');
  const year = today.getFullYear();
  return convertToBangla(`${day}-${month}-${year}`);
}

// Full Text Extractor using PDF.js for accurate Bangla Unicode rendering
async function extractPdfText(pdfBuffer) {
  const loadingTask = pdfjsLib.getDocument({
    data: new Uint8Array(pdfBuffer),
    verbosity: 0,
    stopAtErrors: false
  });
  const pdfDocument = await loadingTask.promise;
  let fullText = '';
  for (let i = 1; i <= pdfDocument.numPages; i++) {
    const page = await pdfDocument.getPage(i);
    const textContent = await page.getTextContent();
    const pageLines = textContent.items.map(item => item.str).join(' ');
    fullText += pageLines + '\n';
  }
  return fullText;
}

// Fast PDF Page Renderer for Canvas Fallback
async function renderPdfPageToBuffer(pdfBuffer) {
  const loadingTask = pdfjsLib.getDocument({
    data: new Uint8Array(pdfBuffer),
    verbosity: 0,
    stopAtErrors: false
  });
  const pdfDocument = await loadingTask.promise;
  const page = await pdfDocument.getPage(1);

  const viewport = page.getViewport({ scale: 1.5 });
  const canvas = createCanvas(viewport.width, viewport.height);
  const context = canvas.getContext('2d');

  await page.render({
    canvasContext: context,
    viewport: viewport
  }).promise;

  return canvas.toBuffer('image/png');
}

// Direct JPEG Stream Extractor
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
    if (jpegBuffer.length > 2000) {
      jpegs.push(jpegBuffer);
    }
    offset = end + 2;
  }
  return jpegs;
}

// Image Extraction Handler (Embedded Stream + Canvas Crop Fallback)
async function extractImages(pdfBuffer) {
  let userIMG = '';
  let signIMG = '';

  // 1. Direct JPEG Extraction (Fast & Best Quality)
  const extractedJpegs = extractJpegsFromBuffer(pdfBuffer);
  if (extractedJpegs.length > 0) {
    userIMG = `data:image/jpeg;base64,${extractedJpegs[0].toString('base64')}`;
  }
  if (extractedJpegs.length > 1) {
    signIMG = `data:image/jpeg;base64,${extractedJpegs[1].toString('base64')}`;
  }

  // 2. Canvas Crop Fallback if Images Missing from Stream
  if (!userIMG || !signIMG) {
    try {
      const pageBuffer = await renderPdfPageToBuffer(pdfBuffer);
      const metadata = await sharp(pageBuffer).metadata();
      const w = metadata.width;
      const h = metadata.height;

      if (!userIMG) {
        // EC Server Copy Photo Location (Top Left Area)
        const croppedUser = await sharp(pageBuffer)
          .extract({
            left: Math.floor(w * 0.02),
            top: Math.floor(h * 0.01),
            width: Math.floor(w * 0.28),
            height: Math.floor(h * 0.22)
          })
          .png()
          .toBuffer();
        userIMG = `data:image/png;base64,${croppedUser.toString('base64')}`;
      }

      if (!signIMG) {
        // EC Server Copy Signature Location (Top Right / Under Name Area)
        const croppedSign = await sharp(pageBuffer)
          .extract({
            left: Math.floor(w * 0.65),
            top: Math.floor(h * 0.18),
            width: Math.floor(w * 0.30),
            height: Math.floor(h * 0.08)
          })
          .grayscale()
          .threshold(180)
          .trim()
          .png()
          .toBuffer();

        if (croppedSign.length > 500) {
          signIMG = `data:image/png;base64,${croppedSign.toString('base64')}`;
        }
      }
    } catch (err) {
      console.error('Canvas image crop fallback error:', err);
    }
  }

  return { userIMG, signIMG };
}

// WEB UI - HTML UPLOAD PAGE (GET /)
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
      .img-box div { text-align: center; flex: 1; min-width: 120px; }
      .img-box img { max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 5px; padding: 5px; background: #fafafa; }
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
          <div>
            <p><b>User Photo</b></p>
            <img id="userImg" src="" alt="User Photo">
          </div>
          <div>
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
            document.getElementById('userImg').src = data.data.userIMG || '';
            document.getElementById('signImg').src = data.data.signIMG || '';
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

    // 1. Text Parsing using PDF.js
    const text = await extractPdfText(pdfBuffer);

    const nameBangla = cleanBanglaName(extractBetween(text, 'Name(Bangla)', 'Name(English)'));
    const nameEnglish = cleanText(extractBetween(text, 'Name(English)', 'Date of Birth')).toUpperCase();
    const nid = extractNid(text);
    const pin = extractPin(text);
    const dobRaw = cleanText(extractBetween(text, 'Date of Birth', 'Birth Place'));
    const dob = formatDateOfBirth(dobRaw);

    const fatherName = cleanBanglaName(extractBetween(text, 'Father Name', 'Mother Name'));
    const motherName = cleanBanglaName(extractBetween(text, 'Mother Name', 'Spouse Name'));

    // 2. Image & Signature Extraction
    const { userIMG, signIMG } = await extractImages(pdfBuffer);

    // Response Object Structure
    const responseData = {
      nameBangla: nameBangla,
      nameEnglish: nameEnglish,
      nationalId: nid,
      pin: pin,
      dateOfBirth: dob,
      dateOfToday: formatTodayBangla(),
      fatherName: fatherName,
      motherName: motherName,
      gender: cleanText(extractBetween(text, 'Gender', 'Marital')),
      religion: cleanText(extractBetween(text, 'Religion', 'Religion Other')),
      birthPlace: cleanText(extractBetween(text, 'Birth Place', 'Birth Other')),
      bloodGroup: cleanText(extractBetween(text, 'Blood Group', 'TIN')),
      userIMG: userIMG,
      signIMG: signIMG,
      address: combineAddress(text)
    };

    return res.status(200).json({
      code: 200,
      success: true,
      message: 'Data fetched successfully',
      data: responseData
    });

  } catch (error) {
    console.error('PDF Processing Error:', error);
    return res.status(500).json({
      code: 500,
      success: false,
      message: 'Error processing PDF: ' + error.message
    });
  }
});

module.exports = app;
