<?php
/**
 * Rent Payments CRUD API
 * CCS110 - Finals Group Project
 */

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET: Fetch all rent payments with tenant & unit details
if ($method === 'GET') {
    try {
        $search = trim($_GET['search'] ?? '');
        $methodFilter = trim($_GET['method'] ?? '');

        $sql = "
            SELECT 
                rp.payment_id AS id,
                l.tenant_id AS tenant,
                CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                u.unit_number,
                DATE_FORMAT(rp.billing_month, '%Y-%m') AS month,
                CAST(rp.amount_paid AS FLOAT) AS amount,
                rp.payment_date AS date,
                rp.payment_method AS method,
                rp.lease_id
            FROM rent_payments rp
            JOIN leases l ON l.lease_id = rp.lease_id
            JOIN tenants t ON t.tenant_id = l.tenant_id
            JOIN units u ON u.unit_id = l.unit_id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($methodFilter)) {
            $sql .= " AND rp.payment_method = ?";
            $params[] = $methodFilter;
        }

        if (!empty($search)) {
            $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR u.unit_number LIKE ? OR rp.payment_method LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY rp.payment_date DESC, rp.payment_id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonResponse(true, 'Rent payments retrieved successfully', $rows);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

// POST: Add, Edit, Delete
$data = getRequestData();
$requestAction = $action ?: ($data['action'] ?? 'create');

if ($method === 'POST') {
    if ($requestAction === 'create') {
        $tenantId = intval($data['tenant'] ?? 0);
        $month = trim($data['month'] ?? '');
        $amount = floatval($data['amount'] ?? 0);
        $date = trim($data['date'] ?? date('Y-m-d'));
        $payMethod = trim($data['method'] ?? 'Cash');

        $errors = [];
        if (!$tenantId) {
            $errors['tenant'] = 'Please select a tenant.';
        }
        if (empty($month)) {
            $errors['month'] = 'Rent month is required.';
        }
        if ($amount <= 0) {
            $errors['amount'] = 'Amount must be greater than 0.';
        }
        if (empty($date)) {
            $errors['date'] = 'Date paid is required.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Validation failed.', ['errors' => $errors], 422);
        }

        // Format month into full date: YYYY-MM-01
        $billingMonth = strlen($month) === 7 ? "{$month}-01" : date('Y-m-01', strtotime($month));

        try {
            // Find active lease for tenant
            $lStmt = $pdo->prepare("SELECT lease_id FROM leases WHERE tenant_id = ? ORDER BY (status = 'Active') DESC, lease_id DESC LIMIT 1");
            $lStmt->execute([$tenantId]);
            $leaseId = $lStmt->fetchColumn();

            if (!$leaseId) {
                // If tenant has no lease, assign to the first vacant unit
                $vacantUnit = $pdo->query("SELECT unit_id, monthly_rent FROM units WHERE status = 'Vacant' OR status = 'Available' LIMIT 1")->fetch();
                if ($vacantUnit) {
                    $newLease = $pdo->prepare("INSERT INTO leases (tenant_id, unit_id, start_date, deposit_amount, status) VALUES (?, ?, ?, ?, 'Active')");
                    $newLease->execute([$tenantId, $vacantUnit['unit_id'], $date, $vacantUnit['monthly_rent']]);
                    $leaseId = $pdo->lastInsertId();
                    $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE unit_id = ?")->execute([$vacantUnit['unit_id']]);
                } else {
                    jsonResponse(false, 'Selected tenant does not have an active lease. Please assign a unit first.', null, 400);
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO rent_payments (lease_id, billing_month, amount_paid, payment_date, payment_method)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$leaseId, $billingMonth, $amount, $date, $payMethod]);

            jsonResponse(true, 'Rent payment recorded successfully', ['id' => $pdo->lastInsertId()], 201);
        } catch (PDOException $e) {
            jsonResponse(false, 'Failed to record payment: ' . $e->getMessage(), null, 500);
        }
    }

    if ($requestAction === 'update') {
        $id = intval($data['id'] ?? 0);
        if (!$id) {
            jsonResponse(false, 'Payment ID is required.', null, 400);
        }

        // Staff permission check: staff can only fix same-day payments
        $currentUserRole = $_SESSION['role'] ?? 'staff';
        if ($currentUserRole === 'staff') {
            $chk = $pdo->prepare("SELECT payment_date FROM rent_payments WHERE payment_id = ?");
            $chk->execute([$id]);
            $existingPayment = $chk->fetch();
            $today = date('Y-m-d');
            if ($existingPayment && $existingPayment['payment_date'] !== $today) {
                jsonResponse(false, 'Staff can only edit payments recorded on the same day. Older payments cannot be modified.', null, 403);
            }
        }

        $tenantId = intval($data['tenant'] ?? 0);
        $month = trim($data['month'] ?? '');
        $amount = floatval($data['amount'] ?? 0);
        $date = trim($data['date'] ?? date('Y-m-d'));
        $payMethod = trim($data['method'] ?? 'Cash');

        $errors = [];
        if (!$tenantId) {
            $errors['tenant'] = 'Please select a tenant.';
        }
        if (empty($month)) {
            $errors['month'] = 'Rent month is required.';
        }
        if ($amount <= 0) {
            $errors['amount'] = 'Amount must be greater than 0.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Validation failed.', ['errors' => $errors], 422);
        }

        $billingMonth = strlen($month) === 7 ? "{$month}-01" : date('Y-m-01', strtotime($month));

        try {
            $lStmt = $pdo->prepare("SELECT lease_id FROM leases WHERE tenant_id = ? ORDER BY (status = 'Active') DESC, lease_id DESC LIMIT 1");
            $lStmt->execute([$tenantId]);
            $leaseId = $lStmt->fetchColumn();

            if (!$leaseId) {
                jsonResponse(false, 'Selected tenant does not have any lease record. Please assign a unit first.', null, 400);
            }

            $stmt = $pdo->prepare("
                UPDATE rent_payments 
                SET lease_id = COALESCE(NULLIF(?, 0), lease_id), billing_month = ?, amount_paid = ?, payment_date = ?, payment_method = ?
                WHERE payment_id = ?
            ");
            $stmt->execute([$leaseId, $billingMonth, $amount, $date, $payMethod, $id]);

            jsonResponse(true, 'Rent payment updated successfully');
        } catch (PDOException $e) {
            jsonResponse(false, 'Failed to update payment: ' . $e->getMessage(), null, 500);
        }
    }

    if ($requestAction === 'delete') {
        $id = intval($data['id'] ?? 0);
        if (!$id) {
            jsonResponse(false, 'Payment ID is required.', null, 400);
        }

        // Staff permission check: staff cannot delete older payments
        $currentUserRole = $_SESSION['role'] ?? 'staff';
        if ($currentUserRole === 'staff') {
            $chk = $pdo->prepare("SELECT payment_date FROM rent_payments WHERE payment_id = ?");
            $chk->execute([$id]);
            $existingPayment = $chk->fetch();
            $today = date('Y-m-d');
            if (!$existingPayment || $existingPayment['payment_date'] !== $today) {
                jsonResponse(false, 'Staff cannot delete older payments. Please contact an administrator.', null, 403);
            }
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM rent_payments WHERE payment_id = ?");
            $stmt->execute([$id]);

            jsonResponse(true, 'Payment deleted successfully');
        } catch (PDOException $e) {
            jsonResponse(false, 'Failed to delete payment: ' . $e->getMessage(), null, 500);
        }
    }
}

jsonResponse(false, 'Unsupported request method', null, 405);
