import type {ProjectFeedbackThread} from '../types';
import {
  asArray,
  asRecord,
  type DataRecord,
  explicitBoolean,
  valueAsBoolean,
  valueAsString,
} from './shared';

// Initial course data and later thread reads must preserve the same capabilities.
export const mapProjectFeedbackThread = (
  value: unknown,
): ProjectFeedbackThread | null => {
  const thread = asRecord(value);
  const level = valueAsString(thread.feedback_level);
  if (!thread.id || !['report', 'enhanced'].includes(level)) return null;

  return {
    id: valueAsString(thread.id),
    feedbackLevel: level as 'report' | 'enhanced',
    canReply: valueAsBoolean(thread.can_reply),
    status: valueAsString(thread.status, 'ready'),
    remainingMessages: Math.max(0, Number(thread.remaining_messages) || 0),
    attachmentsEnabled: valueAsBoolean(thread.attachments_enabled),
    attachmentMaxFiles: Math.min(
      5,
      Math.max(0, Number(thread.attachment_max_files) || 0),
    ),
    messages: asArray<DataRecord>(thread.messages).flatMap(message => {
      const role = valueAsString(message.role);
      const status = valueAsString(message.status);
      if (
        !['assistant', 'user'].includes(role) ||
        ![
          'queued',
          'sent',
          'streaming',
          'completed',
          'failed',
          'cancelled',
        ].includes(status)
      ) {
        return [];
      }
      return [
        {
          id: valueAsString(message.id),
          clientRequestId:
            valueAsString(message.client_request_id) || undefined,
          role: role as 'assistant' | 'user',
          status: status as
            | 'queued'
            | 'sent'
            | 'streaming'
            | 'completed'
            | 'failed'
            | 'cancelled',
          errorCode: valueAsString(message.error_code) || undefined,
          canRetry: explicitBoolean(message.can_retry),
          text: valueAsString(message.text) || undefined,
          createdAt: valueAsString(message.created_at) || undefined,
          attachments: asArray<DataRecord>(message.attachments).map(file => ({
            uri: '',
            name: valueAsString(file.name, 'مرفق'),
            type: valueAsString(file.mime_type, 'application/octet-stream'),
            size: Number(file.size_bytes) || undefined,
            uploadId: valueAsString(file.id),
            serverId: valueAsString(file.id),
            downloadUrl: valueAsString(file.download_url) || undefined,
            downloadExpiresAt:
              valueAsString(file.download_url_expires_at) || undefined,
          })),
        },
      ];
    }),
  };
};
