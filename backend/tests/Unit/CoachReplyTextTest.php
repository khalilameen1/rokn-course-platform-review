<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CoachReplyText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoachReplyTextTest extends TestCase
{
    /** Each case is a before/after pair a learner would actually see. */
    public static function replies(): array
    {
        return [
            'sentence ends become short lines' => [
                'الريندر هو تحويل المشهد لصورة نهائية. بيتعمل بعد ما تخلص الإضاءة.',
                "الريندر هو تحويل المشهد لصورة نهائية\nبيتعمل بعد ما تخلص الإضاءة",
            ],
            'arabic commas and semicolons are dropped' => [
                'ابدأ بخط واحد، وغيّر الحجم والوزن؛ وبعدين جرّب',
                'ابدأ بخط واحد وغيّر الحجم والوزن وبعدين جرّب',
            ],
            'markdown and bullets become plain lines' => [
                "## الخطوات\n- **افتح** الملف\n- صدّر بصيغة PNG\n1. ارفعه",
                "الخطوات\nافتح الملف\nصدّر بصيغة PNG\nارفعه",
            ],
            'arabic colon and spaced dash are dropped' => [
                'النتيجة: الصورة هتبقى أوضح — وكمان أخف',
                'النتيجة الصورة هتبقى أوضح وكمان أخف',
            ],
            'greeting line and help offer are removed' => [
                "سؤال ممتاز!\nالكروب هو قص الصورة\nلو محتاج مساعدة تانية قولي",
                'الكروب هو قص الصورة',
            ],
            'a lone greeting stays when it is the whole reply' => [
                'أهلا بيك',
                'أهلا بيك',
            ],
            'a substantive line that mentions help is kept' => [
                "ابدأ بالإضاءة\nالفلتر ده هيساعدك تخلي الألوان أدفى لما تصور بالليل وكمان يقلل النويز في الضل",
                "ابدأ بالإضاءة\nالفلتر ده هيساعدك تخلي الألوان أدفى لما تصور بالليل وكمان يقلل النويز في الضل",
            ],
            'question and exclamation marks are kept' => [
                'تقصد قص الصورة ولا تجميع العناصر؟',
                'تقصد قص الصورة ولا تجميع العناصر؟',
            ],
            'single letter abbreviation is not split' => [
                'د. أحمد شرح الجزء ده',
                'د. أحمد شرح الجزء ده',
            ],
            'english prose keeps its punctuation' => [
                'Use one font, then vary size. That keeps hierarchy clear.',
                'Use one font, then vary size. That keeps hierarchy clear.',
            ],
            'decimals times links code and file names are untouched' => [
                "خليه 1.5 ثانية الساعة 10:30\nشوف https://rokn.app/help, والملف logo.png\n`a, b; c.`",
                "خليه 1.5 ثانية الساعة 10:30\nشوف https://rokn.app/help, والملف logo.png\n`a, b; c.`",
            ],
            'fenced code keeps every character' => [
                "اكتب كده.\n```css\n.title { margin: 0; }\n```",
                "اكتب كده\n```css\n.title { margin: 0; }\n```",
            ],
            'unclosed fence mid-stream stays protected' => [
                "اكتب كده.\n```js\nconst a = 1;",
                "اكتب كده\n```js\nconst a = 1;",
            ],
            'arabic-indic digits become 0-9 outside code' => [
                "خليه ١٤ على الأقل وجرب ۲ مقاسات\n`size: ١٤`",
                "خليه 14 على الأقل وجرب 2 مقاسات\n`size: ١٤`",
            ],
            'extra blank lines collapse to one' => [
                "فكرة أولى\n\n\n\nفكرة تانية",
                "فكرة أولى\n\nفكرة تانية",
            ],
        ];
    }

    #[DataProvider('replies')]
    public function test_reply_reads_like_a_coach_message(string $before, string $after): void
    {
        self::assertSame($after, CoachReplyText::format($before));
    }

    #[DataProvider('replies')]
    public function test_formatting_twice_changes_nothing(string $before): void
    {
        $once = CoachReplyText::format($before);

        self::assertSame($once, CoachReplyText::format($once));
    }

    public function test_reply_that_would_become_empty_is_kept_as_written(): void
    {
        self::assertSame('...', CoachReplyText::format('...'));
        self::assertSame('', CoachReplyText::format("  \n "));
    }
}
