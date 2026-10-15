(() => {
    const chat = document.getElementById("course-chat-demo");
    const trigger = document.querySelector("[data-chat-open]");
    if (!chat || !trigger || typeof chat.showModal !== "function") return;

    const gate = chat.querySelector("[data-chat-gate]");
    let returnFocus = trigger;
    trigger.hidden = false;
    trigger.addEventListener("click", () => {
        returnFocus = trigger;
        gate.hidden = true;
        chat.showModal();
        chat.querySelector(".chat-messages").scrollTop = 0;
        document.body.classList.add("chat-demo-open");
    });
    chat.querySelector("[data-chat-close]").addEventListener("click", () => chat.close());
    chat.addEventListener("click", (event) => {
        if (event.target !== chat) return;
        const bounds = chat.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right ||
            event.clientY < bounds.top || event.clientY > bounds.bottom) chat.close();
    });
    chat.addEventListener("close", () => {
        document.body.classList.remove("chat-demo-open");
        returnFocus.focus({preventScroll: true});
    });
    chat.querySelectorAll("[data-chat-use]").forEach((button) => {
        button.addEventListener("click", () => {
            gate.hidden = false;
            chat.querySelector("[data-chat-gate-title]").focus({preventScroll: true});
        });
    });
    chat.querySelector("[data-chat-download]").addEventListener("click", (event) => {
        event.preventDefault();
        const download = document.getElementById("download");
        const target = download.querySelector('a[data-channel]') || download;
        // Keep prelaunch availability honest when neither store is configured.
        download.tabIndex = -1;
        returnFocus = target;
        chat.close();
        target.focus({preventScroll: true});
        download.scrollIntoView({block: "center"});
    });
})();

(() => {
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
        ? download.querySelector('a[data-channel="play"]')
        : null;

    // Only a configured download for this phone can become the sticky action.
    if (!preferred) return;
    dock.querySelector("[data-dock-link]").href = preferred.href;

    const observer = new IntersectionObserver(([entry]) => {
        dock.hidden = entry.isIntersecting;
    });
    observer.observe(download);
})();
