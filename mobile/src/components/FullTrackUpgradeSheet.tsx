import React, {useEffect, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Modal,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  getCourseDetails,
  getFullTrackUpgradeQuote,
  type CourseAccessPlan,
} from '../services/roknApi';
import {
  captureAccountSessionBoundary,
  assertAccountSessionBoundary,
} from '../constants/helpers';
import {Palette, Type, textDirection} from '../constants/designSystem';
import CourseSubscriptionSheet from './CourseSubscriptionSheet';

type Props = {
  visible: boolean;
  courseId: string;
  courseTitle: string;
  completed?: boolean;
  onClose: () => void;
  onUpgraded?: () => void | Promise<void>;
  embedded?: boolean;
};
const rank: Record<string, number> = {basic: 0, guided: 1, mentor: 2};

export default function FullTrackUpgradeSheet({
  visible,
  courseId,
  courseTitle,
  onClose,
  onUpgraded,
  embedded = false,
}: Props) {
  const [plans, setPlans] = useState<CourseAccessPlan[]>([]);
  const [selected, setSelected] = useState('');
  const [hasProjects, setHasProjects] = useState(false);
  const [courseRevision, setCourseRevision] = useState<number>();
  const [error, setError] = useState('');
  const [reload, setReload] = useState(0);
  const generation = useRef(0);
  const callbacks = useRef({onClose, onUpgraded});
  callbacks.current = {onClose, onUpgraded};
  useEffect(() => {
    const token = ++generation.current;
    setPlans([]);
    setError('');
    if (!visible) return;
    void (async () => {
      try {
        const boundary = await captureAccountSessionBoundary();
        const [course, upgrade] = await Promise.all([
          getCourseDetails(courseId),
          getFullTrackUpgradeQuote(courseId),
        ]);
        assertAccountSessionBoundary(boundary);
        if (token !== generation.current) return;
        if (upgrade.alreadyUpgraded) {
          await callbacks.current.onUpgraded?.();
          callbacks.current.onClose();
          return;
        }
        const minimum = rank[upgrade.targetPlanCode || ''];
        const available = course.accessPlans.filter(
          plan => minimum !== undefined && rank[plan.code] >= minimum,
        );
        if (!available.length) throw new Error('UPGRADE_UNAVAILABLE');
        setPlans(available);
        setSelected(upgrade.targetPlanCode || available[0].code);
        setHasProjects(course.projectCount > 0);
        setCourseRevision(course.publishedRevision);
      } catch {
        if (token === generation.current) setError('تعذّر تجهيز الترقية');
      }
    })();
    return () => {
      generation.current += 1;
    };
  }, [courseId, reload, visible]);
  if (plans.length)
    return (
      <CourseSubscriptionSheet
        visible={visible}
        courseId={courseId}
        courseTitle={courseTitle}
        courseRevision={courseRevision}
        plans={plans}
        selectedPlan={plans.find(plan => plan.code === selected)}
        onSelectPlan={plan => setSelected(plan.code)}
        onClose={onClose}
        onCompleted={async () => {
          await callbacks.current.onUpgraded?.();
          callbacks.current.onClose();
        }}
        hasProjects={hasProjects}
        mode="upgrade"
        embedded={embedded}
      />
    );
  const loading = (
    <View style={styles.loading}>
      {error ? (
        <>
          <Text accessibilityRole="alert" style={styles.error}>
            {error}
          </Text>
          <Pressable
            accessibilityRole="button"
            onPress={() => setReload(value => value + 1)}
            style={styles.button}>
            <Text style={styles.error}>إعادة المحاولة</Text>
          </Pressable>
        </>
      ) : (
        <ActivityIndicator color={Palette.primary} />
      )}
      {!embedded && (
        <Pressable
          accessibilityRole="button"
          onPress={onClose}
          style={styles.button}>
          <Text style={styles.error}>إغلاق</Text>
        </Pressable>
      )}
    </View>
  );
  return embedded ? (
    loading
  ) : (
    <Modal visible={visible} transparent onRequestClose={onClose}>
      <View style={styles.root}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إغلاق"
          onPress={onClose}
          style={styles.backdrop}
        />
        {loading}
      </View>
    </Modal>
  );
}
const styles = StyleSheet.create({
  root: {flex: 1, justifyContent: 'flex-end'},
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: Palette.overlay,
  },
  loading: {
    padding: 24,
    backgroundColor: Palette.surface,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 160,
  },
  error: {...Type.body, ...textDirection, color: Palette.text},
  button: {minHeight: 48, alignItems: 'center', justifyContent: 'center'},
});
