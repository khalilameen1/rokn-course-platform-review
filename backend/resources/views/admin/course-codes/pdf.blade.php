<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تصدير الأكواد</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            margin: 15px;
            direction: rtl;
            text-align: right;
            font-size: 12px;
            background: #f8f9fa;
        }

        .header {
            background: linear-gradient(135deg, #2563eb 0%, #172554 100%);
            color: white;
            padding: 25px;
            text-align: center;
            margin-bottom: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .header h1 {
            font-size: 24px;
            margin: 0 0 10px 0;
            font-weight: bold;
        }

        .header .subtitle {
            font-size: 16px;
            opacity: 0.95;
            margin: 5px 0;
        }

        .codes-container {
            width: 100%;
            overflow: hidden;
        }

        .code-row {
            margin-bottom: 15px;
            overflow: hidden;
            width: 100%;
        }

        .code-card {
            width: 42%;
            border: 2px solid #2563eb;
            padding: 15px;
            background: white;
            height: 20%;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            float: right;
            margin-left: 2%;
            margin-bottom: 15px;
            margin-right: 2%;
            page-break-inside: avoid;
        }

        .code-card:nth-child(2n+1) {
            margin-left: 0;
            clear: right;
        }

        .code-name {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 8px;
            color: #333;
            text-align: center;
        }

        .code-display {
            font-size: 18px;
            font-weight: bold;
            border: 2px dashed #2563eb;
            padding: 10px;
            margin: 10px 0;
            text-align: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 8px;
            color: #2563eb;
        }

        .target-content {
            font-size: 11px;
            color: #666;
            margin: 5px 0;
            text-align: center;
        }

        .platform-name {
            font-weight: bold;
            font-size: 16px;
            color: #2563eb;
            text-align: center;
            margin: 8px 0;
        }

        .code-info {
            display: flex;
            justify-content: space-around;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #e0e0e0;
        }

        .code-type, .max-uses {
            font-size: 9px;
            color: #888;
            text-align: center;
        }

        .grant-label {
            color: #147a49;
            font-weight: 700;
            margin: 4px 0;
        }

        .codes-clear {
            clear: both;
        }

        .empty-export {
            text-align: center;
            padding: 100px 50px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .empty-export-icon {
            font-size: 64px;
            color: #ddd;
            margin-bottom: 20px;
        }

        .empty-export-title {
            color: #999;
        }

        @media print {
            body {
                background: white;
                margin: 10px;
            }
            .header {
                background: #2563eb;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎓 تصدير أكواد الدورات</h1>
        <div class="subtitle">{{ $platform_name }}</div>
        <div class="subtitle">تاريخ التصدير: {{ $export_date }} | إجمالي الأكواد: {{ $total_codes }}</div>
    </div>

    @if($course_codes->count() > 0)
        <div class="codes-container">
            @foreach($course_codes as $code)
                <div class="code-card">
                    <div class="code-name">{{ $code->name }}</div>
                    <div class="target-content">{{ $code->target_content_name }}</div>
                    @if(!empty($code->is_grant))
                        <div class="grant-label">منحة مشاهدة الكورس بدون شات أو مشاريع أو شهادة</div>
                    @endif
                    <div class="code-display">{{ $code->code }}</div>
                    <div class="platform-name">{{ $platform_name }}</div>
                    <div class="code-info">
                        <div class="code-type">
                            النوع: {{ $code->type == 'course' ? 'دورة' : ($code->type == 'lesson' ? 'درس' : 'دروس متعددة') }}
                        </div>
                        <div class="max-uses">
                            الاستخدامات: {{ $code->max_uses }}
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="codes-clear"></div>
        </div>
    @else
        <div class="empty-export">
            <div class="empty-export-icon">📭</div>
            <h3 class="empty-export-title">لا توجد أكواد للتصدير</h3>
        </div>
    @endif

</body>
</html>
