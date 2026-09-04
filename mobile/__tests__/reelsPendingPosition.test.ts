import {resolvePendingFeedPosition} from '../src/screens/reels/useReelsPosition';

describe('reels pending position', () => {
  const feed = [{key: 'reel-1'}, {key: 'project-1'}];

  it('waits for a project target that is not in the old feed revision yet', () => {
    expect(
      resolvePendingFeedPosition([{key: 'reel-1'}], {
        key: 'project-1',
        index: null,
      }),
    ).toBeNull();
    expect(
      resolvePendingFeedPosition(feed, {key: 'project-1', index: null}),
    ).toBe(1);
  });

  it('does not clamp a not-yet-present index back onto the completed reel', () => {
    expect(
      resolvePendingFeedPosition([{key: 'reel-1'}], {key: null, index: 1}),
    ).toBeNull();
    expect(resolvePendingFeedPosition(feed, {key: null, index: 1})).toBe(1);
  });

  it('rejects stale or invalid positions instead of guessing a destination', () => {
    expect(resolvePendingFeedPosition(feed, {key: 'missing', index: 0})).toBe(
      null,
    );
    expect(resolvePendingFeedPosition(feed, {key: null, index: -1})).toBe(
      null,
    );
    expect(
      resolvePendingFeedPosition(feed, {key: null, index: Number.NaN}),
    ).toBeNull();
  });
});
