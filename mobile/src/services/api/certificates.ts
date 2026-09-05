import {publicRequest} from '../../constants/api';
import {
  trustedCertificateFileUrl,
  trustedCertificateVerificationUrl,
  trustedPortfolioShareUrl,
} from '../publicLinks';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {
  isApiRecord,
  isResourceListPayload,
  payload,
  responseEnvelope,
  resourceList,
} from './common';

export type Certificate = {
  publicId: string;
  courseId: string;
  verificationUrl: string;
  certificateUrl?: string;
  certificatePdfUrl?: string;
  holderName: string;
  courseName: string;
  status: 'active' | 'pending' | 'revoked';
  verificationLevel: 'completion' | 'reviewed_project';
  verificationLabel: string;
  certificateTextTemplateKey: string;
  certificateText: string;
  qrDestination: {
    type: 'certificate' | 'portfolio';
    url: string;
    title: string;
    hint: string;
  } | null;
};

const CERTIFICATES_CACHE_KEY = '@rokn/certificates-cache/v2';

type CertificatesCache = {
  version: 2;
  certificates: unknown[];
};

const isCachedCertificate = (value: unknown): value is Certificate => {
  if (!isApiRecord(value)) return false;
  const status = String(value.status || '');
  const verificationLevel = String(value.verificationLevel || '');
  const publicId = String(value.publicId || '').trim();
  const activeArtifactIsValid =
    status !== 'active' ||
    (Boolean(
      trustedCertificateFileUrl(value.certificateUrl, publicId, 'artifact'),
    ) &&
      Boolean(
        trustedCertificateFileUrl(
          value.certificatePdfUrl,
          publicId,
          'download',
        ),
      ));
  return (
    publicId.length > 0 &&
    /^\d+$/.test(String(value.courseId || '')) &&
    Boolean(
      trustedCertificateVerificationUrl(value.verificationUrl, publicId),
    ) &&
    activeArtifactIsValid &&
    ['active', 'pending', 'revoked'].includes(status) &&
    ['completion', 'reviewed_project'].includes(verificationLevel) &&
    typeof value.holderName === 'string' &&
    value.holderName.trim().length > 0 &&
    typeof value.courseName === 'string' &&
    value.courseName.trim().length > 0 &&
    typeof value.verificationLabel === 'string' &&
    value.verificationLabel.trim().length > 0 &&
    typeof value.certificateTextTemplateKey === 'string' &&
    value.certificateTextTemplateKey.trim().length > 0 &&
    typeof value.certificateText === 'string' &&
    value.certificateText.trim().length > 0 &&
    (value.qrDestination === null ||
      isQrDestination(value.qrDestination, publicId))
  );
};

const migrateLegacyCachedCertificate = (value: unknown): Certificate | null => {
  if (!isApiRecord(value) || 'qrDestination' in value) return null;
  const migrated = {
    ...value,
    // Cache v2 predates the server-owned destination. Its UI always used the
    // verification URL, but that would be wrong for a practical certificate.
    // Keep the credential available offline and hide only its QR until a
    // current response supplies the canonical destination. A current API
    // response missing qr_destination still fails in mapCertificate.
    qrDestination: null,
  };

  return isCachedCertificate(migrated) ? migrated : null;
};

export const getCachedCertificates = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<Certificate[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await accountScopedStorageKey(CERTIFICATES_CACHE_KEY, boundary);
  const cached = await getItem<Partial<CertificatesCache>>(key);
  assertAccountSessionBoundary(boundary);
  if (cached?.version !== 2 || !Array.isArray(cached.certificates)) {
    return [];
  }
  const certificates = cached.certificates.map(value =>
    isCachedCertificate(value) ? value : migrateLegacyCachedCertificate(value),
  );
  return certificates.every(
    (certificate): certificate is Certificate => certificate !== null,
  )
    ? certificates
    : [];
};

type CertificateDto = {
  public_id?: unknown;
  course_id?: unknown;
  holder_name?: unknown;
  course_name?: unknown;
  verification_url?: unknown;
  certificate_url?: unknown;
  certificate_pdf_url?: unknown;
  status?: unknown;
  verification_level?: unknown;
  verification_label?: unknown;
  certificate_text_template_key?: unknown;
  certificate_text?: unknown;
  qr_destination?: unknown;
};

const mapQrDestination = (
  value: unknown,
  publicId: string,
): NonNullable<Certificate['qrDestination']> => {
  if (!isApiRecord(value)) {
    throw new Error('CERTIFICATE_QR_DESTINATION_INVALID');
  }
  const type = String(value.type || '');
  const url =
    type === 'portfolio'
      ? trustedPortfolioShareUrl(value.url)
      : type === 'certificate'
      ? trustedCertificateVerificationUrl(value.url, publicId)
      : null;
  const title = String(value.title || '').trim();
  const hint = String(value.hint || '').trim();
  if (!url || !title || !hint) {
    throw new Error('CERTIFICATE_QR_DESTINATION_INVALID');
  }

  return {
    type: type as NonNullable<Certificate['qrDestination']>['type'],
    url,
    title,
    hint,
  };
};

const isQrDestination = (value: unknown, publicId: string): boolean => {
  try {
    mapQrDestination(value, publicId);
    return true;
  } catch {
    return false;
  }
};

const mapCertificate = (value: unknown): Certificate => {
  if (!isApiRecord(value)) {
    throw new Error('CERTIFICATE_CONTRACT_INVALID');
  }
  const item = value as CertificateDto;
  const publicId = String(item.public_id ?? '').trim();
  if (!publicId) {
    throw new Error('CERTIFICATE_CONTRACT_INVALID');
  }
  const verificationUrl = trustedCertificateVerificationUrl(
    item.verification_url,
    publicId,
  );
  if (!verificationUrl) {
    throw new Error('CERTIFICATE_VERIFICATION_URL_INVALID');
  }
  const rawStatus = String(item.status || '').toLowerCase();
  if (!['active', 'pending', 'revoked'].includes(rawStatus)) {
    throw new Error('CERTIFICATE_STATUS_CONTRACT_INVALID');
  }
  const status = rawStatus as Certificate['status'];
  const rawVerification = String(item.verification_level || '').toLowerCase();
  if (!['completion', 'reviewed_project'].includes(rawVerification)) {
    throw new Error('CERTIFICATE_VERIFICATION_CONTRACT_INVALID');
  }
  const verificationLevel = rawVerification as Certificate['verificationLevel'];
  const certificateTextTemplateKey = String(
    item.certificate_text_template_key || '',
  ).trim();
  const certificateText = String(item.certificate_text || '').trim();
  const holderName = String(item.holder_name || '').trim();
  const courseName = String(item.course_name || '').trim();
  const courseId = String(item.course_id || '').trim();
  const verificationLabel = String(item.verification_label || '').trim();
  if (!certificateTextTemplateKey || !certificateText) {
    throw new Error('CERTIFICATE_TEXT_CONTRACT_INVALID');
  }
  if (
    !holderName ||
    !courseName ||
    !/^\d+$/.test(courseId) ||
    !verificationLabel
  ) {
    throw new Error('CERTIFICATE_IDENTITY_CONTRACT_INVALID');
  }
  const certificateUrl =
    status === 'active'
      ? trustedCertificateFileUrl(item.certificate_url, publicId, 'artifact')
      : '';
  const certificatePdfUrl =
    status === 'active'
      ? trustedCertificateFileUrl(
          item.certificate_pdf_url,
          publicId,
          'download',
        )
      : '';
  if (status === 'active' && (!certificateUrl || !certificatePdfUrl)) {
    throw new Error('CERTIFICATE_ARTIFACT_CONTRACT_INVALID');
  }
  const qrDestination =
    status === 'revoked' && item.qr_destination == null
      ? null
      : mapQrDestination(item.qr_destination, publicId);
  return {
    publicId,
    courseId,
    verificationUrl,
    certificateUrl: certificateUrl || undefined,
    certificatePdfUrl: certificatePdfUrl || undefined,
    holderName,
    courseName,
    status,
    verificationLevel,
    verificationLabel,
    certificateTextTemplateKey,
    certificateText,
    qrDestination,
  };
};

export const getCertificates = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<Certificate[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const data = payload<CertificateDto[] | {data?: CertificateDto[]}>(
    await publicRequest.get('certificates'),
  );
  assertAccountSessionBoundary(boundary);
  if (!isResourceListPayload(data)) {
    throw new Error('CERTIFICATES_CONTRACT_INVALID');
  }
  const list = resourceList<CertificateDto>(data);
  const certificates = list.map(mapCertificate);
  assertAccountSessionBoundary(boundary);
  const cacheKey = await accountScopedStorageKey(
    CERTIFICATES_CACHE_KEY,
    boundary,
  );
  void saveItem(cacheKey, {
    version: 2,
    certificates,
  } satisfies CertificatesCache).catch(() => undefined);
  assertAccountSessionBoundary(boundary);
  return certificates;
};

const submitCertificateRequest = async (
  courseId: string,
  holderName?: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<Certificate | null> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const normalizedCourseId = String(courseId).trim();
  if (!/^\d+$/.test(normalizedCourseId)) {
    throw new Error('INVALID_CERTIFICATE_COURSE_ID');
  }
  const endpoint = `certificates/${normalizedCourseId}/issue`;
  const response = holderName
    ? await publicRequest.post(endpoint, {holder_name: holderName})
    : await publicRequest.post(endpoint);
  assertAccountSessionBoundary(boundary);
  const envelope = responseEnvelope(response);
  const responseStatus = Number(
    (isApiRecord(response) ? response.status : undefined) ??
      envelope.status ??
      0,
  );
  if (
    responseStatus === 202 &&
    envelope.success === true &&
    envelope.code === 'certificate_generating'
  ) {
    return null;
  }
  const data = payload<unknown>(response);
  const certificate = mapCertificate(data);
  assertAccountSessionBoundary(boundary);
  return certificate;
};

export const issueCertificate = async (
  courseId: string,
  holderName: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<Certificate | null> => {
  const normalizedHolderName = holderName.trim();
  if (Array.from(normalizedHolderName).length < 2) {
    throw new Error('INVALID_CERTIFICATE_HOLDER_NAME');
  }
  return submitCertificateRequest(
    courseId,
    normalizedHolderName,
    ownerBoundary,
  );
};

/** Re-enqueue only an already-reserved immutable credential. */
export const recoverCertificate = async (
  courseId: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<Certificate | null> =>
  submitCertificateRequest(courseId, undefined, ownerBoundary);
