const listeners = new Set<(courseId: string) => void>();

export const subscribeToUnavailableCourses = (
  listener: (courseId: string) => void,
) => {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
};

export const publishUnavailableCourse = (courseId: string) => {
  listeners.forEach(listener => listener(courseId));
};
