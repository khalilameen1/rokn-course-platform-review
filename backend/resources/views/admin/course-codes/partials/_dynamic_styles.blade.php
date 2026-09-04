{{-- Dynamic Styles Component for Course Codes Pages --}}
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

<style id="dynamic-theme-styles">
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

/*
 * ============================================================================
 * PROFESSIONAL COLOR SYSTEM - USAGE GUIDE
 * ============================================================================
 *
 * This design system follows professional UI/UX color distribution principles
 *
 * COLOR HIERARCHY:
 *
 * 1. PRIMARY COLOR (color_1) - ~60% usage
 *    - Brand identity and main visual elements
 *    - Used for: Headers, Main CTA buttons (Create), Detail view buttons,
 *      Progress indicators, Key highlights, Primary links
 *    - Examples: .modern-header, .btn-primary-modern, .action-btn (primary)
 *
 * 2. SECONDARY COLOR (color_2) - ~30% usage
 *    - Supporting actions and secondary elements
 *    - Used for: Secondary buttons (Edit, Back), Supporting icons,
 *      Secondary highlights, Special sections
 *    - Examples: .btn-secondary-modern, .header-buttons .btn
 *
 * 3. NEUTRAL COLOR (color_3) - ~60-80% usage
 *    - Structure and readability throughout the UI
 *    - Used for: Back/Cancel buttons, Tertiary actions, Subtle backgrounds,
 *      Borders, Dividers, Disabled states, Non-interactive elements
 *    - Examples: Filter sections, table borders, subtle backgrounds
 *
 * 4. ACCENT COLOR (color_4) - ~5% usage (SPARINGLY!)
 *    - Draw attention to specific important elements
 *    - Used for: Code badges, Number highlights, Usage counts,
 *      Important icons, Dashboard highlights, Attention indicators
 *    - Examples: .code-badge, .badge-info-modern, usage counters
 *
 * 5. FEEDBACK COLORS (Fixed, not customizable)
 *    - System states and user feedback
 *    - Success (#10b981): Active codes, success states
 *    - Warning (#f59e0b): Expired codes, warnings
 *    - Error (#ef4444): Inactive/disabled codes, danger buttons
 *    - Examples: Status badges, validation messages
 *
 * USAGE BEST PRACTICES:
 * - Primary color for headers and main action buttons
 * - Secondary color for edit/view actions
 * - Neutral color for structure and cancel/back buttons
 * - Accent color VERY sparingly for special highlights only
 * - Feedback colors only for system states
 *
 * ============================================================================
 */

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

/* Apply theme colors */
body {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
    transition: background-color 0.3s ease, color 0.3s ease;
}

/* Page Headers - Using PRIMARY color (Scoped to avoid header.blade.php conflicts) */
.page-header,
.course-codes-page .modern-header {
    background: var(--color-primary) !important;
    color: #ffffff !important;
}

/* Page Header Titles and Subtitles - Always White */
.page-header h1,
.page-header h4,
.page-header h5,
.page-header h6,
.page-header p,
.page-header .subtitle,
.course-codes-page .modern-header h1,
.course-codes-page .modern-header h4,
.course-codes-page .modern-header h5,
.course-codes-page .modern-header h6,
.course-codes-page .modern-header p,
.course-codes-page .modern-header .subtitle {
    color: #ffffff !important;
}

/* Ensure white color in dark mode too */
body.dark-mode .page-header,
body.dark-mode .page-header h1,
body.dark-mode .page-header h4,
body.dark-mode .page-header h5,
body.dark-mode .page-header h6,
body.dark-mode .page-header p,
body.dark-mode .page-header .subtitle,
body.dark-mode .course-codes-page .modern-header,
body.dark-mode .course-codes-page .modern-header h1,
body.dark-mode .course-codes-page .modern-header h4,
body.dark-mode .course-codes-page .modern-header h5,
body.dark-mode .course-codes-page .modern-header h6,
body.dark-mode .course-codes-page .modern-header p,
body.dark-mode .course-codes-page .modern-header .subtitle {
    color: #ffffff !important;
}

/* Header buttons - Using SECONDARY color */
.header-buttons .btn {
    background: var(--color-secondary) !important;
    color: white !important;
}

.header-buttons .btn:hover {
    background: var(--color-secondary-dark) !important;
}

/* Cards */
.modern-card,
.info-card,
.status-card,
.action-card {
    background: var(--bg-primary) !important;
    box-shadow: var(--shadow-md) !important;
}

.modern-card-header,
.info-card-header {
    background: var(--bg-secondary) !important;
    border-bottom-color: var(--color-primary) !important;
}

.modern-card-header h4,
.info-card-header h4 {
    color: var(--text-primary) !important;
}

/* Form Elements */
.form-control,
.form-select {
    background: var(--bg-primary) !important;
    color: var(--text-primary) !important;
    border-color: var(--border-color) !important;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--color-primary) !important;
    box-shadow: 0 0 0 3px {{ hexToRgba($colorPrimary, 0.1) }} !important;
}

.form-group label {
    color: var(--text-primary) !important;
}

/* PRIMARY BUTTONS - Main Actions (Detail/View, Create) */
.btn-primary-modern,
.action-btn[style*="667eea"],
.action-btn[style*="linear-gradient(135deg, #2563eb"] {
    background: var(--color-primary) !important;
    color: white !important;
}

.btn-primary-modern:hover,
.action-btn[style*="667eea"]:hover {
    background: var(--color-primary-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorPrimary, 0.3) }} !important;
}

/* SECONDARY BUTTONS - Supporting Actions (Edit) */
.btn-secondary-modern,
.action-btn[style*="f59e0b"],
.action-btn[style*="linear-gradient(135deg, #f59e0b"] {
    background: var(--color-secondary) !important;
    color: white !important;
}

.btn-secondary-modern:hover,
.action-btn[style*="f59e0b"]:hover {
    background: var(--color-secondary-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorSecondary, 0.3) }} !important;
}

/* NEUTRAL BUTTONS - Back/Cancel Actions */
.action-btn[style*="6b7280"],
.action-btn[style*="linear-gradient(135deg, #6b7280"] {
    background: var(--color-neutral) !important;
    color: white !important;
}

.action-btn[style*="6b7280"]:hover {
    background: var(--color-neutral-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorNeutral, 0.3) }} !important;
}

/* FEEDBACK BUTTONS - System States */
.action-btn[style*="ef4444"],
.action-btn[style*="linear-gradient(135deg, #ef4444"] {
    background: var(--color-error) !important;
    color: white !important;
}

.action-btn[style*="ef4444"]:hover {
    background: #dc2626 !important;
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3) !important;
}

/* Table Styling */
.modern-table,
.usage-table-wrapper {
    background: var(--bg-primary) !important;
}

.modern-table thead,
.usage-table thead {
    background: var(--color-primary) !important;
}

.modern-table thead th,
.usage-table thead th {
    color: white !important;
}

.modern-table tbody tr,
.usage-table tbody tr {
    border-bottom-color: var(--border-color) !important;
}

.modern-table tbody tr:hover,
.usage-table tbody tr:hover {
    background: var(--bg-secondary) !important;
}

/* Badge Styling - Using ACCENT color for code badges */
.code-badge {
    background: var(--color-accent) !important;
    color: white !important;
}

.badge-info-modern,
.badge-modern[style*="3b82f6"],
.badge-modern[style*="linear-gradient(135deg, #3b82f6"] {
    background: var(--color-accent) !important;
    color: white !important;
}

/* Status badges - Using feedback colors */
.badge-primary-modern,
.badge-modern[style*="667eea"] {
    background: var(--color-primary) !important;
    color: white !important;
}

.badge-success-modern,
.badge-modern[style*="10b981"] {
    background: var(--color-success) !important;
    color: white !important;
}

.badge-warning-modern,
.badge-modern[style*="f59e0b"] {
    background: var(--color-warning) !important;
    color: white !important;
}

.badge-danger-modern,
.badge-modern[style*="ef4444"] {
    background: var(--color-error) !important;
    color: white !important;
}

.badge-secondary-modern,
.badge-modern[style*="6b7280"] {
    background: var(--color-neutral) !important;
    color: white !important;
}

/* Filter Section */
.filter-section {
    background: var(--bg-primary) !important;
    border-color: var(--border-color) !important;
}

.filter-section-header {
    background: var(--bg-secondary) !important;
    border-bottom-color: var(--border-color) !important;
}

.filter-section-header h5 {
    color: var(--text-primary) !important;
}

.filter-section-header .toggle-icon {
    color: var(--color-primary) !important;
}

.filter-section-body {
    background: var(--bg-primary) !important;
}

/* Bulk Actions */
.bulk-actions {
    background: var(--bg-primary) !important;
}

/* Selection Sections */
.selection-section {
    background: var(--bg-secondary) !important;
    border-color: var(--color-primary) !important;
}

/* Checkbox Items */
.checkbox-item {
    background: var(--bg-primary) !important;
    border-color: var(--border-color) !important;
}

.checkbox-item:hover {
    border-color: var(--color-primary) !important;
    background: var(--bg-secondary) !important;
}

/* Copy Code Button */
.copy-code-btn {
    background: var(--bg-primary) !important;
    color: var(--color-primary) !important;
    border-color: var(--color-primary) !important;
}

.copy-code-btn:hover {
    background: var(--color-primary) !important;
    color: white !important;
}

.copy-code-btn.copied {
    background: var(--color-success) !important;
    border-color: var(--color-success) !important;
}

/* Info Table */
.info-table th {
    color: var(--text-secondary) !important;
}

.info-table td {
    color: var(--text-primary) !important;
}

.info-table tr {
    border-bottom-color: var(--border-color) !important;
}

.info-table tr:hover {
    background: var(--bg-secondary) !important;
}

/* Description & Lessons List */
.description-box,
.lessons-list {
    background: var(--bg-secondary) !important;
    border-right-color: var(--color-primary) !important;
}

.description-box h5,
.lessons-list h5 {
    color: var(--text-primary) !important;
}

.description-box p {
    color: var(--text-secondary) !important;
}

.lessons-list li {
    background: var(--bg-primary) !important;
    border-right-color: var(--color-primary) !important;
}

/* Empty State */
.empty-state {
    color: var(--text-secondary) !important;
}

.empty-state i {
    color: var(--border-color) !important;
}

.empty-state p {
    color: var(--text-secondary) !important;
}

/* User Avatar - Exclude header elements */
.user-avatar:not(header#header .user-avatar) {
    background: var(--color-primary) !important;
}

/* Pagination */
.pagination .page-link {
    background-color: var(--bg-primary);
    border-color: var(--border-color);
    color: var(--color-primary);
}

.pagination .page-link:hover {
    background: var(--color-primary) !important;
    color: white !important;
    border-color: var(--color-primary) !important;
}

.pagination .page-item.active .page-link {
    background: var(--color-primary) !important;
    border-color: var(--color-primary) !important;
    color: white !important;
}

.pagination .page-item.disabled .page-link {
    background: var(--bg-secondary);
    border-color: var(--border-color);
    color: var(--text-tertiary);
}

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

/* Breadcrumb */
.breadcrumb-nav {
    background: var(--bg-primary) !important;
}

.breadcrumb-nav a {
    color: var(--color-primary) !important;
}

.breadcrumb-nav span {
    color: var(--text-secondary) !important;
}

/* Status Cards with colored backgrounds */
.status-card[style*="d1fae5"] {
    background: {{ hexToRgba($colorSuccess, 0.2) }} !important;
}

.status-card[style*="fee2e2"] {
    background: {{ hexToRgba($colorError, 0.2) }} !important;
}

body.dark-mode .status-card[style*="d1fae5"] {
    background: {{ hexToRgba($colorSuccess, 0.3) }} !important;
}

body.dark-mode .status-card[style*="fee2e2"] {
    background: {{ hexToRgba($colorError, 0.3) }} !important;
}

/* Smooth transitions for all themed elements */
* {
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

/* Dark Mode Specific Enhancements */
body.dark-mode .modern-card,
body.dark-mode .info-card,
body.dark-mode .status-card,
body.dark-mode .action-card {
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4) !important;
}

body.dark-mode .filter-section,
body.dark-mode .bulk-actions {
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3) !important;
}

/* Alert messages in dark mode */
body.dark-mode .alert-success {
    background: rgba(16, 185, 129, 0.2) !important;
    color: #6ee7b7 !important;
}

body.dark-mode .alert-warning {
    background: rgba(245, 158, 11, 0.2) !important;
    color: #fcd34d !important;
}

body.dark-mode .alert-danger {
    background: rgba(239, 68, 68, 0.2) !important;
    color: #fca5a5 !important;
}

body.dark-mode .alert-info {
    background: rgba(59, 130, 246, 0.2) !important;
    color: #93c5fd !important;
}

@media (max-width: 768px) {
    .dark-mode-toggle {
        bottom: 20px;
        left: 20px;
        width: 50px;
        height: 50px;
        font-size: 1.2rem;
    }
}
</style>
