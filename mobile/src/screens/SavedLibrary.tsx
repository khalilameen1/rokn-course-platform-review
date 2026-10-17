import React from 'react';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useSelector} from 'react-redux';
import {Container, Content} from '../components/containers/Containers';
import {ResponsiveFrame} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {Spacing} from '../constants/designSystem';
import {sessionIdentityKey} from '../constants/helpers';
import type {RootState} from '../store/store';
import SavedVideos from './savedLibrary/SavedVideos';

/** The full saved library, opened from ركني or an old profile/saved link. */
export default function SavedLibrary() {
  const insets = useSafeAreaInsets();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);

  return (
    <Container noPadding>
      <Content
        noPadding
        paddingBottom={Math.max(Spacing.section, insets.bottom + Spacing.xl)}>
        <ResponsiveFrame>
          <HeaderWithBack title="المحفوظات" />
          <SavedVideos key={`saved:${identityKey}`} />
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}
