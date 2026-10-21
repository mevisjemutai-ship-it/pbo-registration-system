<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Edit a user account
|--------------------------------------------------------------------------
|
| Corrects the details that were fixed at creation time: full name, username
| and - for administrators - the email address that sign-in codes go to.
| Names get mistyped, people marry, and a username chosen in a hurry sticks
| around; until now the only remedy was to delete the account and start again,
| which is not available once the account has done any work.
|
| Deliberately NOT editable here:
|   - the role, because promoting or demoting an account is a different
|     decision from fixing a typo and deserves its own control;
|   - the password, which has its own Reset Password action.
|
*/

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();
requirePasswordChanged();


/*
|--------------------------------------------------------------------------
| Which account
|--------------------------------------------------------------------------
*/

$userId = filter_var(
    $_POST['user_id'] ?? $_GET['id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$userId) {

    $_SESSION['flash_message'] =
        'Invalid user account.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: /pbo-portal/admin/users.php');

    exit;
}


$stmt = $pdo->prepare(
    'SELECT id, username, full_name, email, role
     FROM users
     WHERE id = :id
     LIMIT 1'
);

$stmt->execute(['id' => $userId]);

$user = $stmt->fetch();

if (!$user) {

    $_SESSION['flash_message'] =
        'User account not found.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: /pbo-portal/admin/users.php');

    exit;
}


$error = '';

$fullName = (string) $user['full_name'];
$username = (string) $user['username'];
$email    = (string) ($user['email'] ?? '');
$role     = (string) $user['role'];


/*
|--------------------------------------------------------------------------
| Save
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyCsrfToken($_POST['csrf_token'] ?? null);

    $fullName = trim(
        (string) ($_POST['full_name'] ?? '')
    );

    $username = strtolower(
        trim((string) ($_POST['username'] ?? ''))
    );

    /*
     * Email belongs to administrator accounts only; a staff account keeps
     * none. The role is not editable here, so the stored role decides.
     */
    $email = $role === 'admin'
        ? strtolower(trim((string) ($_POST['email'] ?? '')))
        : '';


    /*
    |----------------------------------------------------------------------
    | Validation - the same rules Create User applies
    |----------------------------------------------------------------------
    */

    if ($fullName === '') {

        $error =
            'Enter the staff member\'s full name.';

    } elseif (mb_strlen($fullName) > 150) {

        $error =
            'The full name is too long.';

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
        $role === 'admin'
        && $email === ''
    ) {

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
        |------------------------------------------------------------------
        | Uniqueness - excluding this account, or saving an unchanged
        | username would collide with itself
        |------------------------------------------------------------------
        */

        $check = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE username = :username
               AND id <> :id
             LIMIT 1'
        );

        $check->execute([
            'username' => $username,
            'id'       => $userId,
        ]);

        $emailInUse = false;

        if ($email !== '') {

            $emailCheck = $pdo->prepare(
                'SELECT id
                 FROM users
                 WHERE email = :email
                   AND id <> :id
                 LIMIT 1'
            );

            $emailCheck->execute([
                'email' => $email,
                'id'    => $userId,
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

            $update = $pdo->prepare(
                'UPDATE users
                    SET username  = :username,
                        full_name = :full_name,
                        email     = :email
                  WHERE id = :id'
            );

            $update->execute([
                'username'  => $username,
                'full_name' => $fullName,
                'email'     => $email !== '' ? $email : null,
                'id'        => $userId,
            ]);


            /*
             * An administrator may be editing their own account. The header
             * greets them from the session, so refresh it rather than leave
             * the old name on screen until they sign out.
             */
            if ($userId === currentUserId()) {
                $_SESSION['full_name'] = $fullName;
            }


            $changed = [];

            if ($username !== (string) $user['username']) {
                $changed[] = 'username is now ' . $username;
            }

            if ($fullName !== (string) $user['full_name']) {
                $changed[] = 'name is now ' . $fullName;
            }

            if ($email !== (string) ($user['email'] ?? '')) {
                $changed[] = 'email updated';
            }

            $_SESSION['flash_message'] =
                $changed === []
                    ? 'No changes were made to ' . $fullName . '.'
                    : 'Account updated: ' . implode(', ', $changed) . '.';

            $_SESSION['flash_type'] =
                'success';

            header('Location: /pbo-portal/admin/users.php');

            exit;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Shared top navigation
|--------------------------------------------------------------------------
*/

$pageTitle = 'Edit User';
$activePage = 'users';

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
                Edit User Account
            </h1>

            <p>
                Correct the name, username or contact address
                for this account.
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


    <div class="create-user-layout">


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
                        Changes take effect immediately.
                        The password is not affected.
                    </p>

                </div>

            </div>


            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="user_id"
                    value="<?= (int) $user['id'] ?>"
                >


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
                        maxlength="150"
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
                        maxlength="50"
                        required
                        autocomplete="off"
                    >


                    <span class="form-help">
                        Changing this changes what the staff member
                        types to sign in. Tell them before you save.
                    </span>

                </div>


                <!-- ADMINISTRATOR EMAIL -->

                <?php if ($role === 'admin'): ?>

                    <div class="form-group">

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
                            maxlength="190"
                            autocomplete="off"
                            required
                        >


                        <span class="form-help">
                            Sign-in codes and password reset codes
                            are sent to this address.
                        </span>

                    </div>

                <?php endif; ?>


                <!-- ROLE (read only) -->

                <div class="form-group">

                    <label>
                        System Role
                    </label>

                    <p class="form-help">
                        This account is
                        <strong><?= htmlspecialchars(
                            $role === 'admin' ? 'an Administrator' : 'Staff'
                        ) ?></strong>.
                        The role is not changed here.
                    </p>

                </div>


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
                        <span>
                            Save Changes
                        </span>
                    </button>

                </div>

            </form>

        </section>

    </div>

</div>


<?php

require_once __DIR__ . '/../includes/footer.php';
