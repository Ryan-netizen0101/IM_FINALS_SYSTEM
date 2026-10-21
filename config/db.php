<?php
/**
 * Database Connection & Global Configuration
 * CCS110 - Information Management 1
 * Apartment / Boarding House Tenant & Billing System
 */

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_host = 'localhost';
$db_port = '3306';
$db_name = 'tenant_billing_system';
$db_user = 'root';
$db_pass = '';
$charset = 'utf8mb4';

try {
    // Connect directly to MySQL
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    // If database does not exist, try creating it
    try {
        $initPdo = new PDO("mysql:host={$db_host};port={$db_port};charset={$charset}", $db_user, $db_pass);
        $initPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $initPdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
        
        // Load and run initial SQL script if available
        $sqlFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tenant_billing_system.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            $pdo->exec($sql);
        }
    } catch (PDOException $ex) {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $ex->getMessage()
            ]);
            exit;
        }
        die('Database connection error: ' . htmlspecialchars($ex->getMessage()));
    }
}

// Ensure users and deleted_records tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        user_id          INT AUTO_INCREMENT PRIMARY KEY,
        full_name        VARCHAR(100) NOT NULL,
        email            VARCHAR(100) NOT NULL UNIQUE,
        password         VARCHAR(255) NOT NULL,
        role             VARCHAR(20) NOT NULL DEFAULT 'staff',
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed default users if empty
    $checkUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($checkUsers == 0) {
        $adminHash = password_hash('admin123', PASSWORD_BCRYPT);
        $staffHash = password_hash('staff123', PASSWORD_BCRYPT);
        $seedStmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
        $seedStmt->execute(['Property Administrator', 'admin@property.com', $adminHash, 'admin']);
        $seedStmt->execute(['Front Desk Staff', 'staff@property.com', $staffHash, 'staff']);
    }

    // Ensure deleted_records table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_records (
        deleted_id    INT AUTO_INCREMENT PRIMARY KEY,
        table_name    VARCHAR(50)  NOT NULL,
        record_id     INT          NOT NULL,
        record_label  VARCHAR(255) NOT NULL,
        record_data   LONGTEXT     NOT NULL,
        deleted_by    VARCHAR(100) NULL,
        deleted_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_table_name (table_name),
        INDEX idx_deleted_at (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // ignore if table exists
}

/**
 * Send JSON response and exit
 */
function jsonResponse($success, $message, $data = null, $statusCode = 200) {
    header('Content-Type: application/json');
    http_response_code($statusCode);
    $response = [
        'success' => $success,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

/**
 * Get incoming JSON payload or POST data
 */
function getRequestData() {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

/**
 * Verify user session
 */
function requireAuth($allowedRoles = ['admin', 'staff']) {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(false, 'Unauthorized. Please sign in.', null, 401);
    }
    if (!in_array($_SESSION['role'] ?? '', $allowedRoles)) {
        jsonResponse(false, 'Forbidden. Insufficient permissions.', null, 403);
    }
    return $_SESSION;
}
