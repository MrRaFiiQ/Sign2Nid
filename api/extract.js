const pdfParse = require('pdf-parse');
const formidable = require('formidable');
const fs = require('fs');

export const config = {
  api: {
    bodyParser: false,
  },
};

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ code: 405, message: 'Method Not Allowed' });
  }

  const form = formidable({ multiples: false });

  form.parse(req, async (err, fields, files) => {
    if (err || !files.nid_pdf) {
      return res.status(400).json({ code: 400, message: 'File upload error' });
    }

    try {
      const file = Array.isArray(files.nid_pdf) ? files.nid_pdf[0] : files.nid_pdf;
      const dataBuffer = fs.readFileSync(file.filepath);
      const pdfData = await pdfParse(dataBuffer);
      const text = pdfData.text;

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

      const responseData = {
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
          userIMG: "",
          signIMG: "",
          address: addressMatch ? addressMatch[1].trim() : ""
        }
      };

      res.status(200).json(responseData);
    } catch (error) {
      res.status(500).json({ code: 500, message: "PDF extraction failed", error: error.message });
    }
  });
}
