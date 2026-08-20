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
    
    // অদরকারি কন্ট্রোল ক্যারেক্টার রিমুভ, কিন্তু নিউলাইন ঠিক রাখা
    text = text.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, ' ');

    // ২. ইমেজ এক্সট্রাক্টর (থ্রেশহোল্ড কমিয়ে ৫০ বাইট করা হয়েছে ছোট সিগনেচার ধরার জন্য)
    const extractImagesAsPng = async (buffer) => {
      const pngImages = [];
      let start = 0;
      while ((start = buffer.indexOf(Buffer.from([0xFF, 0xD8, 0xFF]), start)) !== -1) {
        let end = buffer.indexOf(Buffer.from([0xFF, 0xD9]), start);
        if (end !== -1) {
          end += 2;
          const imgBuf = buffer.slice(start, end);
          if (imgBuf.length > 50) { // একদম ছোট ছবিও স্কিপ করবে না
            try {
              const img = await Jimp.read(imgBuf);
              const pngBuffer = await img.getBufferAsync(Jimp.MIME_PNG);
              pngImages.push('data:image/png;base64,' + pngBuffer.toString('base64'));
            } catch (err) {
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
    // দ্বিতীয় ইমেজটি সিগনেচার হিসেবে ধরার চেষ্টা
    const signIMG = extractedImages[1] || "";

    // ৩. হার্ডকোর Regex ফিল্টারিং (খুবই ব্রড প্যাটার্ন)
    const extractLine = (keywordStr) => {
        const regex = new RegExp(`(?:${keywordStr})\\s*[:ঃ]?\\s*([^\\n\\r]+)`, 'i');
        const match = text.match(regex);
        return match ? match[1].trim() : "";
    };

    // নির্দিষ্ট ফিল্ডের জন্য স্পেশাল Regex
    const nidMatch = text.match(/(?:National ID|NID|জাতীয় পরিচয়পত্র নম্বর)[\s:ঃ]*([0-9\s]{9,17})/i);
    const pinMatch = text.match(/(?:Pin|পিন)[\s:ঃ]*([0-9\s]{10,17})/i);
    const dobMatch = text.match(/(?:Date of Birth|জন্ম তারিখ|DOB)[\s:ঃ]*([0-9]{2}[\s\/\-][A-Za-z0-9]+[\s\/\-][0-9]{4})/i);
    const bloodMatch = text.match(/(?:Blood Group|রক্তের গ্রুপ)[\s:ঃ]*([A-Z]{1,2}[+-])/i);

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
        nameBangla: extractLine('নাম\\s*\\(বাংলা\\)|\\bনাম'),
        nameEnglish: extractLine('Name\\s*\\(English\\)|\\bName').replace(/Date of Birth.*/i, '').trim(),
        nationalId: nidMatch ? nidMatch[1].replace(/\s+/g, '') : "",
        pin: pinMatch ? pinMatch[1].replace(/\s+/g, '') : "",
        dateOfBirth: dobMatch ? dobMatch[1].trim() : extractLine('Date of Birth|জন্ম তারিখ'),
        dateOfToday: getBanglaDate(),
        fatherName: extractLine('পিতা|Father Name'),
        motherName: extractLine('মাতা|Mother Name'),
        gender: "male",
        religion: "Islam",
        birthPlace: extractLine('জন্ম\\s*স্থান|Place of Birth'),
        bloodGroup: bloodMatch ? bloodMatch[1].trim() : "B+",
        userIMG: userIMG,
        signIMG: signIMG,
        address: extractLine('ঠিকানা|Address')
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
