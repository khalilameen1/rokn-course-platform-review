import {mapPortfolioProfile} from '../src/services/api/portfolioContract';

describe('portfolio moderation contract', () => {
  const url = 'https://rokn.app/@student';

  it.each(['pending', 'rejected', 'suspended', 'other', undefined])(
    'discards a supplied public URL when sharing status is %s',
    sharing_status => {
      expect(
        mapPortfolioProfile({sharing_status, public_url: url}).publicUrl,
      ).toBe('');
    },
  );

  it('accepts only the approved URL and preserves the snapshot revision', () => {
    expect(
      mapPortfolioProfile({
        sharing_status: 'approved',
        sharing_revision: 7,
        public_url: url,
      }),
    ).toMatchObject({
      sharingStatus: 'approved',
      sharingRevision: 7,
      publicUrl: url,
    });
  });

  it('lets a suspension override a stale approval and strips stale rejection text', () => {
    expect(
      mapPortfolioProfile({
        sharing_status: 'approved',
        sharing_suspended: true,
        sharing_rejection_reason: 'old',
        public_url: url,
      }),
    ).toMatchObject({
      sharingStatus: 'suspended',
      sharingSuspended: true,
      sharingRejectionReason: '',
      publicUrl: '',
    });
  });

  it('retains a rejection reason only for the current rejected snapshot', () => {
    expect(
      mapPortfolioProfile({
        sharing_status: 'rejected',
        sharing_rejection_reason: ' عدّل الصورة ',
      }),
    ).toMatchObject({
      sharingRejectionReason: 'عدّل الصورة',
    });
  });
});
