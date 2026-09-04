{{-- Dynamic Styles Component for Urgent Tasks Pages --}}
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

<style id="dynamic-theme-styles-urgent">
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

/* Apply theme colors to body */
body {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
    transition: background-color 0.3s ease, color 0.3s ease;
}

/* Urgent Tasks Headers */
.urgent-header,
.subpage-header {
    background: var(--color-primary) !important;
    color: #ffffff !important;
    border-radius: 15px !important;
}

.urgent-header h2,
.urgent-header p,
.subpage-header h2,
.subpage-header p,
.subpage-title h2 {
    color: #ffffff !important;
}

/* Ensure white color in dark mode too */
body.dark-mode .urgent-header,
body.dark-mode .subpage-header,
body.dark-mode .urgent-header h2,
body.dark-mode .urgent-header p,
body.dark-mode .subpage-header h2,
body.dark-mode .subpage-header p {
    color: #ffffff !important;
}

/* Container backgrounds */
.urgent-tasks-container,
.urgent-subpage-container {
    background: var(--bg-secondary) !important;
}

/* Card backgrounds */
.modern-section-card,
.modern-data-card,
.urgent-stat-card,
.system-warnings-card {
    background: var(--bg-primary) !important;
    box-shadow: var(--shadow-md) !important;
}

/* Stat cards */
.urgent-stat-card {
    border: 1px solid var(--border-color) !important;
    border-radius: 15px !important;
}

.urgent-stat-card::before {
    background: var(--color-primary) !important;
}

/* Card headers */
.modern-card-header,
.data-card-header {
    background: var(--bg-secondary) !important;
    border-bottom: 2px solid var(--border-color) !important;
}

.modern-card-title {
    color: var(--text-primary) !important;
}

/* Tables */
.modern-table,
.modern-table-enhanced {
    background: var(--bg-primary) !important;
}

.modern-table thead th,
.modern-table-enhanced thead th {
    background: var(--bg-secondary) !important;
    border-bottom: 2px solid var(--border-color) !important;
    color: var(--text-primary) !important;
}

.modern-table tbody tr:hover,
.modern-table-enhanced tbody tr:hover {
    background-color: var(--bg-secondary) !important;
}

.modern-table tbody td,
.modern-table-enhanced tbody td {
    border-bottom: 1px solid var(--border-color) !important;
    color: var(--text-primary) !important;
}

/* Empty states */
.empty-state,
.empty-state-enhanced {
    background: var(--bg-primary) !important;
    color: var(--text-secondary) !important;
}

.empty-state h3,
.empty-state-enhanced h3 {
    color: var(--color-success) !important;
}

/* PRIMARY BUTTONS - Main CTAs (View Details) */
.btn-primary-center,
.modern-btn.btn-outline-primary,
.modern-btn.btn-outline-info,
.modern-btn.btn-outline-danger,
.modern-btn.btn-outline-warning {
    background: var(--color-primary) !important;
    color: white !important;
    border-color: var(--color-primary) !important;
}

.btn-primary-center:hover,
.modern-btn.btn-outline-primary:hover,
.modern-btn.btn-outline-info:hover,
.modern-btn.btn-outline-danger:hover,
.modern-btn.btn-outline-warning:hover {
    background: var(--color-primary-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorPrimary, 0.3) }} !important;
    color: white !important;
}

/* SECONDARY BUTTONS - Supporting Actions (Add, Create, Edit) */
.btn-secondary-center,
.btn-add-new,
.warning-action-btn {
    background: var(--color-secondary) !important;
    color: white !important;
}

.btn-secondary-center:hover,
.btn-add-new:hover,
.warning-action-btn:hover {
    background: var(--color-secondary-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorSecondary, 0.3) }} !important;
    color: white !important;
}

/* NEUTRAL BUTTONS - Cancel, Back */
.btn-cancel-modern,
.back-button {
    background: var(--color-secondary) !important;
    color: white !important;
    border-color: var(--color-neutral) !important;
}

.btn-cancel-modern:hover,
.back-button:hover {
    background: var(--color-neutral-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorNeutral, 0.3) }} !important;
    color: white !important;
}

/* SUCCESS BUTTONS - Approve, Activate */
.btn-success-center,
.action-btn.success,
.action-btn-enhanced.success,
.action-btn-enhanced.btn-success-center {
    background: var(--color-success) !important;
    color: white !important;
}

.btn-success-center:hover,
.action-btn.success:hover,
.action-btn-enhanced.success:hover,
.action-btn-enhanced.btn-success-center:hover {
    background: #059669 !important;
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3) !important;
    color: white !important;
}

/* DANGER BUTTONS - Reject, Delete */
.btn-danger-center,
.action-btn.danger,
.action-btn-enhanced.danger,
.action-btn-enhanced.btn-danger-center {
    background: var(--color-error) !important;
    color: white !important;
}

.btn-danger-center:hover,
.action-btn.danger:hover,
.action-btn-enhanced.danger:hover,
.action-btn-enhanced.btn-danger-center:hover {
    background: #dc2626 !important;
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3) !important;
    color: white !important;
}

/* INFO BUTTONS */
.action-btn-enhanced.info,
.action-btn-enhanced.btn-primary-center {
    background: var(--color-primary) !important;
    color: white !important;
}

.action-btn-enhanced.info:hover,
.action-btn-enhanced.btn-primary-center:hover {
    background: var(--color-primary-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorPrimary, 0.3) }} !important;
    color: white !important;
}

/* Stat icons and numbers */
.urgent-stat-icon {
    background: var(--color-accent) !important;
}

.stat-number,
.urgent-stat-info h3 {
    color: var(--text-primary) !important;
}

.stat-label,
.urgent-stat-info p,
.urgent-stat-info small {
    color: var(--text-primary) !important;
}

/* Dark mode stat labels */
body.dark-mode .stat-label,
body.dark-mode .urgent-stat-info p,
body.dark-mode .urgent-stat-info small {
    color: rgba(255, 255, 255, 0.9) !important;
}

/* Badges */
.center-badge,
.notification-badge,
.count-badge {
    background: var(--color-accent) !important;
    color: white !important;
}

/* System warnings card */
.system-warnings-card {
    background: {{ hexToRgba($colorSecondary, 0.9) }} !important;
}

.system-warnings-description {
    color: #ffffff !important;
}

.warning-item {
    background: var(--bg-primary) !important;
    border-left: 5px solid var(--color-accent) !important;
}

.warning-content strong,
.warning-content p {
    color: var(--text-primary) !important;
}

/* Alert messages */
.alert-modern {
    background: var(--color-warning) !important;
    color: white !important;
}

/* User info */
.user-name,
.course-name,
.group-name {
    color: var(--text-primary) !important;
}

.user-contact,
.course-meta,
.group-center,
.course-description {
    color: var(--text-secondary) !important;
}

/* Dark mode text improvements - Exclude header elements */
body.dark-mode .user-name:not(header#header .user-name),
body.dark-mode .course-name,
body.dark-mode .group-name {
    color: var(--text-primary) !important;
}

body.dark-mode .user-contact,
body.dark-mode .course-meta,
body.dark-mode .group-center,
body.dark-mode .course-description {
    color: var(--text-secondary) !important;
}

body.dark-mode .text-muted,
body.dark-mode small.text-muted {
    color: var(--text-tertiary) !important;
}
.status-badge-enhanced,
.status-badge {
    color: black !important;
}
/* Status badges in dark mode */
body.dark-mode .status-badge-enhanced,
body.dark-mode .status-badge {
    color: white !important;
}

/* Table text in dark mode */
body.dark-mode .modern-table tbody td strong,
body.dark-mode .modern-table-enhanced tbody td strong {
    color: var(--text-primary) !important;
}

/* Info items in dark mode */
body.dark-mode .info-item,
body.dark-mode .group-info-item {
    color: var(--text-secondary) !important;
}

/* Pagination */
.pagination .page-link {
    background-color: var(--bg-primary);
    border-color: var(--border-color);
    color: var(--color-primary);
}

.pagination .page-link:hover {
    background-color: var(--color-primary);
    color: white;
}

.pagination .page-item.active .page-link {
    background-color: var(--color-primary);
    border-color: var(--color-primary);
}

/* Dark mode specific adjustments */
body.dark-mode .urgent-stat-card,
body.dark-mode .modern-section-card,
body.dark-mode .modern-data-card {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
}

body.dark-mode .stat-number {
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
}

body.dark-mode .warning-item {
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
}

/* Badge text colors */
body.dark-mode .badge {
    color: white !important;
}

/* Card title in dark mode */
body.dark-mode .modern-card-title i {
    color: var(--text-primary) !important;
}

/* User avatar in dark mode - Exclude header elements */
body.dark-mode .user-avatar:not(header#header .user-avatar),
body.dark-mode .user-avatar-enhanced {
    color: white !important;
}

/* Course icon in dark mode */
body.dark-mode .course-icon,
body.dark-mode .course-icon-enhanced {
    color: white !important;
}

/* Ensure white text on colored backgrounds - Exclude header elements */
.urgent-stat-icon,
.user-avatar:not(header#header .user-avatar),
.user-avatar-enhanced,
.course-icon,
.course-icon-enhanced {
    color: white !important;
}

/* Fix inline styled links - override hardcoded #2c3e50 */
a[style*="color: #2c3e50"],
a.d-block[style*="font-weight: 700"],
a.user-name,
a.course-name,
a.group-name {
    color: var(--text-primary) !important;
}

a[style*="color: #2c3e50"]:hover,
a.d-block[style*="font-weight: 700"]:hover,
a.user-name:hover,
a.course-name:hover,
a.group-name:hover {
    color: var(--color-primary) !important;
}

/* Fix hardcoded row numbers - override #2563eb */
span[style*="color: #2563eb"] {
    color: var(--color-primary) !important;
}

/* Dark mode for inline styled elements */
body.dark-mode a[style*="color: #2c3e50"],
body.dark-mode a.d-block[style*="font-weight: 700"],
body.dark-mode a.user-name,
body.dark-mode a.course-name,
body.dark-mode a.group-name {
    color: var(--text-primary) !important;
}

body.dark-mode a[style*="color: #2c3e50"]:hover,
body.dark-mode a.d-block[style*="font-weight: 700"]:hover,
body.dark-mode a.user-name:hover,
body.dark-mode a.course-name:hover,
body.dark-mode a.group-name:hover {
    color: var(--color-primary) !important;
}

/* Fix strong tags with d-block class */
strong.d-block {
    color: var(--text-primary) !important;
}

body.dark-mode strong.d-block {
    color: var(--text-primary) !important;
}

/* Badge colors - ensure they work in dark mode */
.badge-danger {
    background-color: var(--color-error) !important;
    color: white !important;
}

.badge-warning {
    background-color: var(--color-warning) !important;
    color: white !important;
}

.badge-info {
    background-color: #17a2b8 !important;
    color: white !important;
}

.badge-success {
    background-color: var(--color-success) !important;
    color: white !important;
}

/* Text color classes in dark mode */
body.dark-mode .text-danger {
    color: #fca5a5 !important;
}

body.dark-mode .text-warning {
    color: #fcd34d !important;
}

body.dark-mode .text-info {
    color: #93c5fd !important;
}

body.dark-mode .text-success {
    color: #6ee7b7 !important;
}

/* Smooth transitions for all themed elements */
* {
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}
</style>
