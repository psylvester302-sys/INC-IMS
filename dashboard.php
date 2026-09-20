<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone_number, course, current_year, account_status
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if ($user['account_status'] !== 'Active') {
    session_destroy();
    die("Your account is not active. Please contact the administrator.");
}

function getSetting(PDO $pdo, string $key, int $default): int
{
    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM system_settings
        WHERE setting_key = ?
        LIMIT 1
    ");
    $stmt->execute([$key]);

    $value = $stmt->fetchColumn();

    return $value !== false ? (int) $value : $default;
}

$classDuration = getSetting($pdo, 'class_session_duration', 30);
$classSessions = getSetting($pdo, 'class_required_sessions', 4);
$examDuration  = getSetting($pdo, 'exam_session_duration', 60);
$examSessions  = getSetting($pdo, 'exam_required_sessions', 2);

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| START NEW ATTENDANCE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_attendance') {

    $attendanceType = $_POST['attendance_type'] ?? '';
    $academicYear   = (int) ($_POST['academic_year'] ?? 0);
    $semester       = (int) ($_POST['semester'] ?? 0);
    $identifier     = trim($_POST['identifier'] ?? '');

    if (!in_array($attendanceType, ['class', 'exam'], true)) {
        $error = "Please select Class Attendance or Examination Attendance.";
    } elseif ($academicYear < 1 || $academicYear > 5) {
        $error = "Please select a valid academic year.";
    } elseif ($semester < 1 || $semester > 2) {
        $error = "Please select a valid semester.";
    } elseif ($identifier === '') {
        $error = "Please enter the class or examination identifier.";
    } else {

        if ($attendanceType === 'class') {
            $requiredSessions = $classSessions;
            $durationMinutes  = $classDuration;
            $sessionType      = 'Lecture';
        } else {
            $requiredSessions = $examSessions;
            $durationMinutes  = $examDuration;
            $sessionType      = 'Examination';
        }

        try {
            $pdo->beginTransaction();

            /*
             * Prevent accidental duplicate active/incomplete attendance
             * for the same student, type, year, semester and identifier.
             */
            $check = $pdo->prepare("
                SELECT id
                FROM attendance_events
                WHERE user_id = ?
                  AND attendance_type = ?
                  AND academic_year = ?
                  AND semester = ?
                  AND identifier = ?
                  AND completion_status IN ('In Progress', 'Complete')
                ORDER BY id DESC
                LIMIT 1
            ");

            $check->execute([
                $user_id,
                $attendanceType,
                $academicYear,
                $semester,
                $identifier
            ]);

            $existingEvent = $check->fetchColumn();

            if ($existingEvent) {
                $pdo->rollBack();

                header(
                    "Location: dashboard.php?event=" .
                    (int) $existingEvent
                );
                exit;
            }

            /*
             * Create attendance event.
             */
            $stmt = $pdo->prepare("
                INSERT INTO attendance_events (
                    user_id,
                    attendance_type,
                    academic_year,
                    semester,
                    identifier,
                    required_sessions,
                    session_duration_minutes,
                    completed_sessions,
                    completion_status,
                    admin_status
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?, 0, 'In Progress', 'Pending'
                )
            ");

            $stmt->execute([
                $user_id,
                $attendanceType,
                $academicYear,
                $semester,
                $identifier,
                $requiredSessions,
                $durationMinutes
            ]);

            $eventId = (int) $pdo->lastInsertId();

            /*
             * Create every required session.
             *
             * Session 1 is available immediately.
             * All later sessions remain locked until the previous
             * session has been completed.
             */
            for ($i = 1; $i <= $requiredSessions; $i++) {

                $status = ($i === 1) ? 'Available' : 'Locked';

                $stmt = $pdo->prepare("
                    INSERT INTO attendance_sessions (
                        attendance_event_id,
                        session_number,
                        session_type,
                        duration_minutes,
                        status,
                        attendance_result
                    )
                    VALUES (?, ?, ?, ?, ?, 'Pending')
                ");

                $stmt->execute([
                    $eventId,
                    $i,
                    $sessionType,
                    $durationMinutes,
                    $status
                ]);
            }

            /*
             * Create initial admin decision record.
             */
            $stmt = $pdo->prepare("
                INSERT INTO attendance_admin_decisions (
                    attendance_event_id,
                    decision
                )
                VALUES (?, 'Pending')
            ");

            $stmt->execute([$eventId]);

            /*
             * Audit creation.
             */
            $stmt = $pdo->prepare("
                INSERT INTO admin_audit_logs (
                    admin_user_id,
                    attendance_event_id,
                    action,
                    new_value,
                    reason
                )
                VALUES (
                    NULL,
                    ?,
                    'Attendance Event Created',
                    ?,
                    'Student started a new attendance record'
                )
            ");

            $stmt->execute([
                $eventId,
                $attendanceType
            ]);

            $pdo->commit();

            header("Location: dashboard.php?event=" . $eventId);
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = "Unable to start attendance. Please try again.";
        }
    }
}

/*
|--------------------------------------------------------------------------
| SELECT CURRENT EVENT
|--------------------------------------------------------------------------
*/
$selectedEventId = (int) ($_GET['event'] ?? 0);

$currentEvent = null;

if ($selectedEventId > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM attendance_events
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $selectedEventId,
        $user_id
    ]);

    $currentEvent = $stmt->fetch();
}

/*
|--------------------------------------------------------------------------
| LOAD CURRENT EVENT SESSIONS
|--------------------------------------------------------------------------
*/
$currentSessions = [];

if ($currentEvent) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM attendance_sessions
        WHERE attendance_event_id = ?
        ORDER BY session_number ASC
    ");

    $stmt->execute([
        $currentEvent['id']
    ]);

    $currentSessions = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| LOAD ATTENDANCE HISTORY
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        ae.*,
        COALESCE(aad.decision, ae.admin_status) AS final_admin_status
    FROM attendance_events ae
    LEFT JOIN attendance_admin_decisions aad
        ON aad.attendance_event_id = ae.id
    WHERE ae.user_id = ?
    ORDER BY ae.created_at DESC
");

$stmt->execute([$user_id]);

$attendanceHistory = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>NCS-IMS Dashboard</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f7f5;
            color: #17231d;
        }

        header {
            background: #123b2a;
            color: white;
            padding: 18px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        header h1 {
            margin: 0;
            font-size: 22px;
        }

        header a {
            color: white;
            text-decoration: none;
            background: #1f6347;
            padding: 9px 14px;
            border-radius: 7px;
        }

        main {
            width: min(1100px, 94%);
            margin: 25px auto;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.06);
        }

        h2 {
            margin-top: 0;
            color: #123b2a;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .profile-item {
            background: #f3f6f4;
            padding: 14px;
            border-radius: 8px;
        }

        .profile-item strong {
            display: block;
            margin-bottom: 5px;
            color: #123b2a;
        }

        .attendance-selector {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .type-option {
            position: relative;
        }

        .type-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .type-option label {
            display: block;
            padding: 18px;
            border: 2px solid #d8e1dc;
            border-radius: 10px;
            cursor: pointer;
            text-align: center;
            font-weight: bold;
            background: #fff;
        }

        .type-option input:checked + label {
            border-color: #123b2a;
            background: #e9f2ed;
            color: #123b2a;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            font-weight: bold;
        }

        select,
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5cf;
            border-radius: 7px;
            font-size: 15px;
        }

        button {
            border: 0;
            background: #123b2a;
            color: white;
            padding: 12px 18px;
            border-radius: 7px;
            cursor: pointer;
            font-size: 15px;
        }

        button:hover {
            background: #1b543b;
        }

        .message {
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 15px;
            background: #e7f4eb;
            color: #14532d;
        }

        .error {
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 15px;
            background: #fdecec;
            color: #991b1b;
        }

        .session-list {
            display: grid;
            gap: 10px;
        }

        .session {
            border: 1px solid #d9e1dc;
            border-radius: 9px;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .session-info strong {
            display: block;
            margin-bottom: 5px;
        }

        .status {
            font-weight: bold;
        }

        .locked {
            color: #777;
        }

        .available {
            color: #146c43;
        }

        .active {
            color: #0d6efd;
        }

        .completed {
            color: #198754;
        }

        .expired {
            color: #b02a37;
        }

        .history {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th,
        td {
            padding: 11px;
            border-bottom: 1px solid #e1e6e3;
            text-align: left;
        }

        th {
            background: #f1f5f2;
        }

        @media (max-width: 650px) {

            .attendance-selector,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .session {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>NCS-IMS</h1>

    <a href="logout.php">Logout</a>
</header>

<main>

    <?php if ($message): ?>
        <div class="message">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- PROFILE -->

    <section class="card">

        <h2>Student Profile</h2>

        <div class="profile-grid">

            <div class="profile-item">
                <strong>Name</strong>
                <?= htmlspecialchars($user['full_name']) ?>
            </div>

            <div class="profile-item">
                <strong>Email</strong>
                <?= htmlspecialchars($user['email']) ?>
            </div>

            <div class="profile-item">
                <strong>Phone</strong>
                <?= htmlspecialchars($user['phone_number']) ?>
            </div>

            <div class="profile-item">
                <strong>Course</strong>
                <?= htmlspecialchars($user['course']) ?>
            </div>

            <div class="profile-item">
                <strong>Academic Year</strong>
                Year <?= (int) $user['current_year'] ?>
            </div>

            <div class="profile-item">
                <strong>Account Status</strong>
                <?= htmlspecialchars($user['account_status']) ?>
            </div>

        </div>

    </section>


    <!-- START ATTENDANCE -->

    <section class="card">

        <h2>Start Attendance</h2>

        <form method="POST" action="dashboard.php">

            <input
                type="hidden"
                name="action"
                value="start_attendance"
            >

            <!-- IMPORTANT:
                 The selected radio button controls the actual
                 attendance_type value submitted to PHP.
            -->

            <div class="attendance-selector">

                <div class="type-option">

                    <input
                        type="radio"
                        id="class_attendance"
                        name="attendance_type"
                        value="class"
                        checked
                    >

                    <label for="class_attendance">
                        Class Attendance
                    </label>

                </div>

                <div class="type-option">

                    <input
                        type="radio"
                        id="exam_attendance"
                        name="attendance_type"
                        value="exam"
                    >

                    <label for="exam_attendance">
                        Examination Attendance
                    </label>

                </div>

            </div>


            <div class="form-grid">

                <div class="form-group">

                    <label for="academic_year">
                        Academic Year
                    </label>

                    <select
                        id="academic_year"
                        name="academic_year"
                        required
                    >

                        <option value="">
                            Select Academic Year
                        </option>

                        <?php for ($year = 1; $year <= 5; $year++): ?>

                            <option value="<?= $year ?>">
                                Year <?= $year ?>
                            </option>

                        <?php endfor; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label for="semester">
                        Semester
                    </label>

                    <select
                        id="semester"
                        name="semester"
                        required
                    >

                        <option value="">
                            Select Semester
                        </option>

                        <option value="1">
                            First Semester
                        </option>

                        <option value="2">
                            Second Semester
                        </option>

                    </select>

                </div>


                <div class="form-group full">

                    <label for="identifier">
                        Class / Examination Identifier
                    </label>

                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        placeholder="Example: CSC 201 or First Semester Examination"
                        required
                    >

                </div>


                <div class="form-group full">

                    <button type="submit">
                        Start Selected Attendance
                    </button>

                </div>

            </div>

        </form>

    </section>


    <!-- CURRENT ATTENDANCE -->

    <?php if ($currentEvent): ?>

        <section class="card">

            <h2>
                Current
                <?= $currentEvent['attendance_type'] === 'class'
                    ? 'Class Attendance'
                    : 'Examination Attendance'
                ?>
            </h2>

            <p>
                <strong>Academic Year:</strong>
                <?= (int) $currentEvent['academic_year'] ?>
            </p>

            <p>
                <strong>Semester:</strong>
                <?= (int) $currentEvent['semester'] ?>
            </p>

            <p>
                <strong>Identifier:</strong>
                <?= htmlspecialchars($currentEvent['identifier']) ?>
            </p>

            <p>
                <strong>Progress:</strong>
                <?= (int) $currentEvent['completed_sessions'] ?>
                /
                <?= (int) $currentEvent['required_sessions'] ?>
            </p>

            <p>
                <strong>Completion:</strong>
                <?= htmlspecialchars($currentEvent['completion_status']) ?>
            </p>

            <p>
                <strong>Admin Status:</strong>
                <?= htmlspecialchars($currentEvent['admin_status']) ?>
            </p>


            <div class="session-list">

                <?php foreach ($currentSessions as $session): ?>

                    <div class="session">

                        <div class="session-info">

                            <strong>
                                <?= $session['session_type'] ?>
                                Session
                                <?= (int) $session['session_number'] ?>
                            </strong>

                            <span>
                                Duration:
                                <?= (int) $session['duration_minutes'] ?>
                                minutes
                            </span>

                            <br>

                            <span class="status">
                                <?= htmlspecialchars($session['status']) ?>
                            </span>

                            <br>

                            <span>
                                Result:
                                <?= htmlspecialchars($session['attendance_result']) ?>
                            </span>

                        </div>


                        <div>

                            <?php if (
                                in_array(
                                    $session['status'],
                                    ['Available', 'Verification Required', 'Active'],
                                    true
                                )
                            ): ?>

                                <a
                                    href="attendance.php?session=<?= (int) $session['id'] ?>"
                                >
                                    <button type="button">
                                        Open Session
                                    </button>
                                </a>

                            <?php elseif ($session['status'] === 'Completed'): ?>

                                <span class="completed">
                                    Completed
                                </span>

                            <?php elseif ($session['status'] === 'Expired'): ?>

                                <span class="expired">
                                    Expired
                                </span>

                            <?php else: ?>

                                <span class="locked">
                                    Locked
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </section>

    <?php endif; ?>


    <!-- ATTENDANCE HISTORY -->

    <section class="card">

        <h2>Attendance History</h2>

        <div class="history">

            <table>

                <thead>

                    <tr>
                        <th>Type</th>
                        <th>Year</th>
                        <th>Semester</th>
                        <th>Identifier</th>
                        <th>Progress</th>
                        <th>Completion</th>
                        <th>Admin</th>
                        <th>Date</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (!$attendanceHistory): ?>

                    <tr>
                        <td colspan="8">
                            No attendance records yet.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($attendanceHistory as $record): ?>

                        <tr>

                            <td>
                                <?= $record['attendance_type'] === 'class'
                                    ? 'Class'
                                    : 'Examination'
                                ?>
                            </td>

                            <td>
                                Year <?= (int) $record['academic_year'] ?>
                            </td>

                            <td>
                                Semester <?= (int) $record['semester'] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($record['identifier']) ?>
                            </td>

                            <td>
                                <?= (int) $record['completed_sessions'] ?>
                                /
                                <?= (int) $record['required_sessions'] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($record['completion_status']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($record['final_admin_status']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($record['created_at']) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- ABOUT NCS-IMS -->

<section class="card">

    <h2>About NCS-IMS</h2>

    <p>
        <strong>NCS-IMS</strong> is a Computer Science student management
        and attendance system designed to manage student information,
        class attendance, examination attendance, and attendance records
        in one place.
    </p>


</section>
</main>

</body>
</html>