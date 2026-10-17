import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetProfile = jest.fn();
const mockUpdateProfile = jest.fn();
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: () => undefined,
}));
jest.mock('../src/services/roknApi', () => ({
  getPortfolioProfile: (...args: unknown[]) => mockGetProfile(...args),
  updatePortfolioProfile: (...args: unknown[]) => mockUpdateProfile(...args),
}));

import {usePortfolioProfileEditor} from '../src/screens/Profile/gallery/usePortfolioProfileEditor';

const boundary = {scope: 'owner', epoch: 1};
const captureBoundary = async () => boundary;
const flush = async () => {
  for (let index = 0; index < 20; index += 1) await Promise.resolve();
};

describe('portfolio profile skills and contact links', () => {
  let editor!: ReturnType<typeof usePortfolioProfileEditor>;
  let renderer: TestRenderer.ReactTestRenderer;
  const mountedRef = {current: true};
  const onProfileChange = jest.fn();
  const Harness = () => {
    editor = usePortfolioProfileEditor({
      captureBoundary,
      mountedRef,
      onProfileChange,
    });
    return null;
  };
  const step = async (run: () => unknown) =>
    act(async () => {
      await run();
      await flush();
    });

  beforeEach(async () => {
    jest.clearAllMocks();
    mockGetProfile.mockResolvedValue({
      skills: ['تصميم'],
      links: [{label: 'Behance', url: 'https://behance.net/sara'}],
    });
    mockUpdateProfile.mockImplementation(async patch => ({
      published: true,
      ...patch,
    }));
    await step(() => {
      renderer = TestRenderer.create(<Harness />);
    });
    await step(() => editor.openProfileEditor());
  });
  afterEach(async () => {
    await step(() => renderer.unmount());
  });

  it('loads the saved lists, adds a WhatsApp button from a local number and saves', async () => {
    expect(editor.profileEditor.skills).toEqual(['تصميم']);
    await step(() => editor.setSkillInput('موشن، مونتاج'));
    await step(() => editor.addSkill());
    await step(() => editor.setWhatsAppInput('01012345678'));
    await step(() => editor.addWhatsApp());
    expect(editor.profileEditor.links[0]).toEqual({
      label: 'واتساب',
      url: 'https://wa.me/201012345678',
    });
    await step(() => editor.saveProfile());
    expect(mockUpdateProfile).toHaveBeenCalledWith(
      {
        skills: ['تصميم', 'موشن', 'مونتاج'],
        links: [
          {label: 'واتساب', url: 'https://wa.me/201012345678'},
          {label: 'Behance', url: 'https://behance.net/sara'},
        ],
      },
      boundary,
    );
    expect(onProfileChange).toHaveBeenCalledWith(
      expect.objectContaining({published: true}),
    );
    expect(editor.profileEditor.open).toBe(false);
  });

  it('refuses an insecure link and a wrong WhatsApp number before saving', async () => {
    await step(() => editor.setWhatsAppInput('0101'));
    await step(() => editor.addWhatsApp());
    expect(editor.profileEditor.error).toBe('اكتب رقم واتساب صحيح زي 01012345678');
    await step(() => editor.addLink());
    await step(() => editor.updateLink(1, {label: 'موقعي', url: 'http://me.dev'}));
    await step(() => editor.saveProfile());
    expect(mockUpdateProfile).not.toHaveBeenCalled();
    expect(editor.profileEditor.error).toBe('الرابط لازم يبدأ بـ https://');
    expect(editor.profileEditor.open).toBe(true);
  });
});
