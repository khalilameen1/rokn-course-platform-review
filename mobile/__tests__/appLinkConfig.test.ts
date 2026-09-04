import fs from 'fs';
import path from 'path';
import renderIntentFilters from '@expo/config-plugins/build/android/IntentFilters';
import appConfig from '../app.json';

const hosts = ['rokn.app', 'www.rokn.app'];
const exactPaths = ['/home', '/profile', '/wallet'];
const routePrefixes = ['/support/', '/course/'];
const expectedData = (host: string) => [
  {scheme: 'https'},
  {host},
  ...exactPaths.map(routePath => ({path: routePath})),
  ...routePrefixes.map(pathPrefix => ({pathPrefix})),
];

const matchesConfiguredPath = (item: any, pathname: string) =>
  typeof item.path === 'string'
    ? pathname === item.path
    : typeof item.pathPrefix === 'string' &&
      pathname.startsWith(item.pathPrefix);

describe('native app-link scope', () => {
  it('keeps Expo generation split into one attribute per data tag', () => {
    const filters = appConfig.expo.android.intentFilters;
    const rendered = renderIntentFilters(filters);
    for (const [index, host] of hosts.entries()) {
      const hostFilter = filters[index];
      expect(hostFilter?.autoVerify).toBe(true);
      expect(hostFilter?.data).toEqual(expectedData(host));
      expect(rendered[index].data?.map(item => item.$)).toEqual(
        expectedData(host).map(item =>
          Object.fromEntries(
            Object.entries(item).map(([key, value]) => [
              `android:${key}`,
              value,
            ]),
          ),
        ),
      );
    }
  });

  it('keeps the checked-in Android manifest path-scoped too', () => {
    const manifest = fs.readFileSync(
      path.resolve(__dirname, '../android/app/src/main/AndroidManifest.xml'),
      'utf8',
    );
    const verifiedFilters = [
      ...manifest.matchAll(
        /<intent-filter android:autoVerify="true">([\s\S]*?)<\/intent-filter>/g,
      ),
    ].map(match => match[1]);
    expect(verifiedFilters).toHaveLength(hosts.length);
    for (const host of hosts) {
      const hostFilter = verifiedFilters.find(filter =>
        filter.includes(`<data android:host="${host}" />`),
      );
      const dataAttributes = [
        ...(hostFilter || '').matchAll(/<data\s+([^>]+?)\s*\/>/g),
      ].map(match => match[1]);
      expect(dataAttributes).toEqual(
        expectedData(host).map(item => {
          const [key, value] = Object.entries(item)[0];
          return `android:${key}="${value}"`;
        }),
      );
    }
  });

  it('keeps Expo and native iOS associated domains in sync', () => {
    expect(appConfig.expo.ios.associatedDomains).toEqual(
      hosts.map(host => `applinks:${host}`),
    );
    const entitlements = fs.readFileSync(
      path.resolve(__dirname, '../ios/Rokn/Rokn.entitlements'),
      'utf8',
    );
    for (const host of hosts) {
      expect(entitlements).toContain(`<string>applinks:${host}</string>`);
    }
  });

  it.each([
    '/@student',
    '/course-evil',
    '/courses/42',
    '/coursesX',
    '/homepage',
  ])('does not claim unrelated website path %s', pathname => {
    const items = appConfig.expo.android.intentFilters.flatMap(
      filter => filter.data,
    );
    expect(items.some(item => matchesConfiguredPath(item, pathname))).toBe(
      false,
    );
  });
});
