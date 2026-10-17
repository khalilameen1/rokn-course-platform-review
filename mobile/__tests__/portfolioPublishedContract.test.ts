const mockPut = jest.fn();
const mockPost = jest.fn();
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    put: (...args: unknown[]) => mockPut(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string) => key,
  assertAccountSessionBoundary: () => undefined,
  captureAccountSessionBoundary: async () => ({scope: 'a', epoch: 1}),
  getItem: async () => null,
  saveItem: async () => true,
}));

import {
  mapPortfolioItem,
  mapPortfolioProfile,
  normalizePortfolioChips,
  portfolioLinkError,
  whatsAppLinkFromPhone,
} from '../src/services/api/portfolioContract';
import {
  portfolioItemPatchBody,
  updatePortfolioItem,
  updatePortfolioProfile,
} from '../src/services/api/portfolio';
import {portfolioProjectShareUrl} from '../src/screens/Profile/gallery/portfolioModel';
import {portfolioSharingNotice} from '../src/screens/Profile/portfolioState';

const LIVE_URL = 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa';
const PROJECT_ID = '5b8f2d7e-4c1a-4f7e-9a2b-3c4d5e6f7a8b';

const itemDto = (fields: Record<string, unknown> = {}) => ({
  id: 9,
  title: 'هوية',
  description: '',
  media: [
    {id: 1, file_type: 'image', status: 'ready', image_url: 'https://cdn/1.jpg'},
    {id: 2, file_type: 'image', status: 'ready', image_url: 'https://cdn/2.jpg'},
  ],
  upload_state: 'ready',
  uploaded_media_count: 2,
  expected_media_count: 2,
  ...fields,
});

describe('published portfolio contract (backend 510b9e76)', () => {
  beforeEach(() => jest.clearAllMocks());

  it('keeps the live link while newer edits wait for review', () => {
    const profile = mapPortfolioProfile({
      slug: 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      sharing_status: 'pending',
      published: true,
      published_at: '2026-10-01T10:00:00+00:00',
      has_unpublished_changes: true,
      public_url: LIVE_URL,
    });
    expect(profile).toMatchObject({
      published: true,
      publicUrl: LIVE_URL,
      hasUnpublishedChanges: true,
      publishedAt: '2026-10-01T10:00:00+00:00',
    });
    expect(portfolioSharingNotice(profile)).toBe(
      'تعديلاتك قيد المراجعة والنسخة المنشورة شغالة',
    );
  });

  it('has no link before the first approval or while suspended', () => {
    expect(
      mapPortfolioProfile({sharing_status: 'pending', published: false, public_url: null}),
    ).toMatchObject({published: false, publicUrl: ''});
    expect(
      mapPortfolioProfile({
        sharing_status: 'approved',
        sharing_suspended: true,
        published: true,
        public_url: LIVE_URL,
      }),
    ).toMatchObject({published: false, publicUrl: ''});
  });

  it('maps profile skills and https contact links', () => {
    expect(
      mapPortfolioProfile({
        sharing_status: 'approved',
        published: true,
        public_url: LIVE_URL,
        skills: ['تصميم', ' '],
        links: [
          {label: 'واتساب', url: 'https://wa.me/201012345678'},
          {label: 'bad', url: 'http://insecure.example'},
        ],
      }),
    ).toMatchObject({
      skills: ['تصميم'],
      links: [{label: 'واتساب', url: 'https://wa.me/201012345678'}],
    });
  });

  it('maps project tools (skills alias), cover choice, visibility and order', () => {
    const item = mapPortfolioItem(
      itemDto({
        public_id: PROJECT_ID.toUpperCase(),
        tools: ['Figma', 'فوتوشوب'],
        skills: ['ignored alias'],
        role: 'مصمم',
        external_url: 'https://behance.net/x',
        cover_media_id: 2,
        is_hidden: true,
        is_featured: true,
        sort_order: 3,
      }),
    );
    expect(item).toMatchObject({
      publicId: PROJECT_ID,
      tools: ['Figma', 'فوتوشوب'],
      role: 'مصمم',
      externalUrl: 'https://behance.net/x',
      coverMediaId: '2',
      coverUri: 'https://cdn/2.jpg',
      hidden: true,
      featured: true,
      sortOrder: 3,
    });
    expect(mapPortfolioItem(itemDto({skills: ['Canva']})).tools).toEqual(['Canva']);
  });

  it('sends only the changed project fields', async () => {
    expect(portfolioItemPatchBody({visible: false})).toEqual({is_public: false});
    expect(portfolioItemPatchBody({coverMediaId: '12'})).toEqual({cover_media_id: 12});
    expect(portfolioItemPatchBody({coverMediaId: null})).toEqual({cover_media_id: null});
    expect(portfolioItemPatchBody({sortOrder: 20000, featured: true})).toEqual({
      sort_order: 10000,
      is_featured: true,
    });
    expect(
      portfolioItemPatchBody({
        title: 'هوية',
        summary: 'وصف',
        role: 'مصمم',
        tools: [' Figma ', 'figma', '', 'x'.repeat(90)],
        externalUrl: ' ',
      }),
    ).toEqual({
      title: 'هوية',
      description: 'وصف',
      role: 'مصمم',
      tools: ['Figma', 'x'.repeat(80)],
      external_url: null,
    });
    mockPost.mockResolvedValue({data: {data: itemDto({is_hidden: true})}});
    await updatePortfolioItem('9', {visible: false});
    expect(mockPost).toHaveBeenCalledWith('portfolio/9', {is_public: false});
  });

  it('saves profile skills and links with the backend field names', async () => {
    mockPut.mockResolvedValue({
      data: {data: {sharing_status: 'pending', published: false, skills: ['تصميم']}},
    });
    await updatePortfolioProfile({
      skills: ['تصميم', 'تصميم'],
      links: [{label: ' واتساب ', url: 'https://wa.me/201012345678 '}],
    });
    expect(mockPut).toHaveBeenCalledWith('portfolio-profile', {
      portfolio_skills: ['تصميم'],
      portfolio_links: [{label: 'واتساب', url: 'https://wa.me/201012345678'}],
    });
  });

  it('builds the WhatsApp click-to-chat link from Egyptian numbers', () => {
    expect(whatsAppLinkFromPhone('01012345678')).toBe('https://wa.me/201012345678');
    expect(whatsAppLinkFromPhone('٠١٢٣٤٥٦٧٨٩٠')).toBe('https://wa.me/201234567890');
    expect(whatsAppLinkFromPhone('+20 101 234 5678')).toBe(
      'https://wa.me/201012345678',
    );
    expect(whatsAppLinkFromPhone('00201012345678')).toBe(
      'https://wa.me/201012345678',
    );
    expect(whatsAppLinkFromPhone('0101234')).toBeNull();
    expect(whatsAppLinkFromPhone('abc')).toBeNull();
  });

  it('validates contact links like the profile endpoint', () => {
    expect(portfolioLinkError({label: 'موقعي', url: 'https://site.dev'})).toBe('');
    expect(portfolioLinkError({label: 'موقعي', url: 'http://site.dev'})).toBe(
      'الرابط لازم يبدأ بـ https://',
    );
    expect(portfolioLinkError({label: '', url: 'https://site.dev'})).toBe(
      'اكتب اسم للرابط',
    );
    expect(
      portfolioLinkError({label: 'واتساب', url: 'https://wa.me/201012345678'}),
    ).toBe('');
    expect(
      portfolioLinkError({
        label: 'واتساب',
        url: 'https://api.whatsapp.com/send?phone=201012345678',
      }),
    ).toBe('');
    expect(portfolioLinkError({label: 'واتساب', url: 'https://wa.link/abc'})).toBe(
      'اكتب رقم واتساب صحيح',
    );
    expect(normalizePortfolioChips(['a', 'b', 'c'], 2)).toEqual(['a', 'b']);
  });

  it('shares one visible published project at /p/<public id>', () => {
    const project = {publicId: PROJECT_ID, hidden: false, shareReady: true};
    expect(portfolioProjectShareUrl(LIVE_URL, project)).toBe(
      `${LIVE_URL}/p/${PROJECT_ID}`,
    );
    expect(portfolioProjectShareUrl('', project)).toBeNull();
    expect(portfolioProjectShareUrl(LIVE_URL, {...project, hidden: true})).toBeNull();
    expect(
      portfolioProjectShareUrl(LIVE_URL, {...project, shareReady: false}),
    ).toBeNull();
    expect(
      portfolioProjectShareUrl('https://evil.example/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa', project),
    ).toBeNull();
  });
});
