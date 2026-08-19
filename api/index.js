const express = require('express');
const multer = require('multer');
const pdfParse = require('pdf-parse');
const pdfImgConvert = require('pdf-img-convert');
const sharp = require('sharp');
const cors = require('cors');

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

function cleanText(text) {
  if (!text) return '';
  return text
    .replace(/["\r\n\t,]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function cleanBanglaName(text) {
  if (!text) return '';
  let cleaned = text
    .replace(/halnagad_\d+/gi, '')
    .replace(/Tag/gi, '')
    .replace(/Name\(Bangla\)/gi, '');
  return cleanText(cleaned);
}

function convertToBangla(numberStr) {
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
  return match ? match[1] : '২০৫০';
}

function combineAddress(text) {
  let villageRaw = extractBetween(text, 'Village/Road', 'Home/Holding');
  if (!villageRaw) villageRaw = extractBetween(text, 'Village/Road', 'Post Office');
  let village = cleanText(villageRaw.replace(/Village\/Road|Home\/Holding|Additional|No\.|No/gi, ''));

  let homeRaw = extractBetween(text, 'Home/Holding', 'Post Office');
  if (!homeRaw) homeRaw = extractBetween(text, 'Home/Holding', 'Postal Code');
  let home = cleanText(homeRaw.replace(/Home\/Holding|Village\/Road|Additional|No\.|No/gi, ''));

  let postOffice = cleanText(extractBetween(text, 'Post Office', 'Postal Code'));
  let postalCode = extractPostalCode(text);
  let postalCodeBangla = convertToBangla(postalCode);

  let upozila = cleanText(extractBetween(text, 'Upozila', 'Union'));
  if (!upozila) upozila = cleanText(extractBetween(text, 'Upozila', 'Municipality'));

  let district = cleanText(extractBetween(text, 'District', 'RMO'));
  if (!district) district = cleanText(extractBetween(text, 'District', 'City'));

  let parts = [];
  if (home) parts.push('বাসা/হোল্ডিং: ' + home);
  if (village) parts.push('গ্রাম/রাস্তা: ' + village);
  parts.push('ডাকঘর: ' + postOffice + ' -' + postalCodeBangla);
  if (upozila) parts.push(upozila);
  if (district) parts.push(district);

  return parts.join(', ');
}

function formatDateOfBirth(dobRaw) {
  if (!dobRaw) return '';
  const dateObj = new Date(dobRaw);
  if (isNaN(dateObj.getTime())) return '';
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

// MAIN API ENDPOINT
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

    const nameBangla = cleanBanglaName(extractBetween(text, 'Name(Bangla)', 'Name(English)'));
    const nameEnglish = cleanText(extractBetween(text, 'Name(English)', 'Date of Birth')).toUpperCase();
    const nid = extractNid(text);
    const pin = extractPin(text);
    const dobRaw = cleanText(extractBetween(text, 'Date of Birth', 'Birth Place'));
    const dob = formatDateOfBirth(dobRaw);

    // 2. High Resolution Image Rendering (Page 1)
    let userImgBase64 = '';
    let signImgBase64 = '';

    try {
      const outputImages = await pdfImgConvert.convert(pdfBuffer, { page_numbers: [1], scale: 2.0 });
      if (outputImages && outputImages.length > 0) {
        const page1Buffer = Buffer.from(outputImages[0]);
        const imagePipeline = sharp(page1Buffer);
        const metadata = await imagePipeline.metadata();

        const w = metadata.width;
        const h = metadata.height;

        // User Image Crop Logic
        const userCropRect = {
          left: Math.floor(w * 0.60),
          top: Math.floor(h * 0.005),
          width: Math.floor(w * 0.36),
          height: Math.floor(h * 0.22)
        };

        const croppedUser = await sharp(page1Buffer)
          .extract(userCropRect)
          .png()
          .toBuffer();
        userImgBase64 = `data:image/png;base64,${croppedUser.toString('base64')}`;

        // Signature Crop & Auto-trim Background Logic
        const signCropRect = {
          left: Math.floor(w * 0.05),
          top: Math.floor(h * 0.25),
          width: Math.floor(w * 0.63),
          height: Math.floor(h * 0.05)
        };

        const croppedSign = await sharp(page1Buffer)
          .extract(signCropRect)
          .threshold(160)
          .trim()
          .png()
          .toBuffer();
        signImgBase64 = `data:image/png;base64,${croppedSign.toString('base64')}`;
      }
    } catch (imgError) {
      console.error('Image Extraction Error:', imgError);
    }

    // Response Object Structure
    const responseData = {
      nameBangla: nameBangla,
      nameEnglish: nameEnglish,
      nationalId: nid,
      pin: pin,
      dateOfBirth: dob,
      dateOfToday: formatTodayBangla(),
      fatherName: cleanText(extractBetween(text, 'Father Name', 'Mother Name')),
      motherName: cleanText(extractBetween(text, 'Mother Name', 'Spouse Name')),
      gender: cleanText(extractBetween(text, 'Gender', 'Marital')),
      religion: cleanText(extractBetween(text, 'Religion', 'Religion Other')),
      birthPlace: cleanText(extractBetween(text, 'Birth Place', 'Birth Other')),
      bloodGroup: cleanText(extractBetween(text, 'Blood Group', 'TIN')),
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

module.exports = app;  const month = String(today.getMonth() + 1).padStart(2, '0');
  const year = today.getFullYear();
  return convertToBangla(`${day}-${month}-${year}`);
}

// MAIN API ENDPOINT
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

    const nameBangla = cleanBanglaName(extractBetween(text, 'Name(Bangla)', 'Name(English)'));
    const nameEnglish = cleanText(extractBetween(text, 'Name(English)', 'Date of Birth')).toUpperCase();
    const nid = extractNid(text);
    const pin = extractPin(text);
    const dobRaw = cleanText(extractBetween(text, 'Date of Birth', 'Birth Place'));
    const dob = formatDateOfBirth(dobRaw);

    // 2. High Resolution Image Rendering (Page 1)
    let userImgBase64 = '';
    let signImgBase64 = '';

    try {
      const outputImages = await pdfImgConvert.convert(pdfBuffer, { page_numbers: [1], scale: 2.0 });
      if (outputImages && outputImages.length > 0) {
        const page1Buffer = Buffer.from(outputImages[0]);
        const imagePipeline = sharp(page1Buffer);
        const metadata = await imagePipeline.metadata();

        const w = metadata.width;
        const h = metadata.height;

        // User Image Crop Logic
        const userCropRect = {
          left: Math.floor(w * 0.60),
          top: Math.floor(h * 0.005),
          width: Math.floor(w * 0.36),
          height: Math.floor(h * 0.22)
        };

        const croppedUser = await sharp(page1Buffer)
          .extract(userCropRect)
          .png()
          .toBuffer();
        userImgBase64 = `data:image/png;base64,${croppedUser.toString('base64')}`;

        // Signature Crop & Auto-trim Background Logic
        const signCropRect = {
          left: Math.floor(w * 0.05),
          top: Math.floor(h * 0.25),
          width: Math.floor(w * 0.63),
          height: Math.floor(h * 0.05)
        };

        const croppedSign = await sharp(page1Buffer)
          .extract(signCropRect)
          .threshold(160) // Clean signature background
          .trim()        // Trim surrounding empty white space
          .png()
          .toBuffer();
        signImgBase64 = `data:image/png;base64,${croppedSign.toString('base64')}`;
      }
    } catch (imgError) {
      console.error('Image Extraction Error:', imgError);
    }

    // Response Object Structure
    const responseData = {
      nameBangla: nameBangla,
      nameEnglish: nameEnglish,
      nationalId: nid,
      pin: pin,
      dateOfBirth: dob,
      dateOfToday: formatTodayBangla(),
      fatherName: cleanText(extractBetween(text, 'Father Name', 'Mother Name')),
      motherName: cleanText(extractBetween(text, 'Mother Name', 'Spouse Name')),
      gender: cleanText(extractBetween(text, 'Gender', 'Marital')),
      religion: cleanText(extractBetween(text, 'Religion', 'Religion Other')),
      birthPlace: cleanText(extractBetween(text, 'Birth Place', 'Birth Other')),
      bloodGroup: cleanText(extractBetween(text, 'Blood Group', 'TIN')),
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
