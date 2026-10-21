<?php
/**
 * Entry Point
 * Redirects to dashboard if logged in, otherwise to login.html
 */
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.html');
} else {
    header('Location: login.html');
}
exit;
