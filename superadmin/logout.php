<?php
/**
 * Sign the super admin out and return to the login screen.
 *
 * The request must carry the one-shot sign-out token that the shell
 * puts in the link / form, so a third party cannot sign somebody
 * out with a bare <img src="logout.php">.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (auth_logout_request_ok()) {
    auth_session_logout($conn, 'user');
    header('Location: login.php?signed_out=1');
    exit();
}

/* Forged, stale or already-used link: leave the session alone and
   send the visitor back to where they came from. */
if (isSuperAdminLoggedIn()) {
    require_once dirname(__DIR__) . '/includes/sa_helpers.php';
    sa_flash('warning', 'That sign-out link is not valid, so you are still signed in.');
    header('Location: index.php');
} else {
    header('Location: login.php?signed_out=invalid');
}
exit();
