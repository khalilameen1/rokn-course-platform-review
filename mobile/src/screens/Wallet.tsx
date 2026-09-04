import React from 'react';

import {WalletView} from './wallet/WalletView';
import {useWalletController} from './wallet/useWalletController';

export default function Wallet() {
  const controller = useWalletController();
  return <WalletView controller={controller} />;
}
