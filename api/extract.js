const pdfParse = require('pdf-parse/lib/pdf-parse.js');
const formidable = require('formidable');
const fs = require('fs');

module.exports = async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ code: 405, message: 'Method Not Allowed' });
  }

  try {
    const { files } = await new Promise((resolve, reject) => {
      const form = formidable({ multiples: false, keepExtensions: true });
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
    
    // ১. PDF টেক্সট পার্স করা (Vercel Fix)
    const pdfData = await pdfParse(dataBuffer);
    const text = pdfData.text || '';

    // ২. PDF থেকে JPEG ইমেজ স্ট্রিম এক্সট্রাক্ট
    const extractJpegs = (buffer) => {
      const jpegs = [];
      let start = 0;
      while ((start = buffer.indexOf(Buffer.from([0xFF, 0xD8, 0xFF]), start)) !== -1) {
        let end = buffer.indexOf(Buffer.from([0xFF, 0xD9]), start);
        if (end !== -1) {
          end += 2;
          const imgBuf = buffer.slice(start, end);
          if (imgBuf.length > 2000) {
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

    // ৩. ডাটা ফিল্টারিং (Regex)
    const nameBanglaMatch = text.match(/নাম\s*[:ঃ]?\s*(.*)/);
    const nameEnglishMatch = text.match(/Name\s*[:ঃ]?\s*(.*)/i);
    const nidMatch = text.match(/National ID\s*[:ঃ]?\s*([0-9\s]+)/i);
    const pinMatch = text.match(/Pin\s*[:ঃ]?\s*([0-9\s]+)/i);
    const dobMatch = text.match(/Date of Birth\s*[:ঃ]?\s*([0-9]{2}\s+[A-Za-z]{3}\s+[0-9]{4})/i);
    const fatherMatch = text.match(/পিতা\s*[:ঃ]?\s*(.*)/);
    const motherMatch = text.match(/মাতা\s*[:ঃ]?\s*(.*)/);
    const birthPlaceMatch = text.match(/জন্ম\s*স্থান\s*[:ঃ]?\s*(.*)/);
    const bloodMatch = text.match(/Blood Group\s*[:ঃ]?\s*([A-Z][+-])/i);
    const addressMatch = text.match(/ঠিকানা\s*[:ঃ]?\s*(.*)/);

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
        nameBangla: nameBanglaMatch ? nameBanglaMatch[1].trim() : "",
        nameEnglish: nameEnglishMatch ? nameEnglishMatch[1].trim() : "",
        nationalId: nidMatch ? nidMatch[1].replace(/\s+/g, '').trim() : "",
        pin: pinMatch ? pinMatch[1].replace(/\s+/g, '').trim() : "",
        dateOfBirth: dobMatch ? dobMatch[1].trim() : "",
        dateOfToday: getBanglaDate(),
        fatherName: fatherMatch ? fatherMatch[1].trim() : "",
        motherName: motherMatch ? motherMatch[1].trim() : "",
        gender: "male",
        religion: "Islam",
        birthPlace: birthPlaceMatch ? birthPlaceMatch[1].trim() : "",
        bloodGroup: bloodMatch ? bloodMatch[1].trim() : "B+",
        userIMG: userIMG,
        signIMG: signIMG,
        address: addressMatch ? addressMatch[1].trim() : ""
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
