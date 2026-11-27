<?php
/**
 * ICNHAEM-2027 configuration
 *
 * IMPORTANT: fill in the real database credentials and notification e-mails
 * before deploying. This file is blocked from direct web access by .htaccess.
 */

// --- Database -------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'icnhaem2027');
define('DB_USER', 'icnhaem2027_user');
define('DB_PASS', 'change-this-password');
define('DB_CHARSET', 'utf8mb4');

// --- Mail ------------------------------------------------------------------
// The address submissions are sent from, and the addresses that receive a
// copy of every abstract / registration.
define('SITE_EMAIL', 'info@icnhaem2027.com'); // TODO: replace with real inbox
define('NOTIFY_EMAILS', [
    'info@icnhaem2027.com', // TODO: replace with organising committee inboxes
]);

// --- Upload directories ----------------------------------------------------
define('ABSTRACT_UPLOAD_DIR', __DIR__ . '/uploads/abstract/');
define('UPLOAD_DIR', __DIR__ . '/uploads/registration/');

// --- Conference details (single source of truth) ---------------------------
define('CONF_NAME', 'ICNHAEM-2027');
define('CONF_FULL_NAME', 'International Conference on Nanomaterials, Hydrogen and Advanced Energy Materials and Devices');
define('CONF_DATES', '18–20 March 2027');
define('CONF_VENUE', 'Banaras Hindu University, Varanasi, India');

/**
 * Return a shared PDO connection.
 */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
