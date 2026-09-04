import {mapCourseDetailsPayload} from '../src/services/api/courseDetailsContract';

const accessPlans = ['basic', 'guided', 'mentor'].map((code, index) => ({
  code,
  name: code,
  price_coins: 100 + index * 100,
  minimum_paid_coins: 0,
  chat_enabled: index > 0,
  chat_message_limit: index * 10,
  project_feedback_level:
    index === 0 ? 'pass_only' : index === 1 ? 'report' : 'enhanced',
  project_report_enabled: index > 0,
  project_thread_reply_enabled: index > 1,
  project_output_enabled: index > 0,
  certificate_enabled: true,
}));

const detailsPayload = (isPreview: boolean, metadataPreviewCount: number) => ({
  id: 52,
  title: 'Course',
  is_coming_soon: false,
  ratings_count: 0,
  average_rating: null,
  published_revision: 1,
  metadata: {
    duration_minutes: 2,
    preview_reels_count: metadataPreviewCount,
    students_count: 0,
  },
  modules: [
    {
      id: 1,
      title: 'Module',
      sections: [
        {
          id: 1,
          content_id: 1,
          title: 'Lesson',
          type: 'lesson',
          is_preview: isPreview,
        },
      ],
    },
  ],
  access_plans: accessPlans,
});

describe('course details preview contract', () => {
  it('advertises only previews that exist in the player module graph', () => {
    expect(mapCourseDetailsPayload(detailsPayload(false, 3)).previewReelCount).toBe(
      0,
    );
    expect(mapCourseDetailsPayload(detailsPayload(true, 0)).previewReelCount).toBe(
      1,
    );
  });
});
