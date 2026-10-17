import React from 'react';
import {Modal, Text} from 'react-native';
import ReactTestRenderer from 'react-test-renderer';

jest.mock('expo-web-browser', () => ({
  openBrowserAsync: jest.fn(async () => ({type: 'opened'})),
}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 24, left: 0, right: 0}),
}));
jest.mock('../src/services/publicLinks', () => ({
  privacyPolicyUrl: 'https://rokn.app/privacy-policy',
}));

import * as WebBrowser from 'expo-web-browser';
import {
  AI_CONSENT_COPY,
  AiConsentPromptHost,
} from '../src/components/ui/AiConsentSheet';
import {presentAiConsentPrompt} from '../src/services/aiConsentPrompt';

const settle = async () => {
  for (let i = 0; i < 10; i++) await Promise.resolve();
};

describe('AI consent sheet', () => {
  it('names no vendor and lets the learner read the policy without declining', async () => {
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(<AiConsentPromptHost />);
    });
    expect(renderer.root.findByType(Modal).props.visible).toBe(false);

    let decision: boolean | undefined;
    await ReactTestRenderer.act(async () => {
      void presentAiConsentPrompt().then(value => {
        decision = value;
      });
    });
    expect(renderer.root.findByType(Modal).props.visible).toBe(true);
    const copy = renderer.root
      .findAllByType(Text)
      .map(node => String(node.props.children))
      .join('\n');
    expect(copy).not.toMatch(/OpenRouter/i);
    expect(copy).toContain('مزود الذكاء الاصطناعي');
    expect(copy).toContain(AI_CONSENT_COPY.privacy);

    await ReactTestRenderer.act(async () => {
      renderer.root.findByProps({testID: 'ai-consent-privacy'}).props.onPress();
      await settle();
    });
    expect(WebBrowser.openBrowserAsync).toHaveBeenCalledWith(
      'https://rokn.app/privacy-policy',
    );
    expect(decision).toBeUndefined();
    expect(renderer.root.findByType(Modal).props.visible).toBe(true);

    await ReactTestRenderer.act(async () => {
      renderer.root.findByProps({testID: 'ai-consent-accept'}).props.onPress();
      await settle();
    });
    expect(decision).toBe(true);
    expect(renderer.root.findByType(Modal).props.visible).toBe(false);
    await ReactTestRenderer.act(() => renderer.unmount());
  });

  it.each(['ai-consent-later', 'back'])(
    'treats %s as "not now"',
    async action => {
      let renderer!: ReactTestRenderer.ReactTestRenderer;
      await ReactTestRenderer.act(() => {
        renderer = ReactTestRenderer.create(<AiConsentPromptHost />);
      });
      let decision: boolean | undefined;
      await ReactTestRenderer.act(async () => {
        void presentAiConsentPrompt().then(value => {
          decision = value;
        });
      });
      await ReactTestRenderer.act(async () => {
        if (action === 'back')
          renderer.root.findByType(Modal).props.onRequestClose();
        else renderer.root.findByProps({testID: action}).props.onPress();
        await settle();
      });
      expect(decision).toBe(false);
      await ReactTestRenderer.act(() => renderer.unmount());
    },
  );
});
