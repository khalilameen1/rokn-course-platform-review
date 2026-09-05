import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Text} from 'react-native';
import FeedFooter from '../src/components/VideoPlayer/FeedFooter';
import SearchAssist from '../src/components/search/SearchAssist';
import CoursesSection from '../src/components/view/CoursesSection';
import type {Course} from '../src/types/Course';
import {PortfolioProjectGrid} from '../src/screens/Profile/gallery/PortfolioProjectGrid';
import type {CourseReel} from '../src/components/VideoPlayer/types';
import type {Project} from '../src/screens/Profile/gallery/portfolioModel';
import {cleanUnicodeText} from '../src/utils/unicodeText';

const authoredTitle = 'ريلز 2026';
const authoredCode = 'const label = "ريلز";\nconst limit = 3;';

const renderText = (
  element: React.ReactElement,
  verify: (renderer: TestRenderer.ReactTestRenderer, texts: unknown[]) => void,
) => {
  let renderer!: TestRenderer.ReactTestRenderer;
  try {
    act(() => {
      renderer = TestRenderer.create(element);
    });
    verify(
      renderer,
      renderer.root
        .findAllByType(Text)
        .map(node =>
          typeof node.props.children === 'string'
            ? cleanUnicodeText(node.props.children)
            : node.props.children,
        ),
    );
  } finally {
    act(() => renderer?.unmount());
  }
};

describe('authored text is not localized as interface copy', () => {
  it('preserves an authored home row heading through the shared section component', () => {
    const course = {id: 'course-1', title: 'Blender', image: 1} as Course;
    renderText(
      <CoursesSection
        title={authoredTitle}
        data={[course]}
        onCoursePress={jest.fn()}
      />,
      (_renderer, texts) => expect(texts).toContain(authoredTitle),
    );
  });

  it('preserves lesson title and code caption while localizing the reel counter', () => {
    const reel = {
      id: 'lesson-1',
      title: authoredTitle,
      caption: authoredCode + '\u202e',
      reelNumber: 3,
    } as CourseReel;
    renderText(<FeedFooter data={reel} />, (_renderer, texts) => {
      expect(texts).toContain(authoredTitle);
      expect(texts).toContain(authoredCode);
      expect(texts).toContainEqual(['المقطع ', '٣']);
    });
  });

  it('preserves the learner portfolio title and summary beneath direction-only formatting', () => {
    const project: Project = {
      id: 'portfolio-1',
      title: authoredTitle,
      summary: authoredCode + '\u0000',
      cover: 1,
      skills: [],
      source: 'remote',
      media: [],
      shareReady: false,
    };
    renderText(
      <PortfolioProjectGrid
        projects={[project]}
        cardWidth={180}
        gap={8}
        onCoverError={jest.fn()}
        onCoverLoad={jest.fn()}
        onOpen={jest.fn()}
      />,
      (_renderer, texts) => {
        expect(texts).toContain(authoredTitle);
        expect(texts).toContain(authoredCode);
      },
    );
  });

  it('shows the same authored search query that selecting the history chip submits', () => {
    const onSelect = jest.fn();
    renderText(
      <SearchAssist
        recent={[authoredTitle]}
        suggestions={[]}
        visible
        onClearRecent={jest.fn()}
        onSelect={onSelect}
      />,
      (renderer, texts) => {
        expect(texts).toContain(authoredTitle);
        const chip = renderer.root.findByProps({
          accessibilityLabel: `ابحث عن ${authoredTitle}`,
        });
        act(() => chip.props.onPress());
        expect(onSelect).toHaveBeenCalledWith(authoredTitle);
      },
    );
  });
});
