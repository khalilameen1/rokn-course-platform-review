import {deadlineFromServerTtl} from '../../../utils/serverClock';
import type {AttachmentPlatform, CourseAttachment} from '../types';
import {
  asArray,
  asRecord,
  type DataRecord,
  explicitBoolean,
  valueAsBoolean,
  valueAsString,
} from './shared';

export type CoursePayloadDto = DataRecord & {
  content?: CoursePayloadDto;
  latest_submission?: CoursePayloadDto;
  feedback_thread?: CoursePayloadDto;
  modules?: CoursePayloadDto[];
  sections?: CoursePayloadDto[];
};

export const courseRecord = (value: unknown): CoursePayloadDto =>
  asRecord(value) as CoursePayloadDto;

export const normaliseAttachmentPlatform = (
  value?: unknown,
): AttachmentPlatform => {
  const platform = valueAsString(value).toLowerCase();
  return ['computer', 'mobile', 'app', 'file', 'any'].includes(platform)
    ? (platform as AttachmentPlatform)
    : 'any';
};

const mapAttachment = (
  raw: CoursePayloadDto,
  fallbackPlatform: AttachmentPlatform,
  fallbackId: string,
  courseId?: string,
): CourseAttachment | null => {
  const url = raw.download_url;
  if (!url || !valueAsBoolean(raw.download_only)) return null;

  const platform = normaliseAttachmentPlatform(raw.platform);
  const fileSizeBytes = Number(raw.file_size_bytes);
  return {
    id: valueAsString(raw.id, fallbackId),
    title: valueAsString(raw.title, 'ملف مرفق'),
    url: valueAsString(url),
    fileType: raw.file_type ? valueAsString(raw.file_type) : undefined,
    mimeType: raw.mime_type ? valueAsString(raw.mime_type) : undefined,
    fileSize: raw.file_size ? valueAsString(raw.file_size) : undefined,
    fileSizeBytes:
      Number.isFinite(fileSizeBytes) && fileSizeBytes > 0
        ? fileSizeBytes
        : undefined,
    downloadVersion: valueAsString(raw.download_version) || undefined,
    platform: platform === 'any' ? fallbackPlatform : platform,
    courseId,
    temporary: valueAsBoolean(raw.download_url_is_temporary),
    expiresAt:
      deadlineFromServerTtl(Number(raw.expires_in_seconds)) ||
      valueAsString(raw.download_url_expires_at) ||
      undefined,
  };
};

export const mapCourseAttachments = (
  rawAttachments: unknown,
  platform: AttachmentPlatform,
  courseId?: string,
): CourseAttachment[] =>
  asArray<CoursePayloadDto>(rawAttachments)
    .map((item, index) =>
      mapAttachment(
        item,
        platform,
        `${courseId || 'course'}-${index + 1}`,
        courseId,
      ),
    )
    .filter((attachment): attachment is CourseAttachment =>
      Boolean(attachment),
    );

export const uniqueCourseAttachments = (attachments: CourseAttachment[]) =>
  Array.from(
    new Map(
      attachments.map(
        attachment =>
          [
            `${attachment.id}:${attachment.downloadVersion || attachment.url}`,
            attachment,
          ] as const,
      ),
    ).values(),
  );

export const courseSectionType = (section: CoursePayloadDto): string =>
  valueAsString(section.type).toLowerCase();

export const stableCourseContentId = (value: unknown): string =>
  valueAsString(value).trim();

/** Paid learning maps fail as one contract instead of silently dropping steps. */
export const hasValidLearningContract = (
  modules: CoursePayloadDto[],
): boolean => {
  if (!modules.length) return false;
  const moduleIds = new Set<string>();
  const sectionIds = new Set<string>();
  const contentIds = new Set<string>();

  return modules.every(module => {
    const moduleId = stableCourseContentId(module.id);
    if (!moduleId || moduleIds.has(moduleId)) return false;
    moduleIds.add(moduleId);

    const sections = asArray<CoursePayloadDto>(module.sections);
    return (
      sections.length > 0 &&
      sections.every(section => {
        const type = courseSectionType(section);
        if (!['lesson', 'project'].includes(type)) return false;

        const sectionId = stableCourseContentId(section.id);
        if (!sectionId || sectionIds.has(sectionId)) return false;
        sectionIds.add(sectionId);

        const content = courseRecord(section.content);
        const contentId = stableCourseContentId(section.content_id);
        const isPreview = explicitBoolean(section.is_preview);
        const isLocked = explicitBoolean(section.is_locked);
        if (
          isPreview === undefined ||
          isLocked === undefined ||
          !Object.prototype.hasOwnProperty.call(section, 'lock_reason') ||
          ((!isLocked || isPreview) && !Object.keys(content).length)
        ) {
          return false;
        }
        if (
          Object.keys(content).length > 0 &&
          stableCourseContentId(content.id) !== contentId
        ) {
          return false;
        }
        const contentKey = `${type}:${contentId}`;
        if (!contentId || contentIds.has(contentKey)) return false;
        contentIds.add(contentKey);
        return true;
      })
    );
  });
};

export const courseVideoUrl = (content: CoursePayloadDto): string =>
  valueAsString(content.bunny_video_url || content.video_link);
