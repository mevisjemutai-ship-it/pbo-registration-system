<?php

declare(strict_types=1);

session_start();

if (!empty($_SESSION['user_id'])) {

    if (!empty($_SESSION['must_change_password'])) {
        header('Location: change-password.php');
    } else {
        header('Location: dashboard.php');
    }

} else {

    header('Location: login.php');

}

exit;