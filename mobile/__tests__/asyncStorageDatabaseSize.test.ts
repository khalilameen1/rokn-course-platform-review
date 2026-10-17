import fs from 'fs';
import path from 'path';

const read = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('Android AsyncStorage database size', () => {
  it('raises the 6 MB default through the property the installed library reads', () => {
    const libraryConfig = read(
      'node_modules/@react-native-async-storage/async-storage/android/config.gradle',
    );
    // Guard against a library upgrade renaming the property silently.
    expect(libraryConfig).toContain(
      "getPropertyOfDefault('AsyncStorage_db_size_in_MB', null)",
    );

    const match = read('android/gradle.properties').match(
      /^AsyncStorage_db_size_in_MB=(\d+)$/m,
    );
    expect(match).not.toBeNull();
    expect(Number(match?.[1])).toBeGreaterThan(6);
  });
});
