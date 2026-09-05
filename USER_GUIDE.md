# POWERNET ASSOCIATE - BISCO
## Comprehensive User Guide & System Manual

---

## 1. System Overview & Tech Stack
**POWERNET ASSOCIATE - BISCO** is a high-performance, modular Native PHP (PHP 8.x) and MySQL web application designed for Multi-Level Marketing (MLM), subscription affiliate rewards, and automated rank progression.

### Core Tech Stack:
- **Backend:** Native PHP (PHP 8.x) using procedural & OOP architecture.
- **Database:** MySQL with PHP Data Objects (PDO) & prepared statements.
- **Frontend:** HTML5, CSS3, Bootstrap 5, FontAwesome/Bootstrap Icons, Vanilla JavaScript (Fetch API / AJAX).
- **Brand Colors:**
  - Primary Green: `#006837`
  - Secondary Green: `#39B54A`
  - Accent Gold: `#F7941E`
- **Gateways & Extensions:**
  - **Online Payment Gateway:** Razorpay Checkout JS & Webhook Verification API.
  - **Offline/Voucher Gateway:** Electronic PIN (ePIN) Generator & Redemption System.
  - **Internal Payout Gateway:** Earning Wallet -> ePIN Converter Engine.
  - **SMS Gateway:** Fast2SMS / MSG91 API integration.

---

## 2. Quick Installation & Deployment Guide

### cPanel / Shared Hosting Setup:
1. **Upload Files:** Upload all project files to your web root directory (e.g. `public_html/bisco/` or `public_html/`).
2. **Create MySQL Database:**
   - Log into cPanel -> **MySQL Databases**.
   - Create a new database (e.g., `dealmybiz_bisco`).
   - Create a MySQL user and grant **ALL PRIVILEGES**.
3. **Import Database Schema:**
   - Log into cPanel -> **phpMyAdmin**.
   - Select your newly created database and click **Import**.
   - Select `schema.sql` from the project files and run import.
4. **Configure Database Credentials:**
   - Open `config/db.php` in cPanel File Manager and enter your MySQL credentials:
     ```php
     $host     = getenv('DB_HOST') ?: 'localhost';
     $port     = getenv('DB_PORT') ?: '3306';
     $dbname   = getenv('DB_NAME') ?: 'dealmybiz_bisco';
     $username = getenv('DB_USER') ?: 'dealmybiz_user';
     $password = getenv('DB_PASS') ?: 'YourPassword123!';
     ```

---

## 3. Default Admin & Member Credentials

### Pre-Seeded Super Admin Account:
- **URL:** `http://yourdomain.com/login.php`
- **Phone Number:** `9999999999`
- **Password:** `admin123`
- **Role:** Super Admin (Access to Dashboard & Business Schemes Manager)

### Registration of New Members:
- Anyone can sign up via `register.php`.
- Enter **Full Name**, **Phone Number**, **Password**, and an optional **Sponsor User ID** (e.g., `#1` for Admin).

---

## 4. 12-Level Affiliate Commission Engine

When any user activates a subscription package (via Razorpay or ePIN redemption), the system instantly awards a **Self Earn Bonus** and traverses up to 12 sponsor levels, crediting cash directly to user wallets based on point values (1 Point = ₹1).

### Package Point Distributions:

| Sponsor Level | Classic Points (`voucher_600` / `sip_600`) | Basic Points (`voucher_300` / `sip_300`) | Team Points (`smart_recharge`) |
| :--- | :--- | :--- | :--- |
| **Self Bonus (Level 0)** | **300 Pts (₹300)** | **300 Pts (₹300)** | **300 Pts (₹300)** |
| **Level 01** | 500 Pts (₹500) | 250 Pts (₹250) | 25 Pts (₹25) |
| **Level 02** | 200 Pts (₹200) | 100 Pts (₹100) | 10 Pts (₹10) |
| **Level 03** | 100 Pts (₹100) | 50 Pts (₹50) | 8 Pts (₹8) |
| **Level 04** | 50 Pts (₹50) | 25 Pts (₹25) | 6 Pts (₹6) |
| **Level 05** | 20 Pts (₹20) | 10 Pts (₹10) | 4 Pts (₹4) |
| **Level 06** | 20 Pts (₹20) | 10 Pts (₹10) | 2 Pts (₹2) |
| **Level 07** | 20 Pts (₹20) | 10 Pts (₹10) | 1 Pt (₹1) |
| **Level 08** | 10 Pts (₹10) | 10 Pts (₹10) | 1 Pt (₹1) |
| **Level 09** | 10 Pts (₹10) | 10 Pts (₹10) | 1 Pt (₹1) |
| **Level 10** | 10 Pts (₹10) | 10 Pts (₹10) | 1 Pt (₹1) |
| **Level 11** | 10 Pts (₹10) | 10 Pts (₹10) | 1 Pt (₹1) |
| **Level 12** | 10 Pts (₹10) | 5 Pts (₹5) | 1 Pt (₹1) |

---

## 5. 12-Tier Rank Progression & Incentive Calculator

The rank progression engine evaluates user network downline structures and triggers rank upgrades, one-time cash bonuses, and recurring monthly payouts.

### Rank Qualification & Reward Table:

| Rank Tier | Rank Name | Team Qualification Requirement | One-Time Rank Bonus | Monthly Incentive | Duration |
| :---: | :--- | :--- | :--- | :--- | :---: |
| **1** | **Promoter** | 5 Direct Active Sales | ₹500 | - | - |
| **2** | **Senior Promoter** | 5 Promoters in team network | ₹1,000 | ₹500 / month | 10 Months |
| **3** | **Team Leader** | 5 Senior Promoters in team | ₹5,000 | ₹1,000 / month | 10 Months |
| **4** | **Team Manager** | 5 Team Leaders in team | ₹10,000 | ₹2,000 / month | 10 Months |
| **5** | **Area Manager** | 5 Team Managers in team | ₹25,000 | ₹4,000 / month | 10 Months |
| **6** | **Zonal Manager** | 5 Area Managers in team | ₹50,000 | ₹8,000 / month | 10 Months |
| **7** | **Regional Manager** | 5 Zonal Managers in team | ₹1,00,000 | ₹10,000 / month | 10 Months |
| **8** | **State Head** | 5 Regional Managers in team | ₹5,00,000 | ₹25,000 / month | 10 Months |
| **9** | **National Head** | 5 State Heads in team | ₹10,00,000 | ₹50,000 / month | 10 Months |
| **10** | **Global Head** | 5 National Heads in team | ₹25,00,000 | ₹1,00,000 / month | 10 Months |
| **11** | **Ambassador** | 5 Global Heads in team | ₹50,00,000 | ₹5,00,000 / month | 10 Months |
| **12** | **Crown Ambassador** | 5 Ambassadors in team | ₹1,00,00,000 | ₹10,00,000 / month | 10 Months |

### Automating Rank Calculations (Cron Job):
To run rank updates and monthly payouts automatically every night on your cPanel server, set up a cron job:
```bash
0 0 * * * /usr/bin/php /home/username/public_html/bisco/rank_calculator.php >/dev/null 2>&1
```

---

## 6. Earning Wallet to ePIN Generator Engine

Active members can use their earned commission wallet balance to generate 16-character Electronic PINs (ePINs) to register new members or activate downline packages.

### How It Works:
1. Member selects a package (e.g. `voucher_300` - ₹300).
2. System checks `wallet_balance` inside a transaction (`wallet_balance >= value_amount`).
3. Wallet balance is deducted, and a unique 16-character PIN code (e.g. `PN3A8F2K910L4M5N`) is generated.
4. An SMS confirmation is dispatched to the user's phone.
5. Any member can enter the 16-character ePIN on the dashboard under **Buy Package** to instantly activate a subscription.

---

## 7. Business Schemes Manager (`admin/schemes.php`)

Super Admins can edit commission point allocations, rank requirements, cash bonuses, and create new rank scheme tiers dynamically.

### Features in Schemes Manager:
1. **Edit 12-Level Commission Points:** Modify Classic, Basic, or Team points for any of the 12 sponsor levels.
2. **Edit Rank Reward Requirements:** Update required downline counts, one-time cash bonuses, monthly payout amounts, or duration months.
3. **Create New Rank Scheme Tiers:** Click **Create New Business Rank Scheme** to add higher rank tiers beyond Rank 12.

---

## 8. Summary of API Endpoints & Files

- `schema.sql`: Full MySQL database schema with master tables and seeds.
- `init_db.php`: Database initializer script for SQLite/MySQL environments.
- `config/db.php`: Central PDO database connection helper.
- `calculate_commissions.php`: 12-level commission calculation engine.
- `rank_calculator.php`: Rank progression & monthly payout processing engine.
- `wallet_to_epin.php`: Earning wallet to ePIN generator script.
- `redeem_epin.php`: ePIN redemption script.
- `razorpay_callback.php`: Razorpay payment verification & webhook handler.
- `dashboard.php`: Interactive user dashboard.
- `api/tree.php`: JSON API returning the 12-level downline tree structure.
- `admin/schemes.php`: Business schemes and compensation manager.
- `tests/test_system.php`: End-to-end integration test suite.
