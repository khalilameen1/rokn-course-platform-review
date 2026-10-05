import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
import {
  StartupExperience,
  useStartupExperience,
} from '../src/screens/appInitializer/StartupExperience';

describe('startup brand covers real loading, not a fixed animation delay', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  let startup!: NonNullable<ReturnType<typeof useStartupExperience>>;
  const Home = () => {
    startup = useStartupExperience()!;
    return <React.Fragment>Home is mounted</React.Fragment>;
  };
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => {
    act(() => renderer?.unmount());
    jest.useRealTimers();
  });
  it('mounts Home immediately and leaves the cover only when content and session are ready', () => {
    act(() => {
      renderer = TestRenderer.create(
        <StartupExperience sessionReady={false}>
          <Home />
        </StartupExperience>,
      );
    });
    expect(renderer.root.findAllByType(Home)).toHaveLength(1);
    act(() => startup.initialContentReady());
    expect(
      renderer.root.findAllByProps({testID: 'startup-cover'}),
    ).not.toHaveLength(0);
    act(() =>
      renderer.update(
        <StartupExperience sessionReady>
          <Home />
        </StartupExperience>,
      ),
    );
    expect(
      renderer.root.findAllByProps({testID: 'startup-cover'}),
    ).toHaveLength(0);
    expect(startup.readyForPrompts).toBe(true);
  });
  it('does not hold a ready home for a minimum marketing duration', () => {
    act(() => {
      renderer = TestRenderer.create(
        <StartupExperience sessionReady>
          <Home />
        </StartupExperience>,
      );
    });
    act(() => startup.initialContentReady());
    expect(
      renderer.root.findAllByProps({testID: 'startup-cover'}),
    ).toHaveLength(0);
    expect(startup.readyForPrompts).toBe(true);
  });
  it('releases stalled startup to the ordinary retry UI without authorizing guest popups', () => {
    act(() => {
      renderer = TestRenderer.create(
        <StartupExperience sessionReady={false}>
          <Home />
        </StartupExperience>,
      );
    });
    act(() => jest.advanceTimersByTime(8_000));
    expect(
      renderer.root.findAllByProps({testID: 'startup-cover'}),
    ).toHaveLength(0);
    expect(startup.readyForPrompts).toBe(false);
  });
});
