import React, {ErrorInfo, ReactNode} from 'react';
import {Pressable, ScrollView, StyleSheet, Text, View} from 'react-native';
import {SafeAreaProvider, SafeAreaView} from 'react-native-safe-area-context';
import RNRestart from 'react-native-restart';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../../constants/designSystem';
import {reportClientError} from '../../services/operationalTelemetry';

type Props = {children: ReactNode};
type State = {hasError: boolean};

/**
 * A last-resort recovery surface for rendering faults. Operational crash
 * reporting can be attached in componentDidCatch without changing the UX.
 */
export default class AppErrorBoundary extends React.Component<Props, State> {
  state: State = {hasError: false};

  static getDerivedStateFromError(): State {
    return {hasError: true};
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    if (__DEV__) {
      console.error('Rokn render failure', error, info.componentStack);
    }
    reportClientError(error, {
      source: 'react_error_boundary',
      componentStack: info.componentStack,
      fatal: true,
    });
  }

  private retry = () => this.setState({hasError: false});

  render() {
    if (!this.state.hasError) {
      return this.props.children;
    }

    return (
      <SafeAreaProvider>
        <SafeAreaView style={styles.safeArea}>
          <ScrollView
            bounces={false}
            contentContainerStyle={styles.frame}
            contentInsetAdjustmentBehavior="automatic"
            showsVerticalScrollIndicator={false}>
            <View accessibilityLiveRegion="assertive" style={styles.copy}>
              <View style={styles.mark}>
                <Text style={styles.markText}>ر</Text>
              </View>
              <Text accessibilityRole="header" style={styles.title}>
                حدث توقف غير متوقع
              </Text>
              <Text style={styles.message}>
                حاول المتابعة
                {'\n'}أو أعد تشغيل ركن
              </Text>
            </View>
            <Pressable
              accessibilityRole="button"
              onPress={this.retry}
              style={({pressed}) => [
                styles.primary,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.primaryText}>حاول المتابعة</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={() => RNRestart.Restart()}
              style={({pressed}) => [
                styles.secondary,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.secondaryText}>إعادة تشغيل التطبيق</Text>
            </Pressable>
          </ScrollView>
        </SafeAreaView>
      </SafeAreaProvider>
    );
  }
}

const styles = StyleSheet.create({
  safeArea: {flex: 1, backgroundColor: Palette.canvas},
  frame: {
    flexGrow: 1,
    width: '100%',
    maxWidth: 560,
    alignSelf: 'center',
    justifyContent: 'center',
    alignItems: 'center',
    paddingHorizontal: Spacing.xl,
    paddingVertical: Spacing.xl,
  },
  copy: {width: '100%', alignItems: 'center'},
  mark: {
    width: 58,
    height: 58,
    borderRadius: Radius.lg,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primary,
    marginBottom: Spacing.lg,
  },
  markText: {...Type.section, color: Palette.text, fontSize: 28},
  title: {...Type.display, color: Palette.text, textAlign: 'center'},
  message: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.sm,
    marginBottom: Spacing.xl,
  },
  primary: {
    minHeight: Accessibility.minTouchTarget,
    width: '100%',
    maxWidth: 360,
    borderRadius: Radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primary,
    paddingHorizontal: Spacing.lg,
  },
  primaryText: {...Type.bodyStrong, color: Palette.text},
  secondary: {
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.lg,
    marginTop: Spacing.sm,
  },
  secondaryText: {...Type.bodyStrong, color: Palette.textMuted},
  pressed: {opacity: 0.76, transform: [{scale: 0.99}]},
});
