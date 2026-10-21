<?php
/**
 * Tenants CRUD API
 * CCS110 - Finals Group Project
 */

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET: Fetch all tenants with lease & unit info
if ($method === 'GET') {
    try {
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $sql = "
            SELECT 
                t.tenant_id AS id,
                CONCAT(t.first_name, ' ', t.last_name) AS name,
                t.first_name,
                t.last_name,
                t.contact_no AS contact,
                t.email,
                t.emergency_contact,
                t.date_registered,
                COALESCE(l.unit_id, 0) AS unit,
                COALESCE(u.unit_number, 'None') AS unit_number,
                COALESCE(l.start_date, t.date_registered) AS moveIn,
                CASE 
                    WHEN l.status IN ('Ended', 'Terminated', 'Moved out') THEN 'Moved out'
                    ELSE COALESCE(l.status, 'Active')
                END AS status,
                COALESCE(l.lease_id, 0) AS lease_id
            FROM tenants t
            LEFT JOIN leases l ON l.tenant_id = t.tenant_id AND l.status IN ('Active', 'Ended', 'Terminated', 'Moved out')
            LEFT JOIN units u ON u.unit_id = l.unit_id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($status)) {
            if ($status === 'Moved out' || $status === 'Ended') {
                $sql .= " AND l.status IN ('Ended', 'Terminated', 'Moved out')";
            } else {
                $sql .= " AND l.status = ?";
                $params[] = $status;
            }
        }

        if (!empty($search)) {
            $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.contact_no LIKE ? OR u.unit_number LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY t.tenant_id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonResponse(true, 'Tenants retrieved successfully', $rows);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

// POST: Add new tenant or handle updates/deletes
$data = getRequestData();
$requestAction = $action ?: ($data['action'] ?? 'create');

if ($method === 'POST') {
    if ($requestAction === 'create') {
        // Input validation
        $name = trim($data['name'] ?? '');
        $contact = trim($data['contact'] ?? '');
        $unitId = !empty($data['unit']) ? intval($data['unit']) : null;
        $moveIn = trim($data['moveIn'] ?? date('Y-m-d'));
        $status = trim($data['status'] ?? 'Active');
        $email = trim($data['email'] ?? '');
        $emergency = trim($data['emergency_contact'] ?? '');

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Full name is required.';
        }
        if (empty($contact)) {
            $errors['contact'] = 'Mobile number is required.';
        } elseif (!preg_match('/^09\d{9}$/', $contact)) {
            $errors['contact'] = 'Mobile number must be 11 digits starting with 09.';
        }
        if (empty($moveIn)) {
            $errors['moveIn'] = 'Move-in date is required.';
        }

        // Validate unit availability: an occupied unit cannot be assigned
        if ($unitId) {
            $uStmt = $pdo->prepare("SELECT status, unit_number FROM units WHERE unit_id = ?");
            $uStmt->execute([$unitId]);
            $uRow = $uStmt->fetch();
            if ($uRow && (strtolower($uRow['status']) === 'occupied')) {
                $errors['unit'] = "Unit {$uRow['unit_number']} is currently occupied. Please choose an available unit or add a new unit.";
            }
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Validation failed.', ['errors' => $errors], 422);
        }

        // Split name into first and last name
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO tenants (first_name, last_name, contact_no, email, emergency_contact, date_registered)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $firstName,
                $lastName,
                $contact,
                $email ?: strtolower(str_replace(' ', '.', $name)) . '@tenant.local',
                $emergency,
                $moveIn
            ]);
            $tenantId = $pdo->lastInsertId();

            // If unit assigned, create lease and set unit to Occupied
            if ($unitId) {
                // Get monthly rent for deposit
                $uStmt = $pdo->prepare("SELECT monthly_rent FROM units WHERE unit_id = ?");
                $uStmt->execute([$unitId]);
                $rent = $uStmt->fetchColumn() ?: 0;

                $leaseStatus = ($status === 'Moved out') ? 'Ended' : 'Active';
                $lStmt = $pdo->prepare("
                    INSERT INTO leases (tenant_id, unit_id, start_date, end_date, deposit_amount, status)
                    VALUES (?, ?, ?, DATE_ADD(?, INTERVAL 1 YEAR), ?, ?)
                ");
                $lStmt->execute([$tenantId, $unitId, $moveIn, $moveIn, $rent * 2, $leaseStatus]);

                if ($leaseStatus === 'Active') {
                    $updUnit = $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE unit_id = ?");
                    $updUnit->execute([$unitId]);
                } else {
                    $updUnit = $pdo->prepare("UPDATE units SET status = 'Vacant' WHERE unit_id = ?");
                    $updUnit->execute([$unitId]);
                }
            }

            $pdo->commit();
            jsonResponse(true, 'Tenant added successfully', ['id' => $tenantId], 201);
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Failed to save tenant: ' . $e->getMessage(), null, 500);
        }
    }

    if ($requestAction === 'update') {
        $id = intval($data['id'] ?? 0);
        if (!$id) {
            jsonResponse(false, 'Tenant ID is required.', null, 400);
        }

        $name = trim($data['name'] ?? '');
        $contact = trim($data['contact'] ?? '');
        $unitId = !empty($data['unit']) ? intval($data['unit']) : null;
        $moveIn = trim($data['moveIn'] ?? date('Y-m-d'));
        $status = trim($data['status'] ?? 'Active');
        $email = trim($data['email'] ?? '');
        $emergency = trim($data['emergency_contact'] ?? '');

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Full name is required.';
        }
        if (empty($contact)) {
            $errors['contact'] = 'Mobile number is required.';
        } elseif (!preg_match('/^09\d{9}$/', $contact)) {
            $errors['contact'] = 'Mobile number must be 11 digits starting with 09.';
        }

        // Check existing lease
        $chkLease = $pdo->prepare("SELECT lease_id, unit_id, status FROM leases WHERE tenant_id = ? ORDER BY lease_id DESC LIMIT 1");
        $chkLease->execute([$id]);
        $existingLease = $chkLease->fetch();
        $oldUnitId = $existingLease ? $existingLease['unit_id'] : null;

        // If assigning a new unit, verify that it is not already occupied by someone else
        if ($unitId && $unitId != $oldUnitId && $status !== 'Moved out') {
            $uStmt = $pdo->prepare("SELECT status, unit_number FROM units WHERE unit_id = ?");
            $uStmt->execute([$unitId]);
            $uRow = $uStmt->fetch();
            if ($uRow && (strtolower($uRow['status']) === 'occupied')) {
                $errors['unit'] = "Unit {$uRow['unit_number']} is currently occupied. Please choose an available unit.";
            }
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Validation failed.', ['errors' => $errors], 422);
        }

        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE tenants 
                SET first_name = ?, last_name = ?, contact_no = ?, email = COALESCE(NULLIF(?, ''), email), emergency_contact = COALESCE(NULLIF(?, ''), emergency_contact)
                WHERE tenant_id = ?
            ");
            $stmt->execute([$firstName, $lastName, $contact, $email, $emergency, $id]);

            $leaseStatus = ($status === 'Moved out') ? 'Ended' : 'Active';

            if ($existingLease) {
                $updLease = $pdo->prepare("UPDATE leases SET unit_id = ?, start_date = ?, status = ? WHERE lease_id = ?");
                $updLease->execute([$unitId ?: $oldUnitId, $moveIn, $leaseStatus, $existingLease['lease_id']]);

                // Update old & new unit statuses
                if ($unitId && $unitId != $oldUnitId) {
                    $pdo->prepare("UPDATE units SET status = 'Vacant' WHERE unit_id = ?")->execute([$oldUnitId]);
                    if ($leaseStatus === 'Active') {
                        $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE unit_id = ?")->execute([$unitId]);
                    } else {
                        $pdo->prepare("UPDATE units SET status = 'Vacant' WHERE unit_id = ?")->execute([$unitId]);
                    }
                } elseif ($leaseStatus === 'Ended') {
                    if ($oldUnitId) {
                        $pdo->prepare("UPDATE units SET status = 'Vacant' WHERE unit_id = ?")->execute([$oldUnitId]);
                    }
                } elseif ($leaseStatus === 'Active') {
                    if ($oldUnitId) {
                        $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE unit_id = ?")->execute([$oldUnitId]);
                    }
                }
            } elseif ($unitId) {
                // Create lease if not existing
                $uStmt = $pdo->prepare("SELECT monthly_rent FROM units WHERE unit_id = ?");
                $uStmt->execute([$unitId]);
                $rent = $uStmt->fetchColumn() ?: 0;

                $lStmt = $pdo->prepare("
                    INSERT INTO leases (tenant_id, unit_id, start_date, end_date, deposit_amount, status)
                    VALUES (?, ?, ?, DATE_ADD(?, INTERVAL 1 YEAR), ?, ?)
                ");
                $lStmt->execute([$id, $unitId, $moveIn, $moveIn, $rent * 2, $leaseStatus]);
                if ($leaseStatus === 'Active') {
                    $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE unit_id = ?")->execute([$unitId]);
                } else {
                    $pdo->prepare("UPDATE units SET status = 'Vacant' WHERE unit_id = ?")->execute([$unitId]);
                }
            }

            $pdo->commit();
            jsonResponse(true, 'Tenant updated successfully');
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Failed to update tenant: ' . $e->getMessage(), null, 500);
        }
    }

    if ($requestAction === 'delete') {
        // Staff cannot delete tenant records permanently (Admin only)
        requireAuth(['admin']);

        $id = intval($data['id'] ?? 0);
        if (!$id) {
            jsonResponse(false, 'Tenant ID is required.', null, 400);
        }

        try {
            // Check for dependent records
            $chkPayments = $pdo->prepare("
                SELECT COUNT(*) FROM rent_payments rp
                JOIN leases l ON l.lease_id = rp.lease_id
                WHERE l.tenant_id = ?
            ");
            $chkPayments->execute([$id]);
            if ($chkPayments->fetchColumn() > 0) {
                jsonResponse(false, 'Cannot delete tenant: Rent payment records exist for this tenant.', null, 409);
            }

            $chkBills = $pdo->prepare("
                SELECT COUNT(*) FROM utility_bills ub
                JOIN leases l ON l.lease_id = ub.lease_id
                WHERE l.tenant_id = ?
            ");
            $chkBills->execute([$id]);
            if ($chkBills->fetchColumn() > 0) {
                jsonResponse(false, 'Cannot delete tenant: Utility billing records exist for this tenant.', null, 409);
            }

            $pdo->beginTransaction();

            // Release unit if leased
            $leases = $pdo->prepare("SELECT unit_id FROM leases WHERE tenant_id = ?");
            $leases->execute([$id]);
            while ($unitId = $leases->fetchColumn()) {
                $pdo->prepare("UPDATE units SET status = 'Vacant' WHERE unit_id = ?")->execute([$unitId]);
            }

            $pdo->prepare("DELETE FROM leases WHERE tenant_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tenants WHERE tenant_id = ?")->execute([$id]);

            $pdo->commit();
            jsonResponse(true, 'Tenant deleted successfully');
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Failed to delete tenant: ' . $e->getMessage(), null, 500);
        }
    }
}

jsonResponse(false, 'Unsupported request method', null, 405);
