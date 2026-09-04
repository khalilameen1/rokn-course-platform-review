// import {faArrowLeft, faArrowRight} from '@fortawesome/free-solid-svg-icons';
// import {FontAwesomeIcon} from '@fortawesome/react-native-fontawesome';
import {useNavigation} from '@react-navigation/native';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import type {RootNavigation} from '../../navigation/types';
import React, {FC, useState} from 'react';
import {useTranslation} from 'react-i18next';
import {
  Pressable,
  StyleProp,
  StyleSheet,
  Text,
  TextStyle,
  TouchableOpacity,
  View,
  ViewStyle,
} from 'react-native';
import {
  Colors,
  PixelPerfect,
  SharedStyles,
} from '../../constants/styleConstants';
import {ArrowRight, SearchIcon} from '../../assets/SVG';
import Input from '../forms/Input';
import {
  fixedIconSlot,
  flexibleTextColumn,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';

interface IHeader {
  title?: string;
  leftContent?: () => React.ReactNode;
  rightContent?: () => React.ReactNode;
  inputSearchValue?: (value: string) => void;
  hasArrow?: boolean;
  hasSearchInput?: boolean;
  hideLeftContent?: boolean;
  style?: StyleProp<ViewStyle>;
  styleCircle?: StyleProp<ViewStyle>;
  styleTitle?: StyleProp<TextStyle>;
  hasHorizontalSpace?: boolean;
  onPress?: () => void;
}

const HeaderWithBack: FC<IHeader> = ({
  title,
  leftContent,
  rightContent,
  hasArrow = true,
  style,
  styleTitle,
  styleCircle,
  inputSearchValue,
  hasHorizontalSpace,
  hasSearchInput = false,
  hideLeftContent = false,
  onPress,
}) => {
  const navigation = useNavigation<RootNavigation>();
  const {contentWidth, gutter, largeText} = useResponsiveLayout();

  const {t} = useTranslation();
  const [state, setState] = useState({
    inputSearch: '',
  });
  return (
    <View
      style={[
        styles.container,
        {maxWidth: contentWidth},
        hasHorizontalSpace && {paddingHorizontal: gutter},
        style,
      ]}>
      {hasArrow ? (
        <Pressable
          accessibilityLabel={t('Back')}
          accessibilityRole="button"
          hitSlop={6}
          onPress={() => {
            onPress ? onPress() : goBackOrHome(navigation);
          }}
          style={[styles.backButton, styleCircle]}>
          <ArrowRight />
        </Pressable>
      ) : (
        <View style={styles.headerSlot}>
          {rightContent ? rightContent() : null}
        </View>
      )}
      {hasSearchInput ? (
        <View style={styles.searchWrap}>
          <Input
            options={{
              placeholder: t('Search'),
              value: state.inputSearch,
              onChangeText(text) {
                setState(s => ({...s, inputSearch: text}));
                inputSearchValue?.(text);
              },
              onSubmitEditing(_event) {
                inputSearchValue?.(state.inputSearch);
              },
            }}
            styleCon={styles.inputCont}
            rightContent={() => (
              <TouchableOpacity
                accessibilityLabel={t('Search')}
                accessibilityRole="button"
                style={styles.rightContent}
                onPress={() => inputSearchValue?.(state.inputSearch)}>
                <SearchIcon
                  fill={'#C5C5C5'}
                  height={PixelPerfect(16)}
                  width={PixelPerfect(16)}
                />
              </TouchableOpacity>
            )}
          />
        </View>
      ) : (
        <>
          {title && (
            <Text
              accessibilityRole="header"
              numberOfLines={largeText ? 3 : 2}
              style={[styles.title, styleTitle]}>
              {title}
            </Text>
          )}
        </>
      )}
      {!hideLeftContent && leftContent ? (
        <View style={styles.iconLeft}>{leftContent()}</View>
      ) : (
        <View style={styles.headerSlot} />
      )}
    </View>
  );
};

export default HeaderWithBack;

const styles = StyleSheet.create({
  container: {
    alignItems: 'center',
    paddingVertical: Spacing.xs,
    justifyContent: 'space-between',
    ...rtlRowStyle,
    zIndex: 33,
    width: '100%',
    alignSelf: 'center',
  },
  backButton: {
    ...fixedIconSlot,
    backgroundColor: Palette.surface,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  title: {
    ...Type.section,
    ...textDirection,
    textAlign: 'center',
    flex: 1,
    minWidth: 0,
    color: Palette.text,
  },
  iconLeft: {
    ...fixedIconSlot,
  },
  headerSlot: {...fixedIconSlot},
  searchWrap: {
    ...flexibleTextColumn,
    marginHorizontal: Spacing.xs,
  },
  inputCont: {
    backgroundColor: Colors.white,
    flex: 1,
    borderWidth: 1,
    borderColor: Colors.border,
    minHeight: PixelPerfect(48),
    marginBottom: 0,
  },
  rightContent: {
    minWidth: 48,
    height: '100%',
    ...SharedStyles.centred,
    zIndex: 1,
  },
});
