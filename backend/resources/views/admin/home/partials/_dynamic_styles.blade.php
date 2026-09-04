{{-- Dynamic Styles Component for Main Dashboard Page --}}
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
@endphp

<style id="dynamic-dashboard-theme-styles">
:root {
    /* PRIMARY COLOR (Brand Identity - Main Buttons, Key Highlights) ~60% */
    --color-primary: {{ $colorPrimary }};
    --color-primary-light: {{ $colorPrimaryLight }};
    --color-primary-dark: {{ $colorPrimaryDark }};
    --color-primary-rgb: {{ hexToRgba($colorPrimary, 1) }};

    /* SECONDARY COLOR (Supporting Elements - Secondary Buttons) ~30% */
    --color-secondary: {{ $colorSecondary }};
    --color-secondary-light: {{ $colorSecondaryLight }};
    --color-secondary-dark: {{ $colorSecondaryDark }};
    --color-secondary-rgb: {{ hexToRgba($colorSecondary, 1) }};

    /* NEUTRAL COLOR (Structure - Backgrounds, Text, Borders) ~60-80% */
    --color-neutral: {{ $colorNeutral }};
    --color-neutral-light: {{ $colorNeutralLight }};
    --color-neutral-dark: {{ $colorNeutralDark }};

    /* ACCENT COLOR (Attention Elements - Notifications, Highlights) ~5% */
    --color-accent: {{ $colorAccent }};
    --color-accent-light: {{ $colorAccentLight }};
    --color-accent-dark: {{ $colorAccentDark }};
    --color-accent-rgb: {{ hexToRgba($colorAccent, 1) }};

    /* FEEDBACK COLORS (Fixed System States) */
    --color-success: {{ $colorSuccess }};
    --color-warning: {{ $colorWarning }};
    --color-error: {{ $colorError }};

    /* Light Mode Variables */
    --bg-primary: #ffffff;
    --bg-secondary: #f8f9fa;
    --bg-tertiary: #e9ecef;
    --text-primary: #2d3748;
    --text-secondary: #718096;
    --text-tertiary: #a0aec0;
    --border-color: #e2e8f0;
    --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
    --shadow-md: 0 10px 30px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 20px 50px rgba(0, 0, 0, 0.15);
}

/* Dark Mode Variables */
body.dark-mode {
    --bg-primary: #1a202c;
    --bg-secondary: #2d3748;
    --bg-tertiary: #4a5568;
    --text-primary: #f7fafc;
    --text-secondary: #e2e8f0;
    --text-tertiary: #cbd5e0;
    --border-color: #4a5568;
    --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.3);
    --shadow-md: 0 10px 30px rgba(0, 0, 0, 0.4);
    --shadow-lg: 0 20px 50px rgba(0, 0, 0, 0.5);
}

/* Apply theme colors to dashboard */
body {
    background-color: var(--bg-secondary) !important;
    color: var(--text-primary);
    transition: background-color 0.3s ease, color 0.3s ease;
}

/* Ensure body background changes in dark mode */
body.dark-mode {
    background-color: var(--bg-secondary) !important;
}

/* Dashboard Container */
.dashboard-container {
    background: var(--bg-secondary) !important;
}

/* Welcome Header - Uses Primary Color */
.welcome-header {
    background: var(--color-primary) !important;
    color: white !important;
}

.welcome-header h2,
.welcome-header p {
    color: white !important;
}

/* Stats Cards */
.stats-card {
    background: var(--bg-primary) !important;
    box-shadow: var(--shadow-sm) !important;
    border: 1px solid var(--border-color) !important;
}

.stats-card:hover {
    box-shadow: var(--shadow-md) !important;
}

.stats-card.primary::before { background: var(--color-primary) !important; }
.stats-card.success::before { background: var(--color-success) !important; }
.stats-card.warning::before { background: var(--color-warning) !important; }
.stats-card.info::before { background: var(--color-accent) !important; }
.stats-card.danger::before { background: var(--color-error) !important; }
.stats-card.dark::before { background: var(--color-neutral-dark) !important; }

.stats-info h3 {
    color: var(--text-primary) !important;
}

.stats-info p {
    color: var(--text-secondary) !important;
}

/* Stats Info - Ensure consistent layout */
.stats-info {
    flex: 1;
    min-width: 0; /* Allow text to wrap if needed */
}

/* Stats Icon - Fixed sizes for consistency */
.stats-icon {
    width: 60px !important;
    height: 60px !important;
    min-width: 60px;
    min-height: 60px;
    flex-shrink: 0;
}

.stats-icon.primary { background: var(--color-primary) !important; }
.stats-icon.success { background: var(--color-success) !important; }
.stats-icon.warning { background: var(--color-warning) !important; }
.stats-icon.info { background: var(--color-accent) !important; }
.stats-icon.danger { background: var(--color-error) !important; }
.stats-icon.dark { background: var(--color-neutral) !important; }

/* Responsive Stats Cards */
@media (max-width: 1400px) {
    .stats-card {
        margin-bottom: 1rem;
    }
}

@media (max-width: 992px) {
    .stats-icon {
        width: 50px !important;
        height: 50px !important;
        min-width: 50px;
        min-height: 50px;
        font-size: 1.25rem !important;
    }

    .stats-info h3 {
        font-size: 2rem;
    }
}

@media (max-width: 768px) {
    .stats-icon {
        width: 55px !important;
        height: 55px !important;
        min-width: 55px;
        min-height: 55px;
        font-size: 1.35rem !important;
    }

    .stats-info h3 {
        font-size: 1.75rem;
    }

    .stats-info p {
        font-size: 0.875rem;
    }
}

@media (max-width: 576px) {
    .stats-card {
        padding: 1.25rem;
    }

    .stats-card-body {
        gap: 0.75rem;
    }

    .stats-icon {
        width: 50px !important;
        height: 50px !important;
        min-width: 50px;
        min-height: 50px;
        font-size: 1.2rem !important;
    }

    .stats-info h3 {
        font-size: 1.5rem;
    }
}

/* Chart Cards */
.chart-card {
    background: var(--bg-primary) !important;
    box-shadow: var(--shadow-sm) !important;
}

.chart-card:hover {
    box-shadow: var(--shadow-md) !important;
}

.chart-card-header {
    background: var(--bg-secondary) !important;
    border-bottom: 1px solid var(--border-color) !important;
}

.chart-card-title {
    color: var(--text-primary) !important;
}

.chart-card-subtitle {
    color: var(--text-secondary) !important;
}

/* Summary Cards */
.summary-card {
    background: var(--bg-primary) !important;
    box-shadow: var(--shadow-sm) !important;
}

.summary-card:hover {
    box-shadow: var(--shadow-md) !important;
}

.summary-card.primary::before { background: var(--color-primary) !important; }
.summary-card.success::before { background: var(--color-success) !important; }
.summary-card.warning::before { background: var(--color-warning) !important; }
.summary-card.info::before { background: var(--color-accent) !important; }

.summary-card-info h3 {
    color: var(--text-primary) !important;
}

.summary-card-info p,
.summary-card-info small {
    color: var(--text-secondary) !important;
}

.summary-card.primary .summary-card-icon { background: var(--color-primary) !important; }
.summary-card.success .summary-card-icon { background: var(--color-success) !important; }
.summary-card.warning .summary-card-icon { background: var(--color-warning) !important; }
.summary-card.info .summary-card-icon { background: var(--color-accent) !important; }

/* Buttons - Following Centers Pattern */
.btn-outline-primary,
.btn-primary {
    background: var(--color-primary) !important;
    border-color: var(--color-primary) !important;
    color: white !important;
}

.btn-outline-primary:hover,
.btn-primary:hover {
    background: var(--color-primary-dark) !important;
    border-color: var(--color-primary-dark) !important;
}

.btn-outline-secondary,
.btn-secondary {
    background: var(--color-secondary) !important;
    border-color: var(--color-secondary) !important;
    color: white !important;
}

.btn-outline-secondary:hover,
.btn-secondary:hover {
    background: var(--color-secondary-dark) !important;
    border-color: var(--color-secondary-dark) !important;
}

.btn-outline-success,
.btn-success {
    background: var(--color-success) !important;
    border-color: var(--color-success) !important;
}

.btn-outline-warning,
.btn-warning {
    background: var(--color-warning) !important;
    border-color: var(--color-warning) !important;
}

/* Dark Mode Enhancements */
body.dark-mode {
    background-color: var(--bg-secondary) !important;
}

body.dark-mode .dashboard-container {
    background: var(--bg-secondary) !important;
}

body.dark-mode .stats-card,
body.dark-mode .chart-card,
body.dark-mode .summary-card {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
}

body.dark-mode .welcome-header {
    background: var(--color-primary) !important;
}

/* Smooth transitions for all themed elements */
* {
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

/* Chart colors - use primary color */
.chart-card canvas {
    color: var(--text-primary);
}

/* Pagination in dark mode */
body.dark-mode .pagination .page-link {
    background-color: var(--bg-secondary);
    border-color: var(--border-color);
    color: var(--text-primary);
}

body.dark-mode .pagination .page-link:hover {
    background-color: var(--bg-tertiary);
    color: var(--text-primary);
}

body.dark-mode .pagination .page-item.active .page-link {
    background-color: var(--color-primary);
    border-color: var(--color-primary);
}

/* Course Statistics Table */
.course-stats-table {
    margin-bottom: 0 !important;
}

.course-stats-table thead th {
    padding: 0.75rem 0.5rem;
}

.course-stats-table tbody td {
    padding: 0.75rem 0.5rem;
    vertical-align: middle;
}

.course-stats-table tr:hover td {
    background-color: var(--bg-tertiary);
}

/* Custom scrollbar for course stats table */
.chart-card-body::-webkit-scrollbar {
    width: 6px;
}

.chart-card-body::-webkit-scrollbar-track {
    background: var(--bg-secondary);
    border-radius: 3px;
}

.chart-card-body::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 3px;
}

.chart-card-body::-webkit-scrollbar-thumb:hover {
    background: var(--text-tertiary);
}

body.dark-mode .chart-card-body::-webkit-scrollbar-track {
    background: var(--bg-tertiary);
}

body.dark-mode .chart-card-body::-webkit-scrollbar-thumb {
    background: var(--border-color);
}

body.dark-mode .chart-card-body::-webkit-scrollbar-thumb:hover {
    background: var(--text-secondary);
}
</style>
