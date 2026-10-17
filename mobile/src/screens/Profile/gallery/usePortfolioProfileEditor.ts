import {useCallback, useRef, useState} from 'react';

import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  getPortfolioProfile,
  updatePortfolioProfile,
  type PortfolioProfile,
} from '../../../services/roknApi';
import {
  normalizePortfolioChips,
  PORTFOLIO_LINKS_MAX,
  PORTFOLIO_SKILLS_MAX,
  portfolioLinkError,
  whatsAppLinkFromPhone,
  type PortfolioLink,
} from '../../../services/api/portfolioContract';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {isPortfolioAccountChangedError} from './portfolioModel';

const CHIP_SEPARATORS = /[,،\n]/;

/**
 * Profile skills and contact buttons (Behance "About" / Dribbble profile
 * links). Reads the authoritative profile on open; never saves a partial or
 * stale list over the server copy.
 */
export const usePortfolioProfileEditor = ({
  captureBoundary,
  mountedRef,
  onProfileChange,
}: {
  captureBoundary: () => Promise<AccountSessionBoundary>;
  mountedRef: React.MutableRefObject<boolean>;
  onProfileChange?: (profile: PortfolioProfile) => void;
}) => {
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [skills, setSkills] = useState<string[]>([]);
  const [skillInput, setSkillInput] = useState('');
  const [links, setLinks] = useState<PortfolioLink[]>([]);
  const [whatsAppInput, setWhatsAppInput] = useState('');
  const generationRef = useRef(0);

  const openProfileEditor = useCallback(() => {
    const generation = ++generationRef.current;
    setOpen(true);
    setLoading(true);
    setError('');
    setSkillInput('');
    setWhatsAppInput('');
    void captureBoundary()
      .then(async boundary => {
        const profile = await getPortfolioProfile(boundary);
        assertAccountSessionBoundary(boundary);
        if (!mountedRef.current || generation !== generationRef.current) return;
        setSkills(profile.skills);
        setLinks(profile.links);
        setLoading(false);
      })
      .catch(readError => {
        if (!mountedRef.current || generation !== generationRef.current) return;
        if (isPortfolioAccountChangedError(readError)) {
          setOpen(false);
          return;
        }
        setLoading(false);
        setError('تعذّر تحميل بياناتك\nاقفل وافتح تاني');
      });
  }, [captureBoundary, mountedRef]);

  const closeProfileEditor = useCallback(() => {
    if (saving) return;
    generationRef.current += 1;
    setOpen(false);
  }, [saving]);

  const addSkill = useCallback(() => {
    setSkills(current =>
      normalizePortfolioChips(
        [...current, ...skillInput.split(CHIP_SEPARATORS)],
        PORTFOLIO_SKILLS_MAX,
      ),
    );
    setSkillInput('');
  }, [skillInput]);

  const removeSkill = useCallback((skill: string) => {
    setSkills(current => current.filter(candidate => candidate !== skill));
  }, []);

  const addLink = useCallback(() => {
    setLinks(current =>
      current.length >= PORTFOLIO_LINKS_MAX
        ? current
        : [...current, {label: '', url: 'https://'}],
    );
  }, []);

  const updateLink = useCallback(
    (index: number, change: Partial<PortfolioLink>) => {
      setLinks(current =>
        current.map((link, position) =>
          position === index ? {...link, ...change} : link,
        ),
      );
    },
    [],
  );

  const removeLink = useCallback((index: number) => {
    setLinks(current => current.filter((_, position) => position !== index));
  }, []);

  /** Builds https://wa.me/<digits> from a local Egyptian or + number. */
  const addWhatsApp = useCallback(() => {
    const url = whatsAppLinkFromPhone(whatsAppInput);
    if (!url) {
      setError('اكتب رقم واتساب صحيح زي 01012345678');
      return;
    }
    setError('');
    setLinks(current => {
      const others = current.filter(
        link => !/^https:\/\/(?:www\.)?wa\.me\//i.test(link.url),
      );
      return [{label: 'واتساب', url}, ...others].slice(0, PORTFOLIO_LINKS_MAX);
    });
    setWhatsAppInput('');
  }, [whatsAppInput]);

  const saveProfile = useCallback(async () => {
    if (loading || saving) return;
    const cleanLinks = links.filter(
      link => link.label.trim() || link.url.replace(/^https:\/\/$/i, '').trim(),
    );
    const invalid = cleanLinks.map(portfolioLinkError).find(Boolean);
    if (invalid) {
      setError(invalid);
      return;
    }
    const generation = generationRef.current;
    setSaving(true);
    setError('');
    try {
      const boundary = await captureBoundary();
      const profile = await updatePortfolioProfile(
        {
          skills: normalizePortfolioChips(
            [...skills, ...skillInput.split(CHIP_SEPARATORS)],
            PORTFOLIO_SKILLS_MAX,
          ),
          links: cleanLinks,
        },
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      if (!mountedRef.current) return;
      onProfileChange?.(profile);
      if (generation === generationRef.current) setOpen(false);
    } catch (saveError) {
      if (!mountedRef.current || isPortfolioAccountChangedError(saveError))
        return;
      setError(learnerErrorMessage(saveError, 'متحفظتش التعديلات\nجرب تاني'));
    } finally {
      if (mountedRef.current) setSaving(false);
    }
  }, [
    captureBoundary,
    links,
    loading,
    mountedRef,
    onProfileChange,
    saving,
    skillInput,
    skills,
  ]);

  return {
    profileEditor: {
      open,
      loading,
      saving,
      error,
      skills,
      skillInput,
      links,
      whatsAppInput,
      maxLinksReached: links.length >= PORTFOLIO_LINKS_MAX,
    },
    addLink,
    addSkill,
    addWhatsApp,
    closeProfileEditor,
    openProfileEditor,
    removeLink,
    removeSkill,
    saveProfile,
    setSkillInput,
    setWhatsAppInput,
    updateLink,
  };
};
