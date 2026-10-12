<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();
requirePasswordChanged();


/*
|--------------------------------------------------------------------------
| Active account count
|--------------------------------------------------------------------------
*/

$activeUsers = (int) $pdo->query(
    'SELECT COUNT(*)
     FROM users
     WHERE is_active = TRUE'
)->fetchColumn();


/*
|--------------------------------------------------------------------------
| Retrieve system users
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query(
    'SELECT
        id,
        username,
        full_name,
        role,
        is_active,
        must_change_password,
        last_login_at,
        created_at
     FROM users
     ORDER BY
        is_active DESC,
        full_name ASC'
);

$users = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/

$message = $_SESSION['flash_message'] ?? '';
$messageType = $_SESSION['flash_type'] ?? '';

unset(
    $_SESSION['flash_message'],
    $_SESSION['flash_type']
);


/*
|--------------------------------------------------------------------------
| Shared navigation configuration
|--------------------------------------------------------------------------
*/

$pageTitle = 'User Management';
$activePage = 'users';

require_once __DIR__ . '/../includes/header.php';

?>


<div class="users-page">


    <!-- ======================================================
         USER MANAGEMENT HERO
         ====================================================== -->

    <section class="users-hero">

        <div class="users-hero-copy">

            <span class="users-eyebrow">
                Administration
            </span>

            <h1>
                User Management
            </h1>

            <p>
                Manage staff access, account status
                and login credentials.
            </p>

        </div>


        <div class="users-hero-actions">


            <!-- ACTIVE USERS -->

            <div class="active-user-count">

                <span class="active-user-dot"></span>

                <span>

                    <strong>
                        <?= $activeUsers ?>
                    </strong>

                    active
                    <?= $activeUsers === 1
                        ? 'user'
                        : 'users' ?>

                </span>

            </div>


            <!-- CREATE USER -->

            <a
                href="/pbo-portal/admin/create-user.php"
                class="create-user-main"
            >

                <span class="create-user-icon">
                    +
                </span>


                <span class="create-user-text">

                    <strong>
                        Create User
                    </strong>

                    <small>
                        Add a staff account
                    </small>

                </span>

            </a>

        </div>

    </section>


    <!-- ======================================================
         FLASH MESSAGE
         ====================================================== -->

    <?php if ($message !== ''): ?>

        <div
            class="alert <?= $messageType === 'error'
                ? 'alert-error'
                : 'alert-success' ?>"
        >

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <!-- ======================================================
         USER DIRECTORY
         ====================================================== -->

    <section class="user-directory-card">


        <!-- DIRECTORY HEADER -->

        <div class="user-directory-header">

            <div>

                <span class="directory-label">
                    Accounts
                </span>

                <h2>
                    System Users
                </h2>

                <p>
                    Authorized accounts with access to
                    the PBO Registration and Records
                    Management System.
                </p>

            </div>


            <div class="directory-key">

                <span>

                    <i class="key-dot key-blue"></i>

                    Administrator

                </span>


                <span>

                    <i class="key-dot key-red"></i>

                    Staff

                </span>

            </div>

        </div>


        <?php if (!$users): ?>


            <!-- EMPTY STATE -->

            <div class="users-empty-state">

                <div class="users-empty-icon">
                    +
                </div>

                <h3>
                    No user accounts
                </h3>

                <p>
                    Create the first staff account
                    to begin.
                </p>

                <a
                    href="/pbo-portal/admin/create-user.php"
                    class="btn btn-primary"
                >
                    Create User
                </a>

            </div>


        <?php else: ?>


            <!-- ==================================================
                 USER TABLE
                 ================================================== -->

            <div class="users-table-wrapper">

                <table class="users-table">

                    <thead>

                    <tr>

                        <th>
                            Staff Member
                        </th>

                        <th>
                            Username
                        </th>

                        <th>
                            System Role
                        </th>

                        <th>
                            Account Status
                        </th>

                        <th>
                            Password
                        </th>

                        <th>
                            Last Login
                        </th>

                        <th class="actions-heading">
                            Account Actions
                        </th>

                    </tr>

                    </thead>


                    <tbody>

                    <?php foreach ($users as $user): ?>

                        <?php

                        $isCurrentUser =
                            (int) $user['id']
                            === currentUserId();

                        $rowClass =
                            $user['role'] === 'admin'
                                ? 'user-row-admin'
                                : 'user-row-staff';


                        /*
                         * Generate initials
                         */

                        $words = preg_split(
                            '/\s+/',
                            trim($user['full_name'])
                        );

                        $initials = '';

                        foreach (
                            array_slice($words, 0, 2)
                            as $word
                        ) {

                            if ($word !== '') {

                                $initials .= strtoupper(
                                    substr(
                                        $word,
                                        0,
                                        1
                                    )
                                );
                            }
                        }

                        ?>


                        <tr class="<?= $rowClass ?>">


                            <!-- ==============================
                                 STAFF MEMBER
                                 ============================== -->

                            <td class="user-name-cell">

                                <div class="user-avatar">

                                    <?= htmlspecialchars(
                                        $initials
                                    ) ?>

                                </div>


                                <div class="user-identity">

                                    <strong>

                                        <?= htmlspecialchars(
                                            $user['full_name']
                                        ) ?>

                                    </strong>


                                    <?php if ($isCurrentUser): ?>

                                        <span class="you-label">
                                            Your account
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </td>


                            <!-- ==============================
                                 USERNAME
                                 ============================== -->

                            <td>

                                <span class="username-value">

                                    <?= htmlspecialchars(
                                        $user['username']
                                    ) ?>

                                </span>

                            </td>


                            <!-- ==============================
                                 ROLE
                                 ============================== -->

                            <td>

                                <?php if (
                                    $user['role'] === 'admin'
                                ): ?>

                                    <span
                                        class="role-pill role-admin"
                                    >
                                        Administrator
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="role-pill role-staff"
                                    >
                                        Staff
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ==============================
                                 STATUS
                                 ============================== -->

                            <td>

                                <?php if (
                                    (bool) $user['is_active']
                                ): ?>

                                    <span
                                        class="account-status status-active"
                                    >

                                        <span
                                            class="status-dot"
                                        ></span>

                                        Active

                                    </span>

                                <?php else: ?>

                                    <span
                                        class="account-status status-disabled"
                                    >

                                        <span
                                            class="status-dot"
                                        ></span>

                                        Disabled

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ==============================
                                 PASSWORD
                                 ============================== -->

                            <td>

                                <?php if (
                                    (bool) $user[
                                        'must_change_password'
                                    ]
                                ): ?>

                                    <span
                                        class="password-state password-pending"
                                    >
                                        Change required
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="password-state password-set"
                                    >
                                        Password set
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ==============================
                                 LAST LOGIN
                                 ============================== -->

                            <td class="last-login-cell">

                                <?php if (
                                    $user['last_login_at']
                                ): ?>

                                    <strong>

                                        <?= htmlspecialchars(
                                            date(
                                                'd M Y',
                                                strtotime(
                                                    $user[
                                                        'last_login_at'
                                                    ]
                                                )
                                            )
                                        ) ?>

                                    </strong>

                                    <small>

                                        <?= htmlspecialchars(
                                            date(
                                                'H:i',
                                                strtotime(
                                                    $user[
                                                        'last_login_at'
                                                    ]
                                                )
                                            )
                                        ) ?>

                                    </small>

                                <?php else: ?>

                                    <span class="never-login">
                                        Never
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ==============================
                                 ACCOUNT ACTIONS
                                 ============================== -->

                            <td class="user-actions-cell">

                                <?php if ($isCurrentUser): ?>

                                    <span
                                        class="current-account-note"
                                    >
                                        Signed in
                                    </span>

                                <?php else: ?>

                                    <div class="user-action-group">


                                        <!-- RESET PASSWORD -->

                                        <form
                                            method="post"
                                            action="/pbo-portal/admin/reset-password.php"
                                            class="reset-user-form"
                                            style="
                                                display: inline;
                                                margin: 0;
                                            "
                                        >

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

                                            <input
                                                type="hidden"
                                                name="full_name"
                                                value="<?= htmlspecialchars(
                                                    (string) $user['full_name'],
                                                    ENT_QUOTES | ENT_SUBSTITUTE,
                                                    'UTF-8'
                                                ) ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="user-action reset-action"
                                            >
                                                Reset Password
                                            </button>

                                        </form>


                                        <!-- ENABLE / DISABLE -->

                                        <?php if (
                                            (bool) $user[
                                                'is_active'
                                            ]
                                        ): ?>

                                            <a
                                                href="/pbo-portal/admin/toggle-user.php?id=<?= (int) $user['id'] ?>&action=disable"
                                                class="user-action disable-action"
                                            >
                                                Disable
                                            </a>

                                        <?php else: ?>

    <!-- ENABLE -->

    <a
        href="/pbo-portal/admin/toggle-user.php?id=<?= (int) $user['id'] ?>&action=enable"
        class="user-action enable-action"
    >
        Enable
    </a>


    <!-- DELETE DISABLED USER -->

    <form
        method="post"
        action="/pbo-portal/admin/delete-user.php"
        class="delete-user-form"
        style="
            display: inline;
            margin: 0;
        "
    >

        <input
            type="hidden"
            name="user_id"
            value="<?= (int) $user['id'] ?>"
        >

        <input
            type="hidden"
            name="username"
            value="<?= htmlspecialchars(
                (string) $user['username'],
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) ?>"
        >

        <button
            type="submit"
            class="user-action delete-action"
        >
            Delete
        </button>

    </form>

<?php endif; ?>
                                    </div>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</div>
<script>
(function () {

    'use strict';


    /*
     * Confirm before resetting a password.
     */
    document
        .querySelectorAll('.reset-user-form')
        .forEach(function (form) {

            form.addEventListener('submit', function (event) {

                const nameField =
                    form.querySelector('input[name="full_name"]');

                const name =
                    nameField ? nameField.value : 'this user';

                const confirmed = window.confirm(
                    'Reset the password for "' + name + '"?\n\n' +
                    'Their password will become the temporary password ' +
                    'field.123 and they must choose a new one at their ' +
                    'next sign-in.'
                );

                if (!confirmed) {
                    event.preventDefault();
                    return;
                }

                const button =
                    form.querySelector('button[type="submit"]');

                if (button) {
                    button.disabled = true;
                    button.textContent = 'Resetting...';
                }
            });
        });


    const forms =
        document.querySelectorAll(
            '.delete-user-form'
        );


    forms.forEach(
        function (form) {

            form.addEventListener(
                'submit',
                function (event) {

                    const usernameField =
                        form.querySelector(
                            'input[name="username"]'
                        );


                    const username =
                        usernameField
                            ? usernameField.value
                            : 'this user';


                    const confirmed =
                        window.confirm(
                            'Permanently delete disabled user "' +
                            username +
                            '"?\n\n' +
                            'The account will be permanently removed.\n\n' +
                            'A user with existing application or audit history cannot be deleted.'
                        );


                    if (!confirmed) {

                        event.preventDefault();

                        return;
                    }


                    const button =
                        form.querySelector(
                            'button[type="submit"]'
                        );


                    if (button) {

                        button.disabled = true;

                        button.textContent =
                            'Deleting...';
                    }

                }
            );

        }
    );

})();
</script>

<?php

require_once __DIR__ . '/../includes/footer.php';

?>