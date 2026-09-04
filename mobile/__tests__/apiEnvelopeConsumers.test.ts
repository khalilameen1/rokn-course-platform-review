import {publicRequest} from '../src/constants/api';
import {
  issueCertificate,
  recoverCertificate,
} from '../src/services/api/certificates';
import {getNotificationsPage} from '../src/services/api/notifications';

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

const mockedRequest = publicRequest as unknown as {
  get: jest.Mock;
  post: jest.Mock;
};

describe('API envelope consumers', () => {
  beforeEach(() => jest.clearAllMocks());

  it('keeps a successful pending certificate response as null', async () => {
    mockedRequest.post.mockResolvedValue({
      data: {
        status: 202,
        success: true,
        code: 'certificate_generating',
        data: null,
      },
    });

    await expect(recoverCertificate('52')).resolves.toBeNull();
    expect(mockedRequest.post).toHaveBeenCalledWith('certificates/52/issue');
  });

  it('keeps the issued image and PDF on the certificate trust boundary', async () => {
    const credential = '11111111-1111-4111-8111-111111111111';
    mockedRequest.post.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          public_id: credential,
          course_id: 52,
          holder_name: 'طالب ركن',
          course_name: 'كورس تجريبي',
          certificate_text_template_key: 'projects',
          certificate_text: 'تقديرًا لإنجاز مشروعات كورس',
          status: 'active',
          verification_level: 'completion',
          verification_label: 'إتمام الكورس',
          verification_url: `https://rokn.app/c/${credential}`,
          certificate_url: `https://rokn.app/c/${credential}/artifact`,
          certificate_pdf_url: `https://rokn.app/c/${credential}/download`,
        },
      },
    });

    await expect(issueCertificate('52', 'طالب ركن')).resolves.toMatchObject({
      publicId: credential,
      certificateUrl: `https://rokn.app/c/${credential}/artifact`,
      certificatePdfUrl: `https://rokn.app/c/${credential}/download`,
      certificateTextTemplateKey: 'projects',
      certificateText: 'تقديرًا لإنجاز مشروعات كورس',
    });
    expect(mockedRequest.post).toHaveBeenCalledWith(
      'certificates/52/issue',
      {holder_name: 'طالب ركن'},
    );
  });

  it('does not invent one generic certificate sentence when the snapshot is missing', async () => {
    const credential = '22222222-2222-4222-8222-222222222222';
    mockedRequest.post.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          public_id: credential,
          course_id: 52,
          holder_name: 'طالب ركن',
          course_name: 'كورس تجريبي',
          status: 'active',
          verification_level: 'completion',
          verification_label: 'إتمام الكورس',
          verification_url: `https://rokn.app/c/${credential}`,
          certificate_url: `https://rokn.app/c/${credential}/artifact`,
          certificate_pdf_url: `https://rokn.app/c/${credential}/download`,
        },
      },
    });

    await expect(issueCertificate('52', 'طالب ركن')).rejects.toThrow(
      'CERTIFICATE_TEXT_CONTRACT_INVALID',
    );
  });

  it('reads cursor metadata from the envelope', async () => {
    mockedRequest.get.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: [
          {
            id: 9,
            notification_type: 'new_content',
            title_ar: 'محتوى جديد',
            message_ar: 'شاهد ما أضفناه',
            created_at: '2026-09-01T00:00:00Z',
            is_read: false,
          },
        ],
        pagination: {
          has_more_pages: true,
          next_cursor: 'next-page',
        },
      },
    });

    await expect(getNotificationsPage()).resolves.toMatchObject({
      page: 1,
      hasMore: true,
      nextCursor: 'next-page',
      notifications: [{id: '9'}],
    });
  });

  it('rejects a cursor page containing malformed rows', async () => {
    mockedRequest.get.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: [{id: 'not-an-id'}],
        pagination: {has_more_pages: false, next_cursor: null},
      },
    });

    await expect(getNotificationsPage()).rejects.toThrow(
      'NOTIFICATIONS_CONTRACT_INVALID',
    );
  });

  it('does not turn an HTML success body into an empty inbox', async () => {
    mockedRequest.get.mockResolvedValue({data: '<html>gateway page</html>'});

    await expect(getNotificationsPage()).rejects.toThrow(
      'NOTIFICATIONS_CONTRACT_INVALID',
    );
  });
});
