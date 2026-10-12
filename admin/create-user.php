<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();
requirePasswordChanged();

$error = '';

$fullName = '';
$username = '';
$email = '';
$role = 'staff';


/*
|--------------------------------------------------------------------------
| Process account creation
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $fullName = trim(
        $_POST['full_name'] ?? ''
    );

    $username = strtolower(
        trim($_POST['username'] ?? '')
    );

    $role = $_POST['role'] ?? 'staff';

    /*
     * Email is only collected for administrators (used for sign-in
     * codes and "Forgot password"). Staff accounts have no email.
     */
    $email = strtolower(
        trim((string) ($_POST['email'] ?? ''))
    );

    if ($role !== 'admin') {
        $email = '';
    }


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($fullName === '') {

        $error =
            'Enter the staff member\'s full name.';

    } elseif ($username === '') {

        $error =
            'Enter a username.';

    } elseif (
        !preg_match(
            '/^[a-z0-9._-]{3,50}$/',
            $username
        )
    ) {

        $error =
            'Username must be 3 to 50 characters and may contain '
            . 'letters, numbers, dots, underscores or hyphens.';

    } elseif (
        !in_array(
            $role,
            ['admin', 'staff'],
            true
        )
    ) {

        $error =
            'Select a valid system role.';

    } elseif ($role === 'admin' && $email === '') {

        $error =
            'Enter an email address for the administrator. '
            . 'It is used for sign-in codes and password resets.';

    } elseif (
        $role === 'admin'
        && (
            strlen($email) > 190
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        )
    ) {

        $error =
            'Enter a valid email address for the administrator.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Ensure username is unique
        |--------------------------------------------------------------------------
        */

        $check = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE username = :username
             LIMIT 1'
        );

        $check->execute([
            'username' => $username
        ]);


        $emailInUse = false;

        if ($email !== '') {

            $emailCheck = $pdo->prepare(
                'SELECT id
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );

            $emailCheck->execute([
                'email' => $email
            ]);

            $emailInUse = (bool) $emailCheck->fetch();
        }


        if ($check->fetch()) {

            $error =
                'That username is already in use.';

        } elseif ($emailInUse) {

            $error =
                'That email address is already used by another account.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Standard temporary password
            |--------------------------------------------------------------------------
            */

            $temporaryPassword =
                'field.123';

            $passwordHash =
                password_hash(
                    $temporaryPassword,
                    PASSWORD_DEFAULT
                );


            /*
            |--------------------------------------------------------------------------
            | Create account
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare(
                'INSERT INTO users (
                    username,
                    full_name,
                    email,
                    password_hash,
                    role,
                    is_active,
                    must_change_password
                )
                VALUES (
                    :username,
                    :full_name,
                    :email,
                    :password_hash,
                    :role,
                    TRUE,
                    TRUE
                )'
            );

            $stmt->execute([
                'username' => $username,
                'full_name' => $fullName,
                'email' => $email !== '' ? $email : null,
                'password_hash' => $passwordHash,
                'role' => $role
            ]);


            /*
            |--------------------------------------------------------------------------
            | Confirmation
            |--------------------------------------------------------------------------
            */

            $_SESSION['flash_message'] =
                'Account created successfully for '
                . $fullName
                . '. Temporary password: field.123';

            $_SESSION['flash_type'] =
                'success';


            header(
                'Location: /pbo-portal/admin/users.php'
            );

            exit;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Shared top navigation
|--------------------------------------------------------------------------
*/

$pageTitle = 'Create User';
$activePage = 'create-user';

require_once __DIR__ . '/../includes/header.php';

?>


<div class="create-user-page">


    <!-- ==========================================================
         PAGE HEADING
         ========================================================== -->

    <div class="create-user-top">

        <div>

            <span class="users-eyebrow">
                User Management
            </span>

            <h1>
                Create User Account
            </h1>

            <p>
                Add an authorized staff member to the
                PBO Registration System.
            </p>

        </div>


        <a
            href="/pbo-portal/admin/users.php"
            class="back-users-link"
        >
            ← Back to Users
        </a>

    </div>


    <!-- ==========================================================
         ERROR
         ========================================================== -->

    <?php if ($error !== ''): ?>

        <div class="alert alert-error">

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>


    <!-- ==========================================================
         CREATE USER LAYOUT
         ========================================================== -->

    <div class="create-user-layout">


        <!-- ======================================================
             ACCOUNT FORM
             ====================================================== -->

        <section class="create-user-card">


            <div class="create-user-card-heading">

                <span class="form-step-number">
                    01
                </span>


                <div>

                    <h2>
                        Account Details
                    </h2>

                    <p>
                        Enter the staff member's details
                        and select their level of access.
                    </p>

                </div>

            </div>


            <form method="POST">


                <!-- FULL NAME -->

                <div class="form-group">

                    <label for="full_name">

                        Full Name

                        <span class="required">
                            *
                        </span>

                    </label>


                    <input
                        id="full_name"
                        type="text"
                        name="full_name"
                        value="<?= htmlspecialchars($fullName) ?>"
                        placeholder="Enter staff member's full name"
                        required
                        autofocus
                    >

                </div>


                <!-- USERNAME -->

                <div class="form-group">

                    <label for="username">

                        Username

                        <span class="required">
                            *
                        </span>

                    </label>


                    <input
                        id="username"
                        type="text"
                        name="username"
                        value="<?= htmlspecialchars($username) ?>"
                        placeholder="e.g. jkamau"
                        required
                        autocomplete="off"
                    >


                    <span class="form-help">
                        The staff member will use this
                        username when signing in.
                    </span>

                </div>


                <!-- ==================================================
                     SYSTEM ROLE
                     ================================================== -->

                <div class="form-group">

                    <label>

                        System Role

                        <span class="required">
                            *
                        </span>

                    </label>


                    <div class="role-selector">


                        <!-- STAFF -->

                        <label
                            class="role-choice"
                            for="role_staff"
                        >

                            <input
                                id="role_staff"
                                type="radio"
                                name="role"
                                value="staff"
                                <?= $role === 'staff'
                                    ? 'checked'
                                    : '' ?>
                                required
                            >


                            <span class="role-choice-content">

                                <span class="role-choice-title">

                                    <span
                                        class="role-choice-dot staff-dot"
                                    ></span>

                                    Staff

                                </span>


                                <span class="role-choice-description">

                                    Create applications,
                                    save drafts, search and
                                    view PBO records.

                                </span>

                            </span>

                        </label>


                        <!-- ADMINISTRATOR -->

                        <label
                            class="role-choice"
                            for="role_admin"
                        >

                            <input
                                id="role_admin"
                                type="radio"
                                name="role"
                                value="admin"
                                <?= $role === 'admin'
                                    ? 'checked'
                                    : '' ?>
                                required
                            >


                            <span class="role-choice-content">

                                <span class="role-choice-title">

                                    <span
                                        class="role-choice-dot admin-dot"
                                    ></span>

                                    Administrator

                                </span>


                                <span class="role-choice-description">

                                    Full system access,
                                    including user and
                                    administrative management.

                                </span>

                            </span>

                        </label>

                    </div>

                </div>


                <!-- ==================================================
                     ADMINISTRATOR EMAIL (only shown for Administrator)
                     ================================================== -->

                <div
                    class="form-group"
                    id="admin-email-group"
                    <?= $role === 'admin' ? '' : 'hidden' ?>
                >

                    <label for="email">

                        Administrator Email

                        <span class="required">
                            *
                        </span>

                    </label>


                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="<?= htmlspecialchars($email) ?>"
                        placeholder="e.g. jkamau@example.com"
                        maxlength="190"
                        autocomplete="off"
                        <?= $role === 'admin' ? 'required' : 'disabled' ?>
                    >


                    <span class="form-help">
                        Sign-in codes and password reset codes
                        are sent to this address.
                    </span>

                </div>


                <!-- ==================================================
                     FORM ACTIONS
                     ================================================== -->

                <div class="create-user-actions">

                    <a
                        href="/pbo-portal/admin/users.php"
                        class="btn btn-secondary"
                    >
                        Cancel
                    </a>


                    <button
                        type="submit"
                        class="create-account-button"
                    >

                        <span class="create-account-plus">
                            +
                        </span>

                        <span>
                            Create User Account
                        </span>

                    </button>

                </div>

            </form>

        </section>


        <!-- ======================================================
             FIRST LOGIN INFORMATION
             ====================================================== -->

        <aside class="new-account-info">


            <div class="account-info-accent"></div>


            <span class="account-info-label">
                New Account
            </span>


            <h2>
                First Login
            </h2>


            <p>
                Every new account receives the
                standard temporary password.
            </p>


            <!-- TEMPORARY PASSWORD -->

            <div class="temporary-password-box">

                <span>
                    Temporary Password
                </span>

                <strong>
                    field.123
                </strong>

            </div>


            <!-- LOGIN PROCESS -->

            <div class="account-process">


                <!-- STEP 1 -->

                <div class="process-item">

                    <span class="process-number">
                        1
                    </span>

                    <div>

                        <strong>
                            Account created
                        </strong>

                        <p>
                            The username becomes
                            active immediately.
                        </p>

                    </div>

                </div>


                <div class="process-line"></div>


                <!-- STEP 2 -->

                <div class="process-item">

                    <span class="process-number">
                        2
                    </span>

                    <div>

                        <strong>
                            First login
                        </strong>

                        <p>
                            The user signs in using
                            field.123.
                        </p>

                    </div>

                </div>


                <div class="process-line"></div>


                <!-- STEP 3 -->

                <div class="process-item">

                    <span class="process-number">
                        3
                    </span>

                    <div>

                        <strong>
                            Mandatory password change
                        </strong>

                        <p>
                            The user must create a personal
                            password before accessing
                            the system.
                        </p>

                    </div>

                </div>

            </div>


            <!-- SECURITY NOTE -->

            <div class="account-security-note">

                If a staff member later forgets their password,
                an administrator must reset the account.
                The password will return to
                <strong>field.123</strong> and the user
                will be required to create a new password
                at their next login.

                <br><br>

                Administrators reset their own password with
                <strong>Forgot password?</strong> on the sign-in
                page, and confirm new browsers with a code sent
                to their email.

            </div>

        </aside>

    </div>

</div>


<!-- ==========================================================
     ROLE CARD SELECTION
     ========================================================== -->

<script>

const roleChoices =
    document.querySelectorAll('.role-choice');


function updateRoleCards() {

    roleChoices.forEach((choice) => {

        const radio =
            choice.querySelector(
                'input[type="radio"]'
            );

        choice.classList.toggle(
            'role-choice-selected',
            radio.checked
        );

    });
}


roleChoices.forEach((choice) => {

    choice.addEventListener(
        'click',
        updateRoleCards
    );

});


/*
 * Show the email field only when Administrator is selected.
 */
const adminEmailGroup =
    document.getElementById('admin-email-group');

const adminEmailInput =
    document.getElementById('email');


function updateAdminEmail() {

    const adminSelected =
        document.getElementById('role_admin').checked;

    adminEmailGroup.hidden = !adminSelected;
    adminEmailInput.disabled = !adminSelected;
    adminEmailInput.required = adminSelected;
}


document
    .querySelectorAll('input[name="role"]')
    .forEach((radio) => {

        radio.addEventListener('change', () => {
            updateRoleCards();
            updateAdminEmail();
        });

    });


updateRoleCards();
updateAdminEmail();

</script>


<?php

require_once __DIR__ . '/../includes/footer.php';

?>