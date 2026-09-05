import fs from 'fs';
import path from 'path';

jest.mock('expo-crypto', () => ({
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

import {publicRequest} from '../src/constants/api';
import {purchaseCourse, quoteCoursePurchase} from '../src/services/api/access';
import {
  getCourseDetails,
  getLearningCourses,
  getPublishedCourses,
} from '../src/services/api/courses';
import {
  claimCoinTask,
  getCoinTasks,
  getWallet,
  startCoinTask,
  type CoinTask,
} from '../src/services/api/economy';

const mockGet = publicRequest.get as jest.Mock;
const mockPost = publicRequest.post as jest.Mock;

describe('commerce API contracts', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('keeps final-sale language in policy surfaces, not checkout decisions', () => {
    const wallet = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/wallet/WalletView.tsx'),
      'utf8',
    );
    const courseCheckout = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/screens/CourseDetails/details/PurchaseDialogs.tsx',
      ),
      'utf8',
    );
    const terms = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/Informations/TermsOfUse.tsx'),
      'utf8',
    );

    expect(wallet).not.toContain('شراء العملات نهائي');
    expect(courseCheckout).not.toContain('شراء العملات نهائي');
    expect(terms).toContain('شراء العملات نهائي بعد تأكيد الدفع');
  });

  it('keeps paid, reward, and course-spendable balances separate', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          total_balance: 1500,
          purchased_balance: 900,
          reward_balance: 600,
          course_spendable_balance: 1200,
          reward_contribution_cap_per_course: 300,
          spend_policy: 'reward_first_then_paid',
          recent_transactions: [
            {
              id: 1,
              amount: 200,
              direction: 'credit',
              category: 'package_purchase',
              label_ar: 'شحن رصيد',
            },
            {
              id: 2,
              amount: 75,
              direction: 'debit',
              category: 'course_purchase',
              label_ar: 'فتح كورس',
            },
          ],
        },
      },
    });

    await expect(getWallet()).resolves.toMatchObject({
      balance: 1500,
      paidBalance: 900,
      rewardBalance: 600,
      spendableBalance: 1200,
      rewardContributionCap: 300,
      spendPolicy: 'reward_first_then_paid',
      transactions: [
        {id: '1', amount: 200, label: 'شحن رصيد'},
        {id: '2', amount: -75, label: 'فتح كورس'},
      ],
    });
    expect(mockGet).toHaveBeenCalledWith('wallet');
  });

  it('rejects legacy wallet aliases instead of inventing a mixed balance', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          balance: 1500,
          paid_balance: 900,
          reward_balance: 600,
          spendable_balance: 1200,
          reward_contribution_cap_per_course: 300,
          spend_policy: 'reward_first_then_paid',
          recent_transactions: [],
        },
      },
    });

    await expect(getWallet()).rejects.toThrow('WALLET_TOTAL_BALANCE');
  });

  it('presents reward goals without exposing the visit-and-claim implementation', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: [
          {
            id: 11,
            action_key: 'follow_instagram',
            title_ar: 'تابع ركن على Instagram',
            coins_amount: 75,
            task_state: 'available',
            action_url: 'https://instagram.com/rokn.app',
            requires_external_visit: true,
          },
          {
            id: 12,
            action_key: 'link_whatsapp',
            title_ar: 'اربط واتسابك بركن',
            coins_amount: 15,
            task_state: 'available',
            requires_external_visit: true,
          },
          {
            id: 13,
            action_key: 'notification_permission_retired',
            title_ar: 'مهمة غير مفعلة',
            coins_amount: 20,
            task_state: 'available',
            requires_external_visit: false,
          },
        ],
      },
    });

    await expect(getCoinTasks()).resolves.toEqual([
      expect.objectContaining({
        title: 'تابع ركن على Instagram',
        description: '',
      }),
      expect.objectContaining({
        title: 'اربط واتسابك بركن',
        description: 'تواصل مع ركن من واتساب',
      }),
      expect.objectContaining({
        title: 'مهمة غير مفعلة',
        actionKey: 'notification_permission_retired',
        description: '',
      }),
    ]);
  });

  it('preserves a verified WhatsApp task as ready to claim', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: [
          {
            id: 12,
            action_key: 'link_whatsapp',
            title_ar: 'اربط واتسابك بركن',
            coins_amount: 15,
            task_state: 'ready_to_claim',
            requires_external_visit: true,
          },
        ],
      },
    });

    await expect(getCoinTasks()).resolves.toEqual([
      expect.objectContaining({status: 'ready_to_claim', url: undefined}),
    ]);
  });

  it('single-flights rapid task starts and claims per account', async () => {
    const task: CoinTask = {
      id: 'production-11',
      serverId: '11',
      title: 'تابع ركن على Instagram',
      description: '',
      reward: 75,
      status: 'available',
      actionKey: 'follow_instagram',
      requiresExternalVisit: true,
    };
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          attempt_id: 'attempt-11',
          task_state: 'started',
          action_url: 'https://instagram.com/rokn.app',
        },
      },
    });

    const starts = await Promise.all([
      startCoinTask(task),
      startCoinTask(task),
    ]);

    expect(starts).toEqual([
      {
        status: 'started',
        url: 'https://instagram.com/rokn.app',
      },
      {
        status: 'started',
        url: 'https://instagram.com/rokn.app',
      },
    ]);
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockPost).toHaveBeenCalledWith('coin-earning-methods/11/start', {
      supports_ready_claim: true,
    });

    mockPost.mockClear();
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          task_state: 'claimed',
          new_balance: 175,
          earned_amount: 75,
        },
      },
    });

    await expect(
      Promise.all([claimCoinTask(task), claimCoinTask(task)]),
    ).resolves.toEqual([
      {balance: 175, amount: 75},
      {balance: 175, amount: 75},
    ]);
    expect(mockPost).toHaveBeenCalledTimes(1);
  });

  it('accepts a verified task start without requiring another external URL', async () => {
    const task: CoinTask = {
      id: 'production-12',
      serverId: '12',
      title: 'اربط واتسابك بركن',
      description: '',
      reward: 15,
      status: 'available',
      actionKey: 'link_whatsapp',
      requiresExternalVisit: true,
    };
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          attempt_id: 'attempt-12',
          task_state: 'ready_to_claim',
        },
      },
    });

    await expect(startCoinTask(task)).resolves.toEqual({
      status: 'ready_to_claim',
      url: undefined,
    });
  });

  it('preserves an immediate social verification URL for the first open', async () => {
    const task: CoinTask = {
      id: 'production-13',
      serverId: '13',
      title: 'تابع ركن',
      description: '',
      reward: 15,
      status: 'available',
      actionKey: 'follow_instagram',
      requiresExternalVisit: true,
    };
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          attempt_id: 'attempt-13',
          task_state: 'ready_to_claim',
          action_url: 'https://instagram.com/rokn.app',
        },
      },
    });

    await expect(startCoinTask(task)).resolves.toEqual({
      status: 'ready_to_claim',
      url: 'https://instagram.com/rokn.app',
    });
  });

  it('rejects immediate social verification without its required destination', async () => {
    const task: CoinTask = {
      id: 'production-13',
      serverId: '13',
      title: 'تابع ركن',
      description: '',
      reward: 15,
      status: 'available',
      actionKey: 'follow_instagram',
      requiresExternalVisit: true,
    };
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          attempt_id: 'attempt-13',
          task_state: 'ready_to_claim',
        },
      },
    });

    await expect(startCoinTask(task)).rejects.toThrow(
      'API_CONTRACT_INVALID_COIN_TASK_START',
    );
  });

  it('renders coin packages as a horizontal rail with another card visible', () => {
    const walletView = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/wallet/WalletView.tsx'),
      'utf8',
    );
    const packageRail = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/wallet/WalletPackageRail.tsx'),
      'utf8',
    );
    const coin = fs.readFileSync(
      path.resolve(__dirname, '../src/components/ui/RoknCoin.tsx'),
      'utf8',
    );
    const packageCard = fs.readFileSync(
      path.resolve(__dirname, '../src/components/view/Package.tsx'),
      'utf8',
    );

    expect(packageRail).toContain('width={cardWidth}');
    expect(packageRail).toContain('title={item.label}');
    expect(packageRail).toContain('snapToInterval={cardWidth + Spacing.sm}');
    expect(walletView).toContain(
      'const packageCardWidth = Math.floor(railCardWidth)',
    );
    expect(walletView).not.toContain('packageColumns');
    expect(coin).toContain('id="coinMark"');
    expect(coin).not.toContain('#FFF1A9');
    expect(packageCard).not.toContain('<RoknCoin');
    expect(packageCard.match(/<CoinAmount/g)).toHaveLength(1);
  });

  it('uses the same package contract in wallet and in-course top-up', () => {
    const walletRail = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/wallet/WalletPackageRail.tsx'),
      'utf8',
    );
    const courseTopup = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/screens/CourseDetails/details/PurchaseDialogSteps.tsx',
      ),
      'utf8',
    );
    const walletCheckout = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/wallet/useWalletCheckout.ts'),
      'utf8',
    );
    const courseCheckout = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/screens/CourseDetails/details/useCourseCheckout.ts',
      ),
      'utf8',
    );

    expect(walletRail).toContain("type {CoinPackage}");
    expect(courseTopup).toContain("type {CoinPackage}");
    expect(walletRail).toContain('title={item.label}');
    expect(courseTopup).toContain('formatArabicDisplayText(item.label)');
    expect(walletCheckout).toContain('openCoinCheckout(item');
    expect(courseCheckout).toContain('openCoinCheckout(coinPackage');
    expect(walletCheckout).toContain("'recovery_in_progress'");
    expect(walletCheckout).toContain("'FEATURE_CHECKOUT_DISABLED'");
    expect(walletCheckout).toContain('else if (!checkoutUnavailable)');
    expect(walletCheckout).toContain("'الدفع متوقف مؤقتًا'");
  });

  it('uses mobile-first devices artwork without a desktop stand', () => {
    const icons = fs.readFileSync(
      path.resolve(__dirname, '../src/assets/SVG.tsx'),
      'utf8',
    );
    const devicesIcon = icons.match(
      /export const SettingsDevicesIcon[\s\S]*?\n\);/,
    )?.[0];

    expect(devicesIcon).toContain('width={11.5} height={18}');
    expect(devicesIcon).toContain('width={4.5} height={12}');
    expect(devicesIcon).not.toContain('M7 21h8');
  });

  it('maps the three server plans in product order with their benefits', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          id: 64,
          title: 'Course',
          price: 100,
          is_coming_soon: false,
          ratings_count: 0,
          average_rating: null,
          published_revision: 1,
          metadata: {students_count: 0, duration_minutes: 1},
          modules: [
            {
              id: 1,
              title: 'وحدة',
              sections: [
                {id: 1, content_id: 1, title: 'درس', type: 'lesson'},
                {id: 2, content_id: 20, title: 'مشروع أول', type: 'project'},
                {id: 3, content_id: 21, title: 'مشروع ثان', type: 'project'},
              ],
            },
          ],
          access_type: 'scholarship',
          learning_started: true,
          access_plans: [
            {
              code: 'mentor',
              name: 'متابعة',
              price_coins: 900,
              minimum_paid_coins: 0,
              chat_enabled: true,
              chat_message_limit: 40,
              project_feedback_level: 'enhanced',
              project_report_enabled: true,
              project_thread_reply_enabled: true,
              project_output_enabled: true,
              certificate_enabled: true,
            },
            {
              code: 'basic',
              name: 'تعلم',
              price_coins: 300,
              minimum_paid_coins: 0,
              chat_enabled: false,
              project_feedback_level: 'pass_only',
              project_report_enabled: false,
              project_thread_reply_enabled: false,
              project_output_enabled: false,
              certificate_enabled: true,
            },
            {
              code: 'guided',
              name: 'إرشاد',
              price_coins: 600,
              minimum_paid_coins: 0,
              chat_enabled: true,
              chat_message_limit: 10,
              project_feedback_level: 'report',
              project_report_enabled: true,
              project_thread_reply_enabled: false,
              project_output_enabled: true,
              certificate_enabled: true,
            },
          ],
        },
      },
    });

    const details = await getCourseDetails('64');

    expect(mockGet).toHaveBeenCalledWith(
      'courses/64/details',
      expect.objectContaining({
        optionalAuthorization: true,
        signal: undefined,
        roknNetworkRetryDeadlineAt: expect.any(Number),
      }),
    );
    expect(details.owned).toBe(true);
    expect(details.started).toBe(true);
    expect(details.modules[0]).toMatchObject({
      projectCount: 2,
      items: expect.arrayContaining([
        expect.objectContaining({id: '2', type: 'project'}),
        expect.objectContaining({id: '3', type: 'project'}),
      ]),
    });
    expect(details.accessPlans.map(plan => plan.code)).toEqual([
      'basic',
      'guided',
      'mentor',
    ]);
    expect(details.accessPlans).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          code: 'guided',
          priceCoins: 600,
          chatEnabled: true,
          chatMessageLimit: 10,
          projectFeedbackLevel: 'report',
          projectReportEnabled: true,
        }),
        expect.objectContaining({
          code: 'mentor',
          priceCoins: 900,
          projectFeedbackLevel: 'enhanced',
          projectOutputEnabled: true,
        }),
      ]),
    );
  });

  it('never treats a public preview as course ownership', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          id: 65,
          title: 'Course',
          is_coming_soon: false,
          ratings_count: 0,
          average_rating: null,
          published_revision: 1,
          metadata: {students_count: 0, duration_minutes: 1},
          access_type: 'preview',
          // A stale compatibility enrollment must never override the
          // authoritative access snapshot returned by the course read API.
          enrollment: {is_active: true},
          access_plans: [
            {
              code: 'basic',
              name: 'تعلم',
              price_coins: 300,
              minimum_paid_coins: 0,
              chat_enabled: false,
              project_feedback_level: 'pass_only',
              project_report_enabled: false,
              project_thread_reply_enabled: false,
              project_output_enabled: false,
              certificate_enabled: true,
            },
            {
              code: 'guided',
              name: 'إرشاد',
              price_coins: 600,
              minimum_paid_coins: 0,
              chat_enabled: true,
              chat_message_limit: 10,
              project_feedback_level: 'report',
              project_report_enabled: true,
              project_thread_reply_enabled: false,
              project_output_enabled: true,
              certificate_enabled: true,
            },
            {
              code: 'mentor',
              name: 'متابعة',
              price_coins: 900,
              minimum_paid_coins: 0,
              chat_enabled: true,
              chat_message_limit: 40,
              project_feedback_level: 'enhanced',
              project_report_enabled: true,
              project_thread_reply_enabled: true,
              project_output_enabled: true,
              certificate_enabled: true,
            },
          ],
          modules: [
            {
              id: 1,
              title: 'وحدة',
              sections: [
                {
                  id: 1,
                  content_id: 1,
                  title: 'درس',
                  type: 'lesson',
                  is_preview: true,
                },
              ],
            },
          ],
        },
      },
    });

    await expect(getCourseDetails('65')).resolves.toMatchObject({
      owned: false,
    });
  });

  it('rejects preview rows from the owned learning dashboard', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          items: [
            {
              course_id: 65,
              title: 'Course',
              progress_percentage: 0,
              completed_sections: 0,
              total_sections: 1,
              learning_started: false,
              access_type: 'preview',
              chat_available: false,
              certificate_available: false,
              resume: {available: false},
              next_section: null,
            },
          ],
          pagination: {has_more: false, next_cursor: null},
        },
      },
    });

    await expect(getLearningCourses()).rejects.toThrow(
      'LEARNING_COURSES_CONTRACT_INVALID',
    );
  });

  it('does not derive catalogue progress from public legacy enrollment fields', async () => {
    mockGet.mockResolvedValueOnce({
      data: {
        data: {
          courses: [
            {
              id: 65,
              title: 'Course',
              is_coming_soon: false,
              progress_percentage: 80,
              progress: {progress_percentage: 80},
              enrollment: {is_completed: true, progress_percentage: 100},
            },
          ],
          pagination: {current_page: 1, last_page: 1, total: 1},
          catalogue_revision: 1,
        },
      },
    });

    const catalogue = await getPublishedCourses();

    expect(catalogue[0]).toMatchObject({
      id: '65',
      owned: false,
    });
    expect(catalogue[0]).not.toHaveProperty('progress');
  });

  it('sends the selected plan and preserves insufficient-balance details', async () => {
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          total_balance: 500,
          spendable_balance: 450,
          purchased_balance: 300,
          reward_balance: 200,
          original_price: 600,
          discount_amount: 0,
        },
      },
    });

    await expect(purchaseCourse('64', 'guided')).resolves.toEqual({
      kind: 'success',
      balance: 500,
      spendableBalance: 450,
      paidBalance: 300,
      rewardBalance: 200,
      originalPrice: 600,
      discountAmount: 0,
    });
    expect(mockPost).toHaveBeenNthCalledWith(
      1,
      'courses/authorize',
      expect.objectContaining({
        course_id: 64,
        access_plan_code: 'guided',
        idempotency_key: expect.stringMatching(
          /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
        ),
      }),
    );

    mockPost.mockRejectedValueOnce({
      response: {
        data: {
          code: 'insufficient_coins',
          data: {
            total_balance: 100,
            spendable_balance: 80,
            purchased_balance: 60,
            reward_balance: 40,
            deficit: 520,
            recommended_packages: [
              {
                id: 7,
                coins: 600,
                price: 49,
                direct_price: 44.1,
                name_ar: 'باقة مناسبة',
                channels: {direct: true, google: true, apple: true},
                store_products: {
                  google: 'rokn.coins.600',
                  apple: 'rokn.coins.600',
                },
              },
            ],
          },
        },
      },
    });

    await expect(purchaseCourse('64', 'mentor')).resolves.toEqual({
      kind: 'insufficient',
      balance: 100,
      spendableBalance: 80,
      paidBalance: 60,
      rewardBalance: 40,
      deficit: 520,
      packages: [
        {
          id: '7',
          coins: 600,
          price: 49,
          label: 'باقة مناسبة',
          storeProductIds: {
            google: 'rokn.coins.600',
            apple: 'rokn.coins.600',
          },
        },
      ],
    });
  });

  it('requires a canonical access plan before starting course commerce', async () => {
    await expect(quoteCoursePurchase('64', '', '')).rejects.toThrow(
      'API_CONTRACT_INVALID_ACCESS_PLAN_CODE',
    );
    await expect(purchaseCourse('64', '')).rejects.toThrow(
      'API_CONTRACT_INVALID_ACCESS_PLAN_CODE',
    );
    expect(mockPost).not.toHaveBeenCalled();
  });

  it('rejects legacy balance aliases in a purchase response', async () => {
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          current_coins: 500,
          remaining_balance: 500,
          spendable_balance: 450,
          purchased_balance: 300,
          reward_balance: 200,
          original_price: 600,
          discount_amount: 0,
        },
      },
    });

    await expect(purchaseCourse('64', 'guided')).rejects.toThrow(
      'API_CONTRACT_INVALID_COURSE_PURCHASE_TOTAL_BALANCE',
    );
  });
});
