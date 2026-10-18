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
  PORTFOLIO_SKILLS_MAX,
} from '../../../services/api/portfolioContract';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {isPortfolioAccountChangedError} from './portfolioModel';
import {learnerCopy} from '../../../constants/learnerCopy';

const CHIP_SEPARATORS = /[,،\n]/;

/**
 * Profile skills (Behance "About" tools). Reads the authoritative profile on
 * open; never saves a partial or stale list over the server copy.
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
  const generationRef = useRef(0);

  const openProfileEditor = useCallback(() => {
    const generation = ++generationRef.current;
    setOpen(true);
    setLoading(true);
    setError('');
    setSkillInput('');
    void captureBoundary()
      .then(async boundary => {
        const profile = await getPortfolioProfile(boundary);
        assertAccountSessionBoundary(boundary);
        if (!mountedRef.current || generation !== generationRef.current) return;
        setSkills(profile.skills);
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

  const saveProfile = useCallback(async () => {
    if (loading || saving) return;
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
      setError(learnerErrorMessage(saveError, `متحفظتش التعديلات\n${learnerCopy.retry}`));
    } finally {
      if (mountedRef.current) setSaving(false);
    }
  }, [
    captureBoundary,
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
    },
    addSkill,
    closeProfileEditor,
    openProfileEditor,
    removeSkill,
    saveProfile,
    setSkillInput,
  };
};
