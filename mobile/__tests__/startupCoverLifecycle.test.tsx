import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {hide} from 'expo-splash-screen';
import {
  StartupExperience,
  useStartupExperience,
} from '../src/screens/appInitializer/StartupExperience';

describe('one native startup surface covers mounted content loading', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  let startup!: NonNullable<ReturnType<typeof useStartupExperience>>;
  const Home = () => {
    startup = useStartupExperience()!;
    return <React.Fragment>Home is mounted</React.Fragment>;
  };
  const render = (sessionReady: boolean) => act(() => {
    renderer = TestRenderer.create(
      <StartupExperience sessionReady={sessionReady}><Home /></StartupExperience>,
    );
  });
  const setSessionReady = (sessionReady: boolean) => act(() => renderer.update(
    <StartupExperience sessionReady={sessionReady}><Home /></StartupExperience>,
  ));
  beforeEach(() => {
    jest.useFakeTimers();
    jest.mocked(hide).mockClear();
  });
  afterEach(() => {
    act(() => renderer?.unmount());
    jest.useRealTimers();
  });
  it('mounts Home immediately and hides native launch only when content and session are ready', () => {
    render(false);
    expect(renderer.root.findAllByType(Home)).toHaveLength(1);
    expect(renderer.root.findAllByProps({testID: 'startup-cover'})).toHaveLength(0);
    expect(hide).not.toHaveBeenCalled();
    act(() => startup.initialContentReady());
    expect(hide).not.toHaveBeenCalled();
    expect(startup.readyForPrompts).toBe(false);
    setSessionReady(true);
    expect(hide).toHaveBeenCalledTimes(1);
    expect(startup.readyForPrompts).toBe(true);
  });
  it('has no minimum duration and does not hide again for repeated readiness or session changes', () => {
    render(true);
    act(() => startup.initialContentReady());
    expect(hide).toHaveBeenCalledTimes(1);
    expect(startup.readyForPrompts).toBe(true);
    act(() => startup.initialContentReady());
    setSessionReady(false);
    expect(startup.readyForPrompts).toBe(false);
    setSessionReady(true);
    act(() => jest.advanceTimersByTime(8_000));
    expect(hide).toHaveBeenCalledTimes(1);
    expect(startup.readyForPrompts).toBe(true);
  });
  it.each([false, true])('never exposes loading Home because eight seconds elapsed (sessionReady=%s)', sessionReady => {
    render(sessionReady);
    act(() => jest.advanceTimersByTime(8_000));
    expect(hide).not.toHaveBeenCalled();
    expect(startup.readyForPrompts).toBe(false);
    // Catalogue settles independently to content/cache or its offline retry UI.
    act(() => startup.initialContentReady());
    expect(hide).toHaveBeenCalledTimes(1);
    expect(startup.readyForPrompts).toBe(sessionReady);
  });
  it('releases ready public content at the existing session deadline but defers prompts until restore', () => {
    render(false);
    act(() => startup.initialContentReady());
    act(() => jest.advanceTimersByTime(7_999));
    expect(hide).not.toHaveBeenCalled();
    act(() => jest.advanceTimersByTime(1));
    expect(hide).toHaveBeenCalledTimes(1);
    expect(startup.readyForPrompts).toBe(false);
    setSessionReady(true);
    expect(hide).toHaveBeenCalledTimes(1);
    expect(startup.readyForPrompts).toBe(true);
  });
  it('does not release an unready splash from cleanup or leak its timer after unmount', () => {
    render(false);
    act(() => renderer.unmount());
    act(() => jest.advanceTimersByTime(8_000));
    expect(hide).not.toHaveBeenCalled();
  });
});
