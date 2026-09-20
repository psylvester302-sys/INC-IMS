<?php
session_start();
require_once 'db.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $face_verified = $_POST['face_verified'] ?? '';

    if (empty($email) || empty($password)) {

        $msg = "Please enter your email address and password.";

    } elseif ($face_verified !== '1') {

        $msg = "Please complete the 5-second face verification.";

    } else {

        $stmt = $pdo->prepare(
            "SELECT * FROM users WHERE email = ?"
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['course'] = $user['course'];
            $_SESSION['current_year'] = $user['current_year'];

            header("Location: dashboard.php");
            exit;

        } else {

            $msg = "Invalid email address or password.";
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

    <title>Login | NCS-IMS</title>

    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        .login-section {
            margin-top: 10px;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: #fafafa;
        }

        .camera-container {
            display: none;
            margin-top: 15px;
        }

        #loginVideo {
            width: 100%;
            max-width: 480px;
            height: auto;
            min-height: 280px;
            object-fit: cover;
            background: #000;
            border-radius: 10px;
            display: block;
        }

        .face-controls {
            margin-top: 12px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .face-status {
            display: block;
            margin-top: 12px;
            font-weight: 600;
        }

        .face-success {
            color: var(--success-green);
        }

        .face-error {
            color: var(--error-red);
        }

        .face-info {
            color: var(--accent-blue);
        }

        .scan-progress {
            display: none;
            margin-top: 12px;
            font-weight: 600;
        }

        .scan-bar-container {
            display: none;
            width: 100%;
            max-width: 480px;
            height: 8px;
            background: #ddd;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 10px;
        }

        .scan-bar {
            width: 0%;
            height: 100%;
            transition: width 0.2s linear;
            background: var(--primary-blue);
        }

        .login-button {
            width: 100%;
            margin-top: 15px;
        }

        .register-link {
            text-align: center;
            margin-top: 25px;
        }

    </style>

</head>

<body>

<header>

    <h1>NCS-IMS</h1>

    <div>
        Student Login Portal
    </div>

</header>

<div class="container">

    <h2>Login to Your Account</h2>

    <p>
        Enter your account details and complete face verification
        to access your student dashboard.
    </p>

    <?php if (isset($_GET['registered'])): ?>

        <div class="alert-success">
            Registration successful! Please login.
        </div>

    <?php endif; ?>

    <?php if ($msg): ?>

        <div class="alert-error">
            <?= htmlspecialchars($msg) ?>
        </div>

    <?php endif; ?>


    <div class="login-section">

        <form
            method="POST"
            id="loginForm"
        >

            <label>
                Email Address
            </label>

            <input
                type="email"
                name="email"
                placeholder="Enter your email address"
                required
            >


            <label>
                Password
            </label>

            <input
                type="password"
                name="password"
                placeholder="Enter your password"
                required
            >


            <label>
                Face Recognition Verification
            </label>

            <p>
                Open the camera and keep your face visible
                during the 5-second verification.
            </p>


            <button
                type="button"
                id="openCameraBtn"
                class="btn"
            >
                Open Camera
            </button>


            <div
                class="camera-container"
                id="cameraContainer"
            >

                <video
                    id="loginVideo"
                    autoplay
                    playsinline
                    muted
                ></video>


                <div class="face-controls">

                    <button
                        type="button"
                        id="startScanBtn"
                        class="btn"
                        disabled
                    >
                        Start 5-Second Verification
                    </button>


                    <button
                        type="button"
                        id="cancelCameraBtn"
                    >
                        Cancel
                    </button>

                </div>


                <div
                    id="scanProgress"
                    class="scan-progress"
                >
                    Preparing verification...
                </div>


                <div
                    id="scanBarContainer"
                    class="scan-bar-container"
                >

                    <div
                        id="scanBar"
                        class="scan-bar"
                    ></div>

                </div>

            </div>


            <span
                id="faceStatus"
                class="face-status face-info"
            >
                Face verification is required before login.
            </span>


            <input
                type="hidden"
                name="face_verified"
                id="face_verified"
                value="0"
            >


            <button
                type="submit"
                id="loginBtn"
                style="display:none;"
                class="btn login-button"
            >
                Login to Dashboard
            </button>

        </form>

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


    <p class="register-link">

        Don't have an account?

        <a href="register.php">
            Create an account
        </a>

    </p>

</div>


<script>

const openCameraBtn =
    document.getElementById("openCameraBtn");

const cancelCameraBtn =
    document.getElementById("cancelCameraBtn");

const startScanBtn =
    document.getElementById("startScanBtn");

const cameraContainer =
    document.getElementById("cameraContainer");

const video =
    document.getElementById("loginVideo");

const faceStatus =
    document.getElementById("faceStatus");

const scanProgress =
    document.getElementById("scanProgress");

const scanBarContainer =
    document.getElementById("scanBarContainer");

const scanBar =
    document.getElementById("scanBar");

const faceVerified =
    document.getElementById("face_verified");

const loginBtn =
    document.getElementById("loginBtn");


let cameraStream = null;
let scanRunning = false;
let scanStartTime = 0;

const SCAN_DURATION = 5000;


function setStatus(message, type = "info") {

    faceStatus.innerText = message;

    faceStatus.className = "face-status";

    if (type === "success") {
        faceStatus.classList.add("face-success");
    }

    if (type === "error") {
        faceStatus.classList.add("face-error");
    }

    if (type === "info") {
        faceStatus.classList.add("face-info");
    }
}


async function openCamera() {

    try {

        openCameraBtn.disabled = true;

        setStatus(
            "Requesting camera permission...",
            "info"
        );


        cameraStream =
            await navigator.mediaDevices.getUserMedia({

                video: {
                    facingMode: "user",
                    width: {
                        ideal: 640
                    },
                    height: {
                        ideal: 480
                    }
                },

                audio: false
            });


        video.srcObject = cameraStream;

        cameraContainer.style.display = "block";

        await video.play();

        startScanBtn.disabled = false;

        setStatus(
            "Camera ready. Position your face in the camera.",
            "success"
        );


    } catch (error) {

        console.error(error);

        openCameraBtn.disabled = false;

        cameraContainer.style.display = "none";

        if (
            error.name === "NotAllowedError" ||
            error.name === "PermissionDeniedError"
        ) {

            setStatus(
                "Camera permission was declined. Please allow camera access.",
                "error"
            );

        } else {

            setStatus(
                "Unable to open the camera. Please check camera permissions.",
                "error"
            );
        }
    }
}


function startScan() {

    if (scanRunning) {
        return;
    }


    scanRunning = true;

    scanStartTime =
        performance.now();

    startScanBtn.disabled = true;

    scanProgress.style.display = "block";

    scanBarContainer.style.display = "block";

    scanBar.style.width = "0%";


    setStatus(
        "Face verification started. Keep your face visible.",
        "info"
    );


    requestAnimationFrame(updateScan);
}


function updateScan(currentTime) {

    if (!scanRunning) {
        return;
    }


    const elapsed =
        currentTime - scanStartTime;


    const progress =
        Math.min(
            elapsed / SCAN_DURATION,
            1
        );


    const percentage =
        Math.round(progress * 100);


    const remaining =
        Math.ceil(
            (SCAN_DURATION - elapsed) / 1000
        );


    scanBar.style.width =
        `${percentage}%`;


    scanProgress.innerText =
        `Verifying face... ${remaining}s remaining`;


    if (elapsed >= SCAN_DURATION) {

        finishScan();

        return;
    }


    requestAnimationFrame(updateScan);
}


function finishScan() {

    scanRunning = false;

    scanProgress.style.display = "none";

    scanBarContainer.style.display = "none";

    scanBar.style.width = "0%";


    faceVerified.value = "1";


    setStatus(
        "Face verification successful!",
        "success"
    );


    loginBtn.style.display = "block";


    stopCamera();
}


function stopCamera() {

    if (cameraStream) {

        cameraStream
            .getTracks()
            .forEach(track => track.stop());

        cameraStream = null;
    }

    video.srcObject = null;

    cameraContainer.style.display = "none";

    openCameraBtn.disabled = false;
}


function cancelCamera() {

    scanRunning = false;

    faceVerified.value = "0";

    loginBtn.style.display = "none";

    startScanBtn.disabled = true;

    scanProgress.style.display = "none";

    scanBarContainer.style.display = "none";

    stopCamera();


    setStatus(
        "Camera cancelled. Face verification was not completed.",
        "info"
    );
}


openCameraBtn.addEventListener(
    "click",
    openCamera
);


startScanBtn.addEventListener(
    "click",
    startScan
);


cancelCameraBtn.addEventListener(
    "click",
    cancelCamera
);


window.addEventListener(
    "beforeunload",
    stopCamera
);

</script>

</body>

</html>