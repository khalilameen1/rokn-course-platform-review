(() => {
    // UI locks do not provide payment idempotency; the server-held order key does.
    document.querySelectorAll("[data-checkout-form]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            if (form.dataset.submitting) {
                event.preventDefault();
                return;
            }
            form.dataset.submitting = "true";
            form.querySelector("button").disabled = true;
        });
    });
    window.addEventListener("pageshow", () => {
        document
            .querySelectorAll("[data-checkout-form][data-submitting]")
            .forEach((form) => {
                delete form.dataset.submitting;
                form.querySelector("button").disabled = false;
            });
    });

    const receipt = document.querySelector("[data-payment-receipt]");
    if (!receipt) return;
    let polls = 0;
    let stopped = false;
    let running = false;
    const notice = receipt.querySelector("[data-payment-notice]");
    const readStatus = async (reconcile = false) => {
        if (stopped || running || document.hidden) return;
        running = true;
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 15000);
        try {
            const response = await fetch(
                reconcile
                    ? receipt.dataset.reconcileUrl
                    : receipt.dataset.statusUrl,
                {
                    method: reconcile ? "POST" : "GET",
                    credentials: "same-origin",
                    cache: "no-store",
                    signal: abort.signal,
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": receipt.dataset.csrf,
                    },
                }
            );
            if (!response.ok) {
                if ([401, 419].includes(response.status)) {
                    stopped = true;
                    window.location.replace(receipt.dataset.loginUrl);
                    return;
                }
                if (response.status === 404) stopped = true;
                throw new Error("Payment status unavailable");
            }
            const { data } = await response.json();
            if (
                data &&
                (data.status !== receipt.dataset.renderedStatus ||
                    data.financial_status !== receipt.dataset.renderedFinancial)
            ) {
                stopped = true;
                window.location.reload();
                return;
            }
            if (data?.checkout_state === "pending_provider") {
                const actions = receipt.querySelector(
                    "[data-unconfirmed-actions]"
                );
                if (actions) actions.hidden = true;
                notice.textContent =
                    "الدفع قيد التأكيد لا تحتاج إلى الدفع مرة أخرى";
            }
        } catch (_) {
            notice.textContent =
                "تعذّر تحديث الحالة الآن يمكنك تحديثها من الزر";
        } finally {
            clearTimeout(timeout);
            running = false;
        }
    };
    readStatus(receipt.dataset.poll === "true");
    const timer = setInterval(() => {
        if (stopped || polls >= 12 || receipt.dataset.poll !== "true") {
            clearInterval(timer);
            return;
        }
        if (!document.hidden) {
            polls += 1;
            readStatus();
        }
    }, 5000);
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden && !stopped) readStatus();
    });
    window.addEventListener("pageshow", (event) => {
        if (event.persisted) readStatus();
    });
})();
