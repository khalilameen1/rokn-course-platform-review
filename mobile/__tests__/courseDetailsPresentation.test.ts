import {courseDurationMinutes} from '../src/utils/courseDetailsPresentation';

describe('course details presentation', () => {
  it('uses the canonical course duration from metadata', () => {
    expect(
      courseDurationMinutes({
        metadata: {duration_minutes: 95},
      }),
    ).toBe(95);
  });

  it('does not invent a duration for missing, zero, or invalid metadata', () => {
    expect(courseDurationMinutes(undefined)).toBeNull();
    expect(
      courseDurationMinutes({metadata: {duration_minutes: 0}}),
    ).toBeNull();
    expect(
      courseDurationMinutes({metadata: {duration_minutes: 'unknown'}}),
    ).toBeNull();
  });
});
