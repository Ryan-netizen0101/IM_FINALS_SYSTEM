<?php
/**
 * Dashboard Summary API
 * CCS110 - Finals Group Project
 */

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

try {
    // 1. Active tenants count
    $stmtTenants = $pdo->query("
        SELECT COUNT(DISTINCT t.tenant_id) 
        FROM tenants t
        JOIN leases l ON l.tenant_id = t.tenant_id
        WHERE l.status = 'Active'
    ");
    $activeTenants = intval($stmtTenants->fetchColumn());

    // 2. Units counts
    $stmtUnits = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'Occupied' THEN 1 ELSE 0 END) AS occupied
        FROM units
    ");
    $unitsRow = $stmtUnits->fetch();
    $totalUnits = intval($unitsRow['total'] ?? 0);
    $occupiedUnits = intval($unitsRow['occupied'] ?? 0);

    // 3. Rent collected
    $stmtRent = $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM rent_payments");
    $rentCollected = floatval($stmtRent->fetchColumn());

    // 4. Unpaid utility bills
    $stmtUnpaid = $pdo->query("SELECT COALESCE(SUM(amount_due), 0) FROM utility_bills WHERE status != 'Paid'");
    $unpaidBillsTotal = floatval($stmtUnpaid->fetchColumn());

    // 5. Recent rent payments (5 latest)
    $stmtRecent = $pdo->query("
        SELECT 
            rp.payment_id,
            CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
            rp.payment_date AS date,
            CAST(rp.amount_paid AS FLOAT) AS amount
        FROM rent_payments rp
        JOIN leases l ON l.lease_id = rp.lease_id
        JOIN tenants t ON t.tenant_id = l.tenant_id
        ORDER BY rp.payment_date DESC, rp.payment_id DESC
        LIMIT 5
    ");
    $recentPayments = $stmtRecent->fetchAll();

    // 6. Unpaid utility bills list
    $stmtUnpaidList = $pdo->query("
        SELECT 
            ub.bill_id,
            CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
            ut.utility_name AS type,
            ub.due_date AS due,
            CAST(ub.amount_due AS FLOAT) AS amount
        FROM utility_bills ub
        JOIN leases l ON l.lease_id = ub.lease_id
        JOIN tenants t ON t.tenant_id = l.tenant_id
        JOIN utility_types ut ON ut.utility_type_id = ub.utility_type_id
        WHERE ub.status != 'Paid'
        ORDER BY ub.due_date ASC
        LIMIT 10
    ");
    $unpaidBills = $stmtUnpaidList->fetchAll();

    jsonResponse(true, 'Dashboard stats retrieved', [
        'active_tenants'    => $activeTenants,
        'occupied_units'    => $occupiedUnits,
        'total_units'       => $totalUnits,
        'rent_collected'    => $rentCollected,
        'unpaid_bills_total'=> $unpaidBillsTotal,
        'recent_payments'   => $recentPayments,
        'unpaid_bills'      => $unpaidBills
    ]);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
}
