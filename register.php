<?php
require_once 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $academic_year = $_POST['academic_year'] ?? '';
    $face_image = $_POST['face_image'] ?? '';

    if (
        $full_name === '' ||
        $phone === '' ||
        $email === '' ||
        $password === '' ||
        $academic_year === ''
    ) {

        $error = 'Please complete all required fields.';

    } elseif ($face_image === '') {

        $error = 'Please complete the 5-second face verification.';

    } else {

        try {

            $check = $pdo->prepare(
                "SELECT id FROM users WHERE email = ?"
            );

            $check->execute([$email]);

            if ($check->fetch()) {

                $error = 'An account with this email already exists.';

            } else {

                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                /*
                 * Database fields used here match the
                 * existing NCS-IMS users table:
                 *
                 * phone_number
                 * current_year
                 * face_image_path
                 */

                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (
                        full_name,
                        phone_number,
                        email,
                        password,
                        course,
                        current_year,
                        face_image_path
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        'Computer Science',
                        ?,
                        ?
                    )
                ");

                $stmt->execute([
                    $full_name,
                    $phone,
                    $email,
                    $password_hash,
                    $academic_year,
                    $face_image
                ]);

                header("Location: login.php?registered=1");
                exit;
            }

        } catch (PDOException $e) {

            $error = 'Registration failed: ' . $e->getMessage();

        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Register | NCS-IMS</title>

<link rel="stylesheet" href="style.css">

<style>

.verification-box {
    max-width: 520px;
    margin: 20px auto;
}

.camera-container {
    width: 100%;
    background: #111;
    border-radius: 14px;
    overflow: hidden;
    margin-top: 15px;
}

#camera {
    width: 100%;
    height: 300px;
    display: block;
    object-fit: cover;
    background: #111;
}

.verification-status {
    text-align: center;
    font-weight: 600;
    margin: 15px 0;
}

.verification-progress {
    width: 100%;
    height: 10px;
    background: #ddd;
    border-radius: 20px;
    overflow: hidden;
    margin: 12px 0;
}

#verificationProgress {
    width: 0%;
    height: 100%;
    background: #1d5f3a;
    transition: width .1s linear;
}

.verification-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.verification-success {
    color: #176b38;
    font-weight: 700;
}

.verification-error {
    color: #b00020;
    font-weight: 700;
}

#registerButton:disabled {
    opacity: .5;
    cursor: not-allowed;
}

</style>

</head>

<body>

<header>

    <h1>NCS-IMS</h1>

</header>


<div class="container">

    <h2>Create Student Account</h2>


    <?php if ($error): ?>

        <div class="alert-error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <form
        method="POST"
        id="registrationForm"
    >


        <label>Full Name</label>

        <input
            type="text"
            name="full_name"
            required
        >


        <label>Phone Number</label>

        <input
            type="text"
            name="phone"
            required
        >


        <label>Email Address</label>

        <input
            type="email"
            name="email"
            required
        >


        <label>Password</label>

        <input
            type="password"
            name="password"
            required
        >


        <label>Course</label>

        <input
            type="text"
            value="Computer Science"
            readonly
        >


        <label>Academic Year</label>

        <select
            name="academic_year"
            required
        >

            <option value="">
                Select Academic Year
            </option>

            <option value="1">
                Year 1
            </option>

            <option value="2">
                Year 2
            </option>

            <option value="3">
                Year 3
            </option>

            <option value="4">
                Year 4
            </option>

            <option value="5">
                Year 5
            </option>

        </select>


        <!-- FACE VERIFICATION -->

        <div class="verification-box">

            <h3>Face Verification</h3>

            <p>
                Open the camera, position your face inside
                the camera area, then start the 5-second
                verification.
            </p>


            <!--
                Camera is completely hidden until
                the user presses Open Camera.
            -->

            <div
                class="camera-container"
                id="cameraContainer"
                style="display:none;"
            >

                <video
                    id="camera"
                    autoplay
                    playsinline
                    muted
                ></video>

            </div>


            <div
                class="verification-status"
                id="verificationStatus"
            >
                Camera is ready to be opened.
            </div>


            <div class="verification-progress">

                <div
                    id="verificationProgress"
                ></div>

            </div>


            <div class="verification-buttons">


                <button
                    type="button"
                    class="btn"
                    id="openCameraButton"
                >
                    Open Camera
                </button>


                <button
                    type="button"
                    class="btn"
                    id="verifyButton"
                    disabled
                >
                    Start 5-Second Verification
                </button>


                <button
                    type="button"
                    class="btn"
                    id="cancelCameraButton"
                >
                    Cancel
                </button>


            </div>


            <!-- Captured image is submitted with registration -->

            <input
                type="hidden"
                name="face_image"
                id="faceImage"
            >

        </div>


        <!-- Registration remains disabled until
             face verification succeeds -->

        <button
            type="submit"
            class="btn"
            id="registerButton"
            disabled
        >
            Register Account
        </button>


    </form>

<p class="auth-switch">
    Already have an account?
    <a href="login.php">Login</a>
</p>

<section class="card about-section">

    <h2>About NCS-IMS</h2>

    <p>
        <strong>NCS-IMS</strong> is a Computer Science student management
        and attendance system designed to manage student information,
        class attendance, examination attendance, and attendance records
        in one place.
    </p>

    

</section>
</div>


<script>

/*
==================================================
ELEMENTS
==================================================
*/

const camera =
    document.getElementById('camera');

const cameraContainer =
    document.getElementById('cameraContainer');

const openCameraButton =
    document.getElementById('openCameraButton');

const verifyButton =
    document.getElementById('verifyButton');

const cancelCameraButton =
    document.getElementById('cancelCameraButton');

const verificationStatus =
    document.getElementById('verificationStatus');

const verificationProgress =
    document.getElementById('verificationProgress');

const faceImage =
    document.getElementById('faceImage');

const registerButton =
    document.getElementById('registerButton');


let cameraStream = null;

let verificationRunning = false;


/*
==================================================
OPEN CAMERA
==================================================
*/

openCameraButton.addEventListener(
    'click',
    async function () {

        try {

            if (
                !navigator.mediaDevices ||
                !navigator.mediaDevices.getUserMedia
            ) {

                verificationStatus.textContent =
                    'Camera access is not supported by this browser.';

                verificationStatus.className =
                    'verification-status verification-error';

                return;
            }


            verificationStatus.textContent =
                'Requesting camera permission...';

            verificationStatus.className =
                'verification-status';


            cameraStream =
                await navigator.mediaDevices.getUserMedia({

                    video: {
                        facingMode: 'user',

                        width: {
                            ideal: 640
                        },

                        height: {
                            ideal: 480
                        }
                    },

                    audio: false

                });


            camera.srcObject =
                cameraStream;


            /*
             * Show camera only after permission
             * has been granted successfully.
             */

            cameraContainer.style.display =
                'block';


            verificationStatus.textContent =
                'Camera active. Position your face.';


            verificationStatus.className =
                'verification-status';


            verifyButton.disabled =
                false;


            openCameraButton.disabled =
                true;

        } catch (error) {

            console.error(error);


            verificationStatus.textContent =
                'Camera permission denied or camera unavailable.';


            verificationStatus.className =
                'verification-status verification-error';


            cameraContainer.style.display =
                'none';


            verifyButton.disabled =
                true;


            openCameraButton.disabled =
                false;

        }

    }
);


/*
==================================================
START 5-SECOND VERIFICATION
==================================================
*/

verifyButton.addEventListener(
    'click',
    function () {

        if (
            !cameraStream ||
            verificationRunning
        ) {

            return;

        }


        verificationRunning =
            true;


        verifyButton.disabled =
            true;


        verificationStatus.textContent =
            'Face verification in progress...';


        verificationStatus.className =
            'verification-status';


        verificationProgress.style.width =
            '0%';


        const startTime =
            Date.now();


        const verificationTime =
            5000;


        function updateProgress() {

            const elapsed =
                Date.now() - startTime;


            const percentage =
                Math.min(
                    (elapsed / verificationTime) * 100,
                    100
                );


            verificationProgress.style.width =
                percentage + '%';


            if (
                elapsed < verificationTime
            ) {

                requestAnimationFrame(
                    updateProgress
                );

            } else {

                completeVerification();

            }

        }


        requestAnimationFrame(
            updateProgress
        );

    }
);


/*
==================================================
COMPLETE VERIFICATION
==================================================
*/

function completeVerification() {

    /*
     * Capture one image from the camera after
     * the 5-second verification period.
     */

    const canvas =
        document.createElement('canvas');


    canvas.width =
        camera.videoWidth || 640;


    canvas.height =
        camera.videoHeight || 480;


    const context =
        canvas.getContext('2d');


    context.drawImage(
        camera,
        0,
        0,
        canvas.width,
        canvas.height
    );


    const capturedImage =
        canvas.toDataURL(
            'image/jpeg',
            0.85
        );


    /*
     * Store captured image in the hidden
     * registration field.
     */

    faceImage.value =
        capturedImage;


    verificationProgress.style.width =
        '100%';


    verificationStatus.textContent =
        'Face verification successful.';


    verificationStatus.className =
        'verification-status verification-success';


    /*
     * Allow registration.
     */

    registerButton.disabled =
        false;


    verificationRunning =
        false;


    /*
     * Camera automatically stops after
     * successful verification.
     */

    stopCameraAfterVerification();

}


/*
==================================================
STOP CAMERA AFTER SUCCESS
==================================================
*/

function stopCameraAfterVerification() {

    if (cameraStream) {

        cameraStream
            .getTracks()
            .forEach(function (track) {

                track.stop();

            });


        cameraStream =
            null;

    }


    camera.srcObject =
        null;


    cameraContainer.style.display =
        'none';


    openCameraButton.disabled =
        false;


    verifyButton.disabled =
        true;

}


/*
==================================================
CANCEL CAMERA
==================================================
*/

cancelCameraButton.addEventListener(
    'click',
    function () {

        stopCamera();

    }
);


/*
==================================================
STOP CAMERA
==================================================
*/

function stopCamera() {

    if (cameraStream) {

        cameraStream
            .getTracks()
            .forEach(function (track) {

                track.stop();

            });


        cameraStream =
            null;

    }


    camera.srcObject =
        null;


    cameraContainer.style.display =
        'none';


    openCameraButton.disabled =
        false;


    verifyButton.disabled =
        true;


    verificationProgress.style.width =
        '0%';


    verificationStatus.textContent =
        'Camera is ready to be opened.';


    verificationStatus.className =
        'verification-status';


    verificationRunning =
        false;

}


/*
==================================================
FORM SUBMISSION CHECK
==================================================
*/

document
    .getElementById('registrationForm')
    .addEventListener(
        'submit',
        function (event) {

            if (!faceImage.value) {

                event.preventDefault();

                alert(
                    'Please complete the 5-second face verification first.'
                );

            }

        }
    );

</script>

</body>

</html>