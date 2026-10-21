<?php
/**
 * SQL Query Demonstration Runner API
 * CCS110 - Information Management 1 Finals Requirement
 * Enables executing and demonstrating project SQL queries with live database results
 */

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$predefinedQueries = [
    'q1' => [
        'id'          => 'q1',
        'title'       => 'Q1. View all tenants (Basic SELECT)',
        'description' => 'Retrieves all tenant records registered in the system.',
        'sql'         => 'SELECT tenant_id, first_name, last_name, contact_no, email, date_registered FROM tenants ORDER BY tenant_id ASC;'
    ],
    'q2' => [
        'id'          => 'q2',
        'title'       => 'Q2. Vacant units (WHERE clause)',
        'description' => 'Filters units table to display only currently vacant units.',
        'sql'         => "SELECT unit_number, unit_type, floor_no, capacity, monthly_rent FROM units WHERE status = 'Vacant';"
    ],
    'q3' => [
        'id'          => 'q3',
        'title'       => 'Q3. Occupied units with rent 3,500 to 5,000 (WHERE, AND, BETWEEN)',
        'description' => 'Retrieves occupied units whose monthly rent falls within PHP 3,500.00 and PHP 5,000.00.',
        'sql'         => "SELECT unit_number, unit_type, monthly_rent, status FROM units WHERE status = 'Occupied' AND monthly_rent BETWEEN 3500 AND 5000;"
    ],
    'q4' => [
        'id'          => 'q4',
        'title'       => 'Q4. Utility bills that are not paid (WHERE NOT)',
        'description' => 'Demonstrates the NOT operator by listing utility bills that are unpaid, partial, or overdue.',
        'sql'         => "SELECT bill_id, lease_id, utility_type_id, amount_due, due_date, status FROM utility_bills WHERE NOT status = 'Paid';"
    ],
    'q5' => [
        'id'          => 'q5',
        'title'       => 'Q5. Unpaid or overdue bills (WHERE IN)',
        'description' => 'Demonstrates the IN operator to filter bills that are specifically Unpaid or Overdue.',
        'sql'         => "SELECT bill_id, lease_id, utility_type_id, amount_due, due_date, status FROM utility_bills WHERE status IN ('Unpaid', 'Overdue');"
    ],
    'q6' => [
        'id'          => 'q6',
        'title'       => 'Q6. Tenants with lease dates (INNER JOIN)',
        'description' => 'Joins tenants table with leases table to display active lease periods.',
        'sql'         => "SELECT CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.contact_no, l.start_date, l.end_date, l.status FROM tenants t INNER JOIN leases l ON l.tenant_id = t.tenant_id;"
    ],
    'q7' => [
        'id'          => 'q7',
        'title'       => 'Q7. Rent payments with tenant details (Multi-table INNER JOIN)',
        'description' => 'Demonstrates 3-table INNER JOIN connecting rent_payments, leases, and tenants.',
        'sql'         => "SELECT t.first_name, t.last_name, rp.payment_id, rp.billing_month, rp.amount_paid, rp.payment_date, rp.payment_method FROM rent_payments rp INNER JOIN leases l ON l.lease_id = rp.lease_id INNER JOIN tenants t ON t.tenant_id = l.tenant_id ORDER BY rp.payment_date DESC;"
    ],
    'q8' => [
        'id'          => 'q8',
        'title'       => 'Q8. Utility bills with payments (LEFT OUTER JOIN)',
        'description' => 'Displays all utility bills along with any matching payment records, preserving unpaid bills.',
        'sql'         => "SELECT ub.bill_id, ub.billing_month, ub.amount_due, ub.status, up.amount_paid, up.payment_date FROM utility_bills ub LEFT OUTER JOIN utility_payments up ON up.bill_id = ub.bill_id;"
    ],
    'q9' => [
        'id'          => 'q9',
        'title'       => 'Q9. All units with active lease (RIGHT OUTER JOIN)',
        'description' => 'Displays all rental units and joins any active lease information.',
        'sql'         => "SELECT u.unit_number, u.unit_type, u.monthly_rent, u.status, l.lease_id, l.start_date FROM leases l RIGHT OUTER JOIN units u ON u.unit_id = l.unit_id AND l.status = 'Active';"
    ],
    'advanced' => [
        'id'          => 'advanced',
        'title'       => 'ADVANCED QUERY: Active Tenants with Unit & Rent (INNER JOIN, WHERE, BETWEEN, NOT, ORDER BY)',
        'description' => 'Complex relational query combining multiple JOINs, range filtering, and exclusion filters.',
        'sql'         => "SELECT CONCAT(t.first_name, ' ', t.last_name) AS tenant_name, t.contact_no, u.unit_number, u.unit_type, u.monthly_rent, l.start_date FROM tenants t INNER JOIN leases l ON l.tenant_id = t.tenant_id INNER JOIN units u ON u.unit_id = l.unit_id WHERE l.status = 'Active' AND u.monthly_rent BETWEEN 3500 AND 7500 AND NOT u.unit_type = 'Studio' ORDER BY u.monthly_rent DESC;"
    ]
];

// List available queries
if ($method === 'GET' && ($action === 'list' || empty($action))) {
    $tables = ['tenants', 'units', 'leases', 'rent_payments', 'utility_bills', 'utility_types', 'utility_payments', 'users'];
    jsonResponse(true, 'Query list retrieved', [
        'queries' => array_values($predefinedQueries),
        'tables'  => $tables
    ]);
}

// Execute query
if ($method === 'POST' || ($method === 'GET' && $action === 'run')) {
    $data = getRequestData();
    $queryKey = $data['key'] ?? ($_GET['key'] ?? '');
    $customSql = trim($data['sql'] ?? ($_GET['sql'] ?? ''));

    $sqlToRun = '';
    $queryInfo = null;

    if (isset($predefinedQueries[$queryKey])) {
        $queryInfo = $predefinedQueries[$queryKey];
        $sqlToRun = $queryInfo['sql'];
    } elseif (!empty($customSql)) {
        // Only allow SELECT queries for security
        if (!preg_match('/^\s*SELECT/i', $customSql)) {
            jsonResponse(false, 'Only SELECT statements are permitted in the interactive demo runner.', null, 400);
        }
        $sqlToRun = $customSql;
        $queryInfo = [
            'id'          => 'custom',
            'title'       => 'Custom Query',
            'description' => 'User-defined SQL statement',
            'sql'         => $customSql
        ];
    } else {
        jsonResponse(false, 'Invalid query selected.', null, 400);
    }

    try {
        $start = microtime(true);
        $stmt = $pdo->query($sqlToRun);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $duration = round((microtime(true) - $start) * 1000, 2);

        $columns = [];
        if (!empty($rows)) {
            $columns = array_keys($rows[0]);
        }

        jsonResponse(true, 'Query executed successfully', [
            'info'          => $queryInfo,
            'sql'           => $sqlToRun,
            'columns'       => $columns,
            'rows'          => $rows,
            'row_count'     => count($rows),
            'execution_ms'  => $duration
        ]);
    } catch (PDOException $e) {
        jsonResponse(false, 'SQL Execution Error: ' . $e->getMessage(), null, 400);
    }
}

// Table view helper (show raw table content)
if ($method === 'GET' && $action === 'table') {
    $tableName = $_GET['name'] ?? 'tenants';
    $allowed = ['tenants', 'units', 'leases', 'rent_payments', 'utility_bills', 'utility_types', 'utility_payments', 'users'];
    if (!in_array($tableName, $allowed)) {
        jsonResponse(false, 'Invalid table name', null, 400);
    }

    try {
        $stmt = $pdo->query("SELECT * FROM `{$tableName}` LIMIT 50");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = !empty($rows) ? array_keys($rows[0]) : [];

        // Hide password hashes from users table for security
        if ($tableName === 'users') {
            foreach ($rows as &$r) {
                if (isset($r['password'])) {
                    $r['password'] = '●●●●●●●● [Encrypted]';
                }
            }
        }

        jsonResponse(true, "Table {$tableName} retrieved", [
            'table'     => $tableName,
            'columns'   => $columns,
            'rows'      => $rows,
            'row_count' => count($rows)
        ]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Error fetching table: ' . $e->getMessage(), null, 500);
    }
}

jsonResponse(false, 'Invalid request', null, 400);
