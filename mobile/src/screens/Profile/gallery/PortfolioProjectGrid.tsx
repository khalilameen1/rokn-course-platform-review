import React from 'react';
import {Image, Pressable, Text, View} from 'react-native';

import {MetaPill} from '../../../components/ui/PremiumUI';
import {formatArabicDisplayText} from '../../../constants/arabicFormatting';
import {portfolioProjectCoverUri, type Project} from './portfolioModel';
import {galleryStyles as styles} from './galleryStyles';

type Props = {
  cardWidth: number;
  gap: number;
  onCoverError: (project: Project) => void;
  onCoverLoad: (project: Project) => void;
  onOpen: (project: Project) => void;
  projects: Project[];
};

export const PortfolioProjectGrid = ({
  cardWidth,
  gap,
  onCoverError,
  onCoverLoad,
  onOpen,
  projects,
}: Props) => (
  <View style={[styles.grid, {gap}]}>
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
            {width: cardWidth},
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
            <Text numberOfLines={2} style={styles.projectTitle}>
              {formatArabicDisplayText(project.title)}
            </Text>
            <Text numberOfLines={2} style={styles.projectSummary}>
              {formatArabicDisplayText(project.summary)}
            </Text>
            <View style={styles.skillsRow}>
              {project.skills.slice(0, 2).map(skill => (
                <MetaPill key={skill} label={skill} />
              ))}
            </View>
          </View>
        </Pressable>
      );
    })}
  </View>
);
