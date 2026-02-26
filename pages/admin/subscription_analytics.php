<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin']);

$pageTitle = 'Subscription Analytics – EstatePro';
$db = db();

// Get subscription analytics data
$stats = [
    'total_estates' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM estates')['c'] ?? 0),
    'active_subscriptions' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'active'")['c'] ?? 0),
    'pending_subscriptions' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'pending'")['c'] ?? 0),
    'suspended_subscriptions' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'suspended'")['c'] ?? 0),
    'expired_subscriptions' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'expired'")['c'] ?? 0),
    'monthly_revenue' => (float)($db->fetchOne("SELECT SUM(amount) AS total FROM estate_subscriptions WHERE status = 'active' AND billing_cycle = 'monthly'")['total'] ?? 0),
    'annual_revenue' => (float)($db->fetchOne("SELECT SUM(amount) AS total FROM estate_subscriptions WHERE status = 'active' AND billing_cycle = 'annual'")['total'] ?? 0),
    'total_revenue' => (float)($db->fetchOne("SELECT SUM(amount) AS total FROM estate_subscriptions WHERE status = 'active'")['total'] ?? 0),
    'subscription_revenue' => (float)($db->fetchOne("SELECT SUM(sp.amount) AS total FROM subscription_payments sp JOIN estate_subscriptions es ON sp.subscription_id = es.id WHERE sp.status = 'completed' AND sp.payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)")['total'] ?? 0),
];

// Revenue by plan
$revenueByPlan = $db->fetchAll(
    "SELECT 
        sp.name as plan_name,
        sp.code as plan_code,
        COUNT(es.id) as subscription_count,
        SUM(es.amount) as monthly_revenue,
        (SELECT COUNT(*) FROM estate_subscriptions es2 WHERE es2.plan_id = sp.id AND es2.status = 'active') as active_count
     FROM subscription_plans sp
     LEFT JOIN estate_subscriptions es ON es.plan_id = sp.id AND es.status = 'active'
     WHERE sp.status = 'active'
     GROUP BY sp.id, sp.name, sp.code
     ORDER BY monthly_revenue DESC"
);

// Monthly revenue trends (last 12 months)
$monthlyRevenue = $db->fetchAll(
    "SELECT 
        DATE_FORMAT(sp.payment_date, '%Y-%m') as month,
        SUM(sp.amount) as revenue,
        COUNT(sp.id) as payment_count
     FROM subscription_payments sp
     WHERE sp.status = 'completed' 
     AND sp.payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
     GROUP BY DATE_FORMAT(sp.payment_date, '%Y-%m')
     ORDER BY month ASC"
);

// Subscription status breakdown
$statusBreakdown = $db->fetchAll(
    "SELECT 
        status,
        COUNT(*) as count,
        SUM(amount) as total_amount
     FROM estate_subscriptions
     GROUP BY status"
);

// Revenue by estate
$revenueByEstate = $db->fetchAll(
    "SELECT 
        e.name as estate_name,
        sp.name as plan_name,
        es.amount,
        es.status,
        (SELECT SUM(sp2.amount) FROM subscription_payments sp2 WHERE sp2.subscription_id = es.id AND sp2.status = 'completed') as total_paid
     FROM estate_subscriptions es
     JOIN estates e ON e.id = es.estate_id
     JOIN subscription_plans sp ON sp.id = es.plan_id
     WHERE es.status = 'active'
     ORDER BY es.amount DESC
     LIMIT 10"
);

// Recent payments
$recentPayments = $db->fetchAll(
    "SELECT 
        sp.payment_reference,
        sp.amount,
        sp.payment_date,
        sp.payment_method,
        sp.status,
        e.name as estate_name,
        sp2.name as plan_name
     FROM subscription_payments sp
     JOIN estate_subscriptions es ON es.id = sp.subscription_id
     JOIN estates e ON e.id = es.estate_id
     JOIN subscription_plans sp2 ON sp2.id = es.plan_id
     WHERE sp.status = 'completed'
     ORDER BY sp.payment_date DESC
     LIMIT 10"
);

// Revenue forecast
$forecastData = [
    'current_monthly' => $stats['monthly_revenue'],
    'potential_annual' => $stats['monthly_revenue'] * 12,
    'active_subscriptions' => $stats['active_subscriptions'],
    'avg_per_subscription' => $stats['active_subscriptions'] > 0 ? $stats['monthly_revenue'] / $stats['active_subscriptions'] : 0
];

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Subscription Analytics</h1>
    <div class="text-gray-600">Financial reports and revenue analysis for subscription management.</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-light-primary" href="subscription_monitoring.php">
      <i class="ki-duotone ki-eye fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
      Monitor Subscriptions
    </a>
    <a class="btn btn-light" href="subscription_payments.php">
      <i class="ki-duotone ki-credit-cart fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
      Payment Records
    </a>
  </div>
</div>

<!-- Stats Overview -->
<div class="row g-6 mb-6">
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-gray-600 fs-7">Total Estates</div>
        <div class="fs-2 fw-bold text-gray-900"><?= (int)$stats['total_estates'] ?></div>
        <div class="mt-2">
          <span class="badge badge-light">Managed</span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-gray-600 fs-7">Active Subscriptions</div>
        <div class="fs-2 fw-bold text-success"><?= (int)$stats['active_subscriptions'] ?></div>
        <div class="mt-2">
          <span class="text-success fs-8">
            <i class="ki-duotone ki-arrow-up fs-7 me-1"><span class="path1"></span><span class="path2"></span></i>
            Revenue Generating
          </span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-gray-600 fs-7">Monthly Revenue</div>
        <div class="fs-2 fw-bold text-primary">₦<?= number_format((float)$stats['monthly_revenue'], 0) ?></div>
        <div class="mt-2">
          <span class="badge badge-light">Ongoing</span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-gray-600 fs-7">Potential Annual</div>
        <div class="fs-2 fw-bold text-info">₦<?= number_format((float)$stats['monthly_revenue'] * 12, 0) ?></div>
        <div class="mt-2">
          <span class="badge badge-light">Projection</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-6">
  <!-- Main Charts -->
  <div class="col-12 col-xxl-8">
    <!-- Revenue Chart -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Monthly Revenue Trend</div>
        <div class="card-toolbar">
          <span class="badge badge-light-primary">Last 12 months</span>
        </div>
      </div>
      <div class="card-body">
        <div id="revenueChart" style="height: 400px;"></div>
      </div>
    </div>

    <!-- Plan Distribution -->
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Revenue by Plan</div>
        <div class="card-toolbar">
          <span class="badge badge-light-success">Active subscriptions</span>
        </div>
      </div>
      <div class="card-body">
        <div id="planDistributionChart" style="height: 400px;"></div>
      </div>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="col-12 col-xxl-4">
    <!-- Revenue Forecast -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Revenue Forecast</div>
      </div>
      <div class="card-body">
        <div class="d-flex flex-stack mb-4">
          <div class="text-gray-600">Current Monthly</div>
          <div class="fs-4 fw-bold text-primary">₦<?= number_format($forecastData['current_monthly'], 0) ?></div>
        </div>
        <div class="d-flex flex-stack mb-4">
          <div class="text-gray-600">Potential Annual</div>
          <div class="fs-4 fw-bold text-success">₦<?= number_format($forecastData['potential_annual'], 0) ?></div>
        </div>
        <div class="d-flex flex-stack mb-4">
          <div class="text-gray-600">Active Subscriptions</div>
          <div class="fs-4 fw-bold text-gray-900"><?= $forecastData['active_subscriptions'] ?></div>
        </div>
        <div class="d-flex flex-stack">
          <div class="text-gray-600">Avg/Subscription</div>
          <div class="fs-4 fw-bold text-gray-800">₦<?= number_format($forecastData['avg_per_subscription'], 0) ?></div>
        </div>
      </div>
    </div>

    <!-- Status Breakdown -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Subscription Status</div>
      </div>
      <div class="card-body">
        <?php foreach ($statusBreakdown as $status): ?>
          <div class="d-flex align-items-center mb-4">
            <div class="symbol symbol-40px me-3">
              <div class="symbol-label bg-light-primary">
                <i class="ki-duotone ki-chart-line fs-2 text-primary"><span class="path1"></span><span class="path2"></span></i>
              </div>
            </div>
            <div class="flex-grow-1">
              <div class="fw-bold text-gray-900 text-capitalize"><?= e($status['status']) ?></div>
              <div class="text-gray-600 fs-7"><?= (int)$status['count'] ?> subscriptions</div>
            </div>
            <div class="text-end">
              <div class="fw-bold text-success">₦<?= number_format((float)$status['total_amount'], 0) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Top Revenue Estates -->
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Top Revenue Estates</div>
      </div>
      <div class="card-body">
        <?php if (!$revenueByEstate): ?>
          <div class="text-gray-600 text-center py-4">No active subscriptions</div>
        <?php else: ?>
          <?php foreach ($revenueByEstate as $estate): ?>
            <div class="d-flex align-items-center mb-4">
              <div class="symbol symbol-40px me-3">
                <div class="symbol-label bg-light-primary">
                  <i class="ki-duotone ki-home fs-2 text-primary"><span class="path1"></span><span class="path2"></span></i>
                </div>
              </div>
              <div class="flex-grow-1">
                <div class="fw-bold text-gray-900"><?= e($estate['estate_name']) ?></div>
                <div class="text-gray-600 fs-7"><?= e($estate['plan_name']) ?></div>
              </div>
              <div class="text-end">
                <div class="fw-bold text-success">₦<?= number_format((float)$estate['amount'], 0) ?></div>
                <div class="text-gray-600 fs-7">/mo</div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Recent Payments -->
<div class="card mt-6">
  <div class="card-header">
    <div class="card-title fw-bold">Recent Payments</div>
    <div class="card-toolbar">
      <span class="badge badge-light-primary">Last 10 payments</span>
    </div>
  </div>
  <div class="card-body">
    <?php if (!$recentPayments): ?>
      <div class="text-gray-600 text-center py-8">
        <i class="ki-duotone ki-credit-cart fs-3x text-gray-400 mb-3"><span class="path1"></span><span class="path2"></span></i>
        <div>No recent payments found.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-row-dashed align-middle gs-0 gy-3">
          <thead>
            <tr class="fw-bold text-gray-600">
              <th>Reference</th>
              <th>Estate</th>
              <th>Plan</th>
              <th>Amount</th>
              <th>Method</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recentPayments as $payment): ?>
            <tr>
              <td class="fw-bold text-gray-900"><?= e($payment['payment_reference']) ?></td>
              <td><?= e($payment['estate_name']) ?></td>
              <td><span class="badge badge-light"><?= e($payment['plan_name']) ?></span></td>
              <td class="fw-bold">₦<?= number_format((float)$payment['amount'], 2) ?></td>
              <td><span class="badge badge-light-<?= $payment['payment_method'] === 'paystack' ? 'primary' : 'secondary' ?>"><?= e($payment['payment_method']) ?></span></td>
              <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Revenue Chart
    const revenueOptions = {
        chart: {
            type: 'area',
            height: 400,
            toolbar: {
                show: false
            }
        },
        series: [{
            name: 'Revenue',
            data: [
                <?php foreach ($monthlyRevenue as $month): ?>
                    { x: '<?= $month['month'] ?>', y: <?= (float)$month['revenue'] ?> },
                <?php endforeach; ?>
            ]
        }],
        xaxis: {
            type: 'datetime',
            labels: {
                datetimeUTC: false,
                format: 'MMM yyyy'
            }
        },
        yaxis: {
            labels: {
                formatter: function(value) {
                    return '₦' + value.toLocaleString();
                }
            }
        },
        tooltip: {
            x: {
                format: 'dd/MM/yyyy HH:mm'
            },
            y: {
                formatter: function(value) {
                    return '₦' + value.toLocaleString();
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.2,
                stops: [0, 100]
            }
        },
        stroke: {
            curve: 'smooth',
            width: 2
        },
        colors: ['#008FFB']
    };

    const revenueChart = new ApexCharts(document.querySelector("#revenueChart"), revenueOptions);
    revenueChart.render();

    // Plan Distribution Chart
    const planOptions = {
        chart: {
            type: 'donut',
            height: 400
        },
        series: [
            <?php foreach ($revenueByPlan as $plan): ?>
                <?= (float)$plan['monthly_revenue'] ?>,
            <?php endforeach; ?>
        ],
        labels: [
            <?php foreach ($revenueByPlan as $plan): ?>
                '<?= e($plan['plan_name']) ?>',
            <?php endforeach; ?>
        ],
        legend: {
            position: 'bottom'
        },
        colors: ['#008FFB', '#00E396', '#FEB019', '#FF4560', '#775DD0']
    };

    const planChart = new ApexCharts(document.querySelector("#planDistributionChart"), planOptions);
    planChart.render();
});
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>