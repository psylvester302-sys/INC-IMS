<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| NCS-IMS ADMIN CONTROL CENTER
|--------------------------------------------------------------------------
| This page is currently protected by a simple admin key stored in the
| PHP session. A dedicated admin authentication system can be added later.
|--------------------------------------------------------------------------
*/

$adminKey = 'NCS-IMS-ADMIN';

if (!isset($_SESSION['ncs_ims_admin'])) {

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['action'] ?? '') === 'admin_login'
    ) {
        $enteredKey = $_POST['admin_key'] ?? '';

        if (hash_equals($adminKey, $enteredKey)) {
            $_SESSION['ncs_ims_admin'] = true;

            header("Location: admin.php");
            exit;
        }

        $loginError = "Invalid administrator access key.";
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
        <title>NCS-IMS Admin Login</title>

        <style>
            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                font-family: Arial, sans-serif;
                background: #f4f7f5;
                color: #17231d;
            }

            .login-card {
                width: min(420px, 92%);
                background: white;
                padding: 30px;
                border-radius: 14px;
                box-shadow: 0 5px 25px rgba(0,0,0,0.08);
            }

            h1 {
                margin-top: 0;
                color: #123b2a;
            }

            input {
                width: 100%;
                padding: 13px;
                margin: 10px 0 15px;
                border: 1px solid #ccd6d0;
                border-radius: 8px;
                font-size: 15px;
            }

            button {
                width: 100%;
                padding: 13px;
                border: 0;
                border-radius: 8px;
                background: #123b2a;
                color: white;
                cursor: pointer;
                font-size: 15px;
            }

            .error {
                background: #fdecec;
                color: #991b1b;
                padding: 12px;
                border-radius: 8px;
                margin-bottom: 15px;
            }
        </style>
    </head>

    <body>

        <div class="login-card">

            <h1>NCS-IMS</h1>

            <h2>Admin Control Center</h2>

            <?php if (!empty($loginError)): ?>

                <div class="error">
                    <?= htmlspecialchars($loginError) ?>
                </div>

            <?php endif; ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="admin_login"
                >

                <label>
                    Administrator Access Key
                </label>

                <input
                    type="password"
                    name="admin_key"
                    required
                    autofocus
                >

                <button type="submit">
                    Enter Admin Control Center
                </button>

            </form>

        </div>

    </body>
    </html>
    <?php
    exit;
}


/*
|--------------------------------------------------------------------------
| ADMIN LOGOUT
|--------------------------------------------------------------------------
*/

if (isset($_GET['logout'])) {

    unset($_SESSION['ncs_ims_admin']);

    header("Location: admin.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function h($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| ADMIN ACTIONS
|--------------------------------------------------------------------------
*/

$actionMessage = '';
$actionError = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['admin_action'])
) {

    $adminAction = $_POST['admin_action'];
    $eventId = (int) ($_POST['event_id'] ?? 0);

    if ($eventId <= 0) {

        $actionError = "Invalid attendance event.";

    } else {

        try {

            $pdo->beginTransaction();

            /*
             * Verify event exists.
             */
            $stmt = $pdo->prepare("
                SELECT *
                FROM attendance_events
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$eventId]);

            $event = $stmt->fetch();

            if (!$event) {

                throw new Exception(
                    "Attendance event not found."
                );
            }


            /*
             * APPROVE
             */
            if ($adminAction === 'approve') {

                $note = trim(
                    $_POST['admin_note'] ?? ''
                );

                $stmt = $pdo->prepare("
                    UPDATE attendance_events
                    SET
                        admin_status = 'Approved',
                        admin_note = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $note !== '' ? $note : null,
                    $eventId
                ]);


                $stmt = $pdo->prepare("
                    UPDATE attendance_admin_decisions
                    SET
                        decision = 'Approved',
                        reason = ?,
                        decided_at = NOW()
                    WHERE attendance_event_id = ?
                ");

                $stmt->execute([
                    $note !== '' ? $note : null,
                    $eventId
                ]);


                $stmt = $pdo->prepare("
                    INSERT INTO admin_audit_logs (
                        admin_user_id,
                        attendance_event_id,
                        action,
                        old_value,
                        new_value,
                        reason
                    )
                    VALUES (
                        NULL,
                        ?,
                        'Attendance Approved',
                        ?,
                        'Approved',
                        ?
                    )
                ");

                $stmt->execute([
                    $eventId,
                    $event['admin_status'],
                    $note !== '' ? $note : null
                ]);

                $actionMessage =
                    "Attendance record approved.";
            }


            /*
             * DECLINE
             */
            elseif ($adminAction === 'decline') {

                $note = trim(
                    $_POST['admin_note'] ?? ''
                );

                if ($note === '') {
                    throw new Exception(
                        "A reason is required when declining attendance."
                    );
                }


                $stmt = $pdo->prepare("
                    UPDATE attendance_events
                    SET
                        admin_status = 'Declined',
                        admin_note = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $note,
                    $eventId
                ]);


                $stmt = $pdo->prepare("
                    UPDATE attendance_admin_decisions
                    SET
                        decision = 'Declined',
                        reason = ?,
                        decided_at = NOW()
                    WHERE attendance_event_id = ?
                ");

                $stmt->execute([
                    $note,
                    $eventId
                ]);


                $stmt = $pdo->prepare("
                    INSERT INTO admin_audit_logs (
                        admin_user_id,
                        attendance_event_id,
                        action,
                        old_value,
                        new_value,
                        reason
                    )
                    VALUES (
                        NULL,
                        ?,
                        'Attendance Declined',
                        ?,
                        'Declined',
                        ?
                    )
                ");

                $stmt->execute([
                    $eventId,
                    $event['admin_status'],
                    $note
                ]);

                $actionMessage =
                    "Attendance record declined.";
            }


            /*
             * RESET ADMIN DECISION
             */
            elseif ($adminAction === 'reset') {

                $stmt = $pdo->prepare("
                    UPDATE attendance_events
                    SET
                        admin_status = 'Pending',
                        admin_note = NULL
                    WHERE id = ?
                ");

                $stmt->execute([
                    $eventId
                ]);


                $stmt = $pdo->prepare("
                    UPDATE attendance_admin_decisions
                    SET
                        decision = 'Pending',
                        reason = NULL,
                        decided_at = NULL
                    WHERE attendance_event_id = ?
                ");

                $stmt->execute([
                    $eventId
                ]);


                $stmt = $pdo->prepare("
                    INSERT INTO admin_audit_logs (
                        admin_user_id,
                        attendance_event_id,
                        action,
                        old_value,
                        new_value,
                        reason
                    )
                    VALUES (
                        NULL,
                        ?,
                        'Attendance Decision Reset',
                        ?,
                        'Pending',
                        'Administrator reset attendance decision'
                    )
                ");

                $stmt->execute([
                    $eventId,
                    $event['admin_status']
                ]);

                $actionMessage =
                    "Attendance decision reset.";
            }


            /*
             * MARK INCOMPLETE
             */
            elseif ($adminAction === 'mark_incomplete') {

                $stmt = $pdo->prepare("
                    UPDATE attendance_events
                    SET completion_status = 'Not Complete'
                    WHERE id = ?
                ");

                $stmt->execute([
                    $eventId
                ]);


                $stmt = $pdo->prepare("
                    INSERT INTO admin_audit_logs (
                        admin_user_id,
                        attendance_event_id,
                        action,
                        old_value,
                        new_value,
                        reason
                    )
                    VALUES (
                        NULL,
                        ?,
                        'Attendance Marked Incomplete',
                        ?,
                        'Not Complete',
                        'Administrator marked attendance incomplete'
                    )
                ");

                $stmt->execute([
                    $eventId,
                    $event['completion_status']
                ]);

                $actionMessage =
                    "Attendance marked as incomplete.";
            }


            /*
             * DELETE
             *
             * This does NOT delete the student.
             * It deletes only the attendance event and its dependent
             * session/decision/face-verification records through
             * foreign-key cascade.
             */
            elseif ($adminAction === 'delete') {

                $stmt = $pdo->prepare("
                    INSERT INTO admin_audit_logs (
                        admin_user_id,
                        attendance_event_id,
                        action,
                        old_value,
                        new_value,
                        reason
                    )
                    VALUES (
                        NULL,
                        ?,
                        'Attendance Event Deleted',
                        ?,
                        'Deleted',
                        'Administrator deleted attendance event'
                    )
                ");

                $stmt->execute([
                    $eventId,
                    $event['completion_status']
                ]);


                $stmt = $pdo->prepare("
                    DELETE FROM attendance_events
                    WHERE id = ?
                ");

                $stmt->execute([
                    $eventId
                ]);

                $actionMessage =
                    "Attendance event deleted.";
            }


            else {

                throw new Exception(
                    "Unknown administrator action."
                );
            }


            $pdo->commit();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $actionError = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/

$totalStudents = (int) $pdo->query("
    SELECT COUNT(*)
    FROM users
")->fetchColumn();


$activeStudents = (int) $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE account_status = 'Active'
")->fetchColumn();


$totalAttendance = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance_events
")->fetchColumn();


$completeAttendance = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance_events
    WHERE completion_status = 'Complete'
")->fetchColumn();


$incompleteAttendance = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance_events
    WHERE completion_status = 'Not Complete'
")->fetchColumn();


$pendingAttendance = (int) $pdo->query("
    SELECT COUNT(*)
    FROM attendance_events
    WHERE admin_status = 'Pending'
")->fetchColumn();


/*
|--------------------------------------------------------------------------
| ATTENDANCE RECORDS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        ae.*,
        u.full_name,
        u.email,
        u.phone_number,
        u.course,
        u.current_year,
        COALESCE(aad.decision, ae.admin_status) AS decision
    FROM attendance_events ae
    INNER JOIN users u
        ON u.id = ae.user_id
    LEFT JOIN attendance_admin_decisions aad
        ON aad.attendance_event_id = ae.id
    ORDER BY ae.created_at DESC
");

$attendanceRecords = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| STUDENTS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone_number,
        u.course,
        u.current_year,
        u.account_status,
        u.created_at,
        COUNT(ae.id) AS attendance_count
    FROM users u
    LEFT JOIN attendance_events ae
        ON ae.user_id = u.id
    GROUP BY
        u.id,
        u.full_name,
        u.email,
        u.phone_number,
        u.course,
        u.current_year,
        u.account_status,
        u.created_at
    ORDER BY u.created_at DESC
");

$students = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| RECENT AUDIT LOGS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        aal.*,
        u.full_name AS student_name
    FROM admin_audit_logs aal
    LEFT JOIN attendance_events ae
        ON ae.id = aal.attendance_event_id
    LEFT JOIN users u
        ON u.id = ae.user_id
    ORDER BY aal.created_at DESC
    LIMIT 50
");

$auditLogs = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>NCS-IMS Admin Control Center</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6f4;
            color: #17231d;
        }

        header {
            background: #123b2a;
            color: white;
            padding: 18px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        header h1 {
            margin: 0;
            font-size: 23px;
        }

        header a {
            color: white;
            text-decoration: none;
            background: #1d5a40;
            padding: 9px 14px;
            border-radius: 7px;
        }

        main {
            width: min(1400px, 95%);
            margin: 25px auto;
        }

        .notice {
            background: #e8f4ed;
            color: #14532d;
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .error {
            background: #fdecec;
            color: #991b1b;
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .stats {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
        }

        .stat strong {
            display: block;
            font-size: 28px;
            color: #123b2a;
            margin-bottom: 7px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
        }

        h2 {
            margin-top: 0;
            color: #123b2a;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }

        th,
        td {
            padding: 11px;
            border-bottom: 1px solid #e1e7e3;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f0f4f1;
            color: #123b2a;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .pending {
            background: #fff3cd;
            color: #664d03;
        }

        .approved {
            background: #d1e7dd;
            color: #0f5132;
        }

        .declined {
            background: #f8d7da;
            color: #842029;
        }

        .complete {
            background: #d1e7dd;
            color: #0f5132;
        }

        .incomplete {
            background: #f8d7da;
            color: #842029;
        }

        .progress {
            background: #cff4fc;
            color: #055160;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .actions form {
            display: inline;
        }

        button {
            border: 0;
            border-radius: 6px;
            padding: 8px 10px;
            cursor: pointer;
            font-size: 12px;
        }

        .approve-btn {
            background: #198754;
            color: white;
        }

        .decline-btn {
            background: #dc3545;
            color: white;
        }

        .reset-btn {
            background: #6c757d;
            color: white;
        }

        .incomplete-btn {
            background: #fd7e14;
            color: white;
        }

        .delete-btn {
            background: #212529;
            color: white;
        }

        textarea {
            width: 100%;
            min-height: 70px;
            padding: 8px;
            border: 1px solid #ccd6d0;
            border-radius: 6px;
            resize: vertical;
        }

        .small {
            font-size: 12px;
            color: #68736d;
        }

    </style>

</head>

<body>

<header>

    <h1>NCS-IMS Admin Control Center</h1>

    <a href="admin.php?logout=1">
        Logout
    </a>

</header>


<main>

    <?php if ($actionMessage): ?>

        <div class="notice">
            <?= h($actionMessage) ?>
        </div>

    <?php endif; ?>


    <?php if ($actionError): ?>

        <div class="error">
            <?= h($actionError) ?>
        </div>

    <?php endif; ?>


    <!-- STATISTICS -->

    <section class="stats">

        <div class="stat">
            <strong><?= $totalStudents ?></strong>
            Total Students
        </div>

        <div class="stat">
            <strong><?= $activeStudents ?></strong>
            Active Students
        </div>

        <div class="stat">
            <strong><?= $totalAttendance ?></strong>
            Attendance Records
        </div>

        <div class="stat">
            <strong><?= $completeAttendance ?></strong>
            Complete
        </div>

        <div class="stat">
            <strong><?= $incompleteAttendance ?></strong>
            Not Complete
        </div>

        <div class="stat">
            <strong><?= $pendingAttendance ?></strong>
            Pending Review
        </div>

    </section>


    <!-- ATTENDANCE RECORDS -->

    <section class="card">

        <h2>Attendance Control</h2>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>Student</th>
                        <th>Type</th>
                        <th>Year</th>
                        <th>Semester</th>
                        <th>Identifier</th>
                        <th>Progress</th>
                        <th>Completion</th>
                        <th>Admin Status</th>
                        <th>Created</th>
                        <th>Actions</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!$attendanceRecords): ?>

                    <tr>
                        <td colspan="10">
                            No attendance records available.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($attendanceRecords as $record): ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= h($record['full_name']) ?>
                                </strong>

                                <br>

                                <span class="small">
                                    <?= h($record['email']) ?>
                                </span>

                                <br>

                                <span class="small">
                                    <?= h($record['phone_number']) ?>
                                </span>

                            </td>


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
                                <?= h($record['identifier']) ?>
                            </td>


                            <td>

                                <?= (int) $record['completed_sessions'] ?>
                                /
                                <?= (int) $record['required_sessions'] ?>

                            </td>


                            <td>

                                <?php if ($record['completion_status'] === 'Complete'): ?>

                                    <span class="badge complete">
                                        Complete
                                    </span>

                                <?php elseif ($record['completion_status'] === 'Not Complete'): ?>

                                    <span class="badge incomplete">
                                        Not Complete
                                    </span>

                                <?php else: ?>

                                    <span class="badge progress">
                                        In Progress
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if ($record['decision'] === 'Approved'): ?>

                                    <span class="badge approved">
                                        Approved
                                    </span>

                                <?php elseif ($record['decision'] === 'Declined'): ?>

                                    <span class="badge declined">
                                        Declined
                                    </span>

                                <?php else: ?>

                                    <span class="badge pending">
                                        Pending
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>
                                <?= h($record['created_at']) ?>
                            </td>


                            <td>

                                <div class="actions">

                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="admin_action"
                                            value="approve"
                                        >

                                        <input
                                            type="hidden"
                                            name="event_id"
                                            value="<?= (int) $record['id'] ?>"
                                        >

                                        <button
                                            class="approve-btn"
                                            type="submit"
                                        >
                                            Approve
                                        </button>

                                    </form>


                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="admin_action"
                                            value="decline"
                                        >

                                        <input
                                            type="hidden"
                                            name="event_id"
                                            value="<?= (int) $record['id'] ?>"
                                        >

                                        <textarea
                                            name="admin_note"
                                            placeholder="Reason for decline"
                                        ></textarea>

                                        <button
                                            class="decline-btn"
                                            type="submit"
                                        >
                                            Decline
                                        </button>

                                    </form>


                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="admin_action"
                                            value="mark_incomplete"
                                        >

                                        <input
                                            type="hidden"
                                            name="event_id"
                                            value="<?= (int) $record['id'] ?>"
                                        >

                                        <button
                                            class="incomplete-btn"
                                            type="submit"
                                        >
                                            Mark Incomplete
                                        </button>

                                    </form>


                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="admin_action"
                                            value="reset"
                                        >

                                        <input
                                            type="hidden"
                                            name="event_id"
                                            value="<?= (int) $record['id'] ?>"
                                        >

                                        <button
                                            class="reset-btn"
                                            type="submit"
                                        >
                                            Reset
                                        </button>

                                    </form>


                                    <form
                                        method="POST"
                                        onsubmit="
                                            return confirm(
                                                'Delete this attendance event?'
                                            );
                                        "
                                    >

                                        <input
                                            type="hidden"
                                            name="admin_action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="event_id"
                                            value="<?= (int) $record['id'] ?>"
                                        >

                                        <button
                                            class="delete-btn"
                                            type="submit"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- STUDENTS -->

    <section class="card">

        <h2>Student Records</h2>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Course</th>
                        <th>Year</th>
                        <th>Status</th>
                        <th>Attendance Records</th>
                        <th>Registered</th>
                    </tr>

                </thead>

                <tbody>

                <?php foreach ($students as $student): ?>

                    <tr>

                        <td>
                            <?= (int) $student['id'] ?>
                        </td>

                        <td>
                            <?= h($student['full_name']) ?>
                        </td>

                        <td>
                            <?= h($student['email']) ?>
                        </td>

                        <td>
                            <?= h($student['phone_number']) ?>
                        </td>

                        <td>
                            <?= h($student['course']) ?>
                        </td>

                        <td>
                            Year <?= (int) $student['current_year'] ?>
                        </td>

                        <td>
                            <?= h($student['account_status']) ?>
                        </td>

                        <td>
                            <?= (int) $student['attendance_count'] ?>
                        </td>

                        <td>
                            <?= h($student['created_at']) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- AUDIT HISTORY -->

    <section class="card">

        <h2>Administrative Audit History</h2>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>Date</th>
                        <th>Action</th>
                        <th>Student</th>
                        <th>Event ID</th>
                        <th>Old Value</th>
                        <th>New Value</th>
                        <th>Reason</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (!$auditLogs): ?>

                    <tr>

                        <td colspan="7">
                            No audit records available.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($auditLogs as $log): ?>

                        <tr>

                            <td>
                                <?= h($log['created_at']) ?>
                            </td>

                            <td>
                                <?= h($log['action']) ?>
                            </td>

                            <td>
                                <?= h($log['student_name'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <?= h($log['attendance_event_id'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <?= h($log['old_value'] ?? '') ?>
                            </td>

                            <td>
                                <?= h($log['new_value'] ?? '') ?>
                            </td>

                            <td>
                                <?= h($log['reason'] ?? '') ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

</body>

</html>