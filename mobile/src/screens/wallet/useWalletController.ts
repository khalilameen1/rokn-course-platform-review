import {useCallback, useEffect, useState} from 'react';
import {useSelector} from 'react-redux';
import {sessionIdentityKey} from '../../constants/helpers';
import type {RootState} from '../../store/store';
import {useWalletCheckout} from './useWalletCheckout';
import {useWalletData} from './useWalletData';
import {useWalletTasks} from './useWalletTasks';

export const useWalletController = () => {
  const user = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(user);
  const [walletModal, setWalletModal] = useState<
    'breakdown' | 'rules' | 'transactions' | null
  >(null);
  const showCoinRules = useCallback(() => setWalletModal('rules'), []);
  useEffect(() => setWalletModal(null), [identityKey]);
  const data = useWalletData(identityKey);
  const tasks = useWalletTasks(data, showCoinRules);
  const checkout = useWalletCheckout(data);
  const usingRemoteWallet = data.serverSession === true;

  const displayedBalance = usingRemoteWallet
    ? data.wallet?.balance ?? null
    : null;
  const displayedPaidBalance = usingRemoteWallet
    ? data.wallet?.paidBalance ?? 0
    : 0;
  const displayedRewardBalance = usingRemoteWallet
    ? data.wallet?.rewardBalance ?? 0
    : 0;
  const displayedRewardContributionCap = usingRemoteWallet
    ? data.wallet?.rewardContributionCap ?? 0
    : 0;
  const displayedSpendableBalance = usingRemoteWallet
    ? data.wallet?.spendableBalance ?? displayedBalance ?? 0
    : 0;

  return {
    checkoutLoading: checkout.checkoutLoading,
    displayedBalance,
    displayedCoinRules: usingRemoteWallet ? data.wallet?.coinRules ?? [] : [],
    displayedPackages: usingRemoteWallet
      ? data.packages.slice().sort((left, right) => left.coins - right.coins)
      : [],
    displayedPaidBalance,
    displayedRewardBalance,
    displayedRewardContributionCap,
    displayedSpendableBalance,
    displayedTasks: usingRemoteWallet ? data.tasks : [],
    displayedTransactions: usingRemoteWallet
      ? (data.wallet?.transactions ?? []).map(item => ({
          id: item.id,
          title: item.label,
          amount: item.amount,
          createdAt: item.occurred_at
            ? new Date(item.occurred_at).getTime()
            : 0,
        }))
      : [],
    handleTask: tasks.handleTask,
    manualRefreshing: data.manualRefreshing,
    ownerReady: data.ownerReady,
    packagesStatus: data.packagesStatus,
    refreshWallet: data.refresh,
    refreshWalletManually: data.refreshManually,
    serverSession: data.serverSession,
    setWalletModal,
    startCheckout: checkout.startCheckout,
    taskActionLabel: tasks.taskActionLabel,
    taskLoadingIds: tasks.loadingIds,
    tasksStatus: data.tasksStatus,
    usingRemoteWallet,
    walletModal,
    walletStatus: data.walletStatus,
  };
};

export type WalletController = ReturnType<typeof useWalletController>;
