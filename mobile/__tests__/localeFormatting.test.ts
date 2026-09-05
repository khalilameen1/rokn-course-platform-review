import {
  formatArabicCount,
  formatArabicCurrency,
  formatArabicDisplayText,
  formatArabicMinutes,
  formatArabicRatings,
  formatArabicStudents,
  formatAuthoredDisplayText,
} from '../src/constants/arabicFormatting';
import {formatRoknDate, formatRoknRelativeDate} from '../src/utils/dateTime';
import {cleanUnicodeText} from '../src/utils/unicodeText';

describe('learner locale formatting', () => {
  afterEach(() => jest.useRealTimers());

  it.each([
    'ريلز 2026',
    'const label = "ريلز";\nconst limit = 3;',
    'اكتب print(3) ثم اختر "ريلز"',
    'صور ريلز 3.png',
    'افتح Blender Studio 4 من https://rokn.app/course/52.',
  ])(
    'preserves authored content without localizing its words or numbers: %s',
    source => {
      const formatted = formatAuthoredDisplayText(source);
      expect(cleanUnicodeText(formatted)).toBe(source);
      expect(formatAuthoredDisplayText(formatted)).toBe(formatted);
    },
  );

  it('keeps authored Latin phrases together without changing neighbouring values', () => {
    expect(formatAuthoredDisplayText('شرح Grease Pencil في ريلز 3')).toBe(
      'شرح \u2068Grease Pencil\u2069 في ريلز 3',
    );
    expect(formatAuthoredDisplayText('زر https://rokn.app.')).toBe(
      'زر \u2068https://rokn.app\u2069.',
    );
  });

  it('keeps copied machine tokens ASCII and isolates them inside Arabic copy', () => {
    const value = formatArabicDisplayText(
      'افتح https://rokn.app/course/52 واستخدم 2zm_64 ثم شاهد 30 ريلز',
    );

    expect(value).toContain('\u2068https://rokn.app/course/52\u2069');
    expect(value).toContain('\u20682zm_64\u2069');
    expect(value).toContain('٣٠ مقطع');
    expect(formatArabicDisplayText('زر https://rokn.app.')).toBe(
      'زر \u2068https://rokn.app\u2069.',
    );
    expect(
      formatArabicDisplayText(
        'راسل learner52@example.com أو اتصل +20 100 123 4567 واستخدم ID_52',
      ),
    ).toBe(
      'راسل \u2068learner52@example.com\u2069 أو اتصل \u2068+20 100 123 4567\u2069 واستخدم \u2068ID_52\u2069',
    );
  });

  it('removes imported bidi controls before adding bounded isolates', () => {
    expect(
      formatArabicDisplayText(
        '\u200E\u202AABC 52\u202C عنوان\u2066 https://rokn.app\u2069',
      ),
    ).toBe('\u2068ABC\u2069 ٥٢ عنوان \u2068https://rokn.app\u2069');
  });

  it.each(['Grease Pencil', 'Blender Studio', 'CC BY'])(
    'keeps the contiguous Latin phrase %s in authored order',
    phrase => {
      expect(formatArabicDisplayText(`شرح ${phrase} بالعربي`)).toBe(
        `شرح \u2068${phrase}\u2069 بالعربي`,
      );
    },
  );

  it('isolates mixed phrases, URLs and codes once without merging Arabic or numbers', () => {
    const source =
      'افتح Blender Studio عبر https://rokn.app/course/52 ثم استخدم CC BY والكود 2zm_64 في 30 مقطع';
    const formatted = formatArabicDisplayText(source);

    expect(formatted).toBe(
      'افتح \u2068Blender Studio\u2069 عبر \u2068https://rokn.app/course/52\u2069 ثم استخدم \u2068CC BY\u2069 والكود \u20682zm_64\u2069 في ٣٠ مقطع',
    );
    expect(formatArabicDisplayText(formatted)).toBe(formatted);
    expect(formatArabicDisplayText('رمز ABC 52')).toBe(
      'رمز \u2068ABC\u2069 ٥٢',
    );
  });

  it('uses Arabic count forms instead of an English singular rule', () => {
    expect(formatArabicMinutes(1)).toBe('دقيقة');
    expect(formatArabicMinutes(2)).toBe('دقيقتان');
    expect(formatArabicMinutes(8)).toBe('٨ دقائق');
    expect(formatArabicMinutes(62)).toBe('٦٢ دقيقة');
    expect(formatArabicStudents(2)).toBe('طالبان');
    expect(formatArabicStudents(11)).toBe('١١ طالبًا');
    expect(formatArabicRatings(4)).toBe('٤ تقييمات');
    expect(
      formatArabicCount(2, {
        one: 'يوم',
        two: 'يومان',
        few: 'أيام',
        many: 'يومًا',
        other: 'يوم',
      }),
    ).toBe('يومان');
  });

  it('formats supported currency without exposing an ambiguous machine code', () => {
    expect(formatArabicCurrency(25, 'EGP')).toBe('٢٥ جنيه');
    expect(formatArabicCurrency(2.5, 'USD')).toBe('٢٫٥ دولار');
  });

  it('renders instants on the Cairo Gregorian calendar', () => {
    expect(formatRoknDate('2026-08-31T22:30:00Z')).toContain('سبتمبر');
  });

  it('uses the correct Arabic dual form for a recent event', () => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-09-01T12:00:00Z'));
    expect(formatRoknRelativeDate('2026-09-01T11:58:00Z')).toBe('منذ دقيقتين');
  });
});
