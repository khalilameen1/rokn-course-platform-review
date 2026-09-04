(function () {
    'use strict';

    function normalizeHex(value, fallback) {
        return /^#[0-9a-f]{6}$/i.test(value || '') ? value : fallback;
    }

    function adjustBrightness(hex, percent) {
        var number = parseInt(hex.slice(1), 16);
        var factor = 1 + (percent / 100);
        var channels = [
            (number >> 16) & 255,
            (number >> 8) & 255,
            number & 255
        ];

        return '#' + channels.map(function (channel) {
            return Math.max(0, Math.min(255, Math.round(channel * factor)))
                .toString(16)
                .padStart(2, '0');
        }).join('');
    }

    function rgbChannels(hex) {
        var number = parseInt(hex.slice(1), 16);
        return [
            (number >> 16) & 255,
            (number >> 8) & 255,
            number & 255
        ].join(', ');
    }

    function applyTheme() {
        var theme = document.querySelector('meta[name="admin-identity-theme"]');
        if (!theme) return;

        var root = document.documentElement;
        var colors = {
            primary: normalizeHex(theme.content, '#2563eb'),
            secondary: normalizeHex(theme.dataset.secondary, '#16a34a'),
            neutral: normalizeHex(theme.dataset.neutral, '#f5f7fa'),
            accent: normalizeHex(theme.dataset.accent, '#f97316')
        };

        Object.keys(colors).forEach(function (role) {
            var color = colors[role];
            root.style.setProperty('--color-' + role, color);
            root.style.setProperty('--color-' + role + '-light', adjustBrightness(color, 30));
            root.style.setProperty('--color-' + role + '-dark', adjustBrightness(color, -20));
            root.style.setProperty('--admin-identity-' + role + '-rgb', rgbChannels(color));
        });

        document.querySelectorAll('[data-progress-value]').forEach(function (progressBar) {
            var value = Math.min(100, Math.max(0, Number(progressBar.dataset.progressValue) || 0));
            progressBar.style.width = value + '%';
        });

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyTheme, {once: true});
    } else {
        applyTheme();
    }
}());

