import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('social auth architecture', () => {
  it('keeps the public facade stable and delegates each lifecycle owner', () => {
    const facade = source('src/services/socialAuth.ts');
    const browser = source('src/services/socialAuthBrowser.ts');
    const apple = source('src/services/socialAuthApple.ts');
    const completion = source('src/services/socialAuthCompletion.ts');
    const session = source('src/services/socialAuthSession.ts');

    expect(facade.split('\n').length).toBeLessThan(90);
    expect(facade).toContain('export const signInWithSocialProvider');
    expect(facade).toContain('export {resumePendingSocialAuth}');
    expect(facade).not.toContain('publicRequest');
    expect(facade).not.toContain('saveSecureSession');
    expect(browser).toContain('openAndroidAuthSession');
    expect(apple).toContain('AppleAuthentication.signInAsync');
    expect(completion).toContain('const completionFlights');
    expect(session).toContain('persistCompletedSocialLogin');
  });
});
