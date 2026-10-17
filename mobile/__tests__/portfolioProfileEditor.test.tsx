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

describe('portfolio profile skills', () => {
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

  it('loads the saved skills, adds new ones and saves only skills', async () => {
    expect(editor.profileEditor.skills).toEqual(['تصميم']);
    await step(() => editor.setSkillInput('موشن، مونتاج'));
    await step(() => editor.addSkill());
    await step(() => editor.saveProfile());
    expect(mockUpdateProfile).toHaveBeenCalledWith(
      {skills: ['تصميم', 'موشن', 'مونتاج']},
      boundary,
    );
    expect(onProfileChange).toHaveBeenCalledWith(
      expect.objectContaining({published: true}),
    );
    expect(editor.profileEditor.open).toBe(false);
  });

  it('removes a skill before saving', async () => {
    await step(() => editor.removeSkill('تصميم'));
    await step(() => editor.saveProfile());
    expect(mockUpdateProfile).toHaveBeenCalledWith({skills: []}, boundary);
  });
});
