<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>NID PDF Extraction System</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 30px 15px;
    font-family:
        Arial,
        "Noto Sans Bengali",
        sans-serif;
    background: #f4f6f9;
    color: #111;
}

.container {
    max-width: 900px;
    margin: auto;
    background: white;
    padding: 40px;
    border-radius: 18px;
    box-shadow:
        0 10px 35px rgba(0,0,0,.08);
}

h1 {
    text-align: center;
    margin-top: 0;
    margin-bottom: 35px;
}

.file-box {
    border: 1px solid #ccc;
    padding: 10px;
    border-radius: 8px;
}

input[type=file] {
    width: 100%;
    font-size: 16px;
}

button {
    width: 100%;
    margin-top: 25px;
    padding: 17px;
    border: 0;
    border-radius: 7px;
    background: #0568c9;
    color: white;
    font-size: 20px;
    cursor: pointer;
}

button:disabled {
    opacity: .6;
}

.status {
    margin-top: 20px;
    text-align: center;
    font-size: 18px;
}

.images {
    display: grid;
    grid-template-columns:
        repeat(2, 1fr);
    gap: 30px;
    margin-top: 30px;
}

.image-card {
    text-align: center;
}

.image-card img {
    max-width: 100%;
    max-height: 250px;
    object-fit: contain;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 5px;
    background: white;
}

pre {
    margin-top: 30px;
    background: #111;
    color: #65e6c1;
    padding: 25px;
    border-radius: 10px;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

@media(max-width:600px) {

    body {
        padding: 15px;
    }

    .container {
        padding: 25px 18px;
    }

    .images {
        grid-template-columns: 1fr;
    }

    h1 {
        font-size: 28px;
    }
}

</style>
</head>

<body>

<div class="container">

<h1>NID PDF Extraction System</h1>

<form id="uploadForm">

    <div class="file-box">

        <input
            type="file"
            id="pdf"
            name="pdf"
            accept="application/pdf"
            required
        >

    </div>

    <button
        type="submit"
        id="submitBtn"
    >
        ডাটা এক্সট্রাক্ট করুন
    </button>

</form>

<div
    class="status"
    id="status"
></div>


<div
    class="images"
    id="images"
    style="display:none;"
>

    <div class="image-card">

        <h3>User Photo</h3>

        <img
            id="userImage"
            alt="User Photo"
        >

    </div>


    <div class="image-card">

        <h3>Signature</h3>

        <img
            id="signImage"
            alt="Signature"
        >

    </div>

</div>


<h2>এক্সট্রাক্ট করা ডাটা (JSON):</h2>

<pre id="jsonOutput">এখনো কোনো ডাটা নেই।</pre>

</div>


<script>

const form =
    document.getElementById(
        'uploadForm'
    );

const status =
    document.getElementById(
        'status'
    );

const button =
    document.getElementById(
        'submitBtn'
    );

const output =
    document.getElementById(
        'jsonOutput'
    );

const images =
    document.getElementById(
        'images'
    );

const userImage =
    document.getElementById(
        'userImage'
    );

const signImage =
    document.getElementById(
        'signImage'
    );


form.addEventListener(
    'submit',
    async function(e) {

        e.preventDefault();

        const file =
            document.getElementById(
                'pdf'
            ).files[0];


        if (!file) {

            alert(
                'একটি PDF নির্বাচন করুন।'
            );

            return;
        }


        const formData =
            new FormData();

        formData.append(
            'pdf',
            file
        );


        button.disabled = true;

        button.textContent =
            'প্রসেস করা হচ্ছে...';

        status.textContent =
            'PDF প্রসেস করা হচ্ছে...';

        output.textContent =
            'অপেক্ষা করুন...';

        images.style.display =
            'none';


        try {

            const response =
                await fetch(
                    'extract.php',
                    {
                        method: 'POST',
                        body: formData
                    }
                );


            const text =
                await response.text();


            let data;


            try {

                data =
                    JSON.parse(text);

            }
            catch (e) {

                throw new Error(
                    'Server returned invalid JSON: ' +
                    text.substring(
                        0,
                        500
                    )
                );
            }


            output.textContent =
                JSON.stringify(
                    data,
                    null,
                    2
                );


            if (
                data.success &&
                data.data
            ) {

                status.textContent =
                    'ডাটা সফলভাবে এক্সট্রাক্ট হয়েছে।';


                const userURL =
                    data.data.userIMG;


                const signURL =
                    data.data.signIMG;


                if (userURL) {

                    userImage.src =
                        userURL;

                    images.style.display =
                        'grid';
                }


                if (signURL) {

                    signImage.src =
                        signURL;

                    images.style.display =
                        'grid';
                }

            }
            else {

                status.textContent =
                    data.message ||
                    'ডাটা পাওয়া যায়নি।';
            }

        }
        catch (error) {

            status.textContent =
                'Error: ' +
                error.message;

            output.textContent =
                error.message;
        }
        finally {

            button.disabled =
                false;

            button.textContent =
                'ডাটা এক্সট্রাক্ট করুন';
        }

    }
);

</script>

</body>
</html>
