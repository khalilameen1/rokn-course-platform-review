import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  Text,
  View,
} from 'react-native';
import {formatArabicNumber} from '../../../constants/arabicFormatting';
import type {CourseChatUpgradeQuote} from '../../../services/roknApi';
import {courseChatStyles as styles} from './styles';

export const CourseChatGate = ({
  accessUnavailable,
  courseAccessRequired,
  courseChatUnavailable,
  error,
  loading,
  onConfirm,
  onLoadQuote,
  onOpenCourseAccess,
  planLimitReached,
  quote,
  scholarshipAccess,
}: {
  accessUnavailable: boolean;
  courseAccessRequired: boolean;
  courseChatUnavailable: boolean;
  error: string;
  loading: boolean;
  onConfirm: () => void;
  onLoadQuote: () => void;
  onOpenCourseAccess: () => void;
  planLimitReached: boolean;
  quote: CourseChatUpgradeQuote | null;
  scholarshipAccess: boolean;
}) => (
  <ScrollView
    contentContainerStyle={styles.entitlementGate}
    keyboardShouldPersistTaps="handled"
    showsVerticalScrollIndicator={false}>
    <Text style={styles.entitlementTitle}>
      {accessUnavailable
        ? 'الاستفسارات غير متاحة الآن'
        : courseAccessRequired
        ? 'الاستفسارات ضمن فئات الكورس'
        : courseChatUnavailable
        ? 'هذا الكورس متاح مجانًا'
        : planLimitReached
        ? 'استخدمت مساحة الأسئلة في اختيارك الحالي'
        : scholarshipAccess
        ? 'الكورس كامل متاح بمنحتك'
        : 'الاستفسارات غير مشمولة في فئتك'}
    </Text>
    <Text style={styles.entitlementText}>
      {accessUnavailable
        ? 'أغلق الشات وحدّث الكورس قبل المحاولة مرة أخرى'
        : courseAccessRequired
        ? 'أنت تشاهد العينة الآن\nافتح تفاصيل الكورس واختر الفئة المناسبة'
        : courseChatUnavailable
        ? 'الاستفسارات غير متاحة في هذا الكورس'
        : planLimitReached
        ? 'تقدمك وإجاباتك محفوظة\nانتقل إلى الفئة التالية وادفع فرق السعر فقط'
        : scholarshipAccess
        ? 'محتوى الكورس متاح لك كاملًا\nيمكنك إضافة الاستفسارات أو الشهادة دون أن تخسر منحتك'
        : 'محتوى الكورس متاح لك\nراجع الفئات التي تشمل الاستفسارات'}
    </Text>
    {!accessUnavailable &&
      !courseAccessRequired &&
      !courseChatUnavailable &&
      quote && (
        <View style={styles.upgradeCard}>
          <View style={styles.upgradeRow}>
            <Text style={styles.upgradeLabel}>
              {quote.targetPlanName || 'إضافة الاستفسارات'}
            </Text>
            <Text style={styles.upgradeValue}>
              {formatArabicNumber(quote.price)} رصيد
            </Text>
          </View>
          <View style={styles.upgradeDivider} />
          <View style={styles.upgradeRow}>
            <Text style={styles.upgradeHint}>المتاح للترقية</Text>
            <Text style={styles.upgradeHintValue}>
              {formatArabicNumber(quote.spendableBalance)}
            </Text>
          </View>
          {quote.deficit > 0 && (
            <Text style={styles.upgradeDeficit}>
              ينقصك {formatArabicNumber(quote.deficit)} رصيد
            </Text>
          )}
          {!!quote.targetMessageLimit && (
            <Text style={styles.upgradeHint}>
              حتى {formatArabicNumber(quote.targetMessageLimit)} رسالة في هذا
              الكورس
            </Text>
          )}
        </View>
      )}
    {!accessUnavailable &&
      !courseAccessRequired &&
      !courseChatUnavailable &&
      !!error && (
        <Text accessibilityRole="alert" style={styles.upgradeError}>
          {error}
        </Text>
      )}
    {!accessUnavailable && !courseChatUnavailable && (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={
          courseAccessRequired ? 'عرض فئات الكورس' : 'عرض خيارات الاستفسارات'
        }
        disabled={!courseAccessRequired && loading}
        onPress={
          courseAccessRequired
            ? onOpenCourseAccess
            : quote
            ? onConfirm
            : onLoadQuote
        }
        style={({pressed}) => [
          styles.entitlementButton,
          pressed && styles.entitlementButtonPressed,
          !courseAccessRequired && loading && styles.entitlementButtonDisabled,
        ]}>
        {!courseAccessRequired && loading ? (
          <ActivityIndicator color="#FFFFFF" size="small" />
        ) : (
          <Text style={styles.entitlementButtonText}>
            {courseAccessRequired
              ? 'عرض فئات الكورس'
              : quote
              ? quote.deficit > 0
                ? 'اشحن الرصيد الناقص'
                : `انتقل إلى ${quote.targetPlanName || 'الاختيار التالي'}`
              : 'عرض الفئات'}
          </Text>
        )}
      </Pressable>
    )}
  </ScrollView>
);
