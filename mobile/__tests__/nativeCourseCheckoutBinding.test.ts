export {};

const mockValues = new Map<string, string>();
const mockGet = jest.fn();
const mockSet = jest.fn();
const mockRemove = jest.fn();
const mockCheckout = jest.fn();
let mockOwner = 'owner-a';

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: (...args: unknown[]) => mockGet(...args),
  setItem: (...args: unknown[]) => mockSet(...args),
  removeItem: (...args: unknown[]) => mockRemove(...args),
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string) => key + ':' + mockOwner,
}));
jest.mock('../src/services/api/courseCheckout', () => ({
  getCourseCheckout: (...args: unknown[]) => mockCheckout(...args),
}));

const oldId = '11111111-1111-4111-8111-111111111111';
const newId = '22222222-2222-4222-8222-222222222222';
const laterId = '33333333-3333-4333-8333-333333333333';
const product = 'coins.600';
const key = (owner = 'owner-a', sku = product) =>
  '@rokn/native-course-checkout/v1/' + encodeURIComponent(sku) + ':' + owner;
const drainMicrotasks = async () => {
  for (let turn = 0; turn < 20; turn += 1) await Promise.resolve();
};

describe('native course checkout binding ownership', () => {
  beforeEach(() => {
    jest.resetModules();
    mockValues.clear();
    mockOwner = 'owner-a';
    mockGet
      .mockReset()
      .mockImplementation(
        async (storageKey: string) => mockValues.get(storageKey) ?? null,
      );
    mockSet
      .mockReset()
      .mockImplementation(async (storageKey: string, value: string) => {
        mockValues.set(storageKey, value);
      });
    mockRemove.mockReset().mockImplementation(async (storageKey: string) => {
      mockValues.delete(storageKey);
    });
    mockCheckout.mockReset().mockResolvedValue({status: 'completed'});
  });

  it('does not let delayed cleanup erase a newer binding for the same product', async () => {
    const {
      clearNativeCourseCheckout,
      rememberNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    mockValues.set(key(), oldId);
    let releaseRemoval!: () => void;
    let removalStarted!: () => void;
    const started = new Promise<void>(resolve => (removalStarted = resolve));
    mockRemove.mockImplementationOnce((storageKey: string) => {
      removalStarted();
      return new Promise<void>(resolve => {
        releaseRemoval = () => {
          mockValues.delete(storageKey);
          resolve();
        };
      });
    });
    const clearing = clearNativeCourseCheckout(product, oldId);
    await started;
    const saving = rememberNativeCourseCheckout(product, newId);
    await drainMicrotasks();
    releaseRemoval();
    await Promise.all([clearing, saving]);
    expect(mockValues.get(key())).toBe(newId);
  });

  it('does not let concurrent replacements both approve themselves against an old terminal binding', async () => {
    const {
      rememberNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    mockValues.set(key(), oldId);
    mockCheckout.mockImplementation(async (id: string) => ({
      status: id === oldId ? 'completed' : 'pending_payment',
    }));
    const outcomes = await Promise.allSettled([
      rememberNativeCourseCheckout(product, newId),
      rememberNativeCourseCheckout(product, laterId),
    ]);
    expect(outcomes[0].status).toBe('fulfilled');
    expect(outcomes[1]).toMatchObject({
      status: 'rejected',
      reason: new Error('COURSE_CHECKOUT_BINDING_PENDING'),
    });
    expect(mockValues.get(key())).toBe(newId);
  });

  it.each(['completed', 'cancelled', 'expired', 'reconfirm_required'])(
    'allows a replacement after %s',
    async status => {
      const {
        rememberNativeCourseCheckout,
      } = require('../src/services/nativeCourseCheckoutBinding');
      mockValues.set(key(), oldId);
      mockCheckout.mockResolvedValue({status});
      await rememberNativeCourseCheckout(product, newId);
      expect(mockCheckout).toHaveBeenCalledWith(oldId);
      expect(mockValues.get(key())).toBe(newId);
    },
  );

  it.each(['quoted', 'pending_payment'])(
    'preserves a %s binding until it can be replaced',
    async status => {
      const {
        rememberNativeCourseCheckout,
      } = require('../src/services/nativeCourseCheckoutBinding');
      mockValues.set(key(), oldId);
      mockCheckout.mockResolvedValue({status});
      await expect(
        rememberNativeCourseCheckout(product, newId),
      ).rejects.toThrow('COURSE_CHECKOUT_BINDING_PENDING');
      expect(mockValues.get(key())).toBe(oldId);
      expect(mockSet).not.toHaveBeenCalled();
      mockCheckout.mockResolvedValue({status: 'completed'});
      await rememberNativeCourseCheckout(product, newId);
      expect(mockValues.get(key())).toBe(newId);
    },
  );

  it('rejects invalid IDs without touching storage and allows saving the same ID', async () => {
    const {
      rememberNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    await expect(
      rememberNativeCourseCheckout(product, 'not-a-checkout'),
    ).rejects.toThrow('COURSE_CHECKOUT_BINDING_INVALID');
    expect(mockGet).not.toHaveBeenCalled();
    expect(mockSet).not.toHaveBeenCalled();
    mockValues.set(key(), oldId);
    await rememberNativeCourseCheckout(product, oldId);
    expect(mockCheckout).not.toHaveBeenCalled();
    expect(mockValues.get(key())).toBe(oldId);
  });

  it('does not delete a newer binding when an older receipt is finalized', async () => {
    const {
      clearNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    mockValues.set(key(), newId);
    await clearNativeCourseCheckout(product, oldId);
    expect(mockRemove).not.toHaveBeenCalled();
    expect(mockValues.get(key())).toBe(newId);
  });

  it('does not block other products behind a pending status lookup', async () => {
    const {
      rememberNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    mockValues.set(key(), oldId);
    let release!: (value: {status: string}) => void;
    let signalStarted!: () => void;
    const started = new Promise<void>(resolve => (signalStarted = resolve));
    mockCheckout.mockImplementationOnce(() => {
      signalStarted();
      return new Promise(resolve => (release = resolve));
    });
    const waiting = rememberNativeCourseCheckout(product, newId);
    await started;
    await rememberNativeCourseCheckout('coins.900', laterId);
    expect(mockValues.get(key('owner-a', 'coins.900'))).toBe(laterId);
    release({status: 'completed'});
    await waiting;
  });

  it('rejects queued work after an account change without blocking the new account', async () => {
    const {
      clearNativeCourseCheckout,
      rememberNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    mockValues.set(key(), oldId);
    let release!: () => void;
    let signalStarted!: () => void;
    const started = new Promise<void>(resolve => (signalStarted = resolve));
    mockRemove.mockImplementationOnce((storageKey: string) => {
      signalStarted();
      return new Promise<void>(resolve => {
        release = () => {
          mockValues.delete(storageKey);
          resolve();
        };
      });
    });
    const clearing = clearNativeCourseCheckout(product, oldId);
    await started;
    const saving = rememberNativeCourseCheckout(product, newId);
    const outcome = saving.then(
      () => undefined,
      (error: unknown) => error,
    );
    await drainMicrotasks();
    mockOwner = 'owner-b';
    await rememberNativeCourseCheckout(product, laterId);
    expect(mockValues.get(key('owner-b'))).toBe(laterId);
    release();
    await clearing;
    await expect(outcome).resolves.toEqual(
      new Error('STORE_PURCHASE_ACCOUNT_CHANGED'),
    );
    expect(mockValues.has(key('owner-a'))).toBe(false);
    expect(mockCheckout).not.toHaveBeenCalled();
  });

  it('rechecks the account after reading storage before querying its checkout', async () => {
    const {
      rememberNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    mockGet.mockImplementationOnce(async () => {
      mockOwner = 'owner-b';
      return oldId;
    });
    await expect(rememberNativeCourseCheckout(product, newId)).rejects.toThrow(
      'STORE_PURCHASE_ACCOUNT_CHANGED',
    );
    expect(mockCheckout).not.toHaveBeenCalled();
    expect(mockSet).not.toHaveBeenCalled();
  });

  it('rechecks the account after the server lookup before writing a replacement', async () => {
    const {
      rememberNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    mockValues.set(key(), oldId);
    mockCheckout.mockImplementationOnce(async () => {
      mockOwner = 'owner-b';
      return {status: 'completed'};
    });
    await expect(rememberNativeCourseCheckout(product, newId)).rejects.toThrow(
      'STORE_PURCHASE_ACCOUNT_CHANGED',
    );
    expect(mockValues.get(key('owner-a'))).toBe(oldId);
    expect(mockSet).not.toHaveBeenCalled();
  });

  it('does not report a save as usable by a different account after writing', async () => {
    const {
      rememberNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    mockSet.mockImplementationOnce(
      async (storageKey: string, value: string) => {
        mockValues.set(storageKey, value);
        mockOwner = 'owner-b';
      },
    );
    await expect(rememberNativeCourseCheckout(product, newId)).rejects.toThrow(
      'STORE_PURCHASE_ACCOUNT_CHANGED',
    );
    expect(mockValues.get(key('owner-a'))).toBe(newId);
    expect(mockValues.has(key('owner-b'))).toBe(false);
  });

  it.each(['read', 'lookup', 'write', 'remove'])(
    'releases the queue for a retry after a failed %s',
    async stage => {
      const {
        rememberNativeCourseCheckout,
        clearNativeCourseCheckout,
      } = require('../src/services/nativeCourseCheckoutBinding');
      const failure = new Error('unavailable');
      mockValues.set(key(), oldId);
      if (stage === 'read') mockGet.mockRejectedValueOnce(failure);
      if (stage === 'lookup') mockCheckout.mockRejectedValueOnce(failure);
      if (stage === 'write') mockSet.mockRejectedValueOnce(failure);
      if (stage === 'remove') mockRemove.mockRejectedValueOnce(failure);
      const first =
        stage === 'remove'
          ? clearNativeCourseCheckout(product, oldId)
          : rememberNativeCourseCheckout(product, newId);
      await expect(first).rejects.toBe(failure);
      expect(mockValues.get(key())).toBe(oldId);
      await rememberNativeCourseCheckout(product, newId);
      expect(mockValues.get(key())).toBe(newId);
    },
  );

  it('keeps recovery reads best effort for absent, malformed and unavailable storage', async () => {
    const {
      readNativeCourseCheckout,
    } = require('../src/services/nativeCourseCheckoutBinding');
    await expect(readNativeCourseCheckout(product)).resolves.toBeUndefined();
    mockValues.set(key(), 'malformed');
    await expect(readNativeCourseCheckout(product)).resolves.toBeUndefined();
    mockGet.mockRejectedValueOnce(new Error('storage unavailable'));
    await expect(readNativeCourseCheckout(product)).resolves.toBeUndefined();
    mockValues.set(key(), newId);
    await expect(readNativeCourseCheckout(product)).resolves.toBe(newId);
    mockGet.mockImplementationOnce(async () => {
      mockOwner = 'owner-b';
      return newId;
    });
    await expect(readNativeCourseCheckout(product)).resolves.toBeUndefined();
  });
});
