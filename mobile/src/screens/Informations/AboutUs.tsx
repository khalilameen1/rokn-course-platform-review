import React, {useEffect, useState} from 'react';
import {Image, StyleSheet, Text, View} from 'react-native';
import {Container, Content} from '../../components/containers/Containers';
import {ResponsiveFrame} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import {
  Palette,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {getManagedPublicContent} from '../../services/publicContent';

export default function AboutUs() {
  const [managedBody, setManagedBody] = useState('');
  useEffect(() => {
    let active = true;
    void getManagedPublicContent('about')
      .then(body => active && setManagedBody(body))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame style={styles.frame}>
          <HeaderWithBack title="عن رُكن" />
          <View style={styles.identity}>
            <View style={styles.logoShell}>
              <Image
                resizeMode="contain"
                source={require('../../assets/images/authLogo.png')}
                style={styles.logo}
              />
            </View>
            <View style={styles.identityCopy}>
              <Text style={styles.name}>رُكن</Text>
              <Text style={styles.descriptor}>منصة تعليم عربية من مصر</Text>
            </View>
          </View>
          <Text selectable style={styles.body}>
            {managedBody ||
              'كورسات قصيرة ومنظمة، تتعلم فيها دقيقة بدقيقة. شاهد وطبّق واستكمل حتى الشهادة.'}
          </Text>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  frame: {maxWidth: 720, paddingBottom: Spacing.section},
  identity: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.lg,
    paddingVertical: Spacing.xl,
  },
  logoShell: {
    width: 64,
    height: 64,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
  },
  logo: {width: '100%', height: '100%'},
  identityCopy: {flex: 1, minWidth: 0},
  name: {...Type.display, ...textDirection, color: Palette.text},
  descriptor: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xxs,
  },
  body: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    paddingTop: Spacing.xl,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.line,
  },
});
