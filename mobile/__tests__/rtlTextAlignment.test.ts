import {StyleSheet} from 'react-native';
import {
  rtlRowStyle,
  textDirection,
} from '../src/constants/designSystem';

describe('Arabic paragraph direction', () => {
  it('aligns at the RTL paragraph start without a second physical-edge flip', () => {
    expect(StyleSheet.flatten(textDirection)).toEqual({
      direction: 'rtl',
      writingDirection: 'rtl',
      textAlign: 'auto',
    });
  });

  it('preserves deliberate centered labels and RTL row order', () => {
    expect(
      StyleSheet.flatten([textDirection, {textAlign: 'center'}]).textAlign,
    ).toBe('center');
    expect(rtlRowStyle).toEqual({direction: 'rtl', flexDirection: 'row'});
  });
});
