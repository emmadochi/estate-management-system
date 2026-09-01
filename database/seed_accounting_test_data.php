<?php
/**
 * Seeder for Realistic Estate Accounting & Financial Test Data
 * Run via CLI: php database/seed_accounting_test_data.php
 */

require_once __DIR__ . '/db_connection.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    echo "========================================\n";
    echo "Seeding EstatePro Financial Test Data...\n";
    echo "========================================\n";

    // 1. Ensure at least one Estate exists
    $estate = $db->fetchOne('SELECT id, name FROM estates LIMIT 1');
    if (!$estate) {
        $db->execute("INSERT INTO estates (name, code, address, city, state, country, status) 
                      VALUES (' Lekki Palm Heights Estate', 'LPH-001', 'Plot 12 Admiralty Way, Lekki Phase 1', 'Lekki', 'Lagos', 'Nigeria', 'active')");
        $estateId = (int)$conn->lastInsertId();
        echo "✓ Created Demo Estate: Lekki Palm Heights Estate (ID: {$estateId})\n";
    } else {
        $estateId = (int)$estate['id'];
        echo "✓ Using existing Estate: {$estate['name']} (ID: {$estateId})\n";
    }

    // 2. Ensure an Admin / Staff user exists to record & approve
    $user = $db->fetchOne("SELECT id FROM users WHERE role IN ('super_admin', 'estate_admin', 'accountant') LIMIT 1");
    if (!$user) {
        $hash = password_hash('password', PASSWORD_BCRYPT);
        $db->execute("INSERT INTO users (email, password, first_name, last_name, phone, role, status)
                      VALUES ('accountant@estatepro.com', ?, 'Fola', 'Accountant', '08012345678', 'accountant', 'active')", [$hash]);
        $userId = (int)$conn->lastInsertId();
        echo "✓ Created Demo Accountant user: accountant@estatepro.com (Password: password)\n";
    } else {
        $userId = (int)$user['id'];
    }

    // 3. Ensure a few demo vendors exist
    $vendorsList = [
        ['name' => 'Alhaji Musa & Sons Fuel Supply', 'company' => 'Musa Energy Ltd', 'phone' => '08033221144', 'specialization' => 'other'],
        ['name' => 'Oak & Shield Security Patrol', 'company' => 'Oak Security Services', 'phone' => '08022114455', 'specialization' => 'security'],
        ['name' => 'CleanCity Waste & Environmental', 'company' => 'CleanCity Ltd', 'phone' => '08055443322', 'specialization' => 'cleaning'],
        ['name' => 'HydraTech Pumps & Water Solutions', 'company' => 'HydraTech Engineering', 'phone' => '08099887766', 'specialization' => 'plumbing']
    ];

    foreach ($vendorsList as $v) {
        $checkV = $db->fetchOne('SELECT id FROM vendors WHERE name = ?', [$v['name']]);
        if (!$checkV) {
            $db->execute(
                "INSERT INTO vendors (estate_id, name, company, phone, specialization, status) VALUES (?, ?, ?, ?, ?, 'active')",
                [$estateId, $v['name'], $v['company'], $v['phone'], $v['specialization']]
            );
        }
    }
    echo "✓ Seeded / Verified Vendor Contractors\n";

    // 4. Seed Estate Bank Accounts
    $bankCheck = $db->fetchOne('SELECT id FROM bank_accounts WHERE estate_id = ?', [$estateId]);
    if (!$bankCheck) {
        $db->execute("INSERT INTO bank_accounts (estate_id, bank_name, account_number, account_name, currency, opening_balance, current_balance)
                      VALUES (?, 'Zenith Bank PLC', '1012345678', 'Lekki Palm Heights Operations Trust', 'NGN', 5000000.00, 7850000.00)", [$estateId]);
        $db->execute("INSERT INTO bank_accounts (estate_id, bank_name, account_number, account_name, currency, opening_balance, current_balance)
                      VALUES (?, 'Access Bank PLC', '0098765432', 'Lekki Palm Heights Service Charge Escrow', 'NGN', 2500000.00, 4200000.00)", [$estateId]);
        echo "✓ Seeded 2 Estate Bank Accounts (Operations Trust & Escrow)\n";
    }

    // 5. Seed Annual & Monthly Budgets for the current year
    $year = (int)date('Y');
    $budgetSeed = [
        ['code' => 'OPEX-DSL', 'amount' => 18000000.00, 'notes' => 'Annual diesel budget (1,500,000 / month)'],
        ['code' => 'OPEX-SEC', 'amount' => 12000000.00, 'notes' => '24/7 Gate & Security Personnel wages'],
        ['code' => 'OPEX-CLN', 'amount' => 4800000.00, 'notes' => 'Weekly sanitation, refuse and cleaning'],
        ['code' => 'OPEX-WTR', 'amount' => 3600000.00, 'notes' => 'Water treatment chemicals & plant upkeep'],
        ['code' => 'UTIL-POW', 'amount' => 6000000.00, 'notes' => 'Common area EKEDC / PHCN meter bills'],
        ['code' => 'CAPEX-REP', 'amount' => 10000000.00, 'notes' => 'Annual sinking reserve for infrastructure']
    ];

    foreach ($budgetSeed as $bs) {
        $cat = $db->fetchOne('SELECT id FROM expense_categories WHERE code = ?', [$bs['code']]);
        if ($cat) {
            $db->execute(
                "INSERT INTO budgets (estate_id, category_id, fiscal_year, fiscal_month, budgeted_amount, notes, created_by)
                 VALUES (?, ?, ?, NULL, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE budgeted_amount = VALUES(budgeted_amount)",
                [$estateId, (int)$cat['id'], $year, $bs['amount'], $bs['notes'], $userId]
            );
        }
    }
    echo "✓ Seeded FY {$year} Operating & Capital Budgets\n";

    // 6. Seed Realistic Expense Vouchers across past 3 months
    $vendors = $db->fetchAll('SELECT id, name FROM vendors WHERE estate_id = ?', [$estateId]);
    $vendorMap = [];
    foreach ($vendors as $vd) {
        $vendorMap[$vd['name']] = (int)$vd['id'];
    }

    $dslCat = (int)($db->fetchOne("SELECT id FROM expense_categories WHERE code = 'OPEX-DSL'")['id'] ?? 1);
    $secCat = (int)($db->fetchOne("SELECT id FROM expense_categories WHERE code = 'OPEX-SEC'")['id'] ?? 2);
    $clnCat = (int)($db->fetchOne("SELECT id FROM expense_categories WHERE code = 'OPEX-CLN'")['id'] ?? 3);
    $wtrCat = (int)($db->fetchOne("SELECT id FROM expense_categories WHERE code = 'OPEX-WTR'")['id'] ?? 4);
    $powCat = (int)($db->fetchOne("SELECT id FROM expense_categories WHERE code = 'UTIL-POW'")['id'] ?? 5);
    $repCat = (int)($db->fetchOne("SELECT id FROM expense_categories WHERE code = 'CAPEX-REP'")['id'] ?? 7);

    $expensesData = [
        // Current Month
        [
            'title' => 'Supply of 1,200L Generator Diesel - Central Plant',
            'cat' => $dslCat,
            'vendor' => $vendorMap['Alhaji Musa & Sons Fuel Supply'] ?? null,
            'amount' => 1440000.00,
            'tax' => 108000.00,
            'wht' => 72000.00,
            'total' => 1476000.00,
            'status' => 'paid',
            'date' => date('Y-m-05'),
            'ref' => 'VEND-DSL-8921'
        ],
        [
            'title' => 'Monthly Security Guards Remuneration (12 Guards)',
            'cat' => $secCat,
            'vendor' => $vendorMap['Oak & Shield Security Patrol'] ?? null,
            'amount' => 960000.00,
            'tax' => 0.00,
            'wht' => 48000.00,
            'total' => 912000.00,
            'status' => 'paid',
            'date' => date('Y-m-10'),
            'ref' => 'OAK-SEC-M' . date('m')
        ],
        [
            'title' => 'Estate Central Waste Evacuation & Septic Servicing',
            'cat' => $clnCat,
            'vendor' => $vendorMap['CleanCity Waste & Environmental'] ?? null,
            'amount' => 380000.00,
            'tax' => 28500.00,
            'wht' => 19000.00,
            'total' => 389500.00,
            'status' => 'paid',
            'date' => date('Y-m-12'),
            'ref' => 'CLN-INV-441'
        ],
        [
            'title' => 'Water Treatment Filtration Media & Chlorine Replenishment',
            'cat' => $wtrCat,
            'vendor' => $vendorMap['HydraTech Pumps & Water Solutions'] ?? null,
            'amount' => 280000.00,
            'tax' => 21000.00,
            'wht' => 14000.00,
            'total' => 287000.00,
            'status' => 'approved',
            'date' => date('Y-m-18'),
            'ref' => 'HYD-WAT-302'
        ],
        [
            'title' => 'Common Area PHCN / EKEDC Maximum Demand Meter Recharge',
            'cat' => $powCat,
            'vendor' => null,
            'amount' => 520000.00,
            'tax' => 0.00,
            'wht' => 0.00,
            'total' => 520000.00,
            'status' => 'pending_approval',
            'date' => date('Y-m-22'),
            'ref' => 'PHCN-MTR-0099'
        ],
        [
            'title' => 'Emergency Main Entrance Automatic Boom Barrier Gearbox Replacement',
            'cat' => $repCat,
            'vendor' => null,
            'amount' => 750000.00,
            'tax' => 56250.00,
            'wht' => 37500.00,
            'total' => 768750.00,
            'status' => 'pending_approval',
            'date' => date('Y-m-25'),
            'ref' => 'GATE-REP-110'
        ],
        // Last Month
        [
            'title' => 'Supply of 1,500L Generator Diesel Supply',
            'cat' => $dslCat,
            'vendor' => $vendorMap['Alhaji Musa & Sons Fuel Supply'] ?? null,
            'amount' => 1800000.00,
            'tax' => 135000.00,
            'wht' => 90000.00,
            'total' => 1845000.00,
            'status' => 'paid',
            'date' => date('Y-m-10', strtotime('-1 month')),
            'ref' => 'VEND-DSL-8802'
        ],
        [
            'title' => 'Monthly Security Guards Remuneration',
            'cat' => $secCat,
            'vendor' => $vendorMap['Oak & Shield Security Patrol'] ?? null,
            'amount' => 960000.00,
            'tax' => 0.00,
            'wht' => 48000.00,
            'total' => 912000.00,
            'status' => 'paid',
            'date' => date('Y-m-15', strtotime('-1 month')),
            'ref' => 'OAK-SEC-PREV'
        ],
        [
            'title' => 'Perimeter Solar Streetlight Battery Upgrades (10 Units)',
            'cat' => $repCat,
            'vendor' => null,
            'amount' => 1200000.00,
            'tax' => 90000.00,
            'wht' => 60000.00,
            'total' => 1230000.00,
            'status' => 'paid',
            'date' => date('Y-m-20', strtotime('-1 month')),
            'ref' => 'SOLAR-CAP-09'
        ]
    ];

    $countExp = 0;
    foreach ($expensesData as $i => $ed) {
        $expNum = 'EXP-' . date('Ymd', strtotime($ed['date'])) . '-' . str_pad((string)($i + 101), 3, '0', STR_PAD_LEFT);
        $checkE = $db->fetchOne('SELECT id FROM expenses WHERE expense_number = ?', [$expNum]);
        if (!$checkE) {
            $db->execute(
                "INSERT INTO expenses 
                 (expense_number, estate_id, category_id, vendor_id, title, amount, tax_amount, withholding_tax, total_amount, payment_method, payment_status, expense_date, invoice_reference, recorded_by, approved_by, approved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'bank_transfer', ?, ?, ?, ?, ?, ?)",
                [
                    $expNum, $estateId, $ed['cat'], $ed['vendor'], $ed['title'],
                    $ed['amount'], $ed['tax'], $ed['wht'], $ed['total'],
                    $ed['status'], $ed['date'], $ed['ref'], $userId,
                    $ed['status'] === 'paid' ? $userId : null,
                    $ed['status'] === 'paid' ? $ed['date'] . ' 12:00:00' : null
                ]
            );
            $countExp++;
        }
    }
    echo "✓ Seeded {$countExp} realistic expense vouchers (Diesel, Security, Sanitation, Power, Repairs)\n";

    echo "========================================\n";
    echo "✓ Test data seeded successfully!\n";
    echo "========================================\n";

} catch (Throwable $e) {
    echo "Error seeding test data: " . $e->getMessage() . "\n";
    exit(1);
}
