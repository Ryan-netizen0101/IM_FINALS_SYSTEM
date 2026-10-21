<?php
/**
 * Deleted Records API - Soft Delete Archive & Restore
 * CCS110 - Information Management Finals
 * Apartment / Boarding House Tenant & Billing System
 */

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

// Ensure deleted_records table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deleted_records (
            deleted_id    INT AUTO_INCREMENT PRIMARY KEY,
            table_name    VARCHAR(50)  NOT NULL,
            record_id     INT          NOT NULL,
            record_label  VARCHAR(255) NOT NULL,
            record_data   LONGTEXT     NOT NULL,
            deleted_by    VARCHAR(100) NULL,
            deleted_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_table_name (table_name),
            INDEX idx_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    // continue if table exists
}

// All deleted records actions are strictly admin-only
requireAuth(['admin']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data   = getRequestData();
$reqAction = $action ?: ($data['action'] ?? '');
$adminName = $_SESSION['full_name'] ?? ($_SESSION['email'] ?? 'Property Administrator');

/* ============================================================
   GET: Fetch all deleted records (with optional filter & search)
   ============================================================ */
if ($method === 'GET') {
    try {
        $type   = trim($_GET['type'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT deleted_id, table_name, record_id, record_label, record_data, deleted_by, deleted_at FROM deleted_records WHERE 1=1";
        $params = [];

        if (!empty($type)) {
            $sql .= " AND table_name = ?";
            $params[] = $type;
        }

        if (!empty($search)) {
            $sql .= " AND (record_label LIKE ? OR table_name LIKE ? OR deleted_by LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY deleted_at DESC, deleted_id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['record_data'] = json_decode($r['record_data'], true);
        }
        unset($r);

        jsonResponse(true, 'Deleted records retrieved successfully', $rows);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
    }
}

/* ============================================================
   POST: Actions (delete & archive, restore, purge, purge_all)
   ============================================================ */
if ($method === 'POST') {

    /* ------------------------------------------------------------
       ACTION: delete (Archive row & remove from active table)
       ------------------------------------------------------------ */
    if ($reqAction === 'delete') {
        $table = trim($data['table'] ?? ($data['name'] ?? ''));
        $id    = intval($data['id'] ?? 0);

        if (!$table || !$id) {
            jsonResponse(false, 'Table and Record ID are required.', null, 400);
        }

        try {
            $pdo->beginTransaction();

            if ($table === 'tenants') {
                // Fetch tenant
                $tStmt = $pdo->prepare("SELECT * FROM tenants WHERE tenant_id = ?");
                $tStmt->execute([$id]);
                $tenant = $tStmt->fetch();
                if (!$tenant) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Tenant not found.', null, 404);
                }

                // Fetch leases for this tenant
                $lStmt = $pdo->prepare("SELECT * FROM leases WHERE tenant_id = ?");
                $lStmt->execute([$id]);
                $leases = $lStmt->fetchAll();
                $leaseIds = array_column($leases, 'lease_id');

                // Fetch rent payments for these leases
                $rentPayments = [];
                if (!empty($leaseIds)) {
                    $inLeases = implode(',', array_map('intval', $leaseIds));
                    $rentPayments = $pdo->query("SELECT * FROM rent_payments WHERE lease_id IN ($inLeases)")->fetchAll();
                }

                // Fetch utility bills & utility payments for these leases
                $utilityBills = [];
                $utilityPayments = [];
                if (!empty($leaseIds)) {
                    $inLeases = implode(',', array_map('intval', $leaseIds));
                    $utilityBills = $pdo->query("SELECT * FROM utility_bills WHERE lease_id IN ($inLeases)")->fetchAll();
                    $billIds = array_column($utilityBills, 'bill_id');
                    if (!empty($billIds)) {
                        $inBills = implode(',', array_map('intval', $billIds));
                        $utilityPayments = $pdo->query("SELECT * FROM utility_payments WHERE bill_id IN ($inBills)")->fetchAll();
                    }
                }

                // Free occupied units
                foreach ($leases as $lease) {
                    if ($lease['status'] === 'Active' && !empty($lease['unit_id'])) {
                        $pdo->prepare("UPDATE units SET status = 'Vacant' WHERE unit_id = ?")->execute([$lease['unit_id']]);
                    }
                }

                $recordLabel = "Tenant: {$tenant['first_name']} {$tenant['last_name']} (" . ($tenant['contact_no'] ?? 'No contact') . ")";
                $snapshot = [
                    'tenant'           => $tenant,
                    'leases'           => $leases,
                    'rent_payments'    => $rentPayments,
                    'utility_bills'    => $utilityBills,
                    'utility_payments' => $utilityPayments
                ];

                // Disable foreign key checks for clean cascading removal
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                if (!empty($billIds)) {
                    $inBills = implode(',', array_map('intval', $billIds));
                    $pdo->exec("DELETE FROM utility_payments WHERE bill_id IN ($inBills)");
                }
                if (!empty($leaseIds)) {
                    $inLeases = implode(',', array_map('intval', $leaseIds));
                    $pdo->exec("DELETE FROM utility_bills WHERE lease_id IN ($inLeases)");
                    $pdo->exec("DELETE FROM rent_payments WHERE lease_id IN ($inLeases)");
                    $pdo->exec("DELETE FROM leases WHERE lease_id IN ($inLeases)");
                }
                $pdo->prepare("DELETE FROM tenants WHERE tenant_id = ?")->execute([$id]);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                // Save to deleted_records
                $ins = $pdo->prepare("INSERT INTO deleted_records (table_name, record_id, record_label, record_data, deleted_by) VALUES (?, ?, ?, ?, ?)");
                $ins->execute(['tenants', $id, $recordLabel, json_encode($snapshot, JSON_UNESCAPED_UNICODE), $adminName]);

            } elseif ($table === 'units') {
                $uStmt = $pdo->prepare("SELECT * FROM units WHERE unit_id = ?");
                $uStmt->execute([$id]);
                $unit = $uStmt->fetch();
                if (!$unit) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Unit not found.', null, 404);
                }

                // Check leases associated with unit
                $lStmt = $pdo->prepare("SELECT * FROM leases WHERE unit_id = ?");
                $lStmt->execute([$id]);
                $leases = $lStmt->fetchAll();

                $recordLabel = "Unit: {$unit['unit_number']} ({$unit['unit_type']} - PHP " . number_format($unit['monthly_rent'], 2) . ")";
                $snapshot = [
                    'unit'   => $unit,
                    'leases' => $leases
                ];

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                // Temporarily detach unit from leases
                $pdo->prepare("UPDATE leases SET unit_id = NULL WHERE unit_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM units WHERE unit_id = ?")->execute([$id]);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                $ins = $pdo->prepare("INSERT INTO deleted_records (table_name, record_id, record_label, record_data, deleted_by) VALUES (?, ?, ?, ?, ?)");
                $ins->execute(['units', $id, $recordLabel, json_encode($snapshot, JSON_UNESCAPED_UNICODE), $adminName]);

            } elseif ($table === 'payments') {
                $pStmt = $pdo->prepare("
                    SELECT rp.*, CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, u.unit_number
                    FROM rent_payments rp
                    LEFT JOIN leases l ON l.lease_id = rp.lease_id
                    LEFT JOIN tenants t ON t.tenant_id = l.tenant_id
                    LEFT JOIN units u ON u.unit_id = l.unit_id
                    WHERE rp.payment_id = ?
                ");
                $pStmt->execute([$id]);
                $payment = $pStmt->fetch();
                if (!$payment) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Rent payment not found.', null, 404);
                }

                $tName = $payment['tenant_name'] ?: 'Tenant';
                $month = substr($payment['billing_month'], 0, 7);
                $recordLabel = "Rent payment: {$tName} - {$month} (PHP " . number_format($payment['amount_paid'], 2) . ")";

                $snapshot = [
                    'payment' => $payment
                ];

                $pdo->prepare("DELETE FROM rent_payments WHERE payment_id = ?")->execute([$id]);

                $ins = $pdo->prepare("INSERT INTO deleted_records (table_name, record_id, record_label, record_data, deleted_by) VALUES (?, ?, ?, ?, ?)");
                $ins->execute(['payments', $id, $recordLabel, json_encode($snapshot, JSON_UNESCAPED_UNICODE), $adminName]);

            } elseif ($table === 'bills') {
                $bStmt = $pdo->prepare("
                    SELECT ub.*, ut.utility_name, CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, u.unit_number
                    FROM utility_bills ub
                    LEFT JOIN leases l ON l.lease_id = ub.lease_id
                    LEFT JOIN tenants t ON t.tenant_id = l.tenant_id
                    LEFT JOIN units u ON u.unit_id = l.unit_id
                    LEFT JOIN utility_types ut ON ut.utility_type_id = ub.utility_type_id
                    WHERE ub.bill_id = ?
                ");
                $bStmt->execute([$id]);
                $bill = $bStmt->fetch();
                if (!$bill) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Utility bill not found.', null, 404);
                }

                // Check utility payments
                $upStmt = $pdo->prepare("SELECT * FROM utility_payments WHERE bill_id = ?");
                $upStmt->execute([$id]);
                $uPayments = $upStmt->fetchAll();

                $tName = $bill['tenant_name'] ?: 'Tenant';
                $uType = $bill['utility_name'] ?: 'Utility';
                $period = substr($bill['billing_month'], 0, 7);
                $recordLabel = "Utility bill: {$tName} - {$uType} {$period} (PHP " . number_format($bill['amount_due'], 2) . ")";

                $snapshot = [
                    'bill'             => $bill,
                    'utility_payments' => $uPayments
                ];

                $pdo->prepare("DELETE FROM utility_payments WHERE bill_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM utility_bills WHERE bill_id = ?")->execute([$id]);

                $ins = $pdo->prepare("INSERT INTO deleted_records (table_name, record_id, record_label, record_data, deleted_by) VALUES (?, ?, ?, ?, ?)");
                $ins->execute(['bills', $id, $recordLabel, json_encode($snapshot, JSON_UNESCAPED_UNICODE), $adminName]);

            } else {
                $pdo->rollBack();
                jsonResponse(false, 'Invalid table name.', null, 400);
            }

            $pdo->commit();
            jsonResponse(true, "Record deleted and moved to Deleted Records.", ['record_label' => $recordLabel]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Delete error: ' . $e->getMessage(), null, 500);
        }
    }

    /* ------------------------------------------------------------
       ACTION: restore (Restore from deleted_records back to tables)
       ------------------------------------------------------------ */
    if ($reqAction === 'restore') {
        $deletedId = intval($data['deleted_id'] ?? 0);
        if (!$deletedId) {
            jsonResponse(false, 'Deleted Record ID is required.', null, 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM deleted_records WHERE deleted_id = ?");
            $stmt->execute([$deletedId]);
            $archive = $stmt->fetch();
            if (!$archive) {
                jsonResponse(false, 'Archive record not found.', null, 404);
            }

            $table = $archive['table_name'];
            $dataSnap = json_decode($archive['record_data'], true);

            $pdo->beginTransaction();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            if ($table === 'tenants') {
                $t = $dataSnap['tenant'];
                // Restore tenant
                $insT = $pdo->prepare("
                    INSERT INTO tenants (tenant_id, first_name, last_name, contact_no, email, emergency_contact, date_registered)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        first_name = VALUES(first_name),
                        last_name = VALUES(last_name),
                        contact_no = VALUES(contact_no),
                        email = VALUES(email),
                        emergency_contact = VALUES(emergency_contact)
                ");
                $insT->execute([
                    $t['tenant_id'],
                    $t['first_name'],
                    $t['last_name'],
                    $t['contact_no'],
                    $t['email'],
                    $t['emergency_contact'],
                    $t['date_registered']
                ]);

                // Restore leases
                if (!empty($dataSnap['leases'])) {
                    $insL = $pdo->prepare("
                        INSERT INTO leases (lease_id, tenant_id, unit_id, start_date, end_date, deposit_amount, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status), unit_id = VALUES(unit_id)
                    ");
                    foreach ($dataSnap['leases'] as $l) {
                        $insL->execute([
                            $l['lease_id'],
                            $l['tenant_id'],
                            $l['unit_id'],
                            $l['start_date'],
                            $l['end_date'],
                            $l['deposit_amount'],
                            $l['status']
                        ]);

                        // Re-mark unit as occupied if lease was active
                        if ($l['status'] === 'Active' && !empty($l['unit_id'])) {
                            $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE unit_id = ?")->execute([$l['unit_id']]);
                        }
                    }
                }

                // Restore rent payments
                if (!empty($dataSnap['rent_payments'])) {
                    $insRP = $pdo->prepare("
                        INSERT INTO rent_payments (payment_id, lease_id, billing_month, amount_paid, payment_date, payment_method)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE amount_paid = VALUES(amount_paid)
                    ");
                    foreach ($dataSnap['rent_payments'] as $rp) {
                        $insRP->execute([
                            $rp['payment_id'],
                            $rp['lease_id'],
                            $rp['billing_month'],
                            $rp['amount_paid'],
                            $rp['payment_date'],
                            $rp['payment_method']
                        ]);
                    }
                }

                // Restore utility bills
                if (!empty($dataSnap['utility_bills'])) {
                    $insUB = $pdo->prepare("
                        INSERT INTO utility_bills (bill_id, lease_id, utility_type_id, billing_month, units_consumed, amount_due, due_date, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE amount_due = VALUES(amount_due), status = VALUES(status)
                    ");
                    foreach ($dataSnap['utility_bills'] as $ub) {
                        $insUB->execute([
                            $ub['bill_id'],
                            $ub['lease_id'],
                            $ub['utility_type_id'],
                            $ub['billing_month'],
                            $ub['units_consumed'],
                            $ub['amount_due'],
                            $ub['due_date'],
                            $ub['status']
                        ]);
                    }
                }

                // Restore utility payments
                if (!empty($dataSnap['utility_payments'])) {
                    $insUP = $pdo->prepare("
                        INSERT INTO utility_payments (utility_payment_id, bill_id, amount_paid, payment_date, payment_method)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE amount_paid = VALUES(amount_paid)
                    ");
                    foreach ($dataSnap['utility_payments'] as $up) {
                        $insUP->execute([
                            $up['utility_payment_id'],
                            $up['bill_id'],
                            $up['amount_paid'],
                            $up['payment_date'],
                            $up['payment_method']
                        ]);
                    }
                }

            } elseif ($table === 'units') {
                $u = $dataSnap['unit'];
                $insU = $pdo->prepare("
                    INSERT INTO units (unit_id, unit_number, unit_type, floor_no, capacity, monthly_rent, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        unit_type = VALUES(unit_type),
                        monthly_rent = VALUES(monthly_rent),
                        status = VALUES(status)
                ");
                $insU->execute([
                    $u['unit_id'],
                    $u['unit_number'],
                    $u['unit_type'],
                    $u['floor_no'],
                    $u['capacity'],
                    $u['monthly_rent'],
                    $u['status']
                ]);

                // Re-link leases that pointed to this unit
                if (!empty($dataSnap['leases'])) {
                    foreach ($dataSnap['leases'] as $l) {
                        $pdo->prepare("UPDATE leases SET unit_id = ? WHERE lease_id = ?")->execute([$u['unit_id'], $l['lease_id']]);
                    }
                }

            } elseif ($table === 'payments') {
                $p = $dataSnap['payment'];
                $insP = $pdo->prepare("
                    INSERT INTO rent_payments (payment_id, lease_id, billing_month, amount_paid, payment_date, payment_method)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE amount_paid = VALUES(amount_paid)
                ");
                $insP->execute([
                    $p['payment_id'],
                    $p['lease_id'],
                    $p['billing_month'],
                    $p['amount_paid'],
                    $p['payment_date'],
                    $p['payment_method']
                ]);

            } elseif ($table === 'bills') {
                $b = $dataSnap['bill'];
                $insB = $pdo->prepare("
                    INSERT INTO utility_bills (bill_id, lease_id, utility_type_id, billing_month, units_consumed, amount_due, due_date, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE amount_due = VALUES(amount_due), status = VALUES(status)
                ");
                $insB->execute([
                    $b['bill_id'],
                    $b['lease_id'],
                    $b['utility_type_id'],
                    $b['billing_month'],
                    $b['units_consumed'],
                    $b['amount_due'],
                    $b['due_date'],
                    $b['status']
                ]);

                if (!empty($dataSnap['utility_payments'])) {
                    $insUP = $pdo->prepare("
                        INSERT INTO utility_payments (utility_payment_id, bill_id, amount_paid, payment_date, payment_method)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE amount_paid = VALUES(amount_paid)
                    ");
                    foreach ($dataSnap['utility_payments'] as $up) {
                        $insUP->execute([
                            $up['utility_payment_id'],
                            $up['bill_id'],
                            $up['amount_paid'],
                            $up['payment_date'],
                            $up['payment_method']
                        ]);
                    }
                }
            }

            // Remove from deleted_records archive
            $pdo->prepare("DELETE FROM deleted_records WHERE deleted_id = ?")->execute([$deletedId]);

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $pdo->commit();

            jsonResponse(true, "Record successfully restored to {$table}.", ['record_label' => $archive['record_label']]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            jsonResponse(false, 'Restore error: ' . $e->getMessage(), null, 500);
        }
    }

    /* ------------------------------------------------------------
       ACTION: restore_all (Restore all or filtered type from archive)
       ------------------------------------------------------------ */
    if ($reqAction === 'restore_all') {
        $type = trim($data['type'] ?? '');
        try {
            $sql = "SELECT * FROM deleted_records";
            $params = [];
            if (!empty($type)) {
                $sql .= " WHERE table_name = ?";
                $params[] = $type;
            }
            // Restore in logical order: units first, then tenants, then leases/bills/payments
            $sql .= " ORDER BY CASE table_name 
                WHEN 'units' THEN 1 
                WHEN 'tenants' THEN 2 
                WHEN 'payments' THEN 3 
                WHEN 'bills' THEN 4 
                ELSE 5 END ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $archives = $stmt->fetchAll();

            if (empty($archives)) {
                jsonResponse(false, 'No archived records to restore.', null, 400);
            }

            $pdo->beginTransaction();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $restoredCount = 0;
            foreach ($archives as $archive) {
                $table = $archive['table_name'];
                $dataSnap = json_decode($archive['record_data'], true);

                if ($table === 'units') {
                    $u = $dataSnap['unit'];
                    $insU = $pdo->prepare("
                        INSERT INTO units (unit_id, unit_number, unit_type, floor_no, capacity, monthly_rent, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE unit_type = VALUES(unit_type), monthly_rent = VALUES(monthly_rent), status = VALUES(status)
                    ");
                    $insU->execute([$u['unit_id'], $u['unit_number'], $u['unit_type'], $u['floor_no'], $u['capacity'], $u['monthly_rent'], $u['status']]);
                    if (!empty($dataSnap['leases'])) {
                        foreach ($dataSnap['leases'] as $l) {
                            $pdo->prepare("UPDATE leases SET unit_id = ? WHERE lease_id = ?")->execute([$u['unit_id'], $l['lease_id']]);
                        }
                    }
                } elseif ($table === 'tenants') {
                    $t = $dataSnap['tenant'];
                    $insT = $pdo->prepare("
                        INSERT INTO tenants (tenant_id, first_name, last_name, contact_no, email, emergency_contact, date_registered)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), contact_no = VALUES(contact_no), email = VALUES(email), emergency_contact = VALUES(emergency_contact)
                    ");
                    $insT->execute([$t['tenant_id'], $t['first_name'], $t['last_name'], $t['contact_no'], $t['email'], $t['emergency_contact'], $t['date_registered']]);
                    if (!empty($dataSnap['leases'])) {
                        $insL = $pdo->prepare("
                            INSERT INTO leases (lease_id, tenant_id, unit_id, start_date, end_date, deposit_amount, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE status = VALUES(status), unit_id = VALUES(unit_id)
                        ");
                        foreach ($dataSnap['leases'] as $l) {
                            $insL->execute([$l['lease_id'], $l['tenant_id'], $l['unit_id'], $l['start_date'], $l['end_date'], $l['deposit_amount'], $l['status']]);
                            if ($l['status'] === 'Active' && !empty($l['unit_id'])) {
                                $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE unit_id = ?")->execute([$l['unit_id']]);
                            }
                        }
                    }
                    if (!empty($dataSnap['rent_payments'])) {
                        $insRP = $pdo->prepare("
                            INSERT INTO rent_payments (payment_id, lease_id, billing_month, amount_paid, payment_date, payment_method)
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE amount_paid = VALUES(amount_paid)
                        ");
                        foreach ($dataSnap['rent_payments'] as $rp) {
                            $insRP->execute([$rp['payment_id'], $rp['lease_id'], $rp['billing_month'], $rp['amount_paid'], $rp['payment_date'], $rp['payment_method']]);
                        }
                    }
                    if (!empty($dataSnap['utility_bills'])) {
                        $insUB = $pdo->prepare("
                            INSERT INTO utility_bills (bill_id, lease_id, utility_type_id, billing_month, units_consumed, amount_due, due_date, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE amount_due = VALUES(amount_due), status = VALUES(status)
                        ");
                        foreach ($dataSnap['utility_bills'] as $ub) {
                            $insUB->execute([$ub['bill_id'], $ub['lease_id'], $ub['utility_type_id'], $ub['billing_month'], $ub['units_consumed'], $ub['amount_due'], $ub['due_date'], $ub['status']]);
                        }
                    }
                    if (!empty($dataSnap['utility_payments'])) {
                        $insUP = $pdo->prepare("
                            INSERT INTO utility_payments (utility_payment_id, bill_id, amount_paid, payment_date, payment_method)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE amount_paid = VALUES(amount_paid)
                        ");
                        foreach ($dataSnap['utility_payments'] as $up) {
                            $insUP->execute([$up['utility_payment_id'], $up['bill_id'], $up['amount_paid'], $up['payment_date'], $up['payment_method']]);
                        }
                    }
                } elseif ($table === 'payments') {
                    $p = $dataSnap['payment'];
                    $insP = $pdo->prepare("
                        INSERT INTO rent_payments (payment_id, lease_id, billing_month, amount_paid, payment_date, payment_method)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE amount_paid = VALUES(amount_paid)
                    ");
                    $insP->execute([$p['payment_id'], $p['lease_id'], $p['billing_month'], $p['amount_paid'], $p['payment_date'], $p['payment_method']]);
                } elseif ($table === 'bills') {
                    $b = $dataSnap['bill'];
                    $insB = $pdo->prepare("
                        INSERT INTO utility_bills (bill_id, lease_id, utility_type_id, billing_month, units_consumed, amount_due, due_date, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE amount_due = VALUES(amount_due), status = VALUES(status)
                    ");
                    $insB->execute([$b['bill_id'], $b['lease_id'], $b['utility_type_id'], $b['billing_month'], $b['units_consumed'], $b['amount_due'], $b['due_date'], $b['status']]);
                    if (!empty($dataSnap['utility_payments'])) {
                        $insUP = $pdo->prepare("
                            INSERT INTO utility_payments (utility_payment_id, bill_id, amount_paid, payment_date, payment_method)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE amount_paid = VALUES(amount_paid)
                        ");
                        foreach ($dataSnap['utility_payments'] as $up) {
                            $insUP->execute([$up['utility_payment_id'], $up['bill_id'], $up['amount_paid'], $up['payment_date'], $up['payment_method']]);
                        }
                    }
                }

                $pdo->prepare("DELETE FROM deleted_records WHERE deleted_id = ?")->execute([$archive['deleted_id']]);
                $restoredCount++;
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $pdo->commit();

            jsonResponse(true, "Successfully restored {$restoredCount} record(s).", ['count' => $restoredCount]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            jsonResponse(false, 'Restore all error: ' . $e->getMessage(), null, 500);
        }
    }

    /* ------------------------------------------------------------
       ACTION: purge (Permanently delete from archive)
       ------------------------------------------------------------ */
    if ($reqAction === 'purge') {
        $deletedId = intval($data['deleted_id'] ?? 0);
        if (!$deletedId) {
            jsonResponse(false, 'Deleted Record ID is required.', null, 400);
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM deleted_records WHERE deleted_id = ?");
            $stmt->execute([$deletedId]);
            jsonResponse(true, 'Record permanently deleted from archive.');
        } catch (PDOException $e) {
            jsonResponse(false, 'Purge error: ' . $e->getMessage(), null, 500);
        }
    }

    /* ------------------------------------------------------------
       ACTION: purge_all (Clear all or filtered type from archive)
       ------------------------------------------------------------ */
    if ($reqAction === 'purge_all') {
        $type = trim($data['type'] ?? '');
        try {
            if (!empty($type)) {
                $stmt = $pdo->prepare("DELETE FROM deleted_records WHERE table_name = ?");
                $stmt->execute([$type]);
                jsonResponse(true, "All {$type} records permanently cleared from archive.");
            } else {
                $pdo->exec("DELETE FROM deleted_records");
                jsonResponse(true, "All records permanently cleared from archive.");
            }
        } catch (PDOException $e) {
            jsonResponse(false, 'Purge all error: ' . $e->getMessage(), null, 500);
        }
    }
}

jsonResponse(false, 'Unsupported request method or action.', null, 405);
