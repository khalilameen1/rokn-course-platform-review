(() => {
    const scenes = document.querySelector("[data-preview-scenes]");
    const pagination = document.querySelector("[data-preview-pagination]");
    if (scenes && pagination && "IntersectionObserver" in window) {
        const slides = [...scenes.querySelectorAll("[data-preview-scene]")];
        const buttons = [...pagination.querySelectorAll("button")];
        pagination.hidden = false;
        buttons.forEach((button, index) => {
            button.addEventListener("click", () => {
                scenes.scrollTo({
                    top: slides[index].offsetTop,
                    behavior: window.matchMedia(
                        "(prefers-reduced-motion: reduce)"
                    ).matches
                        ? "auto"
                        : "smooth",
                });
            });
        });
        const observer = new IntersectionObserver(
            (entries) => {
                const current = entries.find((entry) => entry.isIntersecting);
                if (!current) return;
                const index = slides.indexOf(current.target);
                buttons.forEach((button, buttonIndex) => {
                    button.setAttribute(
                        "aria-pressed",
                        String(buttonIndex === index)
                    );
                });
            },
            { root: scenes, threshold: 0.6 }
        );
        slides.forEach((slide) => observer.observe(slide));
    }

    const dock = document.querySelector("[data-download-dock]");
    const download = document.getElementById("download");
    if (!dock || !download || !("IntersectionObserver" in window)) return;

    const isIOS =
        /iPhone|iPad|iPod/.test(navigator.userAgent) ||
        (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
    const isAndroid = /Android/i.test(navigator.userAgent);
    const preferred = isIOS
        ? download.querySelector('a[data-channel="appstore"]')
        : isAndroid
        ? download.querySelector('a[data-channel="play"]') ||
          download.querySelector('a[data-channel="direct"]')
        : null;

    // Only a configured download for this phone can become the sticky action.
    if (!preferred) return;
    dock.querySelector("[data-dock-link]").href = preferred.href;

    const observer = new IntersectionObserver(([entry]) => {
        dock.hidden = entry.isIntersecting;
    });
    observer.observe(download);
})();
