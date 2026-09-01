<?php
session_start();
unset($_SESSION['super_admin_id']);
session_destroy();
header('Location: login.php');
exit();
?>
