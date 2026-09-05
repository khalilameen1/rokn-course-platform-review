import {
  formatArabicCount,
  formatArabicCurrency,
  formatArabicDisplayText,
  formatArabicMinutes,
  formatArabicRatings,
  formatArabicStudents,
} from '../src/constants/arabicFormatting';
import {formatRoknDate, formatRoknRelativeDate} from '../src/utils/dateTime';

describe('learner locale formatting', () => {
  afterEach(() => jest.useRealTimers());

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
  });

  it('removes imported bidi controls before adding bounded isolates', () => {
    expect(
      formatArabicDisplayText(
        '\u200E\u202AABC 52\u202C عنوان\u2066 https://rokn.app\u2069',
      ),
    ).toBe(
      '\u2068ABC\u2069 ٥٢ عنوان \u2068https://rokn.app\u2069',
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
    expect(formatRoknRelativeDate('2026-09-01T11:58:00Z')).toBe(
      'منذ دقيقتين',
    );
  });
});
