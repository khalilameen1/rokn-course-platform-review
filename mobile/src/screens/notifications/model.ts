import type {ImageSourcePropType} from 'react-native';

export type NotificationItem = {
  id: string;
  title: string;
  description: string;
  time: string;
  read: boolean;
  tone: 'learning' | 'project' | 'coins';
  link?: string;
  image?: ImageSourcePropType;
  actionLabel?: string;
};

export const notificationImageKey = (source?: ImageSourcePropType) => {
  if (typeof source === 'number') return `local:${source}`;
  if (!source || Array.isArray(source)) return '';
  return source.uri ? `uri:${source.uri}` : '';
};
