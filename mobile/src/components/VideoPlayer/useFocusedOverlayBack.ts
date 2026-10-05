import {useFocusEffect} from '@react-navigation/native';
import {useCallback} from 'react';
import {BackHandler} from 'react-native';

// React Navigation's focus-scoped Android back recipe, adapted only to the
// player's existing overlay owners. Returning false preserves normal routing.
// https://reactnavigation.org/docs/custom-android-back-button-handling/
export function useFocusedOverlayBack(onBackPress: () => boolean) {
  useFocusEffect(
    useCallback(() => {
      const subscription = BackHandler.addEventListener(
        'hardwareBackPress',
        onBackPress,
      );
      return () => subscription.remove();
    }, [onBackPress]),
  );
}
