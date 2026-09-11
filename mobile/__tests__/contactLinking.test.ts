import {Linking} from 'react-native';
import {openRoknDestination} from '../src/navigation/RootNavigationHelper';
import {roknLinking} from '../src/navigation/roknLinking';

jest.mock('@react-navigation/native', () => ({getStateFromPath: jest.fn()}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  openRoknDestination: jest.fn(() => true),
}));
jest.mock('react-native', () => ({
  Linking: {addEventListener: jest.fn(() => ({remove: jest.fn()}))},
}));

describe('Contact app-link entry', () => {
  it('opens the existing support screen above Home on a cold start', () => {
    expect(
      roknLinking.getStateFromPath?.('/contact', roknLinking.config),
    ).toEqual({
      index: 1,
      routes: [{name: 'Home'}, {name: 'Feedback'}],
    });
  });

  it('keeps existing case links attached to their real case', () => {
    const caseId = '01JY7M7QW9WQQRF4S9V4Z0X7GA';
    expect(
      roknLinking.getStateFromPath?.(`support/${caseId}`, roknLinking.config),
    ).toEqual({
      index: 1,
      routes: [{name: 'Home'}, {name: 'Feedback', params: {caseId}}],
    });
  });

  it('delivers a warm Contact link to the same navigation handler', () => {
    const fallback = jest.fn();
    const unsubscribe = roknLinking.subscribe?.(fallback);
    const onUrl = (Linking.addEventListener as jest.Mock).mock.calls[0][1];
    onUrl({url: 'https://rokn.app/contact'});
    expect(openRoknDestination).toHaveBeenCalledWith({name: 'Feedback'});
    expect(fallback).not.toHaveBeenCalled();
    unsubscribe?.();
  });
});
