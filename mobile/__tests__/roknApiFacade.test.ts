import * as access from '../src/services/api/access';
import * as certificates from '../src/services/api/certificates';
import * as courses from '../src/services/api/courses';
import * as economy from '../src/services/api/economy';
import * as engagement from '../src/services/api/engagement';
import * as learning from '../src/services/api/learning';
import * as notifications from '../src/services/api/notifications';
import * as profile from '../src/services/api/profile';
import * as savedLessons from '../src/services/api/savedLessons';
import * as watchHistory from '../src/services/api/watchHistory';
import * as roknApi from '../src/services/roknApi';

describe('roknApi facade', () => {
  it('re-exports every domain function without wrapping it', () => {
    const domainExports = Object.assign(
      {},
      access,
      certificates,
      courses,
      economy,
      engagement,
      learning,
      notifications,
      profile,
      savedLessons,
      watchHistory,
    );

    expect(Object.keys(roknApi).sort()).toEqual(
      Object.keys(domainExports).sort(),
    );
    Object.entries(domainExports).forEach(([name, implementation]) => {
      expect(roknApi[name as keyof typeof roknApi]).toBe(implementation);
    });
  });
});
