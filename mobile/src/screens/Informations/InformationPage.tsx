import React, {useEffect, useState} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {useNavigation} from '@react-navigation/native';
import {Container, Content} from '../../components/containers/Containers';
import {ResponsiveFrame} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import {
  Palette,
  Spacing,
  Type,
  textDirection,
} from '../../constants/designSystem';
import {
  bundledPublicContent,
  getPublicContent,
  type PublicContentPage,
} from '../../services/publicContent';
import type {RootNavigation} from '../../navigation/types';

export default function InformationPage({page}: {page: PublicContentPage}) {
  const navigation = useNavigation<RootNavigation>();
  const [document, setDocument] = useState(() => bundledPublicContent(page));

  useEffect(() => {
    let active = true;
    setDocument(bundledPublicContent(page));
    void getPublicContent(page).then(content => {
      if (active) setDocument(content);
    });
    return () => {
      active = false;
    };
  }, [page]);

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame style={styles.frame}>
          <HeaderWithBack title={document.title} />
          <View style={styles.article}>
            {!!document.last_updated && (
              <Text style={styles.updated}>{document.last_updated}</Text>
            )}
            {!!document.intro_title && (
              <Text accessibilityRole="header" style={styles.heading}>
                {document.intro_title}
              </Text>
            )}
            {!!document.intro_text && (
              <Text selectable style={styles.paragraph}>
                {document.intro_text}
              </Text>
            )}
            {document.sections.map((section, index) => (
              <View key={`${page}-${index}`} style={styles.section}>
                {!!section.title && (
                  <Text accessibilityRole="header" style={styles.heading}>
                    {section.title}
                  </Text>
                )}
                {section.body.map((paragraph, paragraphIndex) => (
                  <Text
                    selectable
                    key={paragraphIndex}
                    style={styles.paragraph}>
                    {paragraph}
                  </Text>
                ))}
              </View>
            ))}
            {!!document.closing && (
              <Text selectable style={[styles.paragraph, styles.section]}>
                {document.closing}
              </Text>
            )}
          </View>
          <View style={styles.links}>
            {page === 'terms' && (
              <>
                <Pressable
                  accessibilityRole="link"
                  style={styles.link}
                  onPress={() => navigation.navigate('PrivacyPolicy')}>
                  <Text style={styles.linkText}>
                    {bundledPublicContent('privacy').title}
                  </Text>
                </Pressable>
                <Pressable
                  accessibilityRole="link"
                  style={styles.link}
                  onPress={() => navigation.navigate('ReturnsPolicy')}>
                  <Text style={styles.linkText}>
                    {bundledPublicContent('returns').title}
                  </Text>
                </Pressable>
              </>
            )}
            <Pressable
              accessibilityRole="button"
              style={styles.link}
              onPress={() =>
                navigation.navigate('Feedback', {sourceScreen: page})
              }>
              <Text style={styles.linkText}>{document.contact_label}</Text>
            </Pressable>
          </View>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  frame: {maxWidth: 720, paddingBottom: Spacing.section},
  article: {paddingTop: Spacing.xl},
  updated: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginBottom: Spacing.lg,
  },
  heading: {...Type.section, ...textDirection, color: Palette.text},
  paragraph: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.md,
  },
  section: {marginTop: Spacing.xxl},
  links: {
    marginTop: Spacing.xl,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.line,
  },
  link: {minHeight: 48, justifyContent: 'center', paddingVertical: Spacing.sm},
  linkText: {...Type.bodyStrong, ...textDirection, color: Palette.primary},
});
