export type VideoQuality = 'auto' | '1080p' | '720p' | '480p' | '360p';

export type VideoQualitySources = Partial<
  Record<Exclude<VideoQuality, 'auto'>, string>
>;

export type AttachmentPlatform = 'computer' | 'mobile' | 'app' | 'file' | 'any';

export interface CourseAttachment {
  id: string;
  title: string;
  url: string;
  fileType?: string;
  mimeType?: string;
  fileSize?: string;
  fileSizeBytes?: number;
  downloadVersion?: string;
  external?: boolean;
  platform: AttachmentPlatform;
  courseId?: string;
  temporary?: boolean;
  expiresAt?: string;
}

export interface CourseReel {
  id: string;
  lessonId: string;
  sectionId: string;
  moduleId: string;
  title: string;
  caption: string;
  videoUrl: string;
  /** Optional second CDN/source used only after bounded recovery attempts fail. */
  fallbackVideoUrl?: string;
  /** Direct MP4 renditions. Without these, fixed quality is shown only for adaptive streams. */
  qualitySources?: VideoQualitySources;
  thumbnailUrl?: string;
  durationSeconds?: number;
  availableQualities: VideoQuality[];
  /** Short-lived server decision for this lesson; never persisted as a library URL. */
  playbackSessionId?: string;
  playbackProtocol?: 'hls' | 'dash' | 'mp4' | 'unknown';
  playbackExpiresAt?: string;
  playbackRefreshAfter?: string;
  /** Changes whenever a signed source is re-issued, even if its session is reused. */
  playbackManifestRevision?: number;
  mediaStatus?: 'ready' | 'processing' | 'failed' | 'unknown';
  isPreview: boolean;
  isLocked: boolean;
  /** Server-owned reason; purchase and progression gates are different states. */
  lockReason?:
    | 'course_purchase_required'
    | 'previous_section_incomplete'
    | 'module_project_not_passed'
    | string;
  isCompleted: boolean;
  reelNumber: number;
  /** Published section order; keeps projects in their real map position. */
  sectionOrder?: number;
}

export type ProjectStatus = 'draft' | 'evaluating' | 'passed' | 'needs_changes';

export type ProjectReportStatus =
  | 'not_included'
  | 'not_requested'
  | 'queued'
  | 'ready'
  | 'failed';

export type CourseLearningGateState =
  | 'locked_purchase'
  | 'locked_project'
  | 'available'
  | 'completed';

export interface ProjectFeedbackMessage {
  id: string;
  clientRequestId?: string;
  role: 'assistant' | 'user';
  status:
    | 'queued'
    | 'sent'
    | 'streaming'
    | 'completed'
    | 'failed'
    | 'cancelled';
  errorCode?: string;
  text?: string;
  createdAt?: string;
  attachments?: ChatAttachmentDraft[];
}

export interface ProjectFeedbackThread {
  id: string;
  feedbackLevel: 'report' | 'enhanced';
  canReply: boolean;
  status: string;
  remainingMessages: number;
  messages: ProjectFeedbackMessage[];
  attachmentsEnabled?: boolean;
  attachmentMaxFiles?: number;
}

export interface CourseProject {
  id: string;
  sectionId: string;
  moduleId: string;
  title: string;
  requirements: string;
  status: ProjectStatus;
  isGraduationProject: boolean;
  isLocked?: boolean;
  lockReason?: string;
  sectionOrder?: number;
  feedbackLevel?: 'pass_only' | 'report' | 'enhanced';
  outputEnabled?: boolean;
  reportEnabled?: boolean;
  reportStatus?: ProjectReportStatus;
  replyEnabled?: boolean;
  canSubmit?: boolean;
  canContinue?: boolean;
  reviewFeedback?: string;
  canRetryReport?: boolean;
  reportRetryEndpoint?: string;
  feedbackThread?: ProjectFeedbackThread;
  submissionTextEnabled?: boolean;
  submissionFilesEnabled?: boolean;
  submissionMaxFiles?: number;
  submissionAllowedMimeTypes?: string[];
}

export interface CourseLearningModule {
  id: string;
  title: string;
  order: number;
  isLocked: boolean;
  /** Server-owned reason for the module gate when supplied. */
  lockReason?: string;
  reels: CourseReel[];
  projects?: CourseProject[];
}

export interface CourseAttachmentPrompt {
  enabled: boolean;
  atSeconds: number;
  title: string;
  body: string;
  buttonText: string;
  frequency: 'once_per_course';
}

export interface CourseLearningData {
  id: string;
  title: string;
  image?: string | number;
  totalReels: number;
  /** Download-only course files. They never belong to one module or project. */
  attachments: CourseAttachment[];
  modules: CourseLearningModule[];
  /** How this learner received access; supplied by the entitlement API. */
  accessType?: string;
  /** Course-chat availability from the entitlement API. */
  chatAvailable?: boolean;
  chatAttachmentsEnabled?: boolean;
  chatAttachmentMaxFiles?: number;
  /** Explicit false keeps certificate generation server- and client-locked. */
  certificateAvailable?: boolean;
  /** The purchased/granted plan includes certificate issuance after completion. */
  certificateIncluded?: boolean;
  /** Dashboard-controlled discovery prompt for the course files. */
  attachmentPrompt?: CourseAttachmentPrompt;
}

export type CourseFeedItem =
  | {
      key: string;
      type: 'reel';
      moduleId: string;
      reel: CourseReel;
    }
  | {
      key: string;
      type: 'project';
      moduleId: string;
      project: CourseProject;
    };

export interface SelectedProjectFile {
  uri: string;
  name: string;
  type: string;
  size?: number;
}

export interface ChatMessage {
  id: string;
  role: 'assistant' | 'user';
  text: string;
  createdAt: number;
  clientRequestId?: string;
  deliveryStatus?:
    | 'submitting'
    | 'checking'
    | 'queued'
    | 'sent'
    | 'streaming'
    | 'interrupted'
    | 'completed'
    | 'failed'
    | 'cancelled';
  errorCode?: string;
  /** Server-owned terminal retry decision for this exact logical turn. */
  canRetry?: boolean;
  retryAfterSeconds?: number;
  /** Failed/system UI copy is visible but never becomes model context. */
  contextEligible?: boolean;
  attachments?: ChatAttachmentDraft[];
}

export interface ChatAttachmentDraft {
  uri: string;
  name: string;
  type: string;
  size?: number;
  uploadId: string;
  serverId?: string;
  downloadUrl?: string;
  downloadExpiresAt?: string;
}
