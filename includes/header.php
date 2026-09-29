<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

requireLogin();
requirePasswordChanged();

$pageTitle = $pageTitle ?? 'PBO Registration System';
$activePage = $activePage ?? '';

$isAdmin = currentUserRole() === 'admin';

$currentName = currentUserName();
$currentRole = currentUserRole();

$roleLabel = $currentRole === 'admin'
    ? 'Administrator'
    : 'Staff';


/*
|--------------------------------------------------------------------------
| Generate initials for account avatar
|--------------------------------------------------------------------------
*/

$nameParts = preg_split(
    '/\s+/',
    trim($currentName)
);

$accountInitials = '';

foreach (
    array_slice($nameParts, 0, 2)
    as $part
) {

    if ($part !== '') {

        $accountInitials .= strtoupper(
            substr($part, 0, 1)
        );
    }
}

?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        <?= htmlspecialchars($pageTitle) ?>
        | PBO Registration System
    </title>

    <link
        rel="stylesheet"
        href="/pbo-portal/assets/css/style.css"
    >

</head>


<body>


<!-- ==========================================================
     INSTITUTIONAL HEADER
     ========================================================== -->

<header class="portal-brand-header">

    <div class="portal-brand">

        <div class="portal-brand-mark">
            PBO
        </div>


        <div class="portal-brand-copy">

            <strong>
                Public Benefit Organizations
            </strong>

            <span>
                Registration & Records Management System
            </span>

        </div>

    </div>


    <div class="portal-system-status">

        <span class="system-status-dot"></span>

        <span>
            Internal System
        </span>

    </div>

</header>


<!-- ==========================================================
     PRIMARY NAVIGATION
     ========================================================== -->

<nav class="top-navigation">

    <div class="top-navigation-inner">


        <!-- HOME -->

        <a
            href="/pbo-portal/dashboard.php"
            class="top-nav-link
            <?= $activePage === 'home'
                ? 'top-nav-active'
                : '' ?>"
        >
            Home
        </a>


        <!-- ==================================================
             APPLICATIONS
             ================================================== -->

        <div class="top-nav-dropdown">

            <button
                type="button"
                class="top-nav-link top-nav-button
                <?= in_array(
                    $activePage,
                    [
                        'new-application',
                        'drafts'
                    ],
                    true
                )
                    ? 'top-nav-active'
                    : '' ?>"
            >

                Applications

                <span class="nav-chevron">
                    ▾
                </span>

            </button>


            <div class="top-nav-menu">

                <!-- NEW APPLICATION -->

                <a
                    href="/pbo-portal/applications/create.php"
                    class="dropdown-featured"
                >

                    <span class="dropdown-icon blue-icon">
                        +
                    </span>

                    <span class="dropdown-copy">

                        <strong>
                            New Application
                        </strong>

                        <small>
                            Record a new PBO registration
                        </small>

                    </span>

                </a>


                <!-- DRAFT APPLICATIONS -->

                <a
                    href="/pbo-portal/applications/drafts.php"
                >

                    <span class="dropdown-icon gold-icon">
                        D
                    </span>

                    <span class="dropdown-copy">

                        <strong>
                            Draft Applications
                        </strong>

                        <small>
                            Continue saved applications
                        </small>

                    </span>

                </a>

            </div>

        </div>


        <!-- ==================================================
             SEARCH
             ================================================== -->

        <a
            href="/pbo-portal/applications/search.php"
            class="top-nav-link
            <?= $activePage === 'search'
                ? 'top-nav-active'
                : '' ?>"
        >
            Search
        </a>


        <!-- ==================================================
             RECORDS
             ================================================== -->

        <a
            href="/pbo-portal/applications/records.php"
            class="top-nav-link
            <?= $activePage === 'records'
                ? 'top-nav-active'
                : '' ?>"
        >
            Records
        </a>


        <!-- ==================================================
             ADMINISTRATION
             ADMIN ONLY
             ================================================== -->

        <?php if ($isAdmin): ?>

            <div class="top-nav-dropdown">

                <button
                    type="button"
                    class="top-nav-link top-nav-button
                    <?= in_array(
                        $activePage,
                        [
                            'users',
                            'create-user',
                            'notices'
                        ],
                        true
                    )
                        ? 'top-nav-active'
                        : '' ?>"
                >

                    Administration

                    <span class="nav-chevron">
                        ▾
                    </span>

                </button>


                <div class="top-nav-menu">

                    <!-- USER MANAGEMENT -->

                    <a
                        href="/pbo-portal/admin/users.php"
                    >

                        <span class="dropdown-icon blue-icon">
                            U
                        </span>

                        <span class="dropdown-copy">

                            <strong>
                                User Management
                            </strong>

                            <small>
                                Manage staff accounts
                            </small>

                        </span>

                    </a>



            </div>

        <?php endif; ?>


        <!-- ==================================================
             ACCOUNT
             ================================================== -->

        <div class="top-nav-account">

            <div class="top-nav-dropdown account-dropdown">

                <button
                    type="button"
                    class="account-button"
                >

                    <span class="account-button-avatar">

                        <?= htmlspecialchars(
                            $accountInitials
                        ) ?>

                    </span>


                    <span class="account-button-copy">

                        <strong>
                            <?= htmlspecialchars(
                                $currentName
                            ) ?>
                        </strong>

                        <small>
                            <?= htmlspecialchars(
                                $roleLabel
                            ) ?>
                        </small>

                    </span>


                    <span class="nav-chevron">
                        ▾
                    </span>

                </button>


                <div class="top-nav-menu account-menu">


                    <!-- CURRENT ACCOUNT -->

                    <div class="account-menu-header">

                        <strong>
                            <?= htmlspecialchars(
                                $currentName
                            ) ?>
                        </strong>

                        <span>
                            <?= htmlspecialchars(
                                $roleLabel
                            ) ?>
                        </span>

                    </div>


                    <!-- LOGOUT ONLY -->

                    <a
                        href="/pbo-portal/logout.php"
                        class="logout-menu-item"
                    >

                        <span class="dropdown-icon red-icon">
                            ×
                        </span>

                        <span class="dropdown-copy">

                            <strong>
                                Logout
                            </strong>

                            <small>
                                End current session
                            </small>

                        </span>

                    </a>

                </div>

            </div>

        </div>

    </div>

</nav>


<!-- ==========================================================
     PAGE CONTENT
     ========================================================== -->

<main class="top-layout-content">