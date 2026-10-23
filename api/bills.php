<?php
/**
 * Utility Bills CRUD API
 * CCS110 - Finals Group Project
 */

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET: Utility rates, used by the bill form to show the amount live
if ($method === 'GET' && $action === 'rates') {
    $rates = $pdo->query("SELECT utility_name, rate_per_unit FROM utility_types")
                 ->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($rates as $name => $rate) {
        $rates[$name] = floatval($rate);
    }
    jsonResponse(true, 'Rates retrieved', $rates);
}

// GET: Fetch all utility bills
if ($method === 'GET') {
    try {
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');

        $sql = "
            SELECT 
                ub.bill_id AS id,
                l.tenant_id AS tenant,
                CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                u.unit_number,
                ut.utility_name AS type,
                DATE_FORMAT(ub.billing_month, '%Y-%m') AS period,
                CAST(ub.amount_due AS FLOAT) AS amount,
                ub.due_date AS due,
                ub.status AS status,
                ub.units_consumed,
                ub.lease_id,
                ub.utility_type_id
            FROM utility_bills ub
            JOIN leases l ON l.lease_id = ub.lease_id
            JOIN tenants t ON t.tenant_id = l.tenant_id
            JOIN units u ON u.unit_id = l.unit_id
            JOIN utility_types ut ON ut.utility_type_id = ub.utility_type_id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($statusFilter)) {
            $sql .= " AND ub.status = ?";
            $params[] = $statusFilter;
        }

        if (!empty($search)) {
            $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR u.unit_number LIKE ? OR ut.utility_name LIKE ? OR ub.status LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY ub.due_date DESC, ub.bill_id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonResponse(true, 'Utility bills retrieved successfully', $rows);
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
        $utilityName = trim($data['type'] ?? 'Water');
        $period = trim($data['period'] ?? '');
        $units = floatval($data['units_consumed'] ?? 0);
        $dueDate = trim($data['due'] ?? date('Y-m-d'));
        $status = trim($data['status'] ?? 'Unpaid');

        $errors = [];
        if (!$tenantId) {
            $errors['tenant'] = 'Please select a tenant.';
        }
        if (empty($period)) {
            $errors['period'] = 'Billing period is required.';
        }
        if ($units <= 0) {
            $errors['units_consumed'] = 'Units consumed must be greater than 0.';
        }
        if (empty($dueDate)) {
            $errors['due'] = 'Due date is required.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Validation failed.', ['errors' => $errors], 422);
        }

        $billingMonth = strlen($period) === 7 ? "{$period}-01" : date('Y-m-01', strtotime($period));

        try {
            $pdo->beginTransaction();

            // Get the utility type and its rate (the rate comes from the database, not the form)
            $uTypeStmt = $pdo->prepare("SELECT utility_type_id, rate_per_unit FROM utility_types WHERE utility_name = ? LIMIT 1");
            $uTypeStmt->execute([$utilityName]);
            $typeRow = $uTypeStmt->fetch();
            if (!$typeRow) {
                $pdo->rollBack();
                jsonResponse(false, "No rate is set up for '{$utilityName}'.", null, 400);
            }
            $typeId = $typeRow['utility_type_id'];
            $amount = round($units * floatval($typeRow['rate_per_unit']), 2);

            // Find tenant lease
            $lStmt = $pdo->prepare("SELECT lease_id FROM leases WHERE tenant_id = ? ORDER BY (status = 'Active') DESC, lease_id DESC LIMIT 1");
            $lStmt->execute([$tenantId]);
            $leaseId = $lStmt->fetchColumn();

            if (!$leaseId) {
                // If tenant has no lease, assign to the first vacant unit
                $vacantUnit = $pdo->query("SELECT unit_id, monthly_rent FROM units WHERE status = 'Vacant' OR status = 'Available' LIMIT 1")->fetch();
                if ($vacantUnit) {
                    $newLease = $pdo->prepare("INSERT INTO leases (tenant_id, unit_id, start_date, deposit_amount, status) VALUES (?, ?, ?, ?, 'Active')");
                    $newLease->execute([$tenantId, $vacantUnit['unit_id'], date('Y-m-d'), $vacantUnit['monthly_rent']]);
                    $leaseId = $pdo->lastInsertId();
                    $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE unit_id = ?")->execute([$vacantUnit['unit_id']]);
                } else {
                    $pdo->rollBack();
                    jsonResponse(false, 'Selected tenant does not have an active lease.', null, 400);
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO utility_bills (lease_id, utility_type_id, billing_month, units_consumed, amount_due, due_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$leaseId, $typeId, $billingMonth, $units, $amount, $dueDate, $status]);
            $billId = $pdo->lastInsertId();

            if ($status === 'Paid') {
                $payStmt = $pdo->prepare("
                    INSERT INTO utility_payments (bill_id, amount_paid, payment_date, payment_method)
                    VALUES (?, ?, ?, 'Cash')
                ");
                $payStmt->execute([$billId, $amount, date('Y-m-d')]);
            }

            $pdo->commit();
            jsonResponse(true, 'Utility bill recorded successfully', ['id' => $billId, 'amount' => $amount], 201);
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Failed to save bill: ' . $e->getMessage(), null, 500);
        }
    }

    if ($requestAction === 'update') {
        $id = intval($data['id'] ?? 0);
        if (!$id) {
            jsonResponse(false, 'Bill ID is required.', null, 400);
        }

        $tenantId = intval($data['tenant'] ?? 0);
        $utilityName = trim($data['type'] ?? 'Water');
        $period = trim($data['period'] ?? '');
        $units = floatval($data['units_consumed'] ?? 0);
        $dueDate = trim($data['due'] ?? date('Y-m-d'));
        $status = trim($data['status'] ?? 'Unpaid');

        $errors = [];
        if (!$tenantId) {
            $errors['tenant'] = 'Please select a tenant.';
        }
        if (empty($period)) {
            $errors['period'] = 'Billing period is required.';
        }
        if ($units <= 0) {
            $errors['units_consumed'] = 'Units consumed must be greater than 0.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Validation failed.', ['errors' => $errors], 422);
        }

        $billingMonth = strlen($period) === 7 ? "{$period}-01" : date('Y-m-01', strtotime($period));

        try {
            $pdo->beginTransaction();

            $uTypeStmt = $pdo->prepare("SELECT utility_type_id, rate_per_unit FROM utility_types WHERE utility_name = ? LIMIT 1");
            $uTypeStmt->execute([$utilityName]);
            $typeRow = $uTypeStmt->fetch();
            if (!$typeRow) {
                $pdo->rollBack();
                jsonResponse(false, "No rate is set up for '{$utilityName}'.", null, 400);
            }
            $typeId = $typeRow['utility_type_id'];
            $amount = round($units * floatval($typeRow['rate_per_unit']), 2);

            $lStmt = $pdo->prepare("SELECT lease_id FROM leases WHERE tenant_id = ? ORDER BY (status = 'Active') DESC, lease_id DESC LIMIT 1");
            $lStmt->execute([$tenantId]);
            $leaseId = $lStmt->fetchColumn();

            $stmt = $pdo->prepare("
                UPDATE utility_bills
                SET lease_id = COALESCE(NULLIF(?, 0), lease_id),
                    utility_type_id = ?,
                    billing_month = ?,
                    units_consumed = ?,
                    amount_due = ?,
                    due_date = ?,
                    status = ?
                WHERE bill_id = ?
            ");
            $stmt->execute([$leaseId, $typeId, $billingMonth, $units, $amount, $dueDate, $status, $id]);

            if ($status === 'Paid') {
                $chkPay = $pdo->prepare("SELECT COUNT(*) FROM utility_payments WHERE bill_id = ?");
                $chkPay->execute([$id]);
                if ($chkPay->fetchColumn() == 0) {
                    $pdo->prepare("INSERT INTO utility_payments (bill_id, amount_paid, payment_date, payment_method) VALUES (?, ?, ?, 'Cash')")
                        ->execute([$id, $amount, date('Y-m-d')]);
                }
            }

            $pdo->commit();
            jsonResponse(true, 'Utility bill updated successfully', ['amount' => $amount]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Failed to update bill: ' . $e->getMessage(), null, 500);
        }
    }

    if ($requestAction === 'delete') {
        // Staff cannot delete bills (admin only)
        requireAuth(['admin']);

        $id = intval($data['id'] ?? 0);
        if (!$id) {
            jsonResponse(false, 'Bill ID is required.', null, 400);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM utility_payments WHERE bill_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM utility_bills WHERE bill_id = ?")->execute([$id]);
            $pdo->commit();

            jsonResponse(true, 'Utility bill deleted successfully');
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Failed to delete bill: ' . $e->getMessage(), null, 500);
        }
    }
}

jsonResponse(false, 'Unsupported request method', null, 405);
