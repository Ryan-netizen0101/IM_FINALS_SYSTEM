CREATE DATABASE tenant_billing_system;
USE tenant_billing_system;

-- =====================================================================
-- PART 1: TABLE CREATION (Primary Keys & Foreign Keys)
-- =====================================================================
 
CREATE TABLE users (
    user_id          INT AUTO_INCREMENT PRIMARY KEY,
    full_name        VARCHAR(100) NOT NULL,
    email            VARCHAR(100) NOT NULL UNIQUE,
    password         VARCHAR(255) NOT NULL,
    role             VARCHAR(20) NOT NULL DEFAULT 'staff', -- admin, staff
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tenants (
    tenant_id          INT AUTO_INCREMENT PRIMARY KEY,
    first_name         VARCHAR(50),
    last_name          VARCHAR(50),
    contact_no         VARCHAR(15),
    email              VARCHAR(100),
    emergency_contact  VARCHAR(100),
    date_registered    DATE
);
 
CREATE TABLE units (
    unit_id       INT AUTO_INCREMENT PRIMARY KEY,
    unit_number   VARCHAR(10),
    unit_type     VARCHAR(30),
    floor_no      INT,
    capacity      INT,
    monthly_rent  DECIMAL(10,2),
    status        VARCHAR(20)      -- Vacant, Occupied, Maintenance
);
 
CREATE TABLE utility_types (
    utility_type_id INT AUTO_INCREMENT PRIMARY KEY,
    utility_name    VARCHAR(30),
    measure_unit    VARCHAR(20),
    rate_per_unit   DECIMAL(10,2)
);
 
CREATE TABLE leases (
    lease_id        INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT,
    unit_id         INT,
    start_date      DATE,
    end_date        DATE,
    deposit_amount  DECIMAL(10,2),
    status          VARCHAR(20),   -- Active, Ended, Terminated
    FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id),
    FOREIGN KEY (unit_id) REFERENCES units(unit_id)
);
 
CREATE TABLE rent_payments (
    payment_id      INT AUTO_INCREMENT PRIMARY KEY,
    lease_id        INT,
    billing_month   DATE,          -- first day of the month covered
    amount_paid     DECIMAL(10,2),
    payment_date    DATE,
    payment_method  VARCHAR(20),   -- Cash, GCash, Bank Transfer
    FOREIGN KEY (lease_id) REFERENCES leases(lease_id)
);
 
CREATE TABLE utility_bills (
    bill_id          INT AUTO_INCREMENT PRIMARY KEY,
    lease_id         INT,
    utility_type_id  INT,
    billing_month    DATE,
    units_consumed   DECIMAL(10,2),
    amount_due       DECIMAL(10,2),
    due_date         DATE,
    status           VARCHAR(20),  -- Unpaid, Partial, Paid, Overdue
    FOREIGN KEY (lease_id) REFERENCES leases(lease_id),
    FOREIGN KEY (utility_type_id) REFERENCES utility_types(utility_type_id)
);
 
CREATE TABLE utility_payments (
    utility_payment_id INT AUTO_INCREMENT PRIMARY KEY,
    bill_id            INT,
    amount_paid        DECIMAL(10,2),
    payment_date       DATE,
    payment_method     VARCHAR(20),
    FOREIGN KEY (bill_id) REFERENCES utility_bills(bill_id)
);

CREATE TABLE deleted_records (
    deleted_id    INT AUTO_INCREMENT PRIMARY KEY,
    table_name    VARCHAR(50)  NOT NULL,
    record_id     INT          NOT NULL,
    record_label  VARCHAR(255) NOT NULL,
    record_data   LONGTEXT     NOT NULL,
    deleted_by    VARCHAR(100) NULL,
    deleted_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_table_name (table_name),
    INDEX idx_deleted_at (deleted_at)
);
 
-- =====================================================================
-- PART 2: SAMPLE DATA
-- =====================================================================

INSERT INTO users (full_name, email, password, role) VALUES
('Property Administrator', 'admin@property.com', '$2y$10$yrxWqgUMU2Rg..Wy3ga9.u7p2ScaPwnGZvKuG4G7J92kEMyeQvV0i', 'admin'),
('Front Desk Staff', 'staff@property.com', '$2y$10$PlkxSGjCJ08gm7JcYmpAu.pQd8xC9y1l.Qd3OI1rPx7zIBueCVcAe', 'staff');

INSERT INTO tenants (first_name, last_name, contact_no, email, emergency_contact, date_registered) VALUES
('Juan',   'Dela Cruz', '09171234567', 'juan.delacruz@email.com', 'Maria Dela Cruz - 09181112222', '2025-12-20'),
('Maria',  'Santos',    '09282345678', 'maria.santos@email.com',  'Pedro Santos - 09193334444',    '2026-01-25'),
('Carlo',  'Reyes',     '09353456789', 'carlo.reyes@email.com',   'Ana Reyes - 09205556666',       '2026-02-20'),
('Angela', 'Garcia',    '09174567890', 'angela.garcia@email.com', 'Luis Garcia - 09216667777',     '2026-01-10'),
('Mark',   'Villanueva','09485678901', 'mark.villanueva@email.com','Rosa Villanueva - 09227778888','2026-04-22'),
('Liza',   'Mendoza',   '09196789012', 'liza.mendoza@email.com',  'Ramon Mendoza - 09238889999',   '2025-05-18');
 
INSERT INTO units (unit_number, unit_type, floor_no, capacity, monthly_rent, status) VALUES
('U101', 'Studio',        1, 2, 4500.00, 'Occupied'),
('U102', 'Single Room',   1, 1, 3500.00, 'Occupied'),
('U201', 'Studio',        2, 2, 5000.00, 'Occupied'),
('U202', 'Family 2BR',    2, 4, 7500.00, 'Occupied'),
('U203', 'Single Room',   2, 1, 3500.00, 'Occupied'),
('U301', 'Studio',        3, 2, 5200.00, 'Vacant');
 
INSERT INTO utility_types (utility_name, measure_unit, rate_per_unit) VALUES
('Electricity', 'kWh',      14.00),
('Water',       'cubic m',  30.00),
('Internet',    'month',   300.00);
 
INSERT INTO leases (tenant_id, unit_id, start_date, end_date, deposit_amount, status) VALUES
(1, 1, '2026-01-01', '2026-12-31',  9000.00, 'Active'),
(2, 2, '2026-02-01', '2027-01-31',  7000.00, 'Active'),
(3, 3, '2026-03-01', '2027-02-28', 10000.00, 'Active'),
(4, 4, '2026-01-15', '2027-01-14', 15000.00, 'Active'),
(5, 5, '2026-05-01', '2027-04-30',  7000.00, 'Active'),
(6, 6, '2025-06-01', '2026-05-31', 10400.00, 'Ended');
 
INSERT INTO rent_payments (lease_id, billing_month, amount_paid, payment_date, payment_method) VALUES
(1, '2026-08-01', 4500.00, '2026-08-03', 'Cash'),
(1, '2026-09-01', 4500.00, '2026-09-02', 'GCash'),
(2, '2026-08-01', 3500.00, '2026-08-05', 'Cash'),
(2, '2026-09-01', 2000.00, '2026-09-06', 'Cash'),          -- partial payment
(3, '2026-08-01', 5000.00, '2026-08-01', 'Bank Transfer'),
(3, '2026-09-01', 5000.00, '2026-09-01', 'Bank Transfer'),
(4, '2026-08-01', 7500.00, '2026-08-16', 'GCash'),          -- late payment
(4, '2026-09-01', 7500.00, '2026-09-10', 'Cash'),
(5, '2026-08-01', 3500.00, '2026-08-04', 'Cash');           -- Sept unpaid
 
INSERT INTO utility_bills (lease_id, utility_type_id, billing_month, units_consumed, amount_due, due_date, status) VALUES
(1, 1, '2026-08-01',  45.00,  630.00, '2026-09-10', 'Paid'),
(1, 2, '2026-08-01',   6.00,  180.00, '2026-09-10', 'Paid'),
(2, 1, '2026-08-01',  30.00,  420.00, '2026-09-10', 'Paid'),
(2, 2, '2026-08-01',   4.00,  120.00, '2026-09-10', 'Paid'),
(3, 1, '2026-09-01',  80.00, 1120.00, '2026-10-10', 'Overdue'),
(3, 3, '2026-09-01',   1.00,  300.00, '2026-10-10', 'Paid'),
(4, 1, '2026-09-01', 120.00, 1680.00, '2026-10-10', 'Overdue'),
(4, 2, '2026-09-01',  12.00,  360.00, '2026-10-10', 'Partial'),
(5, 1, '2026-09-01',  35.00,  490.00, '2026-10-15', 'Unpaid'),
(5, 2, '2026-09-01',   5.00,  150.00, '2026-10-15', 'Unpaid');
 
INSERT INTO utility_payments (bill_id, amount_paid, payment_date, payment_method) VALUES
(1,  630.00, '2026-09-05', 'Cash'),
(2,  180.00, '2026-09-05', 'Cash'),
(3,  420.00, '2026-09-08', 'Cash'),
(4,  120.00, '2026-09-08', 'Cash'),
(6,  300.00, '2026-10-02', 'GCash'),
(8,  200.00, '2026-10-05', 'Cash');
 
-- =====================================================================
-- PART 3: REQUIRED SQL QUERIES (simple)
-- =====================================================================
 
-- Q1. View all tenants
SELECT * FROM tenants;
 
-- Q2. Vacant units
SELECT unit_number, unit_type, monthly_rent
FROM units
WHERE status = 'Vacant';
 
-- Q3. Occupied units with rent from 3,500 to 5,000 (AND, BETWEEN)
SELECT unit_number, unit_type, monthly_rent
FROM units
WHERE status = 'Occupied'
  AND monthly_rent BETWEEN 3500 AND 5000;
 
-- Q4. Utility bills that are not paid (NOT)
SELECT bill_id, amount_due, due_date, status
FROM utility_bills
WHERE NOT status = 'Paid';
 
-- Q5. Unpaid or overdue bills (IN)
SELECT bill_id, amount_due, due_date, status
FROM utility_bills
WHERE status IN ('Unpaid', 'Overdue');
 
-- Q6. Tenants with their lease dates (INNER JOIN)
SELECT CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
       l.start_date, l.end_date, l.status
FROM tenants t
INNER JOIN leases l ON l.tenant_id = t.tenant_id;
 
-- Q7. Rent payments with the tenant who paid (INNER JOIN)
SELECT t.first_name, t.last_name, rp.amount_paid, rp.payment_date
FROM rent_payments rp
INNER JOIN leases  l ON l.lease_id  = rp.lease_id
INNER JOIN tenants t ON t.tenant_id = l.tenant_id;
 
-- Q8. All utility bills with their payments, if any (LEFT OUTER JOIN)
SELECT ub.bill_id, ub.amount_due, ub.status, up.amount_paid
FROM utility_bills ub
LEFT OUTER JOIN utility_payments up ON up.bill_id = ub.bill_id;
 
-- Q9. All units with their active lease, if any (RIGHT OUTER JOIN)
SELECT u.unit_number, u.status, l.lease_id, l.start_date
FROM leases l
RIGHT OUTER JOIN units u ON u.unit_id = l.unit_id AND l.status = 'Active';
 
-- =====================================================================
-- ADVANCED QUERY
-- Active tenants with their units, rent from 3,500 to 7,500,
-- excluding studio units
-- =====================================================================
SELECT t.first_name, t.last_name, u.unit_number, u.unit_type, u.monthly_rent
FROM tenants t
INNER JOIN leases l ON l.tenant_id = t.tenant_id
INNER JOIN units  u ON u.unit_id   = l.unit_id
WHERE l.status = 'Active'
  AND u.monthly_rent BETWEEN 3500 AND 7500
  AND NOT u.unit_type = 'Studio';
