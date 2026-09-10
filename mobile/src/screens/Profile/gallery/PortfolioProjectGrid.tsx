import React, {useState} from 'react';
import {Image, Pressable, Text, View} from 'react-native';

import {formatAuthoredDisplayText} from '../../../constants/arabicFormatting';
import {portfolioProjectCoverUri, type Project} from './portfolioModel';
import {galleryStyles as styles} from './galleryStyles';

type Props = {
  fontScale: number;
  gap: number;
  onCoverError: (project: Project) => void;
  onCoverLoad: (project: Project) => void;
  onOpen: (project: Project) => void;
  projects: Project[];
};

export const PortfolioProjectGrid = ({
  fontScale,
  gap,
  onCoverError,
  onCoverLoad,
  onOpen,
  projects,
}: Props) => {
  const [availableWidth, setAvailableWidth] = useState(0);
  // The frame and safe area own the gutters. Measure the space this grid
  // actually receives instead of reconstructing it from the window width.
  const minimumCardWidth = 300 * Math.max(1, Math.min(fontScale, 1.5));
  const columns = Math.max(
    1,
    Math.min(3, Math.floor((availableWidth + gap) / (minimumCardWidth + gap))),
  );
  const cardWidth = (availableWidth - gap * (columns - 1)) / columns;

  return (
    <View
      onLayout={event => setAvailableWidth(event.nativeEvent.layout.width)}
      style={[styles.grid, {gap}]}>
      {projects.map(project => {
        const remoteCoverUri = portfolioProjectCoverUri(project);
        return (
          <Pressable
            accessibilityLabel={`فتح مشروع ${project.title}`}
            accessibilityRole="button"
            key={project.id}
            onPress={() => onOpen(project)}
            style={({pressed}) => [
              styles.projectCard,
              {width: availableWidth > 0 ? cardWidth : '100%'},
              pressed && styles.pressed,
            ]}>
            <Image
              onError={remoteCoverUri ? () => onCoverError(project) : undefined}
              onLoad={remoteCoverUri ? () => onCoverLoad(project) : undefined}
              progressiveRenderingEnabled
              resizeMethod="resize"
              source={project.cover}
              style={styles.cover}
            />
            <View style={styles.projectCopy}>
              <Text style={styles.projectTitle}>
                {formatAuthoredDisplayText(project.title)}
              </Text>
              <Text numberOfLines={2} style={styles.projectSummary}>
                {formatAuthoredDisplayText(project.summary)}
              </Text>
              {!!project.skills.length && (
                <Text style={styles.projectSkills}>
                  {project.skills
                    .slice(0, 2)
                    .map(formatAuthoredDisplayText)
                    .join(' · ')}
                </Text>
              )}
            </View>
          </Pressable>
        );
      })}
    </View>
  );
};
