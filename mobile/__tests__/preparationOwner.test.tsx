import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {usePreparationOwner} from '../src/hooks/usePreparationOwner';

describe('preparation visit ownership', () => {
  it('does not revive a departed visit when the same screen reopens', () => {
    let current!: () => boolean;
    const Probe = ({active = true, scope = 'project-7'}) => {
      current = usePreparationOwner(scope, active);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    act(() => {
      renderer = TestRenderer.create(<Probe />);
    });
    const firstVisit = current;
    try {
      expect(firstVisit()).toBe(true);
      act(() => {
        renderer.update(<Probe active={false} />);
      });
      expect(firstVisit()).toBe(false);
      expect(current()).toBe(false);
      act(() => {
        renderer.update(<Probe />);
      });
      expect(firstVisit()).toBe(false);
      expect(current()).toBe(true);
      const reopenedVisit = current;
      act(() => {
        renderer.update(<Probe scope="project-8" />);
      });
      expect(reopenedVisit()).toBe(false);
      expect(current()).toBe(true);
    } finally {
      act(() => renderer.unmount());
    }
    expect(firstVisit()).toBe(false);
    expect(current()).toBe(false);
  });

  it('keeps the same owner through ordinary renders within a visit', () => {
    let current!: () => boolean;
    const Probe = ({revision}: {revision: number}) => {
      current = usePreparationOwner('project-7', true);
      return <React.Fragment>{revision}</React.Fragment>;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    act(() => {
      renderer = TestRenderer.create(<Probe revision={1} />);
    });
    const owner = current;
    try {
      act(() => {
        renderer.update(<Probe revision={2} />);
      });
      expect(current).toBe(owner);
      expect(owner()).toBe(true);
    } finally {
      act(() => renderer.unmount());
    }
    expect(owner()).toBe(false);
  });
});
