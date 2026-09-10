(() => {
    const dock = document.querySelector('[data-download-dock]');
    const download = document.getElementById('download');
    if (!dock || !download || !('IntersectionObserver' in window)) return;

    const isIOS =
        /iPhone|iPad|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isAndroid = /Android/i.test(navigator.userAgent);
    const preferred = isIOS
        ? download.querySelector('a[data-channel="appstore"]')
        : isAndroid
        ? download.querySelector('a[data-channel="play"]') ||
          download.querySelector('a[data-channel="direct"]')
        : null;

    // Only a configured download for this phone can become the sticky action.
    if (!preferred) return;
    dock.querySelector('[data-dock-link]').href = preferred.href;

    const observer = new IntersectionObserver(([entry]) => {
        dock.hidden = entry.isIntersecting || entry.boundingClientRect.top > 0;
    });
    observer.observe(download);
})();
