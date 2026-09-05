export function courseChatSheetLayout(
  screenHeight: number,
  viewportHeight: number,
  topInset: number,
  fontScale: number,
) {
  // The Modal's viewport is resized by Android's IME (and by the iOS
  // KeyboardAvoidingView). Do not take another percentage of that reduced area.
  const height = Math.max(
    0,
    Math.min(
      screenHeight * (fontScale > 1.25 ? 0.88 : 0.78),
      viewportHeight - topInset - 8,
    ),
  );
  return {
    height,
    compact: height < 320,
    inputMaxHeight: Math.max(48, Math.min(110, height * 0.25)),
  };
}
