<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Password policy (shared)
|--------------------------------------------------------------------------
|
| Used by change-password.php and reset-password.php so the rules
| live in one place.
|
| 1. Password must be 6–10 characters.
| 2. Password must contain at least one letter.
| 3. Password must contain at least one number OR symbol.
| 4. Password cannot be the standard temporary password.
| 5. Password and confirmation must match.
|
*/

const PBO_TEMPORARY_PASSWORD = 'field.123';


/**
 * Return the first rule the new password breaks, or null if it is valid.
 */
function pboPasswordPolicyError(
    string $newPassword,
    string $confirmPassword
): ?string {
    if (strlen($newPassword) < 6 || strlen($newPassword) > 10) {
        return 'Password must be between 6 and 10 characters.';
    }

    if (!preg_match('/[A-Za-z]/', $newPassword)) {
        return 'Password must contain at least one letter.';
    }

    if (
        !preg_match('/[0-9]/', $newPassword)
        && !preg_match('/[^A-Za-z0-9]/', $newPassword)
    ) {
        return 'Password must contain at least one number or symbol.';
    }

    if ($newPassword === PBO_TEMPORARY_PASSWORD) {
        return 'You cannot continue using the temporary password.';
    }

    if ($newPassword !== $confirmPassword) {
        return 'The passwords do not match.';
    }

    return null;
}
