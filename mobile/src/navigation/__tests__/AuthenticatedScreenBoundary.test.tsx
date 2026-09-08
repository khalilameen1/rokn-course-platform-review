import React from 'react';
import {Alert, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

import {AuthenticatedScreenBoundary} from '../AuthenticatedScreenBoundary';
import type {RootNavigation} from '../types';

const navigation = () =>
  ({
    canGoBack: jest.fn(() => true),
    goBack: jest.fn(),
    navigate: jest.fn(),
    reset: jest.fn(),
  } as unknown as RootNavigation);

describe('AuthenticatedScreenBoundary', () => {
  afterEach(() => jest.restoreAllMocks());

  it('does not mount private content and opens Login above Home for a private deep link', async () => {
    const nav = navigation();
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    let renderer: TestRenderer.ReactTestRenderer;

    await act(async () => {
      renderer = TestRenderer.create(
        <AuthenticatedScreenBoundary
          authenticated={false}
          navigation={nav}
          route={{key: 'wallet-link', name: 'Wallet'}}
          sessionReady>
          <Text testID="private-wallet">Private wallet</Text>
        </AuthenticatedScreenBoundary>,
      );
    });

    expect(renderer!.root.findAllByProps({testID: 'private-wallet'})).toEqual(
      [],
    );
    expect(alert).not.toHaveBeenCalled();
    expect(nav.reset).toHaveBeenCalledWith({
      index: 1,
      routes: [
        {name: 'Home'},
        {name: 'Login', params: {returnTo: {name: 'Wallet'}}},
      ],
    });
    await act(async () => renderer!.unmount());
  });

  it('waits for bootstrap and mounts only an authenticated owner', async () => {
    const nav = navigation();
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    let renderer: TestRenderer.ReactTestRenderer;

    await act(async () => {
      renderer = TestRenderer.create(
        <AuthenticatedScreenBoundary
          authenticated={false}
          navigation={nav}
          route={{key: 'profile', name: 'Profile'}}
          sessionReady={false}>
          <Text testID="private-profile">Private profile</Text>
        </AuthenticatedScreenBoundary>,
      );
    });
    expect(alert).not.toHaveBeenCalled();
    expect(nav.reset).not.toHaveBeenCalled();
    expect(renderer!.root.findAllByProps({testID: 'private-profile'})).toEqual(
      [],
    );

    await act(async () => {
      renderer!.update(
        <AuthenticatedScreenBoundary
          authenticated
          navigation={nav}
          route={{key: 'profile', name: 'Profile'}}
          sessionReady>
          <Text testID="private-profile">Private profile</Text>
        </AuthenticatedScreenBoundary>,
      );
    });
    expect(renderer!.root.findByProps({testID: 'private-profile'})).toBeTruthy();
    expect(alert).not.toHaveBeenCalled();
    await act(async () => renderer!.unmount());
  });
});
