import {useCallback, useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {
  clearSearchHistory,
  getSearchHistory,
  rememberSearch,
} from '../../services/searchHistory';
import {trackProductEvent} from '../../services/productAnalytics';

export const useHomeSearch = (identityKey: string) => {
  const [query, setQuery] = useState('');
  const [focused, setFocused] = useState(false);
  const [history, setHistory] = useState<string[]>([]);
  const blurTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const historyGenerationRef = useRef(0);

  useEffect(() => {
    let current = true;
    const generation = ++historyGenerationRef.current;
    setHistory([]);
    void getSearchHistory()
      .then(items => {
        if (current && generation === historyGenerationRef.current) {
          setHistory(items);
        }
      })
      .catch(() => undefined);
    return () => {
      current = false;
      historyGenerationRef.current += 1;
    };
  }, [identityKey]);

  useEffect(
    () => () => {
      if (blurTimerRef.current) clearTimeout(blurTimerRef.current);
    },
    [],
  );

  const commit = useCallback((rawQuery: string) => {
    const value = rawQuery.trim().replace(/\s+/g, ' ');
    if (!value) return;
    const generation = ++historyGenerationRef.current;
    setQuery(value);
    setFocused(false);
    void rememberSearch(value)
      .then(items => {
        if (generation === historyGenerationRef.current) setHistory(items);
      })
      .catch(() => undefined);
    void trackProductEvent({
      event_name: 'search_submitted',
      screen_key: 'search',
      value: Math.min(value.length, 200),
    });
  }, []);

  const clearHistory = useCallback(() => {
    const generation = ++historyGenerationRef.current;
    setHistory([]);
    void clearSearchHistory().catch(() => {
      // An unremoved history reappears on the next visit. Show the stored
      // list again and say so, unless a newer search or account owns it.
      if (generation !== historyGenerationRef.current) return;
      Alert.alert('متمسحش', 'جرب تاني');
      void getSearchHistory()
        .then(items => {
          if (generation === historyGenerationRef.current) setHistory(items);
        })
        .catch(() => undefined);
    });
  }, []);

  const blur = useCallback(() => {
    if (blurTimerRef.current) clearTimeout(blurTimerRef.current);
    blurTimerRef.current = setTimeout(() => setFocused(false), 120);
  }, []);

  const focus = useCallback(() => {
    if (blurTimerRef.current) {
      clearTimeout(blurTimerRef.current);
      blurTimerRef.current = null;
    }
    setFocused(true);
  }, []);

  return {
    blur,
    clearHistory,
    commit,
    focus,
    focused,
    history,
    query,
    setQuery,
  };
};
