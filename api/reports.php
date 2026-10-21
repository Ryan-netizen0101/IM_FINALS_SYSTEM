<?php
/**
 * Reports API
 * CCS110 - Finals Group Project
 * Generates Billing Summary and Tenant Balances using Relational SQL Queries
 */

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

try {
    // 1. Total rent collected
    $stmtRent = $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM rent_payments");
    $rentCollected = floatval($stmtRent->fetchColumn());

    // 2. Total utility bills paid
    $stmtPaidBills = $pdo->query("SELECT COALESCE(SUM(amount_due), 0) FROM utility_bills WHERE status = 'Paid'");
    $billsPaid = floatval($stmtPaidBills->fetchColumn());

    // 3. Total utility bills unpaid
    $stmtUnpaidBills = $pdo->query("SELECT COALESCE(SUM(amount_due), 0) FROM utility_bills WHERE status != 'Paid'");
    $billsUnpaid = floatval($stmtUnpaidBills->fetchColumn());

    // 4. Occupancy rate calculation
    $stmtUnits = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'Occupied' THEN 1 ELSE 0 END) AS occupied
        FROM units
    ");
    $uRow = $stmtUnits->fetch();
    $totalUnits = intval($uRow['total'] ?? 0);
    $occupiedUnits = intval($uRow['occupied'] ?? 0);
    $occupancyRate = ($totalUnits > 0) ? round(($occupiedUnits / $totalUnits) * 100) : 0;

    $summary = [
        ['Rent collected', $rentCollected],
        ['Utility bills paid', $billsPaid],
        ['Utility bills unpaid', $billsUnpaid],
        ['Occupancy rate', $occupancyRate . '%']
    ];

    // 5. Tenant Balances Report (Advanced SQL JOIN with aggregated sums)
    $stmtBalances = $pdo->query("
        SELECT 
            CONCAT(t.first_name, ' ', t.last_name) AS name,
            COALESCE(u.unit_number, 'None') AS unit,
            CAST(COALESCE(u.monthly_rent, 0) AS FLOAT) AS rent,
            CAST(COALESCE(rent_sum.total_rent_paid, 0) AS FLOAT) AS paid,
            CAST(COALESCE(bill_sum.total_bills_owed, 0) AS FLOAT) AS owed
        FROM tenants t
        JOIN leases l ON l.tenant_id = t.tenant_id AND l.status = 'Active'
        JOIN units u ON u.unit_id = l.unit_id
        LEFT JOIN (
            SELECT lease_id, SUM(amount_paid) AS total_rent_paid
            FROM rent_payments
            GROUP BY lease_id
        ) rent_sum ON rent_sum.lease_id = l.lease_id
        LEFT JOIN (
            SELECT lease_id, SUM(amount_due) AS total_bills_owed
            FROM utility_bills
            WHERE status != 'Paid'
            GROUP BY lease_id
        ) bill_sum ON bill_sum.lease_id = l.lease_id
        ORDER BY owed DESC, paid DESC
    ");
    $balances = $stmtBalances->fetchAll();

    jsonResponse(true, 'Reports retrieved successfully', [
        'summary'  => $summary,
        'balances' => $balances
    ]);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
}
