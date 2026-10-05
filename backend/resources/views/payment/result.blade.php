@php($isPending = !$success && (bool) ($pending ?? false))
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>{{ $success ? 'تم الدفع' : ($isPending ? 'جار تأكيد الدفع' : 'لم يكتمل الدفع') }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            background: #0f1117;
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            padding-bottom: calc(24px + env(safe-area-inset-bottom));
        }

        .card {
            background: #1a1d27;
            border-radius: 24px;
            padding: 40px 32px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(0,0,0,0.4);
        }

        .icon-circle {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 44px;
        }

        .icon-circle.success {
            background: rgba(34, 197, 94, 0.15);
            border: 2px solid rgba(34, 197, 94, 0.4);
        }

        .icon-circle.failure {
            background: rgba(239, 68, 68, 0.15);
            border: 2px solid rgba(239, 68, 68, 0.4);
        }

        .icon-circle.pending {
            background: rgba(99, 102, 241, 0.15);
            border: 2px solid rgba(99, 102, 241, 0.4);
        }

        h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #fff;
        }

        .subtitle {
            font-size: 14px;
            color: #8b8fa8;
            margin-bottom: 28px;
            line-height: 1.6;
        }

        .coins-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.3);
            border-radius: 50px;
            padding: 10px 20px;
            margin-bottom: 28px;
            font-size: 18px;
            font-weight: 700;
            color: #fbbf24;
        }

        .details-block {
            background: #0f1117;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 28px;
            text-align: right;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            font-size: 13px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #8b8fa8;
        }

        .detail-value {
            color: #e2e8f0;
            font-weight: 500;
            direction: ltr;
            max-width: 60%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 16px;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }

        .btn:active {
            transform: scale(0.98);
            opacity: 0.9;
        }

        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            margin-bottom: 12px;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.06);
            color: #8b8fa8;
            font-size: 14px;
            padding: 12px;
        }

        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card {
            animation: fadeInUp 0.4s ease-out;
        }
    </style>
</head>
<body>
    <div class="card">
        @if($success)
            {{-- ======================== SUCCESS STATE ======================== --}}
            <div class="icon-circle success">✓</div>
            <h1>تمت عملية الدفع بنجاح!</h1>
            <p class="subtitle">
                تم شراء الباقة بنجاح وإضافة العملات إلى محفظتك
            </p>

            @if(isset($coins_credited) && $coins_credited > 0)
            <div class="coins-badge">
                🪙 {{ number_format($coins_credited) }} عملة
            </div>
            @endif

            <div class="details-block">
                @if(isset($transaction_id))
                <div class="detail-row">
                    <span class="detail-label">رقم المعاملة</span>
                    <span class="detail-value">{{ $transaction_id }}</span>
                </div>
                @endif
                @if(isset($order_ref))
                <div class="detail-row">
                    <span class="detail-label">رقم الطلب</span>
                    <span class="detail-value">{{ $order_ref }}</span>
                </div>
                @endif
                @if(isset($package) && $package)
                <div class="detail-row">
                    <span class="detail-label">الباقة</span>
                    <span class="detail-value">{{ $package->name_ar ?? $package->name_en }}</span>
                </div>
                @endif
            </div>

            <button class="btn btn-primary" id="returnBtn" onclick="returnToApp()">
                العودة إلى التطبيق
            </button>
            <button class="btn btn-secondary" onclick="retryDeepLink()">
                إعادة المحاولة
            </button>
        @elseif($isPending)
            <div class="icon-circle pending">…</div>
            <h1>جار تأكيد الدفع</h1>
            <p class="subtitle">{{ $message ?? 'سنحدّث الرصيد فور تأكيد العملية' }}</p>

            <button class="btn btn-primary" onclick="returnToApp()">
                العودة إلى التطبيق
            </button>
        @else
            {{-- ======================== FAILURE STATE ======================== --}}
            <div class="icon-circle failure">✕</div>
            <h1>فشلت عملية الدفع</h1>
            <p class="subtitle">
                {{ $message ?? 'حدث خطأ أثناء معالجة الدفع. يرجى المحاولة مرة أخرى.' }}
            </p>

            @if(isset($order_ref))
            <div class="details-block">
                <div class="detail-row">
                    <span class="detail-label">رقم الطلب</span>
                    <span class="detail-value">{{ $order_ref }}</span>
                </div>
            </div>
            @endif

            <button class="btn btn-primary" onclick="returnToApp()">
                العودة إلى التطبيق
            </button>
        @endif
    </div>

    <script>
        // -------------------------------------------------------
        // Payment result data (safe to embed — no sensitive info)
        // -------------------------------------------------------
        var PAYMENT_STATUS  = @json($success ? 'success' : ($isPending ? 'pending' : 'failed'));
        var ORDER_REF       = @json((string) ($order_ref ?? ''));
        var COINS_CREDITED  = @json((int) ($coins_credited ?? 0));
        var TRANSACTION_ID  = @json((string) ($transaction_id ?? ''));

        var deepLinkUrl = "rokn://payment-result"
            + "?status="         + PAYMENT_STATUS
            + "&order_ref="      + encodeURIComponent(ORDER_REF)
            + "&coins="          + COINS_CREDITED
            + "&transaction_id=" + encodeURIComponent(TRANSACTION_ID);

        var resultPayload = JSON.stringify({
            type:           "payment_result",
            status:         PAYMENT_STATUS,
            order_ref:      ORDER_REF,
            coins_credited: parseInt(COINS_CREDITED) || 0,
            transaction_id: TRANSACTION_ID
        });

        /**
         * Communicate the result back to the mobile app.
         * Tries three methods in order of compatibility:
         *   1. ReactNativeWebView.postMessage (React Native)
         *   2. window.webkit.messageHandlers (iOS WKWebView)
         *   3. window.location deep link (Android / fallback)
         */
        function notifyApp() {
            // React Native WebView
            if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
                window.ReactNativeWebView.postMessage(resultPayload);
                return true;
            }
            // iOS WKWebView
            if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.paymentResult) {
                window.webkit.messageHandlers.paymentResult.postMessage(resultPayload);
                return true;
            }
            return false;
        }

        function returnToApp() {
            // Try postMessage first, then deep link
            var sent = notifyApp();
            if (!sent) {
                window.top.location.href = deepLinkUrl;
            }
        }

        function retryDeepLink() {
            window.top.location.href = deepLinkUrl;
        }

        // Auto-notify on page load (after a brief delay for WebView to be ready)
        window.addEventListener('load', function () {
            setTimeout(function () {
                if (!notifyApp()) {
                    // Chrome may block automatic app links inside a hosted
                    // payment iframe. The explicit return button targets top.
                    if (window.top === window) window.location.replace(deepLinkUrl);
                }
            }, 300);
        });
    </script>
</body>
</html>
