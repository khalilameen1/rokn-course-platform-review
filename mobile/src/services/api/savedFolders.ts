import {publicRequest} from '../../constants/api';

/** Saved folders and lesson membership requests. */
export const listSavedFolders = () => publicRequest.get('saved-folders');

export const createSavedFolder = (name: string, clientRequestId: string) =>
  publicRequest.post('saved-folders', {
    name,
    client_request_id: clientRequestId,
  });

export const addLessonToSavedFolder = (folderId: string, lessonId: string) =>
  publicRequest.post(`saved-folders/${folderId}/lessons`, {
    lesson_id: lessonId,
  });

/** `route` is a validated saved-folder or saved-lesson resource path. */
export const deleteSavedResource = (route: string) =>
  publicRequest.delete(route);

export const getSavedLessonsState = (lessonIds: string[]) =>
  publicRequest.get('saved-lessons/state', {params: {lesson_ids: lessonIds}});

export const getSavedLessonFolders = (lessonId: string) =>
  publicRequest.get(`saved-lessons/${lessonId}/folders`);
