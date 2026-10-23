<?php
/**
 * Units CRUD API
 * CCS110 - Finals Group Project
 */

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET: Fetch all units
if ($method === 'GET') {
    try {
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $sql = "
            SELECT 
                unit_id AS id,
                unit_number AS number,
                unit_type AS type,
                floor_no,
                capacity,
                CAST(monthly_rent AS FLOAT) AS rent,
                CASE 
                    WHEN status = 'Vacant' THEN 'Available'
                    ELSE status
                END AS status
            FROM units
            WHERE 1=1
        ";

        $params = [];

        if (!empty($status)) {
            if ($status === 'Available') {
                $sql .= " AND (status = 'Available' OR status = 'Vacant')";
            } else {
                $sql .= " AND status = ?";
                $params[] = $status;
            }
        }

        if (!empty($search)) {
            $sql .= " AND (unit_number LIKE ? OR unit_type LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY unit_number ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonResponse(true, 'Units retrieved successfully', $rows);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

// POST: Add, Edit, Delete
$data = getRequestData();
$requestAction = $action ?: ($data['action'] ?? 'create');

if ($method === 'POST') {
    if ($requestAction === 'create') {
        // Staff cannot add units (admin only)
        requireAuth(['admin']);

        $number = trim($data['number'] ?? '');
        $type = trim($data['type'] ?? 'Studio');
        $rent = floatval($data['rent'] ?? 0);
        $status = trim($data['status'] ?? 'Available');
        $floor = intval($data['floor_no'] ?? (intval(substr($number, 1, 1)) ?: 1));
        $capacity = intval($data['capacity'] ?? 2);

        $dbStatus = ($status === 'Available') ? 'Vacant' : $status;

        $errors = [];
        if (empty($number)) {
            $errors['number'] = 'Unit number is required.';
        }
        if ($rent <= 0) {
            $errors['rent'] = 'Monthly rent must be greater than 0.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Validation failed.', ['errors' => $errors], 422);
        }

        try {
            // Check uniqueness
            $chk = $pdo->prepare("SELECT unit_id FROM units WHERE unit_number = ?");
            $chk->execute([$number]);
            if ($chk->fetch()) {
                jsonResponse(false, "Unit number '{$number}' already exists.", null, 409);
            }

            $stmt = $pdo->prepare("
                INSERT INTO units (unit_number, unit_type, floor_no, capacity, monthly_rent, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$number, $type, $floor, $capacity, $rent, $dbStatus]);

            jsonResponse(true, 'Unit added successfully', ['id' => $pdo->lastInsertId()], 201);
        } catch (PDOException $e) {
            jsonResponse(false, 'Failed to add unit: ' . $e->getMessage(), null, 500);
        }
    }

    if ($requestAction === 'update') {
        // Staff cannot edit units or change monthly rent (admin only)
        requireAuth(['admin']);

        $id = intval($data['id'] ?? 0);
        if (!$id) {
            jsonResponse(false, 'Unit ID is required.', null, 400);
        }

        // Keep the unit's current status unless a status is explicitly sent
        $currentStmt = $pdo->prepare("SELECT status FROM units WHERE unit_id = ?");
        $currentStmt->execute([$id]);
        $currentStatus = $currentStmt->fetchColumn() ?: 'Vacant';
        $defaultStatus = ($currentStatus === 'Vacant') ? 'Available' : $currentStatus;

        $number = trim($data['number'] ?? '');
        $type = trim($data['type'] ?? 'Studio');
        $rent = floatval($data['rent'] ?? 0);
        $status = isset($data['status']) ? trim($data['status']) : $defaultStatus;
        $floor = intval($data['floor_no'] ?? 1);
        $capacity = intval($data['capacity'] ?? 2);

        $dbStatus = ($status === 'Available') ? 'Vacant' : $status;

        $errors = [];
        if (empty($number)) {
            $errors['number'] = 'Unit number is required.';
        }
        if ($rent <= 0) {
            $errors['rent'] = 'Monthly rent must be greater than 0.';
        }

        if (!empty($errors)) {
            jsonResponse(false, 'Validation failed.', ['errors' => $errors], 422);
        }

        try {
            // Check uniqueness if number changed
            $chk = $pdo->prepare("SELECT unit_id FROM units WHERE unit_number = ? AND unit_id != ?");
            $chk->execute([$number, $id]);
            if ($chk->fetch()) {
                jsonResponse(false, "Unit number '{$number}' is already assigned to another unit.", null, 409);
            }

            $stmt = $pdo->prepare("
                UPDATE units 
                SET unit_number = ?, unit_type = ?, floor_no = ?, capacity = ?, monthly_rent = ?, status = ?
                WHERE unit_id = ?
            ");
            $stmt->execute([$number, $type, $floor, $capacity, $rent, $dbStatus, $id]);

            jsonResponse(true, 'Unit updated successfully');
        } catch (PDOException $e) {
            jsonResponse(false, 'Failed to update unit: ' . $e->getMessage(), null, 500);
        }
    }

    if ($requestAction === 'delete') {
        // Staff cannot delete units (admin only)
        requireAuth(['admin']);

        $id = intval($data['id'] ?? 0);
        if (!$id) {
            jsonResponse(false, 'Unit ID is required.', null, 400);
        }

        try {
            // Check if leased
            $chk = $pdo->prepare("SELECT COUNT(*) FROM leases WHERE unit_id = ?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                jsonResponse(false, 'Cannot delete unit: Active or past lease records are associated with this unit.', null, 409);
            }

            $stmt = $pdo->prepare("DELETE FROM units WHERE unit_id = ?");
            $stmt->execute([$id]);

            jsonResponse(true, 'Unit deleted successfully');
        } catch (PDOException $e) {
            jsonResponse(false, 'Failed to delete unit: ' . $e->getMessage(), null, 500);
        }
    }
}

jsonResponse(false, 'Unsupported request method', null, 405);
