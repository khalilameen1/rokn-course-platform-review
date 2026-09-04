jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));
jest.mock('expo-web-browser', () => ({
  openAuthSessionAsync: jest.fn(),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));
let mockCoinAccountEpoch = 1;
let mockCoinAccountScope = 'user-a';
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, boundary?: {scope: string}) =>
      `${key}:${boundary?.scope ?? mockCoinAccountScope}`,
  ),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: mockCoinAccountEpoch,
    scope: mockCoinAccountScope,
  })),
  assertAccountSessionBoundary: jest.fn((boundary: {epoch: number}) => {
    if (boundary.epoch !== mockCoinAccountEpoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  }),
  getItem: jest.fn(async () => null),
  removeItem: jest.fn(async () => undefined),
  saveItem: jest.fn(async () => true),
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_EXTERNAL_CHECKOUT: true,
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: jest.fn(),
}));

describe('coin checkout boundary', () => {
  const originalProfile = process.env.EXPO_PUBLIC_BUILD_PROFILE;

  beforeEach(() => {
    jest.clearAllMocks();
    mockCoinAccountEpoch = 1;
    mockCoinAccountScope = 'user-a';
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      saveItem: jest.Mock;
    };
    helpers.getItem.mockResolvedValue(null);
    helpers.saveItem.mockResolvedValue(true);
  });

  afterAll(() => {
    if (originalProfile === undefined) {
      delete process.env.EXPO_PUBLIC_BUILD_PROFILE;
    } else {
      process.env.EXPO_PUBLIC_BUILD_PROFILE = originalProfile;
    }
    jest.resetModules();
  });

  it('rejects every package without a numeric server id', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<unknown>;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };

    await expect(
      openCoinCheckout({
        id: 'fixture-4200',
        coins: 4200,
        price: 249,
        label: 'fixture',
      }),
    ).rejects.toThrow('COIN_PACKAGE_CONTRACT_INVALID');
    expect(publicRequest.post).not.toHaveBeenCalled();
  });

  it('keeps the same payment intent when the checkout browser is closed', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const {removeItem} = require('../src/constants/helpers') as {
      removeItem: jest.Mock;
    };
    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean}>;
    };

    publicRequest.post.mockResolvedValueOnce({
      data: {
        data: {
          payment_url: 'https://checkout.kashier.io/session',
          order_ref: 'PKG-ONE-01',
          idempotency_key: '11111111-1111-4111-8111-111111111111',
        },
      },
    });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({pending: true, cancelled: false});
    expect(removeItem).not.toHaveBeenCalled();
  });

  it('does not finish an old account checkout after the learner switches accounts', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<unknown>;
    };

    publicRequest.post.mockResolvedValueOnce({
      data: {
        data: {
          payment_url: 'https://checkout.kashier.io/session',
          order_ref: 'PKG-OLD-ACCOUNT',
          idempotency_key: '11111111-1111-4111-8111-111111111111',
        },
      },
    });
    WebBrowser.openAuthSessionAsync.mockImplementationOnce(async () => {
      mockCoinAccountEpoch = 2;
      mockCoinAccountScope = 'user-b';
      return {type: 'cancel'};
    });

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
  });

  it('never opens a payable URL whose order reference was not persisted', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      saveItem: jest.Mock;
    };
    helpers.saveItem.mockResolvedValueOnce(true).mockResolvedValueOnce(false);
    publicRequest.post
      .mockResolvedValueOnce({
        data: {
          data: {
            payment_url: 'https://checkout.kashier.io/unpersisted',
            order_ref: 'PKG-UNPERSISTED',
            idempotency_key: '11111111-1111-4111-8111-111111111111',
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'cancelled',
            financial_status: 'cancelled',
            coins_added: 0,
          },
        },
      });

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{pending: boolean; cancelled: boolean}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({pending: false, cancelled: true});
    expect(publicRequest.post).toHaveBeenNthCalledWith(
      2,
      'payment/abandon/PKG-UNPERSISTED',
    );
    expect(WebBrowser.openAuthSessionAsync).not.toHaveBeenCalled();
  });

  it('shares one checkout flight across concurrent taps in the same account', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    publicRequest.post
      .mockResolvedValueOnce({
        data: {
          data: {
            payment_url: 'https://checkout.kashier.io/single-flight',
            order_ref: 'PKG-SINGLE-FLIGHT',
            idempotency_key: '11111111-1111-4111-8111-111111111111',
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'cancelled',
            financial_status: 'cancelled',
            coins_added: 0,
          },
        },
      });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean; orderRef?: string}>;
    };
    const selected = {id: '7', coins: 600, price: 49, label: 'باقة'};

    const [first, second] = await Promise.all([
      openCoinCheckout(selected),
      openCoinCheckout(selected),
    ]);

    expect(first).toEqual(second);
    expect(first).toMatchObject({
      cancelled: true,
      orderRef: 'PKG-SINGLE-FLIGHT',
    });
    expect(
      publicRequest.post.mock.calls.filter(
        call => call[0] === 'payment/initiate',
      ),
    ).toHaveLength(1);
    expect(WebBrowser.openAuthSessionAsync).toHaveBeenCalledTimes(1);
  });

  it('notifies the mounted wallet/course after foreground reconciliation credits coins', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {getItem: jest.Mock};
    const checkout = require('../src/services/coinCheckout') as {
      reconcilePendingCoinCheckout: () => Promise<unknown>;
      subscribeCoinCheckoutCredits: (listener: jest.Mock) => () => void;
    };
    helpers.getItem.mockResolvedValue({
      attempts: [
        {
          idempotencyKey: '11111111-1111-4111-8111-111111111111',
          packageId: 7,
          expectedPrice: 49,
          expectedCoins: 600,
          createdAt: new Date().toISOString(),
          orderRef: 'PKG-FOREGROUND-CREDIT',
        },
      ],
    });
    publicRequest.post.mockResolvedValue({
      data: {
        data: {
          status: 'approved',
          financial_status: 'settled',
          package: {coins: 600},
        },
      },
    });
    const listener = jest.fn();
    const unsubscribe = checkout.subscribeCoinCheckoutCredits(listener);

    await expect(
      checkout.reconcilePendingCoinCheckout(),
    ).resolves.toMatchObject({
      success: true,
      coinsAdded: 600,
      orderRef: 'PKG-FOREGROUND-CREDIT',
    });
    expect(listener).toHaveBeenCalledWith(
      expect.objectContaining({success: true, coinsAdded: 600}),
      'user-a',
    );
    unsubscribe();
  });

  it('does not manufacture a pending payment from a client-only intent during recovery', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      removeItem: jest.Mock;
    };
    helpers.getItem.mockResolvedValue({
      attempts: [
        {
          idempotencyKey: '11111111-1111-4111-8111-111111111111',
          packageId: 7,
          expectedPrice: 49,
          expectedCoins: 600,
          createdAt: new Date().toISOString(),
        },
      ],
    });

    const {reconcilePendingCoinCheckout} =
      require('../src/services/coinCheckout') as {
        reconcilePendingCoinCheckout: () => Promise<{
          pending: boolean;
          success: boolean;
        } | null>;
      };

    await expect(reconcilePendingCoinCheckout()).resolves.toMatchObject({
      pending: false,
      success: false,
    });
    expect(publicRequest.post).not.toHaveBeenCalled();
    expect(helpers.removeItem).toHaveBeenCalled();
  });

  it('recovers a captured payment after the browser was closed', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {get: jest.Mock; post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      removeItem: jest.Mock;
    };
    helpers.getItem.mockResolvedValue({
      attempts: [
        {
          idempotencyKey: '11111111-1111-4111-8111-111111111111',
          packageId: 7,
          expectedPrice: 49,
          expectedCoins: 600,
          createdAt: '2026-08-31T10:00:00.000Z',
          orderRef: 'PKG-CAPTURED',
        },
      ],
    });
    publicRequest.post.mockResolvedValueOnce({
      data: {
        data: {
          status: 'approved',
          financial_status: 'settled',
          package: {coins: 600},
        },
      },
    });

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{success: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({success: true, orderRef: 'PKG-CAPTURED'});
    expect(publicRequest.post).toHaveBeenCalledWith(
      'payment/reconcile/PKG-CAPTURED',
    );
    expect(WebBrowser.openAuthSessionAsync).not.toHaveBeenCalled();
    expect(helpers.removeItem).toHaveBeenCalled();
  });

  it('reopens the same pending checkout instead of trapping the learner', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
    };
    helpers.getItem.mockResolvedValue({
      attempts: [
        {
          idempotencyKey: '11111111-1111-4111-8111-111111111111',
          packageId: 7,
          expectedPrice: 49,
          expectedCoins: 600,
          createdAt: new Date().toISOString(),
          orderRef: 'PKG-PENDING-RETRY',
        },
      ],
    });
    publicRequest.post
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'pending',
            financial_status: 'pending',
            package: {coins: 600},
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            payment_url: 'https://checkout.kashier.io/resume',
            order_ref: 'PKG-PENDING-RETRY',
            idempotency_key: '11111111-1111-4111-8111-111111111111',
          },
        },
      });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({
      pending: true,
      cancelled: false,
      orderRef: 'PKG-PENDING-RETRY',
    });
    expect(WebBrowser.openAuthSessionAsync).toHaveBeenCalledWith(
      'https://checkout.kashier.io/resume',
      'rokn://payment-result',
      {showInRecents: true},
    );
  });

  it('does not open a second package while the previous checkout can still settle', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      removeItem: jest.Mock;
      saveItem: jest.Mock;
    };
    const olderAttempt = {
      idempotencyKey: '22222222-2222-4222-8222-222222222222',
      packageId: 2,
      expectedPrice: 99,
      expectedCoins: 1200,
      createdAt: '2026-09-01T11:55:43.000Z',
      orderRef: 'PKG-OLDER-PENDING',
    };
    helpers.getItem.mockResolvedValueOnce({attempts: [olderAttempt]});
    publicRequest.post.mockResolvedValueOnce({
      data: {
        data: {
          status: 'pending',
          financial_status: 'pending',
          package: {coins: 1200},
        },
      },
    });

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '3', coins: 2400, price: 149, label: 'باقة'}),
    ).resolves.toMatchObject({
      pending: true,
      cancelled: false,
      orderRef: 'PKG-OLDER-PENDING',
    });
    expect(publicRequest.post).toHaveBeenCalledWith(
      'payment/abandon/PKG-OLDER-PENDING',
    );
    expect(helpers.removeItem).not.toHaveBeenCalled();
    expect(helpers.saveItem).not.toHaveBeenCalled();
    expect(WebBrowser.openAuthSessionAsync).not.toHaveBeenCalled();
  });

  it('opens another package after the closed unpaid checkout is authoritatively cancelled', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      removeItem: jest.Mock;
      saveItem: jest.Mock;
    };
    let stored: unknown = null;
    helpers.getItem.mockImplementation(async () => stored);
    helpers.saveItem.mockImplementation(
      async (_key: string, value: unknown) => {
        stored = value;
        return true;
      },
    );
    helpers.removeItem.mockImplementation(async () => {
      stored = null;
    });
    publicRequest.post
      .mockResolvedValueOnce({
        data: {
          data: {
            payment_url: 'https://checkout.kashier.io/first-package',
            order_ref: 'PKG-FIRST-CLOSED',
            idempotency_key: '11111111-1111-4111-8111-111111111111',
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'cancelled',
            financial_status: 'cancelled',
            coins_added: 0,
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            payment_url: 'https://checkout.kashier.io/second-package',
            order_ref: 'PKG-SECOND-CLOSED',
            idempotency_key: '11111111-1111-4111-8111-111111111111',
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'cancelled',
            financial_status: 'cancelled',
            coins_added: 0,
          },
        },
      });
    WebBrowser.openAuthSessionAsync
      .mockResolvedValueOnce({type: 'cancel'})
      .mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{pending: boolean; cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '2', coins: 1200, price: 99, label: 'الأولى'}),
    ).resolves.toMatchObject({pending: false, cancelled: true});
    await expect(
      openCoinCheckout({id: '3', coins: 2400, price: 149, label: 'الثانية'}),
    ).resolves.toMatchObject({
      pending: false,
      cancelled: true,
      orderRef: 'PKG-SECOND-CLOSED',
    });
    expect(WebBrowser.openAuthSessionAsync).toHaveBeenNthCalledWith(
      2,
      'https://checkout.kashier.io/second-package',
      'rokn://payment-result',
      {showInRecents: true},
    );
    expect(stored).toBeNull();
  });

  it('never opens a server checkout which belongs to another package', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      saveItem: jest.Mock;
    };
    let stored: unknown = null;
    helpers.getItem.mockImplementation(async () => stored);
    helpers.saveItem.mockImplementation(
      async (_key: string, value: unknown) => {
        stored = value;
        return true;
      },
    );
    publicRequest.post.mockRejectedValueOnce({
      response: {
        data: {
          code: 'pending_checkout_exists',
          data: {
            order_ref: 'PKG-OLDER-SERVER-CHECKOUT',
            status: 'pending',
            payment_url: 'https://checkout.kashier.io/older-package',
            amount: 99,
            package: {id: 2, coins: 1200},
          },
        },
      },
    });

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{pending: boolean; cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '3', coins: 2400, price: 149, label: 'الثانية'}),
    ).resolves.toMatchObject({
      pending: true,
      cancelled: false,
      orderRef: 'PKG-OLDER-SERVER-CHECKOUT',
    });
    expect(WebBrowser.openAuthSessionAsync).not.toHaveBeenCalled();
    expect(stored).toMatchObject({
      attempts: [
        expect.objectContaining({
          packageId: 2,
          expectedCoins: 1200,
          orderRef: 'PKG-OLDER-SERVER-CHECKOUT',
        }),
      ],
    });
  });

  it('retires stale package terms before opening the current package contract', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      removeItem: jest.Mock;
      saveItem: jest.Mock;
    };
    let stored: unknown = {
      attempts: [
        {
          idempotencyKey: '22222222-2222-4222-8222-222222222222',
          packageId: 7,
          expectedPrice: 39,
          expectedCoins: 500,
          createdAt: new Date().toISOString(),
          orderRef: 'PKG-STALE-TERMS',
        },
      ],
    };
    helpers.getItem.mockImplementation(async () => stored);
    helpers.saveItem.mockImplementation(
      async (_key: string, value: unknown) => {
        stored = value;
        return true;
      },
    );
    helpers.removeItem.mockImplementation(async () => {
      stored = null;
    });
    publicRequest.post
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'cancelled',
            financial_status: 'cancelled',
            coins_added: 0,
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            payment_url: 'https://checkout.kashier.io/current-terms',
            order_ref: 'PKG-CURRENT-TERMS',
            idempotency_key: '11111111-1111-4111-8111-111111111111',
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'cancelled',
            financial_status: 'cancelled',
            coins_added: 0,
          },
        },
      });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{pending: boolean; cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'الحالية'}),
    ).resolves.toMatchObject({
      pending: false,
      cancelled: true,
      orderRef: 'PKG-CURRENT-TERMS',
    });
    expect(publicRequest.post.mock.calls.map(call => call[0])).toEqual([
      'payment/abandon/PKG-STALE-TERMS',
      'payment/initiate',
      'payment/abandon/PKG-CURRENT-TERMS',
    ]);
    expect(WebBrowser.openAuthSessionAsync).toHaveBeenCalledWith(
      'https://checkout.kashier.io/current-terms',
      'rokn://payment-result',
      {showInRecents: true},
    );
  });

  it('replaces an expired attempt in the same tap', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      removeItem: jest.Mock;
    };
    const expiredAttempt = {
      idempotencyKey: '22222222-2222-4222-8222-222222222222',
      packageId: 2,
      expectedPrice: 99,
      expectedCoins: 1200,
      createdAt: '2026-09-01T10:00:00.000Z',
      orderRef: 'PKG-EXPIRED-ATTEMPT',
    };
    helpers.getItem
      .mockResolvedValueOnce({attempts: [expiredAttempt]})
      .mockResolvedValueOnce({attempts: [expiredAttempt]})
      .mockResolvedValueOnce(null)
      .mockResolvedValueOnce(null);
    publicRequest.post
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'pending',
            financial_status: 'pending',
            package: {coins: 1200},
          },
        },
      })
      .mockRejectedValueOnce({
        response: {
          data: {
            code: 'checkout_attempt_expired',
            data: {order_ref: 'PKG-EXPIRED-ATTEMPT', status: 'cancelled'},
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            payment_url: 'https://checkout.kashier.io/fresh-attempt',
            order_ref: 'PKG-FRESH-ATTEMPT',
            idempotency_key: '11111111-1111-4111-8111-111111111111',
          },
        },
      });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '2', coins: 1200, price: 99, label: 'باقة'}),
    ).resolves.toMatchObject({
      pending: true,
      cancelled: false,
      orderRef: 'PKG-FRESH-ATTEMPT',
    });
    expect(helpers.removeItem).toHaveBeenCalled();
  });

  it('resumes the server checkout after app payment state was lost', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    publicRequest.post.mockRejectedValueOnce({
      response: {
        data: {
          code: 'pending_checkout_exists',
          data: {
            order_ref: 'PKG-SERVER-PENDING',
            status: 'pending',
            payment_url: 'https://checkout.kashier.io/server-resume',
            amount: 49,
            package: {id: 7, coins: 600},
          },
        },
      },
    });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({
      pending: true,
      cancelled: false,
      orderRef: 'PKG-SERVER-PENDING',
    });
    expect(WebBrowser.openAuthSessionAsync).toHaveBeenCalledWith(
      'https://checkout.kashier.io/server-resume',
      'rokn://payment-result',
      {showInRecents: true},
    );
  });

  it('accepts an approved idempotent replay instead of showing a false error', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    publicRequest.post
      .mockRejectedValueOnce({
        response: {
          data: {
            code: 'checkout_attempt_closed',
            data: {order_ref: 'PKG-PAID', status: 'approved'},
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'approved',
            financial_status: 'settled',
            package: {coins: 600},
          },
        },
      });

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{success: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({success: true, orderRef: 'PKG-PAID'});
  });

  it('accepts an approved replay after the response interceptor unwraps AxiosError', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    jest.resetModules();

    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    publicRequest.post
      .mockRejectedValueOnce({
        data: {
          code: 'checkout_attempt_closed',
          data: {order_ref: 'PKG-PAID-UNWRAPPED', status: 'approved'},
        },
        status: 409,
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'approved',
            financial_status: 'settled',
            package: {coins: 600},
          },
        },
      });

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{success: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({
      success: true,
      orderRef: 'PKG-PAID-UNWRAPPED',
    });
  });
});
