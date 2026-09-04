{{-- Dynamic Styles Component for Orders Pages --}}
@php
    // Color Roles:
    // color_1 = Primary Color (Brand identity, main buttons, key highlights) ~60%
    // color_2 = Secondary Color (Supporting elements, secondary buttons) ~30%
    // color_3 = Neutral Colors (Backgrounds, text, borders, structure) ~60-80%
    // color_4 = Accent Color (Notifications, highlights, attention elements) ~5%

    $colorPrimary = $designSettings->color_1 ?? '#2563eb';
    $colorSecondary = $designSettings->color_2 ?? '#16a34a';
    $colorNeutral = $designSettings->color_3 ?? '#f5f7fa';
    $colorAccent = $designSettings->color_4 ?? '#f97316';

    // Feedback colors (fixed, not from settings)
    $colorSuccess = '#10b981'; // Green
    $colorWarning = '#f59e0b'; // Yellow/Orange
    $colorError = '#ef4444';   // Red

    // Generate lighter and darker shades for better UI
    if (!function_exists('adjustBrightness')) {
        function adjustBrightness($hex, $percent) {
            $hex = str_replace('#', '', $hex);
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));

            $r = max(0, min(255, $r + ($r * $percent / 100)));
            $g = max(0, min(255, $g + ($g * $percent / 100)));
            $b = max(0, min(255, $b + ($b * $percent / 100)));

            return sprintf("#%02x%02x%02x", $r, $g, $b);
        }
    }

    if (!function_exists('hexToRgba')) {
        function hexToRgba($hex, $alpha = 1) {
            $hex = str_replace('#', '', $hex);
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return "rgba($r, $g, $b, $alpha)";
        }
    }

    // Generate variations for each color
    $colorPrimaryLight = adjustBrightness($colorPrimary, 30);
    $colorPrimaryDark = adjustBrightness($colorPrimary, -20);

    $colorSecondaryLight = adjustBrightness($colorSecondary, 30);
    $colorSecondaryDark = adjustBrightness($colorSecondary, -20);

    $colorNeutralLight = adjustBrightness($colorNeutral, 40);
    $colorNeutralDark = adjustBrightness($colorNeutral, -20);

    $colorAccentLight = adjustBrightness($colorAccent, 30);
    $colorAccentDark = adjustBrightness($colorAccent, -20);
@endphp<style id="dynamic-theme-styles-orders">
:root {
    /* PRIMARY COLOR (Brand Identity - Main Buttons, Key Highlights) ~60% */
    --color-primary-orders: {{ $colorPrimary }};
    --color-primary-light-orders: {{ $colorPrimaryLight }};
    --color-primary-dark-orders: {{ $colorPrimaryDark }};
    --color-primary-rgb-orders: {{ hexToRgba($colorPrimary, 1) }};

    /* SECONDARY COLOR (Supporting Elements - Secondary Buttons) ~30% */
    --color-secondary-orders: {{ $colorSecondary }};
    --color-secondary-light-orders: {{ $colorSecondaryLight }};
    --color-secondary-dark-orders: {{ $colorSecondaryDark }};
    --color-secondary-rgb-orders: {{ hexToRgba($colorSecondary, 1) }};

    /* NEUTRAL COLOR (Structure - Backgrounds, Text, Borders) ~60-80% */
    --color-neutral-orders: {{ $colorNeutral }};
    --color-neutral-light-orders: {{ $colorNeutralLight }};
    --color-neutral-dark-orders: {{ $colorNeutralDark }};

    /* ACCENT COLOR (Attention Elements - Notifications, Highlights) ~5% */
    --color-accent-orders: {{ $colorAccent }};
    --color-accent-light-orders: {{ $colorAccentLight }};
    --color-accent-dark-orders: {{ $colorAccentDark }};
    --color-accent-rgb-orders: {{ hexToRgba($colorAccent, 1) }};

    /* FEEDBACK COLORS (Fixed System States) */
    --color-success-orders: {{ $colorSuccess }};
    --color-warning-orders: {{ $colorWarning }};
    --color-error-orders: {{ $colorError }};

    /* Light Mode Variables */
    --bg-primary-orders: #ffffff;
    --bg-secondary-orders: #f8f9fa;
    --bg-tertiary-orders: #e9ecef;
    --text-primary-orders: #2d3748;
    --text-secondary-orders: #718096;
    --text-tertiary-orders: #a0aec0;
    --border-color-orders: #e2e8f0;
    --shadow-sm-orders: 0 2px 10px rgba(0, 0, 0, 0.05);
    --shadow-md-orders: 0 10px 30px rgba(0, 0, 0, 0.1);
    --shadow-lg-orders: 0 20px 50px rgba(0, 0, 0, 0.15);
}

/* Dark Mode Variables */
body.dark-mode {
    --bg-primary-orders: #1a202c;
    --bg-secondary-orders: #2d3748;
    --bg-tertiary-orders: #4a5568;
    --text-primary-orders: #f7fafc;
    --text-secondary-orders: #e2e8f0;
    --text-tertiary-orders: #cbd5e0;
    --border-color-orders: #4a5568;
    --shadow-sm-orders: 0 2px 10px rgba(0, 0, 0, 0.3);
    --shadow-md-orders: 0 10px 30px rgba(0, 0, 0, 0.4);
    --shadow-lg-orders: 0 20px 50px rgba(0, 0, 0, 0.5);
    background-color: #1a202c !important;
}

/* Orders Container Background in Dark Mode */
body.dark-mode .orders-container {
    background-color: transparent;
}

/* Modern Card Headers */
.card-header-modern {
    background: linear-gradient(135deg, var(--color-primary-orders) 0%, var(--color-secondary-orders) 100%) !important;
    color: white !important;
}

.card-header-modern h4,
.card-header-modern h5 {
    color: white !important;
}

/* Orders Statistics Cards */
.stat-card {
    background: var(--bg-primary-orders) !important;
    border: 1px solid var(--border-color-orders) !important;
}

body.dark-mode .stat-card {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
}

.stat-card .stat-icon-wrapper {
    box-shadow: var(--shadow-sm-orders) !important;
}

.stat-card:hover .stat-icon-wrapper {
    box-shadow: var(--shadow-md-orders) !important;
}

.stat-card .stat-title {
    color: var(--text-secondary-orders) !important;
}

.stat-card:hover .stat-title {
    color: var(--text-primary-orders) !important;
}

.stat-card small {
    color: var(--text-tertiary-orders) !important;
}

/* Filter Section */
.filter-section {
    background: var(--bg-primary-orders) !important;
    border: 1px solid var(--border-color-orders) !important;
}

.filter-section-header {
    background: linear-gradient(135deg, var(--bg-secondary-orders) 0%, var(--bg-tertiary-orders) 100%) !important;
    border-bottom: 1px solid var(--border-color-orders) !important;
}

.filter-section-header h6 {
    color: var(--text-primary-orders) !important;
}

.filter-section-header .toggle-icon {
    color: var(--color-primary-orders) !important;
}

.filter-section-body {
    background: var(--bg-primary-orders) !important;
}

.filter-label {
    color: var(--text-primary-orders) !important;
}

.filter-control {
    background: var(--bg-primary-orders) !important;
    color: var(--text-primary-orders) !important;
    border: 1px solid var(--border-color-orders) !important;
}

.filter-control:focus {
    border-color: var(--color-primary-orders) !important;
    box-shadow: 0 0 0 0.2rem {{ hexToRgba($colorPrimary, 0.25) }} !important;
}

/* PRIMARY BUTTONS - Main Actions (View Details) */
.btn-apply-filter,
.dropdown-item .fa-eye {
    color: var(--color-primary-orders) !important;
}

.btn-apply-filter {
    background: linear-gradient(135deg, var(--color-primary-orders) 0%, var(--color-secondary-orders) 100%) !important;
    border: none !important;
    color: white !important;
}

.btn-apply-filter:hover {
    background: linear-gradient(135deg, var(--color-primary-dark-orders) 0%, var(--color-secondary-dark-orders) 100%) !important;
    box-shadow: 0 5px 15px {{ hexToRgba($colorPrimary, 0.4) }} !important;
}

/* NEUTRAL BUTTONS - Reset Filter, Back Actions */
.btn-reset-filter,
.btn-outline-secondary {
    background: var(--bg-primary-orders) !important;
    color: var(--text-secondary-orders) !important;
    border: 2px solid var(--border-color-orders) !important;
}

.btn-reset-filter:hover,
.btn-outline-secondary:hover {
    background: var(--bg-secondary-orders) !important;
    border-color: var(--color-neutral-orders) !important;
    color: var(--text-primary-orders) !important;
}

/* Modern Card */
.modern-card {
    background: var(--bg-primary-orders) !important;
    box-shadow: var(--shadow-md-orders) !important;
}

.orders-count-badge {
    background: {{ hexToRgba($colorPrimary, 0.2) }} !important;
    border: 2px solid {{ hexToRgba($colorPrimary, 0.3) }} !important;
}

/* Table Styles */
.table-modern {
    background: var(--bg-primary-orders) !important;
}

.table-modern thead th {
    background: linear-gradient(135deg, var(--bg-secondary-orders) 0%, var(--bg-tertiary-orders) 100%) !important;
    color: var(--text-primary-orders) !important;
    border-bottom: 2px solid var(--border-color-orders) !important;
}

.table-modern tbody td {
    border-bottom: 1px solid var(--border-color-orders) !important;
    color: var(--text-primary-orders) !important;
}

.table-modern tbody tr:hover {
    background-color: var(--bg-secondary-orders) !important;
}

/* Order ID Link - Primary Color */
.order-id {
    color: var(--color-primary-orders) !important;
}

.order-id:hover {
    color: var(--color-primary-dark-orders) !important;
}

/* User and Course Info */
.user-info h6,
.course-info h6 {
    color: var(--text-primary-orders) !important;
}

.user-info small,
.course-info small {
    color: var(--text-secondary-orders) !important;
}

/* Amount Display */
.amount-display {
    color: var(--color-success-orders) !important;
}

.discount-info {
    color: var(--color-accent-orders) !important;
}

/* Action Dropdown */
.action-dropdown .btn.dropdown-toggle {
    background: linear-gradient(135deg, var(--bg-primary-orders) 0%, var(--bg-secondary-orders) 100%) !important;
    border: 2px solid var(--border-color-orders) !important;
    color: var(--text-primary-orders) !important;
    box-shadow: var(--shadow-sm-orders) !important;
}

.action-dropdown .btn.dropdown-toggle:hover,
.action-dropdown .btn.dropdown-toggle:focus,
.action-dropdown.show .btn.dropdown-toggle {
    background: linear-gradient(135deg, var(--color-primary-orders) 0%, var(--color-secondary-orders) 100%) !important;
    border-color: var(--color-primary-orders) !important;
    color: white !important;
    box-shadow: 0 6px 16px {{ hexToRgba($colorPrimary, 0.4) }} !important;
}

.action-dropdown .dropdown-menu {
    background: var(--bg-primary-orders) !important;
    border: 1px solid var(--border-color-orders) !important;
    box-shadow: var(--shadow-md-orders) !important;
}

.action-dropdown .dropdown-menu .dropdown-item {
    color: var(--text-primary-orders) !important;
    background: transparent !important;
}

.action-dropdown .dropdown-menu .dropdown-item::before {
    background: linear-gradient(90deg, {{ hexToRgba($colorPrimary, 0.1) }}, transparent) !important;
}

.action-dropdown .dropdown-menu .dropdown-item:hover {
    background: linear-gradient(135deg, var(--bg-secondary-orders) 0%, var(--bg-tertiary-orders) 100%) !important;
    color: var(--color-primary-orders) !important;
}

.action-dropdown .dropdown-menu .dropdown-item:active {
    background: linear-gradient(135deg, var(--color-primary-orders) 0%, var(--color-secondary-orders) 100%) !important;
    color: white !important;
}

/* Empty State */
.empty-state {
    background: var(--bg-primary-orders) !important;
}

.empty-state i {
    color: var(--border-color-orders) !important;
}

.empty-state h5 {
    color: var(--text-secondary-orders) !important;
}

.empty-state p {
    color: var(--text-tertiary-orders) !important;
}

/* Payment Screenshot */
.payment-screenshot-thumb {
    border: 2px solid var(--border-color-orders) !important;
    box-shadow: var(--shadow-sm-orders) !important;
}

.payment-screenshot-thumb:hover {
    border-color: var(--color-primary-orders) !important;
    box-shadow: 0 4px 12px {{ hexToRgba($colorPrimary, 0.3) }} !important;
}

/* Modal Styles */
.modal-modern .modal-content {
    background: var(--bg-primary-orders) !important;
    box-shadow: var(--shadow-lg-orders) !important;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--color-primary-orders) 0%, var(--color-secondary-orders) 100%) !important;
    color: white !important;
    border: none !important;
}

.modal-modern .modal-title {
    color: white !important;
}

.modal-modern .modal-footer {
    background: var(--bg-secondary-orders) !important;
    border-top: 1px solid var(--border-color-orders) !important;
}

.modal-modern .btn-download {
    background: linear-gradient(135deg, var(--color-primary-orders) 0%, var(--color-secondary-orders) 100%) !important;
    border: none !important;
    color: white !important;
}

.modal-modern .btn-download:hover {
    box-shadow: 0 5px 15px {{ hexToRgba($colorPrimary, 0.4) }} !important;
}

/* Pagination */
.pagination .page-link {
    border: 2px solid var(--border-color-orders) !important;
    color: var(--color-primary-orders) !important;
    background: var(--bg-primary-orders) !important;
}

.pagination .page-link:hover {
    background: var(--color-primary-orders) !important;
    border-color: var(--color-primary-orders) !important;
    color: white !important;
}

.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, var(--color-primary-orders) 0%, var(--color-secondary-orders) 100%) !important;
    border-color: var(--color-primary-orders) !important;
    color: white !important;
}

/* ============================================ */
/* ORDER SHOW PAGE SPECIFIC STYLES */
/* ============================================ */

/* Order Detail Card */
.order-detail-card {
    background: var(--bg-primary-orders) !important;
    border: 1px solid var(--border-color-orders) !important;
    box-shadow: var(--shadow-sm-orders) !important;
}

.order-detail-card .card-header {
    background: linear-gradient(135deg, var(--color-primary-orders) 0%, var(--color-secondary-orders) 100%) !important;
    color: white !important;
}

.order-detail-card .card-header h5 {
    color: white !important;
}

.order-detail-card .card-body {
    background: var(--bg-primary-orders) !important;
}

/* Info Rows */
.info-row {
    border-bottom: 1px solid var(--border-color-orders) !important;
}

.info-label {
    color: var(--text-primary-orders) !important;
}

.info-label i {
    color: var(--color-primary-orders) !important;
}

.info-value {
    color: var(--text-primary-orders) !important;
}

/* User and Course Links - Primary Color */
.user-link,
.course-link {
    color: var(--color-primary-orders) !important;
}

.user-link:hover,
.course-link:hover {
    color: var(--color-primary-dark-orders) !important;
}

/* Code Badge - Primary Color */
.code-badge {
    background: var(--color-primary-orders) !important;
    color: white !important;
}

/* Amount Section */
.amount-section {
    background: linear-gradient(135deg, var(--bg-secondary-orders) 0%, var(--bg-tertiary-orders) 100%) !important;
    border: 1px solid var(--border-color-orders) !important;
}

body.dark-mode .amount-section {
    background: linear-gradient(135deg, {{ hexToRgba($colorPrimary, 0.1) }} 0%, {{ hexToRgba($colorSecondary, 0.1) }} 100%) !important;
}

.amount-row {
    color: var(--text-primary-orders) !important;
}

.amount-row.total {
    border-top: 2px solid var(--color-primary-orders) !important;
}

.amount-row.total .amount-label,
.amount-row.total .amount-value {
    color: var(--color-primary-orders) !important;
}

/* Payment Screenshot Box */
.payment-screenshot-box {
    background: var(--bg-secondary-orders) !important;
}

.payment-screenshot-large {
    box-shadow: var(--shadow-md-orders) !important;
}

/* Actions Card - Secondary Color */
.actions-card {
    background: var(--bg-primary-orders) !important;
    border: 1px solid var(--border-color-orders) !important;
    box-shadow: var(--shadow-sm-orders) !important;
}

.actions-card .card-header {
    background: linear-gradient(135deg, var(--color-secondary-orders) 0%, var(--color-accent-orders) 100%) !important;
    color: white !important;
}

.actions-card .card-header h5 {
    color: white !important;
}

.actions-card .card-body {
    background: var(--bg-primary-orders) !important;
}

/* Action Buttons */
.action-btn {
    color: white !important;
    transition: all 0.3s ease !important;
}

.action-btn:hover {
    transform: translateY(-2px) !important;
}

/* Info box in actions card */
.actions-card .card-body > div[style*="background"] {
    background: var(--bg-secondary-orders) !important;
    border: 1px solid var(--border-color-orders) !important;
}

/* Dark mode specific adjustments */
body.dark-mode .table-modern tbody tr:hover {
    background-color: rgba(255, 255, 255, 0.05) !important;
}

body.dark-mode .stat-card::before {
    opacity: 0.8;
}

body.dark-mode .stat-card:hover::after {
    opacity: 0.9;
}

/* Ensure white text in headers */
body.dark-mode .card-header-modern,
body.dark-mode .order-detail-card .card-header,
body.dark-mode .actions-card .card-header {
    color: white !important;
}

body.dark-mode .card-header-modern h4,
body.dark-mode .card-header-modern h5,
body.dark-mode .order-detail-card .card-header h5,
body.dark-mode .actions-card .card-header h5 {
    color: white !important;
}

/* Alert messages in dark mode */
body.dark-mode .alert-info {
    background: {{ hexToRgba($colorPrimary, 0.2) }} !important;
    color: var(--text-primary-orders) !important;
    border: 1px solid {{ hexToRgba($colorPrimary, 0.3) }} !important;
}

/* Smooth transitions for all themed elements */
* {
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}
</style>


