import React, {useState} from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetItem = jest.fn();
const mockUpdate = jest.fn();

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  assertPortfolioUploadAccess: jest.fn(async () => undefined),
  finalizePortfolioItem: jest.fn(),
  getPortfolioItem: (...args: unknown[]) => mockGetItem(...args),
  updatePortfolioItem: (...args: unknown[]) => mockUpdate(...args),
  deletePortfolioMedia: jest.fn(async () => undefined),
  deletePortfolioItem: jest.fn(async () => undefined),
}));
jest.mock('react-native-image-picker', () => ({launchImageLibrary: jest.fn()}));
jest.mock('../src/services/portfolioMediaOutbox', () => ({
  discardPortfolioMediaUploads: async () => undefined,
  listPortfolioMediaUploads: async () => [],
}));
jest.mock('../src/services/portfolioMediaUpload', () => ({
  stagePortfolioMediaFiles: async () => [],
  uploadPortfolioMediaFiles: jest.fn(),
}));
jest.mock('../src/services/mediaPickerErrors', () => ({
  showMediaPickerFailure: jest.fn(),
}));

import {usePortfolioProjectDetails} from '../src/screens/Profile/gallery/usePortfolioProjectDetails';
import {
  toPortfolioProject,
  type Project,
} from '../src/screens/Profile/gallery/portfolioModel';
import type {PortfolioItem} from '../src/services/roknApi';

const boundary = {epoch: 1, scope: 'owner-a'};
// Stable owner callbacks, as the gallery controller provides them.
const captureBoundary = async () => boundary;
const isCreateBusy = () => false;
const cancelLibraryLoad = jest.fn();
const setMutationBlocked = jest.fn();
const item = (id: string, overrides: Partial<PortfolioItem> = {}): PortfolioItem => ({
  id,
  publicId: `00000000-0000-4000-8000-00000000000${id}`,
  title: `مشروع ${id}`,
  summary: '',
  role: '',
  tools: [],
  externalUrl: '',
  hidden: false,
  sortOrder: 0,
  featured: false,
  media: [
    {id: `${id}1`, type: 'image', status: 'ready', uri: `https://cdn/${id}1.jpg`},
    {id: `${id}2`, type: 'image', status: 'ready', uri: `https://cdn/${id}2.jpg`},
  ],
  publicationState: 'published',
  uploadedMediaCount: 2,
  expectedMediaCount: 2,
  ...overrides,
});
const settle = async (run: () => unknown) => {
  await act(async () => {
    await run();
    await Promise.resolve();
  });
};

describe('portfolio project editor fields and presentation (Behance/Dribbble)', () => {
  let owner: ReturnType<typeof usePortfolioProjectDetails>;
  let projects: Project[];
  let renderer: TestRenderer.ReactTestRenderer;
  const mountedRef = {current: true};
  const server = new Map<string, PortfolioItem>();
  const Harness = () => {
    // Newest first, as the API returns equal sort_order projects.
    const [library, setLibrary] = useState(
      ['3', '2', '1'].map(id => toPortfolioProject(server.get(id)!)),
    );
    projects = library;
    owner = usePortfolioProjectDetails({
      cancelLibraryLoad,
      captureBoundary,
      isCreateBusy,
      libraryProjects: library,
      mountedRef,
      setLibraryProjects: setLibrary,
      setMutationBlocked,
    });
    return null;
  };

  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    server.clear();
    ['1', '2', '3'].forEach(id => server.set(id, item(id)));
    mockGetItem.mockImplementation(async (id: string) => server.get(id));
    mockUpdate.mockImplementation(async (id: string, patch) => {
      const current = server.get(id)!;
      const next = item(id, {
        ...current,
        ...(patch.title !== undefined ? {title: patch.title} : {}),
        ...(patch.role !== undefined ? {role: patch.role} : {}),
        ...(patch.tools !== undefined ? {tools: patch.tools} : {}),
        ...(patch.externalUrl !== undefined
          ? {externalUrl: patch.externalUrl}
          : {}),
        ...(patch.visible !== undefined ? {hidden: !patch.visible} : {}),
        ...(patch.featured !== undefined ? {featured: patch.featured} : {}),
        ...(patch.sortOrder !== undefined ? {sortOrder: patch.sortOrder} : {}),
        ...(patch.coverMediaId !== undefined
          ? {
              coverMediaId: patch.coverMediaId,
              coverUri: current.media.find(m => m.id === patch.coverMediaId)
                ?.uri,
            }
          : {}),
      });
      server.set(id, next);
      return next;
    });
    await settle(() => {
      renderer = TestRenderer.create(<Harness />);
    });
  });
  afterEach(async () => {
    await settle(() => renderer.unmount());
    jest.clearAllTimers();
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  const open = (id: string) =>
    settle(() => owner.openProject(projects.find(p => p.id === id)!));

  it('saves role, tools chips (including a typed one) and the project link', async () => {
    await open('2');
    await settle(() => owner.beginEdit());
    await settle(() => owner.setEditRole('صممت الهوية'));
    await settle(() => owner.setEditToolInput('فوتوشوب'));
    await settle(() => owner.addEditTool());
    await settle(() => owner.setEditToolInput('Figma'));
    await settle(() => owner.setEditExternalUrl('https://behance.net/x'));
    await settle(() => owner.saveProjectEdits());
    expect(mockUpdate).toHaveBeenCalledWith(
      '2',
      {
        title: 'مشروع 2',
        summary: '',
        role: 'صممت الهوية',
        tools: ['فوتوشوب', 'Figma'],
        externalUrl: 'https://behance.net/x',
      },
      boundary,
    );
    expect(owner.editing).toBe(false);
    expect(projects.find(p => p.id === '2')?.tools).toEqual([
      'فوتوشوب',
      'Figma',
    ]);
  });

  it('refuses a non https project link before any request', async () => {
    await open('2');
    await settle(() => owner.beginEdit());
    await settle(() => owner.setEditExternalUrl('http://example.com'));
    await settle(() => owner.saveProjectEdits());
    expect(mockUpdate).not.toHaveBeenCalled();
    expect(Alert.alert).toHaveBeenCalledWith(
      'راجع رابط المشروع',
      'الرابط لازم يبدأ بـ https://',
    );
  });

  it('hides and shows one project without touching its text', async () => {
    await open('2');
    await settle(() => owner.toggleSelectedVisibility());
    expect(mockUpdate).toHaveBeenLastCalledWith('2', {visible: false}, boundary);
    expect(owner.selected?.hidden).toBe(true);
    await settle(() => owner.toggleSelectedVisibility());
    expect(mockUpdate).toHaveBeenLastCalledWith('2', {visible: true}, boundary);
    expect(owner.selected?.hidden).toBe(false);
  });

  it('chooses the cover from the project images', async () => {
    await open('2');
    await settle(() => owner.chooseSelectedCover(owner.selected!.media[1]));
    expect(mockUpdate).toHaveBeenCalledWith('2', {coverMediaId: '22'}, boundary);
    expect(owner.selected?.coverMediaId).toBe('22');
    expect(owner.selected?.cover).toEqual({uri: 'https://cdn/22.jpg'});
  });

  it('features a project and moves it to the top of the library', async () => {
    await open('1');
    expect(projects.map(p => p.id)).toEqual(['3', '2', '1']);
    await settle(() => owner.toggleSelectedFeatured());
    expect(mockUpdate).toHaveBeenCalledWith('1', {featured: true}, boundary);
    expect(projects.map(p => p.id)).toEqual(['1', '3', '2']);
  });

  it('moves a project down by renumbering only the changed positions', async () => {
    await open('3');
    expect(owner.canMoveSelectedUp).toBe(false);
    expect(owner.canMoveSelectedDown).toBe(true);
    await settle(() => owner.moveSelectedProject(1));
    // New order 2, 3, 1 -> positions 0, 1, 2 (all differed from 0 except 2).
    expect(mockUpdate.mock.calls.map(call => [call[0], call[1]])).toEqual([
      ['3', {sortOrder: 1}],
      ['1', {sortOrder: 2}],
    ]);
    expect(projects.map(p => p.id)).toEqual(['2', '3', '1']);
    expect(owner.canMoveSelectedUp).toBe(true);
  });
});
