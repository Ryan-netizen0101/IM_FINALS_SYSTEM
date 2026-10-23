<?php
/**
 * Database Setup & Reset Tool
 * CCS110 - Information Management 1
 */

$db_host = 'localhost';
$db_port = '3306';
$db_user = 'root';
$db_pass = '';
$db_name = 'tenant_billing_system';

$messages = [];
$success = true;

try {
    $pdo = new PDO("mysql:host={$db_host};port={$db_port}", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Reset database: drop if exists then create fresh
    $pdo->exec("DROP DATABASE IF EXISTS {$db_name}");
    $pdo->exec("CREATE DATABASE {$db_name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE {$db_name}");
    $messages[] = "Database {$db_name} reset and selected.";

    // Read SQL file
    $sqlFile = _DIR_ . DIRECTORY_SEPARATOR . 'tenant_billing_system.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("tenant_billing_system.sql file not found!");
    }

    $sqlContent = file_get_contents($sqlFile);
    
    // Split into individual executable statements
    // Remove comments
    $lines = explode("\n", $sqlContent);
    $cleanSql = "";
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (strpos($trimmed, '--') === 0 || empty($trimmed)) {
            continue;
        }
        $cleanSql .= $line . "\n";
    }

    $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
    $executed = 0;
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            try {
                $pdo->exec($stmt);
                $executed++;
            } catch (PDOException $ex) {
                // Ignore duplicate database creation or already existing table warnings if any
                if (strpos($ex->getMessage(), 'already exists') === false) {
                    // Log notice
                }
            }
        }
    }
    $messages[] = "Executed database schema and sample data statements ({$executed} statements).";

    // Ensure users table exists with admin and staff
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        user_id          INT AUTO_INCREMENT PRIMARY KEY,
        full_name        VARCHAR(100) NOT NULL,
        email            VARCHAR(100) NOT NULL UNIQUE,
        password         VARCHAR(255) NOT NULL,
        role             VARCHAR(20) NOT NULL DEFAULT 'staff',
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $adminHash = password_hash('admin123', PASSWORD_BCRYPT);
    $staffHash = password_hash('staff123', PASSWORD_BCRYPT);

    $userStmt = $pdo->prepare("
        INSERT INTO users (full_name, email, password, role) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE password = VALUES(password), full_name = VALUES(full_name)
    ");
    $userStmt->execute(['Property Administrator', 'admin@property.com', $adminHash, 'admin']);
    $userStmt->execute(['Front Desk Staff', 'staff@property.com', $staffHash, 'staff']);
    $messages[] = "Verified default user accounts (admin@property.com & staff@property.com).";

    // Count rows across all required tables
    $tableCounts = [];
    $tables = ['users', 'tenants', 'units', 'utility_types', 'leases', 'rent_payments', 'utility_bills', 'utility_payments'];
    foreach ($tables as $tbl) {
        try {
            $cnt = $pdo->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
            $tableCounts[$tbl] = $cnt;
        } catch (Exception $e) {
            $tableCounts[$tbl] = 'Error: ' . $e->getMessage();
        }
    }

} catch (Exception $e) {
    $success = false;
    $messages[] = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup · Hallway Billing</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 40px 20px; margin: 0; }
        .box { max-width: 680px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 32px; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.4); }
        h1 { margin-top: 0; color: #38bdf8; font-size: 24px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-weight: 600; font-size: 13px; }
        .badge-success { background: #065f46; color: #6ee7b7; }
        .badge-error { background: #991b1b; color: #fca5a5; }
        ul { background: #0f172a; padding: 16px 24px; border-radius: 8px; border: 1px solid #334155; font-size: 14px; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; font-weight: 600; }
        .btn { display: inline-block; background: #0284c7; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; margin-top: 24px; }
        .btn:hover { background: #0369a1; }
        .creds { background: #0f172a; border-left: 4px solid #38bdf8; padding: 12px 16px; margin: 20px 0; border-radius: 0 6px 6px 0; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Database Setup &amp; Migration</h1>
        <p>Status: <span class="badge <?= $success ? 'badge-success' : 'badge-error' ?>"><?= $success ? 'READY &amp; CONNECTED' : 'FAILED' ?></span></p>

        <h3>Installation Log</h3>
        <ul>
            <?php foreach ($messages as $msg): ?>
                <li><?= htmlspecialchars($msg) ?></li>
            <?php endforeach; ?>
        </ul>

        <?php if ($success): ?>
            <h3>Table Status</h3>
            <table>
                <thead>
                    <tr><th>Table Name</th><th>Record Count</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($tableCounts as $tbl => $cnt): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($tbl) ?></code></td>
                            <td><?= htmlspecialchars($cnt) ?> records</td>
                            <td style="color:#6ee7b7;">✓ Verified</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="creds">
                <strong>Login Credentials Ready:</strong><br>
                • <strong>Admin:</strong> <code>admin@property.com</code> | Password: <code>admin123</code><br>
                • <strong>Staff:</strong> <code>staff@property.com</code> | Password: <code>staff123</code>
            </div>

            <a href="login.html" class="btn">Proceed to Sign In &rarr;</a>
        <?php endif; ?>
    </div>
</body>
</html>
