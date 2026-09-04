{{-- Dynamic Styles Component for Courses Pages --}}
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

<style id="dynamic-theme-styles-courses">
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
 * PROFESSIONAL COLOR SYSTEM - USAGE GUIDE FOR COURSES
 * ============================================================================
 *
 * This design system follows professional UI/UX color distribution principles
 *
 * COLOR HIERARCHY:
 *
 * 1. PRIMARY COLOR (color_1) - ~60% usage
 *    - Brand identity and main visual elements
 *    - Used for: Logo, Main CTA buttons (Add, Save, Submit), Active states,
 *      Headers, Key highlights, Primary links, Progress indicators
 *    - Examples: .btn-primary-modern, .btn-card-primary (for View Details buttons)
 *
 * 2. SECONDARY COLOR (color_2) - ~30% usage
 *    - Supporting actions and secondary elements
 *    - Used for: Secondary buttons (Create, Edit), Special sections,
 *      Banners, Supporting icons, Secondary highlights
 *    - Examples: .btn-secondary-modern, .btn-card-success (for Create/Edit buttons)
 *
 * 3. NEUTRAL COLOR (color_3) - ~60-80% usage
 *    - Structure and readability throughout the UI
 *    - Used for: Cancel/Back buttons, Tertiary actions, Subtle backgrounds,
 *      Borders, Dividers, Disabled states, Non-interactive elements
 *    - Examples: .btn-secondary-modern (cancel), .btn-back-action
 *
 * 4. ACCENT COLOR (color_4) - ~5% usage (SPARINGLY!)
 *    - Draw attention to specific important elements
 *    - Used for: Notification badges, Number highlights, Special counts,
 *      Important icons, Dashboard highlights, Attention indicators
 *    - Examples: .notification-badge, .count-badge, .card-count
 *
 * 5. FEEDBACK COLORS (Fixed, not customizable)
 *    - System states and user feedback
 *    - Success (#10b981): Completed actions, success messages, confirmed states
 *    - Warning (#f59e0b): Alerts, caution states, pending actions
 *    - Error (#ef4444): Failed actions, error messages, danger buttons
 *    - Examples: .btn-card-info, .btn-card-danger
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

/* Course Headers - Always White Text */
.courses-management-header,
.create-course-header,
.edit-course-header,
.course-show-header {
    background: var(--color-primary) !important;
    color: #ffffff !important;
}

.courses-management-header h1,
.courses-management-header h2,
.courses-management-header h3,
.courses-management-header h4,
.courses-management-header h5,
.courses-management-header h6,
.courses-management-header p,
.courses-management-header .subtitle,
.create-course-header h1,
.create-course-header h2,
.create-course-header h3,
.create-course-header h4,
.create-course-header h5,
.create-course-header h6,
.create-course-header p,
.create-course-header .subtitle,
.edit-course-header h1,
.edit-course-header h2,
.edit-course-header h3,
.edit-course-header h4,
.edit-course-header h5,
.edit-course-header h6,
.edit-course-header p,
.edit-course-header .subtitle,
.course-show-header h1,
.course-show-header h2,
.course-show-header h3,
.course-show-header h4,
.course-show-header h5,
.course-show-header h6,
.course-show-header p,
.course-show-header .subtitle {
    color: #ffffff !important;
}

/* Ensure white color in dark mode too */
body.dark-mode .courses-management-header,
body.dark-mode .create-course-header,
body.dark-mode .edit-course-header,
body.dark-mode .course-show-header,
body.dark-mode .courses-management-header h1,
body.dark-mode .courses-management-header h2,
body.dark-mode .courses-management-header h3,
body.dark-mode .courses-management-header h4,
body.dark-mode .courses-management-header h5,
body.dark-mode .courses-management-header h6,
body.dark-mode .courses-management-header p,
body.dark-mode .courses-management-header .subtitle,
body.dark-mode .create-course-header h1,
body.dark-mode .create-course-header h2,
body.dark-mode .create-course-header h3,
body.dark-mode .create-course-header h4,
body.dark-mode .create-course-header h5,
body.dark-mode .create-course-header h6,
body.dark-mode .create-course-header p,
body.dark-mode .create-course-header .subtitle,
body.dark-mode .edit-course-header h1,
body.dark-mode .edit-course-header h2,
body.dark-mode .edit-course-header h3,
body.dark-mode .edit-course-header h4,
body.dark-mode .edit-course-header h5,
body.dark-mode .edit-course-header h6,
body.dark-mode .edit-course-header p,
body.dark-mode .edit-course-header .subtitle,
body.dark-mode .course-show-header h1,
body.dark-mode .course-show-header h2,
body.dark-mode .course-show-header h3,
body.dark-mode .course-show-header h4,
body.dark-mode .course-show-header h5,
body.dark-mode .course-show-header h6,
body.dark-mode .course-show-header p,
body.dark-mode .course-show-header .subtitle {
    color: #ffffff !important;
}

.courses-container,
.form-container,
.content-container {
    background: var(--bg-primary) !important;
    box-shadow: var(--shadow-md) !important;
}

.stat-card {
    background: rgba(255, 255, 255, 0.15) !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
    backdrop-filter: blur(10px);
}

.stat-card .stat-number {
    color: rgba(255, 255, 255, 0.85) !important;
    font-weight: 700;
}

.courses-header {
    background: var(--bg-secondary) !important;
    border-bottom: 1px solid var(--border-color) !important;
}

.courses-title {
    color: var(--text-primary) !important;
}

.title-icon {
    background: var(--color-primary) !important;
}

.filters-section {
    background: var(--bg-secondary) !important;
    border-bottom: 1px solid var(--border-color) !important;
}

.filter-input,
.filter-select {
    background: var(--bg-primary) !important;
    color: var(--text-primary) !important;
    border: 2px solid var(--border-color) !important;
}

.filter-input:focus,
.filter-select:focus {
    border-color: var(--color-primary) !important;
    box-shadow: 0 0 0 3px {{ hexToRgba($colorPrimary, 0.1) }} !important;
}

.filter-label {
    color: var(--text-primary) !important;
}

.course-card {
    background: var(--bg-primary) !important;
    border: 2px solid var(--border-color) !important;
    box-shadow: var(--shadow-sm) !important;
}

.course-card:hover {
    border-color: var(--color-primary) !important;
    box-shadow: var(--shadow-lg) !important;
}

.course-title {
    color: var(--text-primary) !important;
}

.meta-item {
    color: var(--text-secondary) !important;
}

.course-description {
    color: var(--text-secondary) !important;
}

.stat-mini-number {
    color: var(--text-primary) !important;
}

.stat-mini-label {
    color: var(--text-tertiary) !important;
}

/* Course Image Containers */
.course-image-container {
    background: var(--bg-secondary) !important;
}

.course-image-placeholder {
    background: linear-gradient(135deg, var(--color-primary), var(--color-secondary)) !important;
}

body.dark-mode .course-image-container {
    background: var(--bg-tertiary) !important;
}


/* PRIMARY BUTTONS - Main View Details (Primary Action) */
.btn-card-primary,
.btn-primary-modern {
    background: var(--color-primary) !important;
    color: white !important;
}

.btn-card-primary:hover,
.btn-primary-modern:hover {
    background: var(--color-primary-dark) !important;
    box-shadow: 0 5px 15px {{ hexToRgba($colorPrimary, 0.4) }} !important;
    color: white !important;
}

/* SECONDARY BUTTONS - Create/Edit Actions */
.btn-card-success,
.btn-success-modern {
    background: var(--color-secondary) !important;
    color: white !important;
}

.btn-card-success:hover,
.btn-success-modern:hover {
    background: var(--color-secondary-dark) !important;
    box-shadow: 0 5px 15px {{ hexToRgba($colorSecondary, 0.4) }} !important;
    color: white !important;
}

/* NEUTRAL BUTTONS - Cancel, Back */
.btn-secondary-modern {
    background: var(--color-secondary) !important;
    color: white !important
}

.btn-secondary-modern:hover {
    background: var(--color-secondary-dark) !important;
    box-shadow: 0 5px 15px {{ hexToRgba($colorSecondary, 0.4) }} !important;
    color: white !important;
}

/* FEEDBACK BUTTONS - Info and Danger */
.btn-card-info {
    background: #3182ce !important;
    color: white !important;
}

.btn-card-info:hover {
    background: #2c5282 !important;
    box-shadow: 0 5px 15px rgba(49, 130, 206, 0.4) !important;
    color: white !important;
}

.btn-card-danger {
    background: var(--color-error) !important;
    color: white !important;
}

.btn-card-danger:hover {
    background: #dc2626 !important;
    box-shadow: 0 5px 15px rgba(239, 68, 68, 0.4) !important;
    color: white !important;
}

.empty-state {
    color: var(--text-secondary) !important;
}

.empty-title {
    color: var(--text-primary) !important;
}

.empty-icon {
    color: var(--border-color) !important;
}

/* Form Styles */
.section-title {
    color: var(--text-primary) !important;
}

.section-icon {
    background: var(--color-primary) !important;
}

.form-label-modern {
    color: var(--text-primary) !important;
}

.label-icon {
    color: var(--color-primary) !important;
}

.form-control-modern {
    background: var(--bg-primary) !important;
    color: var(--text-primary) !important;
    border: 2px solid var(--border-color) !important;
}

.form-control-modern:focus {
    border-color: var(--color-primary) !important;
    box-shadow: 0 0 0 3px {{ hexToRgba($colorPrimary, 0.1) }} !important;
}

.form-control-modern::placeholder {
    color: var(--text-tertiary) !important;
}

.form-help {
    color: var(--text-secondary) !important;
}

.form-section {
    border-bottom: 1px solid var(--border-color) !important;
}

.file-upload-area {
    background: var(--bg-secondary) !important;
    border: 2px dashed var(--border-color) !important;
}

.file-upload-area:hover {
    border-color: var(--color-primary) !important;
    background: {{ hexToRgba($colorPrimary, 0.05) }} !important;
}

.upload-icon {
    color: var(--text-tertiary) !important;
}

.upload-text {
    color: var(--text-primary) !important;
}

.upload-subtext {
    color: var(--text-secondary) !important;
}

.checkbox-item {
    background: var(--bg-primary) !important;
    border: 2px solid var(--border-color) !important;
}

.checkbox-item:hover {
    background: var(--bg-secondary) !important;
    border-color: var(--border-color) !important;
}

.checkbox-item.selected {
    border-color: var(--color-primary) !important;
    background: {{ hexToRgba($colorPrimary, 0.1) }} !important;
}

.custom-checkbox {
    border: 2px solid var(--border-color) !important;
}

.checkbox-item.selected .custom-checkbox {
    background: var(--color-primary) !important;
    border-color: var(--color-primary) !important;
}

.actions-section {
    background: var(--bg-secondary) !important;
    border-top: 1px solid var(--border-color) !important;
}

.progress-bar {
    background: var(--color-primary) !important;
}

.form-progress {
    background: var(--border-color) !important;
}

.changes-indicator {
    background: {{ hexToRgba($colorWarning, 0.2) }} !important;
    border: 1px solid {{ hexToRgba($colorWarning, 0.4) }} !important;
}

.changes-text {
    color: var(--text-primary) !important;
}

/* Show Page Styles */
.content-tabs {
    background: var(--bg-secondary) !important;
    border-bottom: 1px solid var(--border-color) !important;
}

.tab-button {
    color: var(--text-secondary) !important;
    background: none !important;
}

.tab-button.active {
    color: var(--color-primary) !important;
    border-bottom-color: var(--color-primary) !important;
    background: var(--bg-primary) !important;
}

.tab-button:hover {
    color: var(--text-primary) !important;
    background: var(--bg-tertiary) !important;
}

.info-section {
    background: var(--bg-secondary) !important;
    border: 2px solid var(--border-color) !important;
}

.info-table td {
    border-bottom: 1px solid var(--border-color) !important;
    color: var(--text-primary) !important;
}

.info-table td:first-child {
    color: var(--text-secondary) !important;
}

.section-item {
    background: var(--bg-secondary) !important;
    border: 2px solid var(--border-color) !important;
}

.section-item:hover {
    border-color: var(--color-primary) !important;
    box-shadow: var(--shadow-sm) !important;
}

.section-name {
    color: var(--text-primary) !important;
}

.section-meta {
    color: var(--text-secondary) !important;
}

.section-type-icon {
    background: var(--color-primary) !important;
}

.btn-section-primary,
.btn-card.btn-view {
    background: var(--color-primary) !important;
    color: white !important;
}

.btn-section-primary:hover,
.btn-card.btn-view:hover {
    background: var(--color-primary-dark) !important;
    color: white !important;
}

.btn-section-secondary {
    background: var(--color-secondary) !important;
    color: white !important;
}

.btn-section-secondary:hover {
    background: var(--color-secondary-dark) !important;
    box-shadow: 0 5px 15px {{ hexToRgba($colorSecondary, 0.4) }} !important;
    color: white !important;
}

.btn-section-danger {
    background: var(--color-error) !important;
    color: white !important;
}

.btn-section-danger:hover {
    background: #dc2626 !important;
    color: white !important;
}

.stat-number {
    color: var(--text-primary) !important;
}

/* Action buttons */
.btn-action {
    background: rgba(255, 255, 255, 0.1) !important;
    color: white !important;
    border: 2px solid rgba(255, 255, 255, 0.3) !important;
}

.btn-action:hover {
    background: rgba(255, 255, 255, 0.2) !important;
    border-color: rgba(255, 255, 255, 0.5) !important;
    color: white !important;
}

/* Meta cards in header */
.meta-card {
    background: rgba(255, 255, 255, 0.15) !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
}

.meta-value {
    color: var(--color-accent) !important;
}

.meta-label {
    color: rgba(255, 255, 255, 0.8) !important;
}

/* Smooth transitions for all themed elements */
* {
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

/* Dark mode specific adjustments */
body.dark-mode .stat-label {
    color: rgba(255, 255, 255, 0.85) !important;
}

body.dark-mode .stat-number {
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
}

body.dark-mode .course-card {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
}

body.dark-mode .stat-card {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3) !important;
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
    border: 1px solid rgba(239, 68, 68, 0.3) !important;
}

body.dark-mode .alert-info {
    background: rgba(59, 130, 246, 0.2) !important;
    color: #93c5fd !important;
}

/* ACCENT COLOR ELEMENTS - Notifications, Counts, Highlights (~5% usage) */
.notification-badge,
.count-badge,
.highlight-number,
.course-badge {
    background: var(--color-accent) !important;
    color: white !important;
}

.notification-dot {
    background: var(--color-accent) !important;
}

.highlight-text {
    color: var(--color-accent) !important;
}

/* Count/Number highlights in cards */
.card-count,
.card-number,
.highlight-stat {
    color: var(--color-accent) !important;
    font-weight: 700;
}

/* Special highlight backgrounds */
.highlight-box {
    background: {{ hexToRgba($colorAccent, 0.1) }} !important;
    border-left: 4px solid var(--color-accent) !important;
}

body.dark-mode .highlight-box {
    background: {{ hexToRgba($colorAccent, 0.2) }} !important;
}

/* Info card title icons */
.info-section h3 i {
    color: var(--color-accent) !important;
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
