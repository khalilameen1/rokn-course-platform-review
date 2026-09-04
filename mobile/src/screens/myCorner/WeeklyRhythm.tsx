import React from 'react';
import {Text, View} from 'react-native';
import {PremiumCard, SectionHeading} from '../../components/ui/PremiumUI';
import StreakFlame from '../../components/ui/StreakFlame';
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
    <SectionHeading style={styles.section} title="إيقاع هذا الأسبوع" />
    <PremiumCard style={styles.rhythmCard}>
      <View style={styles.streakTop}>
        <View style={styles.streakIcon}>
          <StreakFlame size={38} />
        </View>
        <View style={styles.streakCopy}>
          <Text style={styles.streakTitle}>
            {currentStreak > 0
              ? `${formatArabicNumber(currentStreak)} ${
                  currentStreak === 1 ? 'يوم' : 'أيام'
                } متتالية`
              : 'ابدأ سلسلتك اليوم'}
          </Text>
          <Text style={styles.streakHint}>إكمال مقطع يحسب يوم تعلم</Text>
        </View>
      </View>
      <View style={styles.weekRow}>
        {week.map(item => (
          <View key={item.key} style={styles.day}>
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
              } أيام من آخر ٧ أيام\nمقطع واحد اليوم يحافظ على إيقاعك`
            : 'ابدأ أول مقطع اليوم\nواستمر بإيقاع يناسب يومك',
        )}
      </Text>
    </PremiumCard>
  </>
);
