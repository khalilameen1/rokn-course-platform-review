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
    const cache = source('src/screens/wallet/walletCache.ts');

    expect(view).not.toContain('services/roknApi');
    expect(view).not.toContain('captureAccountSessionBoundary');
    expect(view).not.toContain('WALLET_CACHE_KEY');
    expect(controller).not.toContain('const WALLET_CACHE_KEY =');
    expect(controller).not.toContain('getCoinPackages');
    expect(controller).not.toContain('startCoinTask');
    expect(controller).not.toContain('openCoinCheckout');
    expect(controller).not.toContain('useRoute');
    expect(cache).toContain("WALLET_CACHE_KEY = '@rokn/wallet-cache/v2'");
    expect(data).toContain('getCoinPackages');
    expect(view).not.toContain('StyleSheet.create');
    expect(controller).not.toContain('<PremiumCard');
    expect(controller).not.toContain('<StatusView');
    expect(controller).not.toContain('StyleSheet.create');
  });

  it('queues an authoritative refresh after a mutation and account switch', () => {
    const data = source('src/screens/wallet/useWalletData.ts');
    const tasks = source('src/screens/wallet/useWalletTasks.ts');

    expect(data).toContain('queuedRefreshRef.current');
    expect(data).toContain('return queuedRefreshRef.current');
    expect(data).toContain('ownerRef.current = identityKey');
    expect(data).toContain('void refresh();');
    expect(tasks).toContain(
      'const operationKey = `${boundary.scope}:${task.id}`',
    );
    expect(tasks).not.toContain(
      '`${boundary.scope}:${boundary.epoch}:${task.id}`',
    );
    expect(tasks).toContain('ownerRef.current === operationOwner');
  });

  it('keeps reads, task mutations and checkout return as separate owners', () => {
    const controller = source('src/screens/wallet/useWalletController.ts');
    const data = source('src/screens/wallet/useWalletData.ts');
    const tasks = source('src/screens/wallet/useWalletTasks.ts');
    const checkout = source('src/screens/wallet/useWalletCheckout.ts');

    expect(controller.split('\n').length).toBeLessThan(100);
    expect(data).not.toContain('startCoinTask');
    expect(data).not.toContain('openCoinCheckout');
    expect(tasks).not.toContain('getCoinPackages');
    expect(tasks).not.toContain('openCoinCheckout');
    expect(checkout).not.toContain('getCoinTasks');
    expect(checkout).toContain('if (!claim || !sameReturnDestination');
    expect(checkout).toContain('if (!acknowledged || !focusedRef.current)');
    expect(checkout).not.toContain(
      "returnTo: interruptedReturnTo || {name: 'Wallet'}",
    );
  });

  it('keeps wallet styling in its dedicated module', () => {
    const view = source('src/screens/wallet/WalletView.tsx');
    const styles = source('src/screens/wallet/walletStyles.ts');

    expect(styles).toContain('StyleSheet.create');
    expect(view).toContain(
      "import {walletStyles as styles} from './walletStyles'",
    );
  });

  it('keeps packages discoverable as one horizontal rail with one coin mark', () => {
    const rail = source('src/screens/wallet/WalletPackageRail.tsx');
    const packageCard = source('src/components/view/Package.tsx');

    expect(rail).toContain('horizontal');
    expect(rail).toContain('snapToInterval={cardWidth + Spacing.sm}');
    expect(rail).toContain('width={cardWidth}');
    expect(rail).toContain('title={item.label}');
    expect(packageCard.match(/<CoinAmount/g)).toHaveLength(1);
    expect(packageCard).not.toContain('<RoknCoin ');
  });
});
