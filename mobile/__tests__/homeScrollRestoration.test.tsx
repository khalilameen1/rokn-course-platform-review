import React from 'react';
import {ScrollView} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockRead = jest.fn();
const mockSave = jest.fn(async (_key: string, _value: unknown) => undefined);
let mockIdentity = 'user-1';
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({scope: mockIdentity})),
  assertAccountSessionBoundary: (owner: {scope: string}) => {
    if (owner.scope !== mockIdentity)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: jest.fn(
    async (key: string, owner: {scope: string}) => `${key}:${owner.scope}`,
  ),
  getItem: (key: string) => mockRead(key),
  saveItem: (key: string, value: unknown) => mockSave(key, value),
}));

import {useHomeScrollMemory} from '../src/screens/home/useHomeScrollMemory';

type Props = {
  active: boolean;
  identityKey: string;
  loading: boolean;
  searchQuery: string;
};
const initial: Props = {
  active: true,
  identityKey: 'user-1',
  loading: false,
  searchQuery: '',
};
const deferred = () => {
  let resolve!: (value: number | null) => void;
  const promise = new Promise<number | null>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('Home scroll restoration yields to the current learner interaction', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let current: ReturnType<typeof useHomeScrollMemory>;
  const scrollTo = jest.fn();
  const setSearchQuery = jest.fn();
  let props: Props;
  const Harness = (input: Props) => {
    current = useHomeScrollMemory({...input, setSearchQuery});
    const bind = React.useCallback((view: ScrollView | null) => {
      // Observe the same ScrollView instance the real hook controls, not an
      // unrelated host node behind React Native's imperative wrapper.
      if (view) view.scrollTo = scrollTo;
      current.bind(view);
    }, []);
    return (
      <ScrollView
        ref={bind}
        onScrollBeginDrag={current.markUserMoved}
        onScroll={event => current.record(event.nativeEvent.contentOffset.y)}
      />
    );
  };
  const mount = async (overrides: Partial<Props> = {}) => {
    props = {...initial, ...overrides};
    await act(async () => {
      renderer = TestRenderer.create(<Harness {...props} />);
    });
  };
  const update = async (overrides: Partial<Props>) => {
    props = {...props, ...overrides};
    await act(async () => renderer!.update(<Harness {...props} />));
  };
  const tick = async (milliseconds: number) => {
    await act(async () => jest.advanceTimersByTime(milliseconds));
  };
  const dragTo = async (offset: number) => {
    await act(async () => {
      const scroll = renderer!.root.findByType(ScrollView);
      scroll.props.onScrollBeginDrag();
      scroll.props.onScroll({nativeEvent: {contentOffset: {y: offset}}});
    });
  };
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockIdentity = 'user-1';
    mockRead.mockReset().mockResolvedValue(640);
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it('restores a stored position once when the learner has not interacted', async () => {
    await mount();
    expect(scrollTo).not.toHaveBeenCalled();
    await tick(80);
    expect(scrollTo).toHaveBeenCalledTimes(1);
    expect(scrollTo).toHaveBeenCalledWith({y: 640, animated: false});
    await update({loading: true});
    await update({loading: false});
    await tick(80);
    expect(scrollTo).toHaveBeenCalledTimes(1);
  });

  it('cancels an already scheduled restore as soon as the native drag begins and saves the new position', async () => {
    await mount();
    await tick(40);
    await dragTo(290);
    await tick(40);
    expect(scrollTo).not.toHaveBeenCalled();
    await tick(560);
    expect(mockSave).toHaveBeenCalledWith('@rokn/home-scroll/v1:user-1', 290);
    await update({loading: true});
    await update({loading: false});
    await tick(80);
    expect(scrollTo).not.toHaveBeenCalled();
  });

  it('does not adopt a late stored position after a drag while storage is reading', async () => {
    const read = deferred();
    mockRead.mockReturnValueOnce(read.promise);
    await mount();
    await dragTo(300);
    await act(async () => read.resolve(640));
    await tick(80);
    expect(scrollTo).not.toHaveBeenCalled();
    await tick(520);
    expect(mockSave).toHaveBeenCalledWith('@rokn/home-scroll/v1:user-1', 300);
  });

  it('does not replay restoration when loading completes after the learner moved', async () => {
    await mount({loading: true});
    await dragTo(310);
    await update({loading: false});
    await tick(80);
    expect(scrollTo).not.toHaveBeenCalled();
  });

  it('pauses a scheduled restore off screen and only resumes untouched restoration on return', async () => {
    await mount();
    await update({active: false});
    await tick(80);
    expect(scrollTo).not.toHaveBeenCalled();
    await update({active: true});
    await tick(80);
    expect(scrollTo).toHaveBeenCalledWith({y: 640, animated: false});
  });

  it('does not scroll the search surface and can restore when an untouched search is closed', async () => {
    await mount();
    await update({searchQuery: 'تصميم'});
    await tick(80);
    expect(scrollTo).not.toHaveBeenCalled();
    await update({searchQuery: ''});
    await tick(80);
    expect(scrollTo).toHaveBeenCalledWith({y: 640, animated: false});
  });

  it('does not use the preceding account position while the new owner storage is pending', async () => {
    await mount();
    const nextRead = deferred();
    mockRead.mockReturnValueOnce(nextRead.promise);
    mockIdentity = 'user-2';
    await update({identityKey: 'user-2'});
    await tick(80);
    expect(scrollTo).not.toHaveBeenCalled();
    await act(async () => nextRead.resolve(180));
    await tick(80);
    expect(scrollTo).toHaveBeenCalledTimes(1);
    expect(scrollTo).toHaveBeenCalledWith({y: 180, animated: false});
  });

  it('does not turn an absent saved position into a restore to the top', async () => {
    mockRead.mockResolvedValueOnce(null);
    await mount();
    await tick(80);
    expect(scrollTo).not.toHaveBeenCalled();
    expect(mockSave).not.toHaveBeenCalled();
  });

  it('accepts an actual stored zero as a valid position', async () => {
    mockRead.mockResolvedValueOnce(0);
    await mount();
    await tick(80);
    expect(scrollTo).toHaveBeenCalledWith({y: 0, animated: false});
  });

  it('cancels its restore timer on unmount', async () => {
    await mount();
    await act(async () => renderer!.unmount());
    renderer = undefined;
    await tick(80);
    expect(scrollTo).not.toHaveBeenCalled();
  });

  it('does not consume a guest handoff after leaving while its account persistence is pending', async () => {
    mockIdentity = 'guest-1';
    await mount({identityKey: 'guest-1', searchQuery: 'تصميم'});
    await act(async () => renderer!.unmount());
    renderer = undefined;

    let finishWrite!: () => void;
    const write = new Promise<undefined>(done => {
      finishWrite = () => done(undefined);
    });
    mockSave.mockReturnValueOnce(write);
    mockIdentity = 'user-1';
    await mount();
    expect(mockSave).toHaveBeenLastCalledWith(
      '@rokn/home-scroll/v1:user-1',
      640,
    );
    await act(async () => renderer!.unmount());
    renderer = undefined;
    await act(async () => finishWrite());
    expect(setSearchQuery).not.toHaveBeenCalled();

    // The old visit did not consume the handoff. The current visit can still
    // adopt it through the normal route, without a second handoff mechanism.
    await mount();
    expect(setSearchQuery).toHaveBeenCalledTimes(1);
    expect(setSearchQuery).toHaveBeenCalledWith('تصميم');
  });
});
