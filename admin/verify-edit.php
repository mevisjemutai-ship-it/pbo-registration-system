<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requirePasswordChanged();

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars(
            $value ?? '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

$connection = $pdo ?? $db ?? null;

if (!$connection instanceof PDO) {
    throw new RuntimeException(
        'Database connection was not initialized as a PDO instance.'
    );
}

$applicationId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$applicationId) {
    $applicationId = filter_input(
        INPUT_POST,
        'application_id',
        FILTER_VALIDATE_INT
    );
}

if (!$applicationId || $applicationId < 1) {
    http_response_code(400);
    exit('Invalid application ID.');
}

$applicationId = (int) $applicationId;

/*
|--------------------------------------------------------------------------
| Load submitted record
|--------------------------------------------------------------------------
*/

$stmt = $connection->prepare(
    "SELECT
        id,
        application_reference,
        pbo_name,
        pbo_registration_number,
        registration_date,
        registration_type,
        status,
        submitted_at
     FROM pbo_applications
     WHERE id = :id
       AND status = 'submitted'
     LIMIT 1"
);

$stmt->execute([
    'id' => $applicationId,
]);

$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    http_response_code(404);
    exit('Submitted PBO record was not found.');
}

/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

$pageTitle = 'Verify Administrator Edit Access';
$activePage = 'applications';

$errors = [];

/*
|--------------------------------------------------------------------------
| Staff restriction
|--------------------------------------------------------------------------
*/

if (currentUserRole() !== 'admin') {

    http_response_code(403);

    require_once __DIR__ . '/../includes/header.php';
    ?>

    <style>
        .verify-page {
            max-width: 1120px;
            margin: 0 auto;
            padding: 28px 18px 70px;
        }

        .verify-restricted {
            overflow: hidden;
            border: 1px solid #e3c8cc;
            border-top: 5px solid #c32430;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 10px 28px rgba(31,45,74,.08);
        }

        .verify-restricted-head {
            padding: 25px 28px;
            background: linear-gradient(110deg, #fff7f7, #fff);
            border-bottom: 1px solid #f0dde0;
        }

        .verify-kicker {
            color: #c32430;
            font-size: .75rem;
            font-weight: 900;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .verify-restricted h1 {
            margin: 7px 0 7px;
            color: #263452;
            font-weight: 850;
        }

        .verify-restricted p {
            max-width: 760px;
            margin: 0;
            color: #687386;
            line-height: 1.65;
        }

        .verify-record {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) repeat(3, minmax(130px, .7fr));
            gap: 10px;
            padding: 18px 28px;
        }

        .verify-record-item {
            padding: 12px 14px;
            border: 1px solid #e0e5ed;
            border-radius: 11px;
            background: #fafbfd;
        }

        .verify-record-item span {
            display: block;
            margin-bottom: 4px;
            color: #7a8495;
            font-size: .7rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .verify-record-item strong {
            display: block;
            overflow-wrap: anywhere;
            color: #2b3b5e;
            font-size: .88rem;
        }

        .verify-restricted-body {
            padding: 5px 28px 28px;
        }

        .verify-message {
            padding: 15px 16px;
            border: 1px solid #e2e6ed;
            border-radius: 12px;
            background: #f8f9fb;
            color: #556176;
            line-height: 1.6;
        }

        @media (max-width: 760px) {
            .verify-page { padding: 18px 12px 55px; }
            .verify-record { grid-template-columns: 1fr; padding: 17px; }
            .verify-restricted-head,
            .verify-restricted-body { padding-left: 18px; padding-right: 18px; }
        }
    </style>

    <main class="verify-page">

        <section class="verify-restricted">

            <div class="verify-restricted-head">

                <span class="verify-kicker">
                    Submitted Record • Access Control
                </span>

                <h1>
                    Editing Restricted
                </h1>

                <p>
                    You can view submitted PBO records, but submitted records
                    can only be modified by an authorized administrator.
                </p>

            </div>

            <div class="verify-record">

                <div class="verify-record-item">
                    <span>Organization</span>
                    <strong><?= e((string) $application['pbo_name']) ?></strong>
                </div>

                <div class="verify-record-item">
                    <span>Registration No.</span>
                    <strong><?= e((string) ($application['pbo_registration_number'] ?: '—')) ?></strong>
                </div>

                <div class="verify-record-item">
                    <span>Type</span>
                    <strong><?= e(ucfirst((string) $application['registration_type'])) ?></strong>
                </div>

                <div class="verify-record-item">
                    <span>Status</span>
                    <strong>Submitted</strong>
                </div>

            </div>

            <div class="verify-restricted-body">

                <div class="verify-message mb-3">
                    <strong>
                        You do not have access to edit submitted records.
                    </strong>
                    Submitted PBO records can only be modified by an
                    authorized administrator. Please contact an administrator
                    if a correction is required.
                </div>

                <a
                    href="/pbo-portal/applications/view.php?id=<?= $applicationId ?>"
                    class="btn btn-primary"
                >
                    Return to Record
                </a>

            </div>

        </section>

    </main>

    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| Administrator verification
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        verifyCsrfToken(
            $_POST['csrf_token'] ?? null
        );

        $password = (string) (
            $_POST['current_password'] ?? ''
        );

        if ($password === '') {
            $errors[] =
                'Enter your current administrator password.';
        } else {

            $stmt = $connection->prepare(
                'SELECT
                    password_hash,
                    role,
                    is_active
                 FROM users
                 WHERE id = :id
                 LIMIT 1'
            );

            $stmt->execute([
                'id' => currentUserId(),
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$user ||
                $user['role'] !== 'admin' ||
                (int) $user['is_active'] !== 1
            ) {
                $errors[] =
                    'Your administrator account is not active or no longer has administrator privileges.';
            } elseif (
                !password_verify(
                    $password,
                    (string) $user['password_hash']
                )
            ) {
                $errors[] =
                    'The administrator password is incorrect.';
            } else {

                /*
                 * Refresh the session identifier after successful
                 * step-up authentication, then grant application-scoped
                 * temporary authorization.
                 */
                session_regenerate_id(true);

                grantAdminEditAuthorization(
                    $applicationId
                );

                $audit = $connection->prepare(
                    'INSERT INTO application_audit_log
                    (
                        application_id,
                        user_id,
                        action,
                        description
                    )
                    VALUES
                    (
                        :application_id,
                        :user_id,
                        :action,
                        :description
                    )'
                );

                $audit->execute([
                    'application_id' => $applicationId,
                    'user_id' => currentUserId(),
                    'action' => 'admin_edit_authorization',
                    'description' =>
                        'Administrator identity was re-verified before editing the submitted PBO record.',
                ]);

                header(
                    'Location: /pbo-portal/applications/edit-submitted.php?id=' .
                    $applicationId
                );
                exit;
            }
        }

    } catch (Throwable $exception) {

        error_log(
            'Submitted PBO edit authorization failed: ' .
            $exception->getMessage()
        );

        if ($errors === []) {
            $errors[] =
                'Administrator verification could not be completed. Please try again.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ==========================================================
   ADMINISTRATOR EDIT VERIFICATION
   ========================================================== */

.verify-page {
    max-width: 1120px;
    margin: 0 auto;
    padding: 28px 18px 70px;
}

.verify-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.verify-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 11px;
    border: 1px solid #d8e0ef;
    border-radius: 999px;
    background: #f6f8fc;
    color: #2f499f;
    font-size: .77rem;
    font-weight: 850;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.verify-badge-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #2f499f;
}

.verify-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 25px;
    padding: 28px 30px;
    margin-bottom: 17px;
    border: 1px solid #dfe5ee;
    border-radius: 18px;
    background:
        radial-gradient(circle at 88% 12%, rgba(191,150,45,.13), transparent 27%),
        linear-gradient(135deg, #f7f9fd 0%, #fff 65%);
    box-shadow: 0 7px 22px rgba(25,42,78,.06);
}

.verify-eyebrow {
    display: block;
    margin-bottom: 6px;
    color: #bf962d;
    font-size: .76rem;
    font-weight: 900;
    letter-spacing: .10em;
    text-transform: uppercase;
}

.verify-hero h1 {
    margin: 0 0 8px;
    color: #1e2e55;
    font-size: clamp(1.65rem, 3vw, 2.2rem);
    font-weight: 850;
    line-height: 1.15;
}

.verify-hero p {
    max-width: 720px;
    margin: 0;
    color: #667184;
    line-height: 1.65;
}

.verify-hero-mark {
    flex: 0 0 180px;
    padding: 15px;
    border: 1px solid #d9e0ed;
    border-radius: 14px;
    background: #fff;
}

.verify-hero-mark span {
    display: block;
    color: #7a8495;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.verify-hero-mark strong {
    display: block;
    margin-top: 4px;
    color: #2f499f;
    font-size: 1.05rem;
}

.verify-record-strip {
    display: grid;
    grid-template-columns: minmax(0, 1.65fr) repeat(3, minmax(135px, .7fr));
    gap: 10px;
    margin-bottom: 17px;
}

.verify-record-item {
    padding: 13px 15px;
    border: 1px solid #dfe4ec;
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 3px 11px rgba(30,46,85,.04);
}

.verify-record-item span {
    display: block;
    margin-bottom: 4px;
    color: #7a8495;
    font-size: .71rem;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.verify-record-item strong {
    display: block;
    overflow-wrap: anywhere;
    color: #243457;
    font-size: .9rem;
}

.verify-security {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 13px;
    margin-bottom: 18px;
    padding: 16px 17px;
    border: 1px solid #d8e1f0;
    border-left: 5px solid #2f499f;
    border-radius: 13px;
    background: linear-gradient(100deg, #f5f8fd, #fff);
}

.verify-security-icon {
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: #e9eef9;
    color: #2f499f;
    font-weight: 900;
}

.verify-security strong {
    display: block;
    color: #293c68;
    font-size: .9rem;
}

.verify-security p {
    margin: 2px 0 0;
    color: #6c778a;
    font-size: .81rem;
    line-height: 1.55;
}

.verify-card {
    overflow: hidden;
    border: 1px solid #dce2eb;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 17px rgba(31,48,84,.045);
}

.verify-card-head {
    padding: 19px 22px;
    border-bottom: 1px solid #e7ebf1;
    background: linear-gradient(100deg, #f7f9fd, #fff);
}

.verify-card-head h2 {
    margin: 0 0 4px;
    color: #263b67;
    font-size: 1.04rem;
    font-weight: 850;
}

.verify-card-head p {
    margin: 0;
    color: #6d7788;
    font-size: .84rem;
    line-height: 1.5;
}

.verify-card-body {
    padding: 23px;
}

.verify-user-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}

.verify-user-item {
    padding: 12px 14px;
    border: 1px solid #e0e5ec;
    border-radius: 11px;
    background: #fafbfd;
}

.verify-user-item span {
    display: block;
    margin-bottom: 3px;
    color: #7b8494;
    font-size: .71rem;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.verify-user-item strong {
    color: #2b3b5e;
    font-size: .88rem;
}

.verify-label {
    display: block;
    margin-bottom: 7px;
    color: #34415b;
    font-size: .85rem;
    font-weight: 800;
}

.verify-password {
    min-height: 48px;
    border-color: #cfd6e1;
    border-radius: 10px;
}

.verify-password:focus {
    border-color: #2f499f;
    box-shadow: 0 0 0 3px rgba(47,73,159,.10);
}

.verify-help {
    margin-top: 7px;
    color: #778194;
    font-size: .76rem;
    line-height: 1.5;
}

.verify-submit-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 20px;
    padding-top: 17px;
    border-top: 1px solid #e7ebf1;
}

.verify-submit-row .btn-primary {
    min-width: 190px;
    font-weight: 800;
}

.verify-error {
    margin-bottom: 18px;
    border-left-width: 5px;
    border-radius: 13px;
}

@media (max-width: 900px) {
    .verify-record-strip {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .verify-record-item:first-child {
        grid-column: 1 / -1;
    }
}

@media (max-width: 760px) {
    .verify-page { padding: 18px 12px 55px; }

    .verify-hero {
        display: block;
        padding: 21px;
    }

    .verify-hero-mark {
        margin-top: 17px;
    }

    .verify-record-strip {
        grid-template-columns: 1fr;
    }

    .verify-record-item:first-child {
        grid-column: auto;
    }

    .verify-user-grid {
        grid-template-columns: 1fr;
    }

    .verify-card-body {
        padding: 18px;
    }

    .verify-submit-row {
        align-items: stretch;
        flex-direction: column-reverse;
    }

    .verify-submit-row .btn {
        width: 100%;
    }
}
</style>

<main class="verify-page">

    <div class="verify-topbar">

        <a
            href="/pbo-portal/applications/view.php?id=<?= $applicationId ?>"
            class="btn btn-outline-secondary btn-sm"
        >
            &larr; Back to Record
        </a>

        <span class="verify-badge">
            <span class="verify-badge-dot"></span>
            Administrator Verification
        </span>

    </div>

    <section class="verify-hero">

        <div>

            <span class="verify-eyebrow">
                Submitted PBO Record • Step-up Authentication
            </span>

            <h1>
                Confirm Your Identity
            </h1>

            <p>
                Re-enter your current permanent administrator password to
                authorize a controlled edit of this submitted PBO record.
                This verification does not change your password or account.
            </p>

        </div>

        <div class="verify-hero-mark">
            <span>Authorization scope</span>
            <strong>One PBO record</strong>
            <span style="margin-top:8px;">Temporary • 15 minutes</span>
        </div>

    </section>

    <section class="verify-record-strip">

        <div class="verify-record-item">
            <span>Organization</span>
            <strong><?= e((string) $application['pbo_name']) ?></strong>
        </div>

        <div class="verify-record-item">
            <span>Registration No.</span>
            <strong><?= e((string) ($application['pbo_registration_number'] ?: '—')) ?></strong>
        </div>

        <div class="verify-record-item">
            <span>Date of Registration</span>
            <strong><?= e((string) ($application['registration_date'] ?: '—')) ?></strong>
        </div>

        <div class="verify-record-item">
            <span>Status</span>
            <strong>Submitted</strong>
        </div>

    </section>

    <section class="verify-security">

        <div class="verify-security-icon">
            ✓
        </div>

        <div>
            <strong>Why verification is required</strong>
            <p>
                Submitted records are protected from ordinary staff editing.
                Successful verification grants temporary, application-specific
                edit authorization. The authorization expires automatically
                and is cleared after a successful save.
            </p>
        </div>

    </section>

    <?php if ($errors !== []): ?>

        <div class="alert alert-danger verify-error">

            <div class="fw-bold mb-2">
                Verification was not completed.
            </div>

            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>

        </div>

    <?php endif; ?>

    <section class="verify-card">

        <div class="verify-card-head">

            <h2>
                Administrator credentials
            </h2>

            <p>
                Use the password belonging to the administrator account
                currently signed in. Do not use a temporary password.
            </p>

        </div>

        <div class="verify-card-body">

            <div class="verify-user-grid">

                <div class="verify-user-item">
                    <span>Signed-in user</span>
                    <strong><?= e(currentUserName()) ?></strong>
                </div>

                <div class="verify-user-item">
                    <span>Access level</span>
                    <strong>Administrator</strong>
                </div>

            </div>

            <form method="post" autocomplete="off">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="application_id"
                    value="<?= $applicationId ?>"
                >

                <label
                    for="current_password"
                    class="verify-label"
                >
                    Current Administrator Password
                </label>

                <input
                    id="current_password"
                    type="password"
                    name="current_password"
                    class="form-control verify-password"
                    required
                    autocomplete="current-password"
                    autofocus
                >

                <div class="verify-help">
                    Your password is verified against the active administrator
                    account and is never written to the application audit log.
                </div>

                <div class="verify-submit-row">

                    <a
                        href="/pbo-portal/applications/view.php?id=<?= $applicationId ?>"
                        class="btn btn-outline-secondary"
                    >
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="btn btn-primary btn-lg"
                    >
                        Verify &amp; Continue to Edit
                    </button>

                </div>

            </form>

        </div>

    </section>

</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>