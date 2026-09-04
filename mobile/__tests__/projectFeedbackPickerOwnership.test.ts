import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(
  path.resolve(
    __dirname,
    '../src/components/VideoPlayer/projectTransition/useProjectFeedback.ts',
  ),
  'utf8',
);

describe('project feedback attachment ownership', () => {
  it('binds one picker flight to its project, thread and generation', () => {
    expect(source).toContain('pickerFlightRef.current');
    expect(source).toContain('activeProjectIdRef.current === projectId');
    expect(source).toContain('activeThreadIdRef.current === threadId');
    expect(source).toContain('generationRef.current === generation');
    expect(source).toContain('[projectId, thread?.id]');
    expect(source).toContain(
      'const boundary = await captureAccountSessionBoundary()',
    );
    expect(source).toMatch(
      /cacheProjectFeedbackFile\([\s\S]*?boundary[\s\S]*?assertAccountSessionBoundary\(boundary\)/,
    );
  });

  it('removes copied files when the picker loses ownership', () => {
    expect(source).toMatch(
      /if \(!ownsPicker\(\)\) \{\s*await Promise\.all\(additions\.map\(removeLearnerDraftFile\)\)/,
    );
    expect(source).toMatch(
      /if \(!ownsContext\(\)\) \{\s*void Promise\.all\(additions\.map\(removeLearnerDraftFile\)\)/,
    );
  });
});
