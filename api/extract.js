const pdfParse = require('pdf-parse/lib/pdf-parse.js');
const formidable = require('formidable');
const fs = require('fs');

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

    // ২. PDF থেকে JPEG ইমেজ স্ট্রিম এক্সট্রাক্ট করা (স্বাক্ষরের জন্য থ্রেশহোল্ড ৩০০ বাইটে নামানো হয়েছে)
    const extractJpegs = (buffer) => {
      const jpegs = [];
      let start = 0;
      while ((start = buffer.indexOf(Buffer.from([0xFF, 0xD8, 0xFF]), start)) !== -1) {
        let end = buffer.indexOf(Buffer.from([0xFF, 0xD9]), start);
        if (end !== -1) {
          end += 2;
          const imgBuf = buffer.slice(start, end);
          if (imgBuf.length > 300) { // ৩০০ বাইটের ওপর ইমেজ ফিল্টার করবে
            jpegs.push('data:image/jpeg;base64,' + imgBuf.toString('base64'));
          }
          start = end;
        } else {
          break;
        }
      }
      return jpegs;
    };

    const extractedImages = extractJpegs(dataBuffer);
    const userIMG = extractedImages[0] || "";
    const signIMG = extractedImages[1] || "";

    // ৩. উন্নত Regex প্যাটার্ন দিয়ে ডাটা ফিল্টারিং
    const getMatch = (pattern) => {
      const match = text.match(pattern);
      return match && match[1] ? match[1].trim().replace(/\s+/g, ' ') : "";
    };

    const nameBangla = getMatch(/(?:নাম\s*\(বাংলা\)|\bনাম)\s*[:ঃ]?\s*([^\n\r\t:]+)/i);
    const nameEnglish = getMatch(/(?:Name\s*\(English\)|\bName)\s*[:ঃ]?\s*([A-Za-z\s.]+)/i);
    const nationalId = getMatch(/(?:National ID|NID|জাতীয় পরিচয়পত্র নম্বর)\s*[:ঃ]?\s*([0-9\s]+)/i).replace(/\s+/g, '');
    const pin = getMatch(/(?:Pin|পিন)\s*[:ঃ]?\s*([0-9\s]+)/i).replace(/\s+/g, '');
    const dateOfBirth = getMatch(/(?:Date of Birth|জন্ম তারিখ)\s*[:ঃ]?\s*([0-9]{2}[\/\s\-]+[A-Za-z0-9]+[\/\s\-]+[0-9]{4})/i);
    const fatherName = getMatch(/(?:পিতা|Father Name)\s*[:ঃ]?\s*([^\n\r\t:]+)/i);
    const motherName = getMatch(/(?:মাতা|Mother Name)\s*[:ঃ]?\s*([^\n\r\t:]+)/i);
    const birthPlace = getMatch(/(?:জন্ম\s*স্থান|Place of Birth)\s*[:ঃ]?\s*([^\n\r\t:]+)/i);
    const bloodGroup = getMatch(/(?:Blood Group|রক্তের গ্রুপ)\s*[:ঃ]?\s*([A-Z][+-])/i);
    const address = getMatch(/(?:ঠিকানা|Address)\s*[:ঃ]?\s*([^\n\r\t:]+)/i);

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
