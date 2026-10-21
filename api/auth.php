<?php
/**
 * Authentication API
 * CCS110 - Finals Group Project
 * Handles Login, Logout, Session check, and Registration
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Check current session
if ($method === 'GET' && $action === 'check') {
    if (isset($_SESSION['user_id'])) {
        jsonResponse(true, 'Authenticated', [
            'user_id'   => $_SESSION['user_id'],
            'full_name' => $_SESSION['full_name'],
            'email'     => $_SESSION['email'],
            'role'      => $_SESSION['role']
        ]);
    } else {
        jsonResponse(false, 'Not authenticated', null, 401);
    }
}

// Logout
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    jsonResponse(true, 'Logged out successfully');
}

// Login
if ($method === 'POST' && ($action === 'login' || empty($action))) {
    $data = getRequestData();
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $expectedRole = trim($data['role'] ?? '');

    // Server-side validation
    $errors = [];
    if (empty($email)) {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please provide a valid email address.';
    }

    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }

    if (!empty($errors)) {
        jsonResponse(false, 'Validation failed.', ['errors' => $errors], 422);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(false, 'No account found with this email address.', [
                'errors' => ['email' => 'No account found with this email address.']
            ], 401);
        }

        // Verify password hash or plain text fallback for compatibility
        $validPassword = password_verify($password, $user['password']) || ($password === $user['password']);

        if (!$validPassword) {
            jsonResponse(false, 'Password incorrect. Please check your password.', [
                'errors' => ['password' => 'Password incorrect.']
            ], 401);
        }

        // Check role if specified
        if (!empty($expectedRole) && strtolower($user['role']) !== strtolower($expectedRole)) {
            jsonResponse(false, "This account is registered as " . ucfirst($user['role']) . ", not {$expectedRole}.", [
                'errors' => ['email' => "This account does not have {$expectedRole} access."]
            ], 403);
        }

        // Set session
        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email']     = $user['email'];
        $_SESSION['role']      = $user['role'];

        jsonResponse(true, "Signed in successfully as " . ucfirst($user['role']), [
            'user_id'   => $user['user_id'],
            'full_name' => $user['full_name'],
            'email'     => $user['email'],
            'role'      => $user['role']
        ]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error during authentication: ' . $e->getMessage(), null, 500);
    }
}

// User Registration (Optional feature for admin or account setup)
if ($method === 'POST' && $action === 'register') {
    requireAuth(['admin']);
    $data = getRequestData();
    $name = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $role = in_array($data['role'] ?? '', ['admin', 'staff']) ? $data['role'] : 'staff';

    if (empty($name) || empty($email) || strlen($password) < 8) {
        jsonResponse(false, 'Please fill in all required fields (password >= 8 chars).', null, 422);
    }

    try {
        $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            jsonResponse(false, 'An account with this email already exists.', null, 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $insert = $pdo->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
        $insert->execute([$name, $email, $hash, $role]);

        jsonResponse(true, 'User created successfully', ['user_id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Error creating user: ' . $e->getMessage(), null, 500);
    }
}

jsonResponse(false, 'Invalid request', null, 400);
