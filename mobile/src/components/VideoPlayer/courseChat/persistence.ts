import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import type {ChatMessage} from '../types';
import {
  learnerDraftFileIsReadable,
  retainLearnerDraftFiles,
} from '../../../services/learnerDraftFiles';
import {cleanUnicodeText, truncateGraphemes} from '../../../utils/unicodeText';
import {courseChatTurnIsPolling, courseChatTurnIsUnresolved} from './policy';

const COURSE_CHAT_HISTORY_PREFIX = '@rokn/course-chat-history/v2';
const MAX_STORED_MESSAGES = 36;
const writeFlights = new Map<string, Promise<void>>();
let persistenceGeneration = 0;
const DELIVERY_STATUSES = new Set([
  'submitting',
  'checking',
  'queued',
  'sent',
  'streaming',
  'interrupted',
  'completed',
  'failed',
  'cancelled',
]);

const historyKey = async (
  courseId: string,
  lessonId?: string,
  boundary?: AccountSessionBoundary,
) =>
  `${COURSE_CHAT_HISTORY_PREFIX}:${
    boundary?.scope || (await captureAccountSessionBoundary()).scope
  }:${encodeURIComponent(courseId)}:${encodeURIComponent(
    lessonId || 'course',
  )}`;
const referenceOwner = (courseId: string, lessonId?: string) =>
  `course-chat:${encodeURIComponent(courseId)}:${encodeURIComponent(
    lessonId || 'course',
  )}`;

const messageIdentity = (message: ChatMessage) =>
  `${message.role}:${message.clientRequestId || message.id}`;

/**
 * The server owns terminal truth while the phone owns questions not yet seen
 * by the server. Server history is paginated, so simply appending unmatched
 * local rows puts older cached turns after the newest answer once a lesson has
 * more than one page. Merge by logical turn and restore chronological order.
 */
export const mergeCourseChatHistories = (
  remote: ChatMessage[],
  local: ChatMessage[],
): ChatMessage[] => {
  const messagesByIdentity = new Map(
    remote.map(message => [messageIdentity(message), message] as const),
  );
  local.forEach(message => {
    const identity = messageIdentity(message);
    const serverMessage = messagesByIdentity.get(identity);
    if (!serverMessage) {
      messagesByIdentity.set(identity, message);
      return;
    }
    // Reconciliation is allowed while the composer is already usable. A
    // history response that started before Send may therefore contain an
    // older queued copy of the same turn. Replacing the local bubble also
    // replaces its id, so the live turn owner can no longer settle it and the
    // answer appears to vanish. Server terminal state is authoritative; for
    // two non-terminal copies (or a newer local terminal copy) preserve the
    // live local owner until a later server read proves a terminal result.
    if (courseChatTurnIsUnresolved(serverMessage.deliveryStatus)) {
      messagesByIdentity.set(identity, message);
    }
  });
  return Array.from(messagesByIdentity.values())
    .map((message, stableIndex) => ({message, stableIndex}))
    .sort(
      (left, right) =>
        left.message.createdAt - right.message.createdAt ||
        left.stableIndex - right.stableIndex,
    )
    .map(({message}) => message);
};

const normaliseStoredMessage = (value: unknown): ChatMessage | null => {
  if (!value || typeof value !== 'object') return null;
  const record = value as Record<string, unknown>;
  const role = record.role;
  const status = record.deliveryStatus;
  const text =
    typeof record.text === 'string'
      ? truncateGraphemes(cleanUnicodeText(record.text), 12000)
      : '';
  const id = typeof record.id === 'string' ? record.id.slice(0, 160) : '';
  if (
    !id ||
    (role !== 'assistant' && role !== 'user') ||
    (status !== undefined && !DELIVERY_STATUSES.has(String(status)))
  ) {
    return null;
  }

  const acceptedPending = courseChatTurnIsPolling(String(status || ''));
  const attachments = Array.isArray(record.attachments)
    ? record.attachments.flatMap(attachmentValue => {
        if (!attachmentValue || typeof attachmentValue !== 'object') return [];
        const file = attachmentValue as Record<string, unknown>;
        const uploadId =
          typeof file.uploadId === 'string' ? file.uploadId.slice(0, 100) : '';
        const serverId =
          typeof file.serverId === 'string'
            ? file.serverId.slice(0, 100)
            : undefined;
        const uri = typeof file.uri === 'string' ? file.uri.slice(0, 2048) : '';
        if (!uploadId || (!serverId && !uri)) return [];
        return [
          {
            uploadId,
            serverId,
            uri,
            name:
              typeof file.name === 'string' ? file.name.slice(0, 240) : 'مرفق',
            type:
              typeof file.type === 'string'
                ? file.type.slice(0, 120)
                : 'application/octet-stream',
            size: Number.isFinite(Number(file.size))
              ? Number(file.size)
              : undefined,
            downloadUrl:
              typeof file.downloadUrl === 'string'
                ? file.downloadUrl.slice(0, 2048)
                : undefined,
          },
        ];
      })
    : [];
  return {
    id,
    role,
    text,
    createdAt:
      typeof record.createdAt === 'number' && Number.isFinite(record.createdAt)
        ? record.createdAt
        : Date.now(),
    clientRequestId:
      typeof record.clientRequestId === 'string'
        ? record.clientRequestId.slice(0, 100)
        : undefined,
    deliveryStatus: status as ChatMessage['deliveryStatus'],
    errorCode:
      typeof record.errorCode === 'string'
        ? record.errorCode.slice(0, 80)
        : undefined,
    canRetry:
      typeof record.canRetry === 'boolean' ? record.canRetry : undefined,
    retryAfterSeconds: Number.isFinite(Number(record.retryAfterSeconds))
      ? Math.max(0, Number(record.retryAfterSeconds))
      : undefined,
    contextEligible:
      !acceptedPending &&
      status === 'completed' &&
      record.contextEligible !== false,
    attachments,
  };
};

export const loadCourseChatHistory = async (
  courseId: string,
  lessonId?: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<ChatMessage[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  try {
    const raw = await AsyncStorage.getItem(
      await historyKey(courseId, lessonId, boundary),
    );
    assertAccountSessionBoundary(boundary);
    const parsed = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) return [];
    const messages = parsed
      .map(normaliseStoredMessage)
      .filter((message): message is ChatMessage => Boolean(message))
      .slice(-MAX_STORED_MESSAGES);
    for (const message of messages) {
      if (!message.attachments?.length) continue;
      const readable = [];
      for (const file of message.attachments) {
        if (
          file.serverId ||
          (file.uri && (await learnerDraftFileIsReadable(file)))
        ) {
          readable.push(file);
        }
      }
      message.attachments = readable;
    }
    await retainLearnerDraftFiles(
      referenceOwner(courseId, lessonId),
      messages
        .flatMap(message => message.attachments || [])
        .filter(file => !file.serverId),
      boundary.scope,
    ).catch(() => undefined);
    assertAccountSessionBoundary(boundary);
    return messages;
  } catch (error: unknown) {
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    ) {
      throw error;
    }
    await retainLearnerDraftFiles(
      referenceOwner(courseId, lessonId),
      [],
      boundary.scope,
    ).catch(() => undefined);
    return [];
  }
};

export const saveCourseChatHistory = async (
  courseId: string,
  messages: ChatMessage[],
  lessonId?: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const generation = persistenceGeneration;
  const durable = messages
    .filter(message => !message.id.startsWith('welcome-'))
    .slice(-MAX_STORED_MESSAGES)
    .map(message => ({
      id: message.id,
      role: message.role,
      text: truncateGraphemes(cleanUnicodeText(message.text), 12000),
      createdAt: message.createdAt,
      clientRequestId: message.clientRequestId,
      deliveryStatus: message.deliveryStatus,
      errorCode: message.errorCode,
      canRetry: message.canRetry,
      retryAfterSeconds: message.retryAfterSeconds,
      contextEligible: message.contextEligible === true,
      attachments: message.attachments?.map(file => ({
        uri: file.serverId ? '' : file.uri,
        name: file.name,
        type: file.type,
        size: file.size,
        uploadId: file.uploadId,
        serverId: file.serverId,
        downloadUrl: file.downloadUrl,
      })),
    }));
  const key = await historyKey(courseId, lessonId, boundary);
  const previous = writeFlights.get(key) || Promise.resolve();
  const write = previous
    .catch(() => undefined)
    .then(async () => {
      if (generation !== persistenceGeneration) return;
      assertAccountSessionBoundary(boundary);
      await AsyncStorage.setItem(key, JSON.stringify(durable));
      assertAccountSessionBoundary(boundary);
      const localFiles = messages
        .flatMap(message => message.attachments || [])
        .filter(file => !file.serverId);
      if (localFiles.length) {
        await retainLearnerDraftFiles(
          referenceOwner(courseId, lessonId),
          localFiles,
          boundary.scope,
        );
      } else {
        // Once the durable transcript contains server ids only, releasing an
        // obsolete cache reference is cleanup. A registry failure must not
        // turn an already-uploaded question into a fake failed send.
        await retainLearnerDraftFiles(
          referenceOwner(courseId, lessonId),
          [],
          boundary.scope,
        ).catch(() => undefined);
      }
      assertAccountSessionBoundary(boundary);
    });
  writeFlights.set(key, write);
  try {
    await write;
  } finally {
    if (writeFlights.get(key) === write) writeFlights.delete(key);
  }
};

/**
 * Stop queued history writes before logout removes their scoped keys. Waiting
 * here prevents an already-started AsyncStorage write from recreating private
 * chat history after cleanup has completed.
 */
export const quiesceCourseChatPersistence = async () => {
  persistenceGeneration += 1;
  const pending = Array.from(writeFlights.values());
  await Promise.allSettled(pending);
  writeFlights.clear();
};
