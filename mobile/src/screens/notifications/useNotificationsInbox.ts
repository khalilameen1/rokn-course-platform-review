import {useFocusEffect} from '@react-navigation/native';
import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {
  AccessibilityInfo,
  AppState,
  type ImageSourcePropType,
} from 'react-native';
import {
  getCachedPublishedCourses,
  getNotificationsPage,
  hasSession,
  markAllNotificationsRead,
  markNotificationRead,
  type Notification as NotificationDto,
} from '../../services/roknApi';
import {openExternalUrlOnce} from '../../services/systemActions';
import {
  isExternalWebLink,
  parseRoknDestination,
} from '../../navigation/deepLinks';
import {openRoknDestination} from '../../navigation/RootNavigationHelper';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {networkFailureKind} from '../../services/networkExperience';
import {formatRoknRelativeDate} from '../../utils/dateTime';
import {
  notificationCacheKey,
  readCachedNotifications,
  saveCachedNotifications,
} from './cache';
import {notificationImageKey, type NotificationItem} from './model';

export function useNotificationsInbox() {
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [serverNotifications, setServerNotifications] = useState<
    NotificationDto[]
  >([]);
  const [notificationError, setNotificationError] = useState('');
  const [loading, setLoading] = useState(true);
  const [failedImages, setFailedImages] = useState<Record<string, string>>({});
  const [notificationCursor, setNotificationCursor] = useState<string | null>(
    null,
  );
  const [hasMoreNotifications, setHasMoreNotifications] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [screenReaderEnabled, setScreenReaderEnabled] = useState(false);
  const [courseImages, setCourseImages] = useState<
    Record<string, ImageSourcePropType>
  >({});
  const notificationGenerationRef = useRef(0);
  const notificationMutationRevisionRef = useRef(0);
  const locallyReadNotificationIdsRef = useRef(new Set<string>());
  const notificationReadScopeRef = useRef<string | null>(null);
  const refreshControllerRef = useRef<AbortController | null>(null);
  const loadMoreControllerRef = useRef<AbortController | null>(null);
  const notificationCacheKeyRef = useRef<string | null>(null);
  const notificationCacheBoundaryRef = useRef<AccountSessionBoundary | null>(
    null,
  );
  const loadMoreFlightRef = useRef<symbol | null>(null);
  const markAllFlightRef = useRef<symbol | null>(null);
  const readFlightsRef = useRef(new Map<string, symbol>());
  const lastRefreshAtRef = useRef(0);
  const notificationErrorRef = useRef('');
  notificationErrorRef.current = notificationError;
  const serverNotificationsRef = useRef(serverNotifications);
  serverNotificationsRef.current = serverNotifications;

  useEffect(() => {
    let active = true;
    void AccessibilityInfo.isScreenReaderEnabled().then(enabled => {
      if (active) setScreenReaderEnabled(enabled);
    });
    const subscription = AccessibilityInfo.addEventListener(
      'screenReaderChanged',
      setScreenReaderEnabled,
    );
    return () => {
      active = false;
      subscription.remove();
    };
  }, []);

  const refreshNotifications = useCallback(async () => {
    refreshControllerRef.current?.abort();
    loadMoreControllerRef.current?.abort();
    const controller = new AbortController();
    refreshControllerRef.current = controller;
    lastRefreshAtRef.current = Date.now();
    const requestGeneration = ++notificationGenerationRef.current;
    const mutationRevision = notificationMutationRevisionRef.current;
    loadMoreFlightRef.current = null;
    setLoadingMore(false);
    setLoading(true);
    try {
      const boundary = await captureAccountSessionBoundary();
      const scopedCacheKey = await notificationCacheKey(boundary);
      assertAccountSessionBoundary(boundary);
      if (requestGeneration !== notificationGenerationRef.current) return;
      if (notificationReadScopeRef.current !== scopedCacheKey) {
        locallyReadNotificationIdsRef.current.clear();
        notificationReadScopeRef.current = scopedCacheKey;
      }
      if (
        notificationCacheKeyRef.current !== null &&
        notificationCacheKeyRef.current !== scopedCacheKey
      ) {
        // Never leave the previous account's inbox visible or let one of its
        // mark-read flights block a notification with the same id.
        readFlightsRef.current.clear();
        markAllFlightRef.current = null;
        setServerNotifications([]);
        setCourseImages({});
        setNotificationCursor(null);
        setHasMoreNotifications(false);
        setNotificationError('');
      }
      notificationCacheKeyRef.current = scopedCacheKey;
      notificationCacheBoundaryRef.current = boundary;
      const sessionAvailable = await hasSession();
      assertAccountSessionBoundary(boundary);
      if (requestGeneration !== notificationGenerationRef.current) return;
      setServerSession(sessionAvailable);
      if (!sessionAvailable) {
        readFlightsRef.current.clear();
        markAllFlightRef.current = null;
        notificationCacheKeyRef.current = null;
        notificationCacheBoundaryRef.current = null;
        setServerNotifications([]);
        setCourseImages({});
        setNotificationCursor(null);
        setHasMoreNotifications(false);
        setNotificationError('');
        return;
      }
      const cachedNotifications = await readCachedNotifications(
        scopedCacheKey,
        boundary,
      );
      if (
        requestGeneration === notificationGenerationRef.current &&
        cachedNotifications.length
      ) {
        setServerNotifications(current => {
          const locallyRead = new Set(locallyReadNotificationIdsRef.current);
          if (mutationRevision !== notificationMutationRevisionRef.current) {
            current
              .filter(item => item.read)
              .forEach(item => locallyRead.add(item.id));
          }
          return cachedNotifications.map(item =>
            locallyRead.has(item.id) && !item.read
              ? {...item, read: true}
              : item,
          );
        });
        setLoading(false);
      }
      assertAccountSessionBoundary(boundary);
      const [page, cachedCourses] = await Promise.all([
        getNotificationsPage({
          signal: controller.signal,
          ownerBoundary: boundary,
        }),
        getCachedPublishedCourses().catch(() => []),
      ]);
      assertAccountSessionBoundary(boundary);
      if (requestGeneration !== notificationGenerationRef.current) return;
      setServerNotifications(current => {
        const locallyRead = new Set(locallyReadNotificationIdsRef.current);
        if (mutationRevision !== notificationMutationRevisionRef.current) {
          current
            .filter(item => item.read)
            .forEach(item => locallyRead.add(item.id));
        }
        const next = page.notifications.map(item =>
          locallyRead.has(item.id) && !item.read ? {...item, read: true} : item,
        );
        void saveCachedNotifications(scopedCacheKey, next, boundary).catch(
          () => undefined,
        );
        return next;
      });
      setCourseImages(
        Object.fromEntries(
          cachedCourses.map(course => [course.id, course.image]),
        ),
      );
      setNotificationCursor(page.nextCursor);
      setHasMoreNotifications(page.hasMore);
      setNotificationError('');
    } catch (error) {
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      )
        return;
      if (networkFailureKind(error) === 'cancelled') return;
      if (requestGeneration === notificationGenerationRef.current) {
        setNotificationError('تعذّر تحديث الإشعارات\nحاول مرة أخرى');
      }
    } finally {
      if (requestGeneration === notificationGenerationRef.current) {
        if (refreshControllerRef.current === controller) {
          refreshControllerRef.current = null;
        }
        setLoading(false);
      }
    }
  }, []);

  const loadMoreNotifications = useCallback(async () => {
    if (
      serverSession !== true ||
      loading ||
      loadingMore ||
      loadMoreFlightRef.current ||
      !hasMoreNotifications
    ) {
      return;
    }
    const flight = Symbol('notifications-load-more');
    const controller = new AbortController();
    loadMoreControllerRef.current?.abort();
    loadMoreControllerRef.current = controller;
    const requestGeneration = notificationGenerationRef.current;
    loadMoreFlightRef.current = flight;
    setLoadingMore(true);
    try {
      const boundary = notificationCacheBoundaryRef.current;
      if (!boundary) return;
      assertAccountSessionBoundary(boundary);
      const page = await getNotificationsPage({
        cursor: notificationCursor,
        signal: controller.signal,
        ownerBoundary: boundary,
      });
      assertAccountSessionBoundary(boundary);
      if (
        loadMoreFlightRef.current !== flight ||
        requestGeneration !== notificationGenerationRef.current
      )
        return;
      setServerNotifications(current => {
        const merged = new Map(current.map(item => [item.id, item]));
        page.notifications.forEach(item => {
          const existing = merged.get(item.id);
          merged.set(
            item.id,
            (existing?.read ||
              locallyReadNotificationIdsRef.current.has(item.id)) &&
              !item.read
              ? {...item, read: true}
              : item,
          );
        });
        const next = Array.from(merged.values());
        void saveCachedNotifications(
          notificationCacheKeyRef.current,
          next,
          boundary,
        ).catch(() => undefined);
        return next;
      });
      setNotificationCursor(page.nextCursor);
      setHasMoreNotifications(page.hasMore);
      setNotificationError('');
    } catch (error) {
      if (networkFailureKind(error) === 'cancelled') return;
      if (
        loadMoreFlightRef.current === flight &&
        requestGeneration === notificationGenerationRef.current
      ) {
        setNotificationError('تعذّر تحميل الإشعارات الأقدم\nحاول مرة أخرى');
      }
    } finally {
      if (loadMoreFlightRef.current === flight) {
        if (loadMoreControllerRef.current === controller) {
          loadMoreControllerRef.current = null;
        }
        loadMoreFlightRef.current = null;
        setLoadingMore(false);
      }
    }
  }, [
    hasMoreNotifications,
    loading,
    loadingMore,
    notificationCursor,
    serverSession,
  ]);

  useFocusEffect(
    useCallback(() => {
      void refreshNotifications();
      let previousState = AppState.currentState;
      const appStateSubscription = AppState.addEventListener(
        'change',
        state => {
          const returnedToForeground =
            state === 'active' && previousState !== 'active';
          previousState = state;
          if (
            returnedToForeground &&
            Date.now() - lastRefreshAtRef.current >= 15_000
          ) {
            void refreshNotifications();
          }
        },
      );
      let reconnectAttempts = 0;
      const reconnectTimer = setInterval(() => {
        if (
          notificationErrorRef.current &&
          !refreshControllerRef.current &&
          AppState.currentState === 'active'
        ) {
          if (reconnectAttempts >= 3) {
            clearInterval(reconnectTimer);
            return;
          }
          reconnectAttempts += 1;
          void refreshNotifications();
        }
      }, 20_000);
      return () => {
        refreshControllerRef.current?.abort();
        refreshControllerRef.current = null;
        loadMoreControllerRef.current?.abort();
        loadMoreControllerRef.current = null;
        appStateSubscription.remove();
        notificationGenerationRef.current += 1;
        notificationCacheKeyRef.current = null;
        notificationCacheBoundaryRef.current = null;
        loadMoreFlightRef.current = null;
        markAllFlightRef.current = null;
        readFlightsRef.current.clear();
        clearInterval(reconnectTimer);
      };
    }, [refreshNotifications]),
  );

  const source = useMemo<NotificationItem[]>(() => {
    if (serverSession !== true) {
      return [];
    }
    return serverNotifications.map(item => ({
      id: item.id,
      title: item.title,
      description: item.description,
      time: formatRoknRelativeDate(item.createdAt),
      read: item.read,
      tone: item.tone,
      link: item.link,
      image: item.imageUrl
        ? {uri: item.imageUrl}
        : item.courseId
        ? courseImages[item.courseId]
        : undefined,
      actionLabel: item.actionLabel,
    }));
  }, [courseImages, serverNotifications, serverSession]);

  useEffect(() => {
    const activeImages = new Map(
      source.map(item => [item.id, notificationImageKey(item.image)]),
    );
    setFailedImages(current => {
      const entries = Object.entries(current).filter(
        ([id, failedSource]) => activeImages.get(id) === failedSource,
      );
      return entries.length === Object.keys(current).length
        ? current
        : Object.fromEntries(entries);
    });
  }, [source]);

  const hasUnread = source.some(item => !item.read);

  const markAllRead = async () => {
    if (serverSession === true) {
      if (markAllFlightRef.current) return;
      const flight = Symbol('notifications-mark-all-read');
      markAllFlightRef.current = flight;
      const requestGeneration = notificationGenerationRef.current;
      const boundary = notificationCacheBoundaryRef.current;
      if (!boundary) {
        markAllFlightRef.current = null;
        return;
      }
      try {
        assertAccountSessionBoundary(boundary);
        await markAllNotificationsRead(boundary);
        assertAccountSessionBoundary(boundary);
        if (markAllFlightRef.current === flight) {
          notificationMutationRevisionRef.current += 1;
          serverNotificationsRef.current.forEach(item =>
            locallyReadNotificationIdsRef.current.add(item.id),
          );
          setServerNotifications(current => {
            const next = current.map(item => ({...item, read: true}));
            void saveCachedNotifications(
              notificationCacheKeyRef.current,
              next,
              boundary,
            ).catch(() => undefined);
            return next;
          });
        }
      } catch (error) {
        if (
          error instanceof Error &&
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        )
          return;
        if (requestGeneration === notificationGenerationRef.current) {
          setNotificationError('تعذّر تحديث حالة القراءة\nحاول مرة أخرى');
          void refreshNotifications();
        }
      } finally {
        if (markAllFlightRef.current === flight) {
          markAllFlightRef.current = null;
        }
      }
    }
  };

  const openNotification = useCallback(
    async (item: NotificationItem, read: boolean) => {
      const requestGeneration = notificationGenerationRef.current;
      const boundary = notificationCacheBoundaryRef.current;
      const cacheKey = notificationCacheKeyRef.current;
      if (!read) {
        if (serverSession === true) {
          if (boundary && !readFlightsRef.current.has(item.id)) {
            const flight = Symbol(`notification-read-${item.id}`);
            readFlightsRef.current.set(item.id, flight);
            void markNotificationRead(item.id, boundary)
              .then(() => {
                assertAccountSessionBoundary(boundary);
                if (readFlightsRef.current.get(item.id) !== flight) return;
                notificationMutationRevisionRef.current += 1;
                locallyReadNotificationIdsRef.current.add(item.id);
                setServerNotifications(current => {
                  const next = current.map(notification =>
                    notification.id === item.id
                      ? {...notification, read: true}
                      : notification,
                  );
                  void saveCachedNotifications(cacheKey, next, boundary).catch(
                    () => undefined,
                  );
                  return next;
                });
              })
              .catch(error => {
                if (
                  error instanceof Error &&
                  error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
                ) {
                  return;
                }
                if (requestGeneration === notificationGenerationRef.current) {
                  setNotificationError('تعذّر تحديث حالة القراءة');
                }
              })
              .finally(() => {
                if (readFlightsRef.current.get(item.id) === flight) {
                  readFlightsRef.current.delete(item.id);
                }
              });
          }
        }
      }
      if (item.link) {
        const destination = parseRoknDestination(item.link);
        if (destination) {
          openRoknDestination(destination);
          return;
        }
        try {
          if (isExternalWebLink(item.link)) {
            await openExternalUrlOnce(item.link);
            return;
          }
          setNotificationError(
            'هذا الإشعار لم يعد متاحًا\nحدّث الصفحة ثم حاول مرة أخرى',
          );
        } catch {
          setNotificationError('تعذّر فتح الإشعار الآن');
        }
      }
    },
    [serverSession],
  );

  const markImageFailed = useCallback((id: string, imageKey: string) => {
    setFailedImages(current =>
      current[id] === imageKey ? current : {...current, [id]: imageKey},
    );
  }, []);

  return {
    failedImages,
    hasMoreNotifications,
    hasUnread,
    loadMoreNotifications,
    loading,
    loadingMore,
    markAllRead,
    markImageFailed,
    notificationError,
    openNotification,
    refreshNotifications,
    screenReaderEnabled,
    serverSession,
    source,
  };
}
