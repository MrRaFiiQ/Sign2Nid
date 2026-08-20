<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

function baseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NID PDF Extraction System</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;margin:0;padding:30px}
.card{max-width:620px;margin:40px auto;background:#fff;padding:28px;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
h1{text-align:center;margin-top:0}
input[type=file]{width:100%;padding:12px;box-sizing:border-box}
button{width:100%;padding:16px;margin-top:18px;border:0;border-radius:8px;background:#062a4d;color:#fff;font-size:18px;cursor:pointer}
button:disabled{opacity:.65}
pre{white-space:pre-wrap;word-break:break-word;background:#111;color:#eee;padding:15px;border-radius:8px;margin-top:20px}
</style>
</head>
<body>
<div class="card">
<h1>NID PDF Extraction System</h1>
<form id="form">
<input type="file" name="pdf" accept="application/pdf,.pdf" required>
<button id="btn" type="submit">প্রসেস করুন</button>
</form>
<pre id="result" style="display:none"></pre>
</div>
<script>
const form=document.getElementById('form');
const btn=document.getElementById('btn');
const result=document.getElementById('result');

form.addEventListener('submit',async e=>{
  e.preventDefault();
  const fd=new FormData(form);
  btn.disabled=true;
  btn.textContent='প্রসেস করা হচ্ছে...';
  result.style.display='none';
  try{
    const r=await fetch('extract.php',{method:'POST',body:fd});
    const text=await r.text();
    let data;
    try{data=JSON.parse(text)}catch(_){throw new Error('Server returned invalid JSON: '+text.slice(0,500))}
    result.textContent=JSON.stringify(data,null,2);
    result.style.display='block';
    if(!data.success) alert('এরর: '+(data.message||'Error processing the PDF.'));
  }catch(err){
    alert('এরর: '+err.message);
  }finally{
    btn.disabled=false;
    btn.textContent='প্রসেস করুন';
  }
});
</script>
</body>
</html>
