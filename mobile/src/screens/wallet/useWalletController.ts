import {useCallback, useEffect, useState} from 'react';
import {useSelector} from 'react-redux';
import {sessionIdentityKey} from '../../constants/helpers';
import type {RootState} from '../../store/store';
import {learnerRewardTasks} from './rewardsPresentation';
import {DEFAULT_REWARDS_HELP} from '../../services/api/rewardWalletMapper';
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
  // Rewards never load the store catalogue or initiate a top-up.
  const data = useWalletData(identityKey, false);
  const tasks = useWalletTasks(data, showCoinRules);
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

  return {
    displayedBalance,
    displayedCoinRules: usingRemoteWallet
      ? data.wallet?.rewardRules ?? DEFAULT_REWARDS_HELP
      : [],
    displayedPaidBalance,
    displayedRewardBalance,
    displayedTasks: usingRemoteWallet ? learnerRewardTasks(data.tasks) : [],
    displayedTransactions: usingRemoteWallet
      ? (data.wallet?.rewardTransactions ?? []).map(item => ({
          id: item.id,
          title: item.label,
          amount: item.amount,
          automatic: item.category === 'welcome_bonus',
          createdAt: item.occurred_at
            ? new Date(item.occurred_at).getTime()
            : 0,
        }))
      : [],
    handleTask: tasks.handleTask,
    manualRefreshing: data.manualRefreshing,
    ownerReady: data.ownerReady,
    refreshWallet: data.refresh,
    refreshWalletManually: data.refreshManually,
    serverSession: data.serverSession,
    setWalletModal,
    taskActionLabel: tasks.taskActionLabel,
    taskLoadingIds: tasks.loadingIds,
    tasksStatus: data.tasksStatus,
    usingRemoteWallet,
    walletModal,
    walletStatus: data.walletStatus,
  };
};

export type WalletController = ReturnType<typeof useWalletController>;
