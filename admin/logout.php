<?php
/**
 * Sign the workspace user (tenant or global admin) out.
 *
 * The request must carry the one-shot sign-out token that the shell
 * puts in the link / form, so a third party cannot sign somebody
 * out with a bare <img src="logout.php">.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (auth_logout_request_ok()) {
    auth_session_logout($conn, 'user');
    header('Location: login.php?signed_out=1');
    exit();
}

/* Forged, stale or already-used link: leave the session alone and
   send the visitor back to where they came from. */
if (isLoggedIn()) {
    sa_flash('warning', 'That sign-out link is not valid. Please try again.');
    header('Location: index.php');
} else {
    header('Location: login.php?signed_out=invalid');
}
exit();
