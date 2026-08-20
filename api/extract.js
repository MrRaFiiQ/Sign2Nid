const pdfParse = require('pdf-parse/lib/pdf-parse.js');
const formidable = require('formidable');
const fs = require('fs');
const Jimp = require('jimp');

module.exports = async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ code: 405, message: 'Method Not Allowed' });
  }

  try {
    const { files } = await new Promise((resolve, reject) => {
      const parseForm = typeof formidable === 'function' ? formidable : formidable.formidable;
      const form = parseForm({ multiples: false, keepExtensions: true });
      
      form.parse(req, (err, fields, files) => {
        if (err) reject(err);
        else resolve({ fields, files });
      });
    });

    let rawFile = files.nid_pdf;
    if (Array.isArray(rawFile)) rawFile = rawFile[0];

    if (!rawFile || !rawFile.filepath) {
      return res.status(400).json({ code: 400, message: 'No PDF file uploaded' });
    }

    const dataBuffer = fs.readFileSync(rawFile.filepath);
    
    // ১. PDF টেক্সট পার্স ও ক্লিন করা
    const pdfData = await pdfParse(dataBuffer);
    let text = pdfData.text || '';
    
    // অদরকারি কন্ট্রোল ক্যারেক্টার ও অনাকাঙ্ক্ষিত সিম্বল রিমুভ
    text = text.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, ' ');

    // ২. PDF থেকে JPEG ইমেজ স্ট্রিম এক্সট্রাক্ট করে PNG-তে কনভার্ট করা
    const extractImagesAsPng = async (buffer) => {
      const pngImages = [];
      let start = 0;
      while ((start = buffer.indexOf(Buffer.from([0xFF, 0xD8, 0xFF]), start)) !== -1) {
        let end = buffer.indexOf(Buffer.from([0xFF, 0xD9]), start);
        if (end !== -1) {
          end += 2;
          const imgBuf = buffer.slice(start, end);
          if (imgBuf.length > 300) { // ফিল্টার (ছোট আইকন বাদ দেওয়ার জন্য)
            try {
              // Jimp ব্যবহার করে Jpeg বাফারকে PNG Base64-এ কনভার্ট করা
              const img = await Jimp.read(imgBuf);
              const pngBuffer = await img.getBufferAsync(Jimp.MIME_PNG);
              pngImages.push('data:image/png;base64,' + pngBuffer.toString('base64'));
            } catch (err) {
              // কনভার্ট ফেইল হলে ডিফল্ট Jpeg রাখা হবে
              pngImages.push('data:image/jpeg;base64,' + imgBuf.toString('base64'));
            }
          }
          start = end;
        } else {
          break;
        }
      }
      return pngImages;
    };

    const extractedImages = await extractImagesAsPng(dataBuffer);
    const userIMG = extractedImages[0] || "";
    const signIMG = extractedImages[1] || "";

    // ৩. উন্নত Regex প্যাটার্ন (Lookahead ব্যবহার করে যাতে এক ফিল্ডের ডাটা অন্য ফিল্ডে না যায়)
    const getMatch = (pattern) => {
      const match = text.match(pattern);
      return match && match[1] ? match[1].trim().replace(/\s+/g, ' ') : "";
    };

    // নির্দিষ্ট কি-ওয়ার্ডের আগে থেমে যাওয়ার জন্য Lookahead (?=\s*(?:...)) ব্যবহার করা হয়েছে
    const nameBangla = getMatch(/(?:নাম\s*\(বাংলা\)|\bনাম)\s*[:ঃ]?\s*(.*?)(?=\s*(?:Name|পিতা|মাতা|Date of Birth|National ID|Pin|$))/i);
    const nameEnglish = getMatch(/(?:Name\s*\(English\)|\bName)\s*[:ঃ]?\s*([A-Za-z\s.\-]+?)(?=\s*(?:Date of Birth|National ID|Pin|Blood Group|পিতা|মাতা|$))/i);
    const nationalId = getMatch(/(?:National ID|NID|জাতীয় পরিচয়পত্র নম্বর)\s*[:ঃ]?\s*([0-9\s]{9,17})/i).replace(/\s+/g, '');
    const pin = getMatch(/(?:Pin|পিন)\s*[:ঃ]?\s*([0-9\s]{10,17})/i).replace(/\s+/g, '');
    const dateOfBirth = getMatch(/(?:Date of Birth|জন্ম তারিখ)\s*[:ঃ]?\s*([0-9]{2}[\/\s\-]+[A-Za-z0-9]+[\/\s\-]+[0-9]{4})/i);
    const fatherName = getMatch(/(?:পিতা|Father Name)\s*[:ঃ]?\s*(.*?)(?=\s*(?:মাতা|Mother Name|Date of Birth|National ID|Pin|$))/i);
    const motherName = getMatch(/(?:মাতা|Mother Name)\s*[:ঃ]?\s*(.*?)(?=\s*(?:ঠিকানা|Address|Date of Birth|National ID|Pin|$))/i);
    const birthPlace = getMatch(/(?:জন্ম\s*স্থান|Place of Birth)\s*[:ঃ]?\s*(.*?)(?=\s*(?:ঠিকানা|Address|Blood Group|Date of Birth|$))/i);
    const bloodGroup = getMatch(/(?:Blood Group|রক্তের গ্রুপ)\s*[:ঃ]?\s*([A-Z]{1,2}[+-])/i);
    const address = getMatch(/(?:ঠিকানা|Address)\s*[:ঃ]?\s*(.*?)(?=\s*(?:Blood Group|Date of Birth|National ID|$))/i);

    const getBanglaDate = () => {
      const en = ['0','1','2','3','4','5','6','7','8','9'];
      const bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
      const dateStr = new Date().toLocaleDateString('en-GB').replace(/\//g, '-');
      return dateStr.replace(/[0-9]/g, w => bn[en.indexOf(w)]);
    };

    return res.status(200).json({
      code: 200,
      success: true,
      message: "Data fetched successfully",
      data: {
        nameBangla: nameBangla,
        nameEnglish: nameEnglish,
        nationalId: nationalId,
        pin: pin,
        dateOfBirth: dateOfBirth,
        dateOfToday: getBanglaDate(),
        fatherName: fatherName,
        motherName: motherName,
        gender: "male",
        religion: "Islam",
        birthPlace: birthPlace,
        bloodGroup: bloodGroup || "B+",
        userIMG: userIMG,
        signIMG: signIMG,
        address: address
      }
    });

  } catch (error) {
    return res.status(500).json({
      code: 500,
      message: "PDF extraction failed",
      error: error.message || String(error)
    });
  }
};

module.exports.config = {
  api: {
    bodyParser: false,
  },
};
