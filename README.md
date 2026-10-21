# Hallway Billing - Apartment & Boarding House Tenant & Billing System
**CCS110 – Information Management 1 | Finals Group Project**  
*Pamantasan ng Cabuyao (University of Cabuyao) — College of Computing Studies*

---

## 🌟 System Overview
Hallway Billing is a database-driven web application designed to automate tenant management, unit allocation, rent collection, and utility billing for boarding houses and apartment complexes. The system replaces error-prone manual spreadsheets with an ACID-compliant relational MySQL database and a responsive, user-friendly interface.

---

## 🛠️ Technology Stack
- **Database:** MySQL 8.x (MariaDB via XAMPP)
- **Backend:** PHP 8.x (PDO with prepared statements for SQL injection prevention, session-based authentication, and transaction handling)
- **Frontend:** Responsive HTML5, CSS3, and JavaScript (Vanilla SPA with Fetch API)
- **Server:** Apache HTTP Server (XAMPP)

---

## 📊 Database Design & Compliance Check
This implementation fully satisfies all requirements of the CCS110 Finals Project Specifications:

| Requirement | Implementation in Hallway Billing | Status |
|---|---|---|
| **At least 6 related tables** | **8 tables:** `users`, `tenants`, `units`, `leases`, `rent_payments`, `utility_bills`, `utility_types`, `utility_payments` | ✅ Exceeds requirement |
| **At least 3 main entity tables** | `tenants`, `units`, `utility_types` | ✅ Verified |
| **At least 1 transaction/log table** | `rent_payments`, `utility_bills`, `utility_payments` | ✅ Verified |
| **At least 1 junction/bridge table** | `leases` (resolves M:N relationship between `tenants` and `units`) | ✅ Verified |
| **Primary Keys & Foreign Keys** | Every table has an `INT AUTO_INCREMENT PRIMARY KEY`; 6 FK constraints implemented | ✅ Verified |
| **Minimum 5 sample records per main table** | 6 tenants, 6 units, 3 utility types, 6 leases, 9 rent payments, 10 bills pre-seeded | ✅ Verified |
| **Input Validation & Consistency** | Regex checks, positive number enforcement, date validation, and FK deletion locks | ✅ Verified |
| **SQL Demonstration Queries** | 9 standard queries + 1 Advanced Relational Query with JOIN, WHERE, BETWEEN, NOT, ORDER BY | ✅ Verified |

---

## 🚀 How to Run the Project (Quick Start)

### Step 1: Start XAMPP
Open the **XAMPP Control Panel** and click **Start** for:
- **Apache**
- **MySQL**

### Step 2: One-Click Database Setup & Migration
Open your web browser and navigate to:
```
http://localhost/IM_FINALS/setup_db.php
```
This utility automatically:
- Creates the `tenant_billing_system` database.
- Executes `tenant_billing_system.sql`.
- Configures all 8 tables and foreign key constraints.
- Seeds all default records and admin/staff accounts.

### Step 3: Launch the Application
Navigate to:
```
http://localhost/IM_FINALS/
```
*(or `http://localhost/IM_FINALS/login.html`)*

---

## 🔐 Default Demo Accounts

| Role | Email Address | Password | Privileges |
|---|---|---|---|
| **Property Administrator** | `admin@property.com` | `admin123` | Full administrative access to all modules, rates, and reports |
| **Front Desk Staff** | `staff@property.com` | `staff123` | Operational access to tenants, payments, and billing |

> **Tip:** On the login page, you can click the quick **"Fill Admin"** or **"Fill Staff"** buttons to instantly populate the credentials.

---

## 📁 Project File & Folder Structure
```
IM_FINALS/
├── api/
│   ├── auth.php                # Authentication (login, logout, session check, registration)
│   ├── bills.php               # Utility bills CRUD & payment logging
│   ├── dashboard.php           # Real-time KPI stats & recent transactions
│   ├── payments.php            # Rent payments CRUD with lease linking
│   ├── reports.php             # Billing summary & tenant balance calculations
│   ├── sql_runner.php          # Live SQL query demonstration & table inspector
│   ├── tenants.php             # Tenants CRUD with automatic lease & unit status updates
│   └── units.php               # Units CRUD with rate & occupancy validation
├── config/
│   └── db.php                  # PDO database connection, error handling & helper functions
├── dashboard.css               # Styling for dashboard, tables, modals, and badges
├── dashboard.html              # Main application single-page interface
├── index.php                   # Smart entry point (redirects based on active session)
├── login.html                  # Role-based sign-in interface
├── setup_db.php                # Automated 1-click database setup & migration runner
├── style.css                   # Login page styling
├── tenant_billing_system.sql   # Complete database schema, constraints, queries & sample data
└── README.md                   # System documentation & defense presentation guide
```

---

## 🎯 Features & CRUD Demonstration Guide

### 1. Tenants Module (`api/tenants.php`)
- **Add:** Enter full name, 11-digit mobile (starting with `09`), select unit, move-in date, and status. Automatically creates lease and marks the unit as `Occupied`.
- **View:** Display list with active unit, contact number, and move-in date. Real-time search by name or unit number; filter by status.
- **Edit:** Update tenant personal information and reassign units.
- **Delete:** Protected by database integrity. Tenants with active payment or billing records cannot be deleted until dependencies are cleared.

### 2. Units Module (`api/units.php`)
- **Add:** Unit number, unit type (Studio, Single Room, 1-Bedroom, 2-Bedroom, Bedspace), monthly rent, and availability status.
- **View:** Real-time listing with currency formatting and occupancy status.
- **Edit:** Change rent, room type, or status.
- **Delete:** Safely blocked if any current or past lease references the unit.

### 3. Rent Payments Module (`api/payments.php`)
- **Add:** Select tenant, billing month (`YYYY-MM`), positive amount, payment date, and method (`Cash`, `GCash`, `Bank Transfer`).
- **View:** Shows tenant name, unit number, month covered, date, and method. Real-time search and method filter.
- **Edit / Delete:** Modifies or deletes payment records; changes immediately update dashboard totals.

### 4. Utility Bills Module (`api/bills.php`)
- **Add:** Select tenant, utility type (Water, Electricity, Internet), billing month, amount, due date, and payment status (`Unpaid`, `Paid`, `Partial`, `Overdue`).
- **View:** Formatted listing with status badges. Search and filter by status.
- **Edit / Delete:** Update amount or status. Marking a bill as `Paid` automatically synchronizes with the `utility_payments` table.

### 5. Reports & Analytics (`api/reports.php`)
- **Billing Summary:** Aggregated total rent collected, utility bills paid, utility bills unpaid, and occupancy percentage.
- **Tenant Balance Sheet:** Shows each active tenant's unit, monthly rent, total rent paid to date, and outstanding utility balance (highlighted in red if overdue).

### 6. Live SQL Demonstration (`api/sql_runner.php`)
Click on the **"SQL Demo"** tab in the sidebar:
- Select from **Q1 to Q9** and the **Advanced Query**.
- Click **"Run Query"** to execute the query against MySQL live on screen. Shows the SQL code, execution time in milliseconds, row count, and the formatted output table.
- Use **"Inspect Table"** to preview the raw database table before and after an operation to prove live end-to-end database updates during oral defense.

---

## 🎤 Oral Defense & Video Recording Checklist

1. **Introduction:** State project title (*Hallway Billing*), group number, section, and member roles.
2. **Problem & Solution:** Explain why managing tenant rent and utilities manually on paper or Excel causes missed bills, and how this relational system solves it.
3. **Login Walkthrough:** Sign in with `staff@property.com` and `admin@property.com`.
4. **End-to-End Workflow:**
   - Add a new tenant and assign them a vacant unit.
   - Show unit status automatically flipping to `Occupied`.
   - Record a rent payment for the new tenant.
   - Record an electricity or water bill.
   - Show how the Dashboard statistics and Tenant Balances update immediately.
   - Switch to the **SQL Demo** tab and inspect the `tenants` and `leases` tables to prove database persistence.
5. **SQL Demonstration:**
   - Run **Q3** (Occupied units with rent between 3,500 and 5,000 using `BETWEEN`).
   - Run **Q7** (Rent payments with tenant details using multi-table `INNER JOIN`).
   - Run the **Advanced Query** (`INNER JOIN` + `WHERE` + `BETWEEN` + `NOT` + `ORDER BY`).
