import {cleanUnicodeText} from '../utils/unicodeText';

const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'] as const;

type VisibleValue = string | number | bigint | null | undefined;

const FIRST_STRONG_ISOLATE = '\u2068';
const POP_DIRECTIONAL_ISOLATE = '\u2069';

// Machine tokens remain ASCII so copying a URL, email, phone number or code
// yields the original value. Isolates keep them visually intact inside RTL.
const MIXED_DIRECTION_SEGMENT =
  /(\u2068[^\u2069]*\u2069|(?:https?:\/\/|www\.)[^\s<>{}[\]()،؛!?,;]+|[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[A-Za-z]{2,}|\+?\d(?:[\d\s()-]{5,}\d)|[A-Za-z0-9._:/@+-]*[A-Za-z][A-Za-z0-9._:/@+-]*(?:[ \t]+[A-Za-z0-9._:/@+-]*[A-Za-z][A-Za-z0-9._:/@+-]*)*)/giu;
const IS_MIXED_DIRECTION_SEGMENT =
  /^(?:\u2068[^\u2069]*\u2069|(?:https?:\/\/|www\.)[^\s<>{}[\]()،؛!?,;]+|[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[A-Za-z]{2,}|\+?\d(?:[\d\s()-]{5,}\d)|[A-Za-z0-9._:/@+-]*[A-Za-z][A-Za-z0-9._:/@+-]*(?:[ \t]+[A-Za-z0-9._:/@+-]*[A-Za-z][A-Za-z0-9._:/@+-]*)*)$/iu;

/** Keeps a visible URL, code or mixed identifier intact inside Arabic copy. */
export const isolateBidirectionalText = (value: VisibleValue): string => {
  if (value === null || value === undefined || value === '') return '';
  return `${FIRST_STRONG_ISOLATE}${String(value)}${POP_DIRECTIONAL_ISOLATE}`;
};

const LEARNING_TERM_REPLACEMENTS: ReadonlyArray<readonly [RegExp, string]> = [
  [/(^|[^\u0621-\u064A])الريلز(?=$|[^\u0621-\u064A])/g, '$1المقاطع'],
  [/(^|[^\u0621-\u064A])ريلز(?=$|[^\u0621-\u064A])/g, '$1مقاطع'],
  [/(^|[^\u0621-\u064A])الريلات(?=$|[^\u0621-\u064A])/g, '$1المقاطع'],
  [/(^|[^\u0621-\u064A])ريلات(?=$|[^\u0621-\u064A])/g, '$1مقاطع'],
  [/(^|[^\u0621-\u064A])الريلين(?=$|[^\u0621-\u064A])/g, '$1المقطعين'],
  [/(^|[^\u0621-\u064A])ريلين(?=$|[^\u0621-\u064A])/g, '$1مقطعين'],
  [/(^|[^\u0621-\u064A])الريل(?=$|[^\u0621-\u064A])/g, '$1المقطع'],
  [/(^|[^\u0621-\u064A])ريلًا(?=$|[^\u0621-\u064A])/g, '$1مقطعًا'],
  [/(^|[^\u0621-\u064A])ريلاً(?=$|[^\u0621-\u064A])/g, '$1مقطعًا'],
  [/(^|[^\u0621-\u064A])ريل(?=$|[^\u0621-\u064A])/g, '$1مقطع'],
];

/** Converts only visible Latin digits; IDs, URLs and API payloads stay untouched. */
export const toArabicDigits = (value: VisibleValue): string => {
  if (value === null || value === undefined) return '';
  return String(value).replace(
    /[0-9]/g,
    digit => ARABIC_INDIC_DIGITS[Number(digit)] ?? digit,
  );
};

const isolateDisplaySegment = (part: string): string => {
  if (
    part.startsWith(FIRST_STRONG_ISOLATE) &&
    part.endsWith(POP_DIRECTIONAL_ISOLATE)
  ) {
    return part;
  }
  const urlTrailingStop = /^(.*?)(\.+)$/.exec(part);
  return urlTrailingStop && /^(?:https?:\/\/|www\.)/i.test(part)
    ? `${isolateBidirectionalText(urlTrailingStop[1])}${urlTrailingStop[2]}`
    : isolateBidirectionalText(part);
};

/** Preserve authored words and numbers; only normalize text and its RTL boundaries. */
export const formatAuthoredDisplayText = (value: VisibleValue): string =>
  cleanUnicodeText(value)
    .split(MIXED_DIRECTION_SEGMENT)
    .map(part =>
      IS_MIXED_DIRECTION_SEGMENT.test(part) ? isolateDisplaySegment(part) : part,
    )
    .join('');

/** Localizes learner-facing copy without touching model, route or API names. */
export const formatArabicDisplayText = (value: VisibleValue): string => {
  // Text authored outside the app can contain invisible bidi controls that
  // override the direction of one title while neighbouring titles look fine.
  // Remove imported controls first, then add our own bounded isolates below.
  return cleanUnicodeText(value)
    .split(MIXED_DIRECTION_SEGMENT)
    .map(part => {
      if (IS_MIXED_DIRECTION_SEGMENT.test(part)) {
        return isolateDisplaySegment(part);
      }
      const counted = part.replace(
        /(\d+)\s+(?:الريلز|ريلز|الريلات|ريلات)(?=$|[^\u0621-\u064A])/g,
        (_, rawCount: string) => {
          const count = Number(rawCount);
          const modulo = count % 100;
          const noun =
            count === 1
              ? 'مقطع'
              : count === 2
                ? 'مقطعان'
                : modulo >= 3 && modulo <= 10
                  ? 'مقاطع'
                  : 'مقطع';
          return `${rawCount} ${noun}`;
        },
      );
      const localized = LEARNING_TERM_REPLACEMENTS.reduce(
        (text, [pattern, replacement]) => text.replace(pattern, replacement),
        counted,
      );
      return toArabicDigits(localized);
    })
    .join('');
};

/** Formats a finite number with Arabic digits and Arabic decimal/group separators. */
export const formatArabicNumber = (
  value: number,
  options: Intl.NumberFormatOptions = {},
): string => {
  if (!Number.isFinite(value)) return '';

  try {
    return toArabicDigits(
      new Intl.NumberFormat('ar-EG-u-nu-arab', options).format(value),
    )
      .replace(/,/g, '٬')
      .replace(/\./g, '٫');
  } catch {
    return toArabicDigits(String(value)).replace('.', '٫');
  }
};

export type ArabicCountForms = Readonly<{
  zero?: string;
  one: string;
  two: string;
  few: string;
  many: string;
  other: string;
}>;

/** A short Arabic count phrase without English-style singular/plural rules. */
export const formatArabicCount = (
  value: number,
  forms: ArabicCountForms,
): string => {
  if (!Number.isFinite(value)) return '';
  const count = Math.max(0, Math.trunc(value));
  const modulo100 = count % 100;
  if (count === 0 && forms.zero) return forms.zero;
  if (count === 1) return forms.one;
  if (count === 2) return forms.two;
  const noun =
    modulo100 >= 3 && modulo100 <= 10
      ? forms.few
      : modulo100 >= 11 && modulo100 <= 99
        ? forms.many
        : forms.other;
  return `${formatArabicNumber(count)} ${noun}`;
};

export const formatArabicMinutes = (value: number): string =>
  formatArabicCount(value, {
    zero: 'أقل من دقيقة',
    one: 'دقيقة',
    two: 'دقيقتان',
    few: 'دقائق',
    many: 'دقيقة',
    other: 'دقيقة',
  });

export const formatArabicStudents = (value: number): string =>
  formatArabicCount(value, {
    zero: 'لا طلاب',
    one: 'طالب',
    two: 'طالبان',
    few: 'طلاب',
    many: 'طالبًا',
    other: 'طالب',
  });

export const formatArabicRatings = (value: number): string =>
  formatArabicCount(value, {
    zero: 'لا تقييمات',
    one: 'تقييم',
    two: 'تقييمان',
    few: 'تقييمات',
    many: 'تقييمًا',
    other: 'تقييم',
  });

export const formatRoknCoins = (value: number): string =>
  formatArabicCount(value, {
    zero: 'لا عملات ركن',
    one: 'عملة ركن',
    two: 'عملتا ركن',
    few: 'عملات ركن',
    many: 'عملة ركن',
    other: 'عملة ركن',
  });

const CURRENCY_LABELS: Readonly<Record<string, string>> = {
  EGP: 'جنيه',
  USD: 'دولار',
  EUR: 'يورو',
  AED: 'درهم إماراتي',
  SAR: 'ريال سعودي',
  GBP: 'جنيه إسترليني',
  KWD: 'دينار كويتي',
};

/** Keeps API money numeric while producing one consistent learner label. */
export const formatArabicCurrency = (
  value: number,
  currency = 'EGP',
): string => {
  if (!Number.isFinite(value)) return '';
  const code = String(currency || 'EGP').trim().toUpperCase();
  const label = CURRENCY_LABELS[code] || isolateBidirectionalText(code);
  return `${formatArabicNumber(value, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })} ${label}`;
};
