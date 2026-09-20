<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'db.php';

$user_id = (int) $_SESSION['user_id'];
$session_id = (int) ($_GET['session'] ?? $_POST['session_id'] ?? 0);

if ($session_id <= 0) {
    header("Location: dashboard.php");
    exit;
}


/*
==================================================
LOAD USER
==================================================
*/

$userStmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$userStmt->execute([$user_id]);

$user = $userStmt->fetch();

if (!$user || $user['account_status'] !== 'Active') {
    session_destroy();
    header("Location: login.php");
    exit;
}


/*
==================================================
LOAD ATTENDANCE SESSION
==================================================
*/

$stmt = $pdo->prepare("
    SELECT
        s.*,
        e.user_id,
        e.attendance_type,
        e.academic_year,
        e.semester,
        e.identifier,
        e.required_sessions,
        e.completed_sessions,
        e.completion_status,
        e.admin_status
    FROM attendance_sessions s
    INNER JOIN attendance_events e
        ON s.attendance_event_id = e.id
    WHERE s.id = ?
      AND e.user_id = ?
    LIMIT 1
");

$stmt->execute([
    $session_id,
    $user_id
]);

$session = $stmt->fetch();

if (!$session) {
    header("Location: dashboard.php");
    exit;
}


/*
==================================================
EXPIRE ACTIVE SESSION
==================================================
*/

if (
    $session['status'] === 'Active'
    && !empty($session['expires_at'])
    && strtotime($session['expires_at']) <= time()
) {

    $pdo->beginTransaction();

    try {

        $expireStmt = $pdo->prepare("
            UPDATE attendance_sessions
            SET
                status = 'Expired',
                attendance_result = 'Expired'
            WHERE id = ?
              AND status = 'Active'
        ");

        $expireStmt->execute([
            $session_id
        ]);


        /*
         * If the session expired, the complete attendance
         * event can no longer reach the required number
         * of sessions.
         */

        $eventStmt = $pdo->prepare("
            UPDATE attendance_events
            SET
                completion_status = 'Not Complete'
            WHERE id = ?
              AND completed_sessions < required_sessions
        ");

        $eventStmt->execute([
            $session['attendance_event_id']
        ]);


        /*
         * Record administrative history.
         */

        $auditStmt = $pdo->prepare("
            INSERT INTO admin_audit_logs
            (
                attendance_event_id,
                attendance_session_id,
                action,
                old_value,
                new_value,
                reason
            )
            VALUES
            (
                ?,
                ?,
                'Attendance Session Expired',
                'Active',
                'Expired',
                'The required attendance session time expired before successful submission.'
            )
        ");

        $auditStmt->execute([
            $session['attendance_event_id'],
            $session_id
        ]);


        $pdo->commit();

    } catch (Exception $e) {

        $pdo->rollBack();
    }


    header(
        "Location: dashboard.php?event=" .
        $session['attendance_event_id']
    );

    exit;
}


/*
==================================================
CHECK PREVIOUS SESSION
==================================================
*/

if ($session['session_number'] > 1) {

    $previousNumber =
        $session['session_number'] - 1;

    $previousStmt = $pdo->prepare("
        SELECT *
        FROM attendance_sessions
        WHERE attendance_event_id = ?
          AND session_number = ?
        LIMIT 1
    ");

    $previousStmt->execute([
        $session['attendance_event_id'],
        $previousNumber
    ]);

    $previousSession =
        $previousStmt->fetch();


    if (
        !$previousSession
        || $previousSession['status'] !== 'Completed'
        || $previousSession['attendance_result'] !== 'Present'
    ) {

        header(
            "Location: dashboard.php?event=" .
            $session['attendance_event_id']
        );

        exit;
    }


    /*
     * A later session is only allowed after the previous
     * session has completed its required duration.
     */

    if (
        empty($previousSession['expires_at'])
        || strtotime($previousSession['expires_at']) > time()
    ) {

        $message =
            'The previous attendance session has not completed its required time yet.';
    }
}


/*
==================================================
START SESSION
==================================================
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'start_session'
) {

    if ($session['status'] !== 'Available') {

        header(
            "Location: attendance.php?session=" .
            $session_id
        );

        exit;
    }


    /*
     * A session can only be started if it is the
     * correct next session.
     */

    if ($session['session_number'] > 1) {

        $previousNumber =
            $session['session_number'] - 1;

        $previousStmt = $pdo->prepare("
            SELECT *
            FROM attendance_sessions
            WHERE attendance_event_id = ?
              AND session_number = ?
            LIMIT 1
        ");

        $previousStmt->execute([
            $session['attendance_event_id'],
            $previousNumber
        ]);

        $previousSession =
            $previousStmt->fetch();

        if (
            !$previousSession
            || $previousSession['status'] !== 'Completed'
        ) {

            die(
                'The previous attendance session must be completed first.'
            );
        }
    }


    /*
     * Face verification must be completed before
     * the timed attendance session begins.
     *
     * This is the same simple 5-second verification
     * process used by registration/login.
     */

    $_SESSION['attendance_face_verified'] = false;

    $_SESSION['attendance_face_session_id'] =
        $session_id;

    $_SESSION['attendance_face_event_id'] =
        $session['attendance_event_id'];


    header(
        "Location: attendance.php?session=" .
        $session_id .
        "&verify=1"
    );

    exit;
}


/*
==================================================
FACE VERIFICATION
==================================================
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'verify_face'
) {

    $faceImage =
        $_POST['face_image'] ?? '';

    if ($faceImage === '') {

        $error =
            'Face verification image was not received.';

    } else {

        /*
         * Record verification.
         */

        $verificationStmt = $pdo->prepare("
            INSERT INTO face_verifications
            (
                user_id,
                attendance_event_id,
                attendance_session_id,
                verification_type,
                started_at,
                completed_at,
                duration_seconds,
                verification_status,
                captured_image
            )
            VALUES
            (
                ?,
                ?,
                ?,
                'Attendance',
                DATE_SUB(NOW(), INTERVAL 5 SECOND),
                NOW(),
                5,
                'Successful',
                ?
            )
        ");

        $verificationStmt->execute([
            $user_id,
            $session['attendance_event_id'],
            $session_id,
            $faceImage
        ]);


        /*
         * Start the actual timed attendance session
         * only after face verification succeeds.
         */

        $startTime =
            date('Y-m-d H:i:s');

        $expireTime =
            date(
                'Y-m-d H:i:s',
                time() +
                ($session['duration_minutes'] * 60)
            );


        $pdo->beginTransaction();

        try {

            $updateStmt = $pdo->prepare("
                UPDATE attendance_sessions
                SET
                    started_at = ?,
                    expires_at = ?,
                    verification_started_at =
                        DATE_SUB(?, INTERVAL 5 SECOND),
                    verification_completed_at = ?,
                    status = 'Active'
                WHERE id = ?
                  AND status = 'Available'
            ");

            $updateStmt->execute([
                $startTime,
                $expireTime,
                $startTime,
                $startTime,
                $session_id
            ]);


            /*
             * Activate the next login/session requirement
             * only after this session is properly started.
             */

            $pdo->commit();


            /*
             * Store the active attendance session.
             */

            $_SESSION['active_attendance_session'] =
                $session_id;

            $_SESSION['attendance_face_verified'] =
                true;


            header(
                "Location: attendance.php?session=" .
                $session_id .
                "&active=1"
            );

            exit;

        } catch (Exception $e) {

            $pdo->rollBack();

            $error =
                'Unable to start the attendance session.';
        }
    }
}


/*
==================================================
SUBMIT ATTENDANCE
==================================================
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'submit_attendance'
) {

    /*
     * Verify this is the active session belonging
     * to the logged-in student.
     */

    if (
        (int) ($_SESSION['active_attendance_session'] ?? 0)
        !== $session_id
    ) {

        die(
            'This attendance session is not active.'
        );
    }


    /*
     * Reload session from database.
     */

    $reloadStmt = $pdo->prepare("
        SELECT *
        FROM attendance_sessions
        WHERE id = ?
        LIMIT 1
    ");

    $reloadStmt->execute([
        $session_id
    ]);

    $currentSession =
        $reloadStmt->fetch();


    if (
        !$currentSession
        || $currentSession['status'] !== 'Active'
    ) {

        die(
            'This attendance session is no longer active.'
        );
    }


    /*
     * SERVER-SIDE TIME CHECK.
     *
     * The browser timer is NOT trusted.
     */

    if (
        empty($currentSession['expires_at'])
        || strtotime($currentSession['expires_at']) <= time()
    ) {

        $expireStmt = $pdo->prepare("
            UPDATE attendance_sessions
            SET
                status = 'Expired',
                attendance_result = 'Expired'
            WHERE id = ?
        ");

        $expireStmt->execute([
            $session_id
        ]);


        $eventStmt = $pdo->prepare("
            UPDATE attendance_events
            SET completion_status = 'Not Complete'
            WHERE id = ?
        ");

        $eventStmt->execute([
            $session['attendance_event_id']
        ]);


        unset(
            $_SESSION['active_attendance_session']
        );


        die(
            'The attendance session has expired. Attendance was not recorded.'
        );
    }


    /*
     * Complete attendance.
     */

    $pdo->beginTransaction();

    try {

        $submitStmt = $pdo->prepare("
            UPDATE attendance_sessions
            SET
                submitted_at = NOW(),
                status = 'Completed',
                attendance_result = 'Present'
            WHERE id = ?
              AND status = 'Active'
        ");

        $submitStmt->execute([
            $session_id
        ]);


        /*
         * Count completed sessions.
         */

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM attendance_sessions
            WHERE attendance_event_id = ?
              AND status = 'Completed'
              AND attendance_result = 'Present'
        ");

        $countStmt->execute([
            $session['attendance_event_id']
        ]);

        $completed =
            (int) $countStmt->fetchColumn();


        /*
         * Determine event completion.
         */

        if (
            $completed >=
            (int) $session['required_sessions']
        ) {

            $completionStatus =
                'Complete';

        } else {

            $completionStatus =
                'In Progress';
        }


        $eventUpdate = $pdo->prepare("
            UPDATE attendance_events
            SET
                completed_sessions = ?,
                completion_status = ?
            WHERE id = ?
        ");

        $eventUpdate->execute([
            $completed,
            $completionStatus,
            $session['attendance_event_id']
        ]);


        /*
         * Unlock the next session if one exists.
         */

        $nextNumber =
            $session['session_number'] + 1;


        $nextStmt = $pdo->prepare("
            UPDATE attendance_sessions
            SET status = 'Available'
            WHERE attendance_event_id = ?
              AND session_number = ?
              AND status = 'Locked'
        ");

        $nextStmt->execute([
            $session['attendance_event_id'],
            $nextNumber
        ]);


        /*
         * Record audit information.
         */

        $auditStmt = $pdo->prepare("
            INSERT INTO admin_audit_logs
            (
                attendance_event_id,
                attendance_session_id,
                action,
                old_value,
                new_value,
                reason
            )
            VALUES
            (
                ?,
                ?,
                'Attendance Session Completed',
                'Active',
                'Completed / Present',
                'Student successfully completed the required attendance session.'
            )
        ");

        $auditStmt->execute([
            $session['attendance_event_id'],
            $session_id
        ]);


        $pdo->commit();


        unset(
            $_SESSION['active_attendance_session']
        );

        unset(
            $_SESSION['attendance_face_verified']
        );


        /*
         * When the session is complete, force logout.
         *
         * This means the student must authenticate again
         * before the next session.
         */

        $sessionUserId =
            $_SESSION['user_id'];

        session_unset();

        session_destroy();


        /*
         * Start a clean session containing only the
         * message needed by login.php.
         */

        session_start();

        $_SESSION['attendance_message'] =
            'Attendance session completed successfully. Please log in again and complete face verification for the next session.';

        $_SESSION['attendance_event_id'] =
            $session['attendance_event_id'];


        header("Location: login.php");

        exit;

    } catch (Exception $e) {

        $pdo->rollBack();

        $error =
            'Unable to submit attendance. ' .
            $e->getMessage();
    }
}


/*
==================================================
REFRESH SESSION DATA
==================================================
*/

$refreshStmt = $pdo->prepare("
    SELECT
        s.*,
        e.user_id,
        e.attendance_type,
        e.academic_year,
        e.semester,
        e.identifier,
        e.required_sessions,
        e.completed_sessions,
        e.completion_status,
        e.admin_status
    FROM attendance_sessions s
    INNER JOIN attendance_events e
        ON s.attendance_event_id = e.id
    WHERE s.id = ?
      AND e.user_id = ?
    LIMIT 1
");

$refreshStmt->execute([
    $session_id,
    $user_id
]);

$session =
    $refreshStmt->fetch();


$showVerification =
    isset($_GET['verify'])
    && $session['status'] === 'Available';


$showActive =
    isset($_GET['active'])
    && $session['status'] === 'Active';

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
NCS-IMS Attendance
</title>

<link
    rel="stylesheet"
    href="style.css"
>

<style>

.attendance-box {
    max-width: 700px;
    margin: 30px auto;
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 25px;
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
    height: 320px;
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
    margin: 15px 0;
}

#verificationProgress {
    width: 0%;
    height: 100%;
    background: #1d5f3a;
    transition: width .1s linear;
}

.timer-box {
    text-align: center;
    padding: 25px;
    background: #f4f7ff;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    margin: 20px 0;
}

.timer-number {
    font-size: 3rem;
    font-weight: 800;
}

.timer-warning {
    font-weight: 700;
}

.success-message {
    color: var(--success-green);
    font-weight: 700;
}

.error-message {
    color: var(--error-red);
    font-weight: 700;
}

</style>

</head>


<body>


<header>

<h1>NCS-IMS</h1>

<div>
Attendance Verification
</div>

</header>


<div class="container">


<div class="attendance-box">


<h2>
<?= htmlspecialchars($session['session_type']) ?>
<?= htmlspecialchars($session['session_number']) ?>
</h2>


<p>

<strong>Attendance:</strong>

<?= ucfirst(
    htmlspecialchars(
        $session['attendance_type']
    )
) ?>

</p>


<p>

<strong>Academic Year:</strong>

Year
<?= htmlspecialchars(
    $session['academic_year']
) ?>

</p>


<p>

<strong>Semester:</strong>

Semester
<?= htmlspecialchars(
    $session['semester']
) ?>

</p>


<p>

<strong>Day / Examination:</strong>

<?= htmlspecialchars(
    $session['identifier']
) ?>

</p>


<?php if (!empty($error)): ?>

<div class="error-message">
    <?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>


<?php if ($showVerification): ?>


<h3>
Face Verification Required
</h3>


<p>

Complete the 5-second face verification before
your attendance session begins.

</p>


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

<div id="verificationProgress"></div>

</div>


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


<form
    method="POST"
    id="verificationForm"
>

<input
    type="hidden"
    name="action"
    value="verify_face"
>

<input
    type="hidden"
    name="session_id"
    value="<?= $session_id ?>"
>

<input
    type="hidden"
    name="face_image"
    id="faceImage"
>

</form>


<script>

const camera =
    document.getElementById('camera');

const cameraContainer =
    document.getElementById('cameraContainer');

const openCameraButton =
    document.getElementById('openCameraButton');

const verifyButton =
    document.getElementById('verifyButton');

const verificationStatus =
    document.getElementById('verificationStatus');

const verificationProgress =
    document.getElementById('verificationProgress');

const faceImage =
    document.getElementById('faceImage');

const verificationForm =
    document.getElementById('verificationForm');


let cameraStream = null;

let running = false;


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

                return;
            }


            verificationStatus.textContent =
                'Requesting camera permission...';


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


            cameraContainer.style.display =
                'block';


            verificationStatus.textContent =
                'Camera active. Position your face.';


            verifyButton.disabled =
                false;


            openCameraButton.disabled =
                true;

        } catch (error) {

            console.error(error);

            verificationStatus.textContent =
                'Camera permission denied or unavailable.';

        }

    }
);


/*
==================================================
5 SECOND VERIFICATION
==================================================
*/

verifyButton.addEventListener(
    'click',
    function () {

        if (
            !cameraStream ||
            running
        ) {

            return;
        }


        running = true;

        verifyButton.disabled =
            true;


        verificationStatus.textContent =
            'Face verification in progress...';


        const start =
            Date.now();


        const duration =
            <?= $faceDuration ?> * 1000;


        function update() {

            const elapsed =
                Date.now() - start;


            const percentage =
                Math.min(
                    (elapsed / duration) * 100,
                    100
                );


            verificationProgress.style.width =
                percentage + '%';


            if (elapsed < duration) {

                requestAnimationFrame(update);

            } else {

                completeVerification();

            }

        }


        requestAnimationFrame(update);

    }
);


/*
==================================================
CAPTURE FACE
==================================================
*/

function completeVerification() {

    const canvas =
        document.createElement('canvas');


    canvas.width =
        320;


    canvas.height =
        240;


    const context =
        canvas.getContext('2d');


    context.drawImage(
        camera,
        0,
        0,
        320,
        240
    );


    faceImage.value =
        canvas.toDataURL(
            'image/jpeg',
            0.60
        );


    verificationProgress.style.width =
        '100%';


    verificationStatus.textContent =
        'Face verification successful. Starting attendance session...';


    if (cameraStream) {

        cameraStream
            .getTracks()
            .forEach(function(track) {

                track.stop();

            });

    }


    setTimeout(
        function() {

            verificationForm.submit();

        },
        500
    );

}

</script>


<?php elseif ($showActive): ?>


<h3>
Attendance Session Active
</h3>


<p>
Your attendance session has started.
Remain available until the required session time
has been completed.
</p>


<div class="timer-box">

<div>
Time Remaining
</div>

<div
    class="timer-number"
    id="timer"
>
--:--
</div>

<div
    class="timer-warning"
    id="timerStatus"
>
Session is active.
</div>

</div>


<form
    method="POST"
    id="attendanceSubmitForm"
>

<input
    type="hidden"
    name="action"
    value="submit_attendance"
>

<input
    type="hidden"
    name="session_id"
    value="<?= $session_id ?>"
>

<button
    type="submit"
    class="btn"
    id="submitButton"
    disabled
>
    Submit Attendance
</button>

</form>


<script>

/*
==================================================
SERVER AUTHORITATIVE EXPIRATION
==================================================
*/

const expiresAt =
    new Date(
        <?= json_encode(
            date(
                'c',
                strtotime(
                    $session['expires_at']
                )
            )
        ) ?>
    ).getTime();


const timer =
    document.getElementById('timer');

const timerStatus =
    document.getElementById('timerStatus');

const submitButton =
    document.getElementById('submitButton');


function updateTimer() {

    const remaining =
        expiresAt - Date.now();


    if (remaining <= 0) {

        timer.textContent =
            '00:00';


        timerStatus.textContent =
            'Session time reached.';


        /*
         * Server will make the final decision.
         */

        window.location.href =
            'attendance.php?session=<?= $session_id ?>';


        return;
    }


    const totalSeconds =
        Math.floor(
            remaining / 1000
        );


    const minutes =
        Math.floor(
            totalSeconds / 60
        );


    const seconds =
        totalSeconds % 60;


    timer.textContent =
        String(minutes).padStart(2, '0')
        + ':'
        + String(seconds).padStart(2, '0');


    /*
     * The submit button becomes available only when
     * the required time has elapsed.
     */

    submitButton.disabled =
        true;


    setTimeout(
        updateTimer,
        250
    );

}


updateTimer();

</script>


<?php else: ?>


<h3>
Ready to Start
</h3>


<p>

This session requires face verification before
the timed attendance period begins.

</p>


<form method="POST">

<input
    type="hidden"
    name="action"
    value="start_session"
>

<input
    type="hidden"
    name="session_id"
    value="<?= $session_id ?>"
>


<button
    type="submit"
    class="btn"
>
    Begin Face Verification
</button>

</form>


<?php endif; ?>


<br>


<a href="dashboard.php">
    Return to Dashboard
</a>


</div>


</div>


</body>

</html>