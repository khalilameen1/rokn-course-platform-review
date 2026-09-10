import React from 'react';
import {Text, View} from 'react-native';
import {SectionHeading} from '../../components/ui/PremiumUI';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../constants/arabicFormatting';
import {styles} from './styles';

type WeekDay = {key: string; day: string; complete: boolean};

export const WeeklyRhythm = ({
  activityDays,
  currentStreak,
  week,
}: {
  activityDays: string[];
  currentStreak: number;
  week: WeekDay[];
}) => (
  <>
    <SectionHeading style={styles.section} title="نشاطك هذا الأسبوع" />
    <View style={styles.rhythmCard}>
      <View style={styles.streakTop}>
        <View style={styles.streakCopy}>
          <Text style={styles.streakTitle}>
            {currentStreak > 0
              ? `${formatArabicNumber(currentStreak)} ${
                  currentStreak === 1 ? 'يوم' : 'أيام'
                } متتالية`
              : 'لم تبدأ سلسلة متتالية بعد'}
          </Text>
          <Text style={styles.streakHint}>إكمال مقطع يحسب يوم تعلم</Text>
        </View>
      </View>
      <View style={styles.weekRow}>
        {week.map(item => (
          <View
            accessible
            accessibilityLabel={`${item.day}، ${
              item.complete ? 'يوم تعلّم مكتمل' : 'لا يوجد إكمال مسجل'
            }`}
            key={item.key}
            style={styles.day}>
            <View style={[styles.dayMark, item.complete && styles.dayComplete]}>
              <Text
                style={[
                  styles.dayMarkText,
                  item.complete && styles.dayMarkTextComplete,
                ]}>
                {item.complete ? '✓' : ''}
              </Text>
            </View>
            <Text style={styles.dayLabel}>{item.day}</Text>
          </View>
        ))}
      </View>
      <Text style={styles.rhythmText}>
        {formatArabicDisplayText(
          activityDays.length
            ? `تعلمت في ${
                week.filter(item => item.complete).length
              } أيام من آخر ٧ أيام`
            : 'لا توجد أيام تعلّم مسجلة بعد',
        )}
      </Text>
    </View>
  </>
);
