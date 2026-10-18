import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('wallet architecture', () => {
  it('keeps the route as a small controller-to-view boundary', () => {
    const route = source('src/screens/Wallet.tsx');

    expect(route.split('\n').length).toBeLessThan(30);
    expect(route).toContain('useWalletController()');
    expect(route).toContain('<WalletView controller={controller} />');
    expect(route).not.toContain('useState');
    expect(route).not.toContain('StyleSheet.create');
  });

  it('keeps API, cache and account ownership out of presentation', () => {
    const view = source('src/screens/wallet/WalletView.tsx');
    const controller = source('src/screens/wallet/useWalletController.ts');
    const data = source('src/screens/wallet/useWalletData.ts');

    expect(view).not.toContain('services/roknApi');
    expect(view).not.toContain('captureAccountSessionBoundary');
    expect(view).not.toContain('WALLET_CACHE_KEY');
    expect(controller).not.toContain('const WALLET_CACHE_KEY =');
    expect(controller).not.toContain('getCoinPackages');
    expect(controller).not.toContain('startCoinTask');
    expect(controller).not.toContain('openCoinCheckout');
    expect(controller).not.toContain('useRoute');
    expect(controller).not.toContain('useWalletCheckout');
    expect(controller).toContain('useWalletData(identityKey)');
    expect(view).not.toContain('WalletPackageRail');
    expect(view).not.toContain('CAN_START_COIN_CHECKOUT');
    // Rewards never read the store catalogue.
    expect(data).not.toContain('getCoinPackages');
    expect(view).not.toContain('StyleSheet.create');
    expect(controller).not.toContain('<PremiumCard');
    expect(controller).not.toContain('<StatusView');
    expect(controller).not.toContain('StyleSheet.create');
  });

  it('queues an authoritative refresh after a mutation and account switch', () => {
    const tasks = source('src/screens/wallet/useWalletTasks.ts');

    // The post-mutation refresh and account switch of the wallet read are
    // proven by walletForegroundRefresh.test.tsx.
    expect(tasks).toContain(
      'const operationKey = `${boundary.scope}:${boundary.epoch}:${task.id}`',
    );
    expect(tasks).not.toContain(
      'const operationKey = `${boundary.scope}:${task.id}`',
    );
    expect(tasks).toContain('ownerRef.current === operationOwner');
    expect(tasks).toContain('presentationRef.current === visit');
    expect(tasks).toContain('if (!ownsPresentation(visit)) return;');
  });

  it('keeps reads, task mutations and checkout return as separate owners', () => {
    const controller = source('src/screens/wallet/useWalletController.ts');
    const data = source('src/screens/wallet/useWalletData.ts');
    const tasks = source('src/screens/wallet/useWalletTasks.ts');

    expect(controller.split('\n').length).toBeLessThan(100);
    expect(data).not.toContain('startCoinTask');
    expect(data).not.toContain('openCoinCheckout');
    expect(tasks).not.toContain('getCoinPackages');
    expect(tasks).not.toContain('openCoinCheckout');
  });

  it('keeps wallet styling in its dedicated module', () => {
    const view = source('src/screens/wallet/WalletView.tsx');
    const styles = source('src/screens/wallet/walletStyles.ts');

    expect(styles).toContain('StyleSheet.create');
    expect(view).toContain(
      "import {walletStyles as styles} from './walletStyles'",
    );
  });
});
