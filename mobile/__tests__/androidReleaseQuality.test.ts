import fs from 'fs';
import path from 'path';

const read = (file: string) =>
  fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const nativeRoot = 'android/app/src/main/java/com/rokn';

describe('Android release quality contract', () => {
  it('enables the supported edge-to-edge and integrated resource pipelines', () => {
    const properties = read('android/gradle.properties');
    expect(properties).toMatch(/^edgeToEdgeEnabled=true$/m);
    expect(properties).toMatch(/^android.r8.optimizedResourceShrinking=true$/m);
    expect(properties).not.toMatch(/^android.enableR8.fullMode=false$/m);
    expect(read('android/app/build.gradle')).toContain(
      'proguard-android-optimize.txt',
    );
  });

  it('does not ship the unused orientation bridge or manifest resize restrictions', () => {
    expect(
      fs.existsSync(
        path.join(
          __dirname,
          '..',
          nativeRoot,
          'orientation/RoknOrientationModule.kt',
        ),
      ),
    ).toBe(false);
    expect(read(`${nativeRoot}/MainApplication.kt`)).not.toContain(
      'RoknOrientation',
    );
    expect(read('android/app/src/main/AndroidManifest.xml')).not.toMatch(
      /android:(?:screenOrientation|minAspectRatio|maxAspectRatio)=|android:resizeableActivity="false"/,
    );
    expect(JSON.parse(read('app.json')).expo.orientation).toBe('default');
  });

  it('uses native insets and no app-owned deprecated system-bar color calls', () => {
    const checkout = read(`${nativeRoot}/checkout/CheckoutActivity.kt`);
    expect(checkout).toContain('enableEdgeToEdge(');
    expect(checkout).toContain('WindowInsetsCompat.Type.ime()');
    expect(checkout).not.toMatch(
      /window\.(?:statusBarColor|navigationBarColor)/,
    );
    expect(read('src/screens/reels/ReelsSurface.tsx')).not.toContain(
      '<StatusBar',
    );
    expect(read('src/screens/CourseDetails/index.tsx')).not.toContain(
      '<StatusBar',
    );
    expect(read('src/navigation/Navigation.tsx')).toContain(
      "statusBarStyle: 'light'",
    );
    expect(read('android/app/src/main/res/values-v30/styles.xml')).toContain(
      '>always<',
    );
  });

  it('shares a managed image loader across scheduled and immediate reminders', () => {
    const receiver = read(`${nativeRoot}/reminders/ReminderReceiver.kt`);
    expect(receiver.match(/NotificationArtworkLoader\.load\(/g)).toHaveLength(
      2,
    );
    expect(receiver).not.toMatch(
      /HttpURLConnection|decodeByteArray|downloadBitmap|Thread\s*\{/,
    );
    const loader = read(`${nativeRoot}/media/NotificationArtworkLoader.kt`);
    expect(loader).toContain('ResizeOptions(1024, 512, 1024f)');
    expect(loader).toContain('source.close()');
    expect(loader).toContain('deadlines.removeCallbacks(timeout)');
    expect(loader).toContain('completion.complete(null)');
  });
});
