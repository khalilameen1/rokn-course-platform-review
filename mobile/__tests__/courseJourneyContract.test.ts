import fs from 'fs';
import path from 'path';
import {
  courseLearningGateState,
  learningGateText,
  learningGateTextForStep,
  orderedModuleSteps,
} from '../src/components/VideoPlayer/courseLearning/sequence';
import type {CourseLearningModule} from '../src/components/VideoPlayer/types';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

const moduleFixture = (): CourseLearningModule => ({
  id: 'module-1',
  title: 'الوحدة',
  order: 1,
  isLocked: false,
  reels: [
    {
      id: 'reel-1',
      lessonId: 'lesson-1',
      sectionId: 'section-1',
      moduleId: 'module-1',
      title: 'المقطع الأول',
      caption: '',
      videoUrl: 'https://example.com/1.m3u8',
      availableQualities: ['auto'],
      isPreview: false,
      isLocked: false,
      isCompleted: false,
      reelNumber: 1,
      sectionOrder: 1,
    },
  ],
  projects: [
    {
      id: 'project-1',
      sectionId: 'project-section-1',
      moduleId: 'module-1',
      title: 'المشروع',
      requirements: 'نفّذ المطلوب',
      status: 'draft',
      isGraduationProject: false,
      isLocked: false,
      sectionOrder: 2,
    },
  ],
});

describe('course journey contract', () => {
  it('uses the same server-owned gate in the map and the player index', () => {
    const module = moduleFixture();
    module.projects![0].isLocked = true;
    module.projects![0].lockReason = 'previous_section_incomplete';
    let steps = orderedModuleSteps(module);
    expect(courseLearningGateState(module, steps, 1)).toBe('locked_project');

    module.reels[0].isCompleted = true;
    steps = orderedModuleSteps(module);
    expect(courseLearningGateState(module, steps, 1)).toBe('locked_project');

    module.projects![0].isLocked = false;
    module.projects![0].lockReason = undefined;
    steps = orderedModuleSteps(module);
    expect(courseLearningGateState(module, steps, 1)).toBe('available');

    const courseMap = source('src/components/view/Module.tsx');
    const playerIndex = source(
      'src/components/VideoPlayer/feedSideBar/CourseIndexModule.tsx',
    );

    expect(courseMap).toContain('courseLearningGateState(');
    expect(playerIndex).toContain('courseLearningGateState(');
    expect(courseMap).not.toContain('.slice(0, stepIndex)');
    expect(playerIndex).not.toContain('.slice(0, stepIndex)');
  });

  it('does not describe purchase and project locks as the same gate', () => {
    expect(learningGateText('course_purchase_required')).toContain(
      'اختر فئة الكورس',
    );
    expect(learningGateText('module_project_not_passed')).toContain(
      'مشروع العبور',
    );
    expect(learningGateText('course_purchase_required')).not.toBe(
      learningGateText('module_project_not_passed'),
    );
  });

  it('names the real preceding gate instead of calling every lock a project', () => {
    const reelBlocked = moduleFixture();
    reelBlocked.projects![0].isLocked = true;
    reelBlocked.projects![0].lockReason = 'previous_section_incomplete';
    let steps = orderedModuleSteps(reelBlocked);
    expect(learningGateTextForStep(reelBlocked, steps, 1)).toContain(
      'المقطع السابق',
    );

    reelBlocked.reels[0].isCompleted = true;
    reelBlocked.projects![0].status = 'passed';
    reelBlocked.projects![0].sectionOrder = 1;
    reelBlocked.reels[0].sectionOrder = 2;
    reelBlocked.reels[0].isLocked = true;
    reelBlocked.reels[0].lockReason = 'module_project_not_passed';
    steps = orderedModuleSteps(reelBlocked);
    expect(learningGateTextForStep(reelBlocked, steps, 1)).toContain(
      'مشروع العبور',
    );

    reelBlocked.reels[0].lockReason = 'course_purchase_required';
    expect(learningGateTextForStep(reelBlocked, steps, 1)).toContain(
      'اختر فئة الكورس',
    );
  });

  it('uses one explicit gate state for player and map decisions', () => {
    const module = moduleFixture();
    module.projects![0].isLocked = true;
    module.projects![0].lockReason = 'previous_section_incomplete';
    let steps = orderedModuleSteps(module);

    expect(courseLearningGateState(module, steps, 0)).toBe('available');
    expect(courseLearningGateState(module, steps, 1)).toBe('locked_project');

    module.reels[0].isCompleted = true;
    steps = orderedModuleSteps(module);
    expect(courseLearningGateState(module, steps, 0)).toBe('completed');
    expect(courseLearningGateState(module, steps, 1)).toBe('locked_project');

    module.projects![0].isLocked = false;
    module.projects![0].lockReason = undefined;
    steps = orderedModuleSteps(module);
    expect(courseLearningGateState(module, steps, 1)).toBe('available');

    module.isLocked = true;
    module.lockReason = 'course_purchase_required';
    expect(courseLearningGateState(module, steps, 0)).toBe('locked_purchase');
    expect(learningGateText('locked_purchase')).not.toBe(
      learningGateText('locked_project'),
    );
  });

  it('uses lock reasons only to name a lock never to invent one', () => {
    const module = moduleFixture();
    module.projects![0].lockReason = 'course_purchase_required';
    const steps = orderedModuleSteps(module);

    expect(courseLearningGateState(module, steps, 1)).toBe('available');

    module.projects![0].isLocked = true;
    expect(courseLearningGateState(module, steps, 1)).toBe('locked_purchase');
  });

  it('opens an authored project boundary independently of video autoplay', () => {
    const progress = source('src/screens/reels/useReelsProgress.ts');

    expect(progress).toContain(
      'await refreshAfterSectionCompletion(currentIndex + 1);',
    );
    expect(progress).not.toContain(
      'autoplay ? currentIndex + 1 : currentIndex',
    );
  });

  it('does not carry catalogue copies of price or copy into course routes', () => {
    const home = source('src/screens/Home.tsx');
    const card = source('src/components/view/CourseCard.tsx');
    const routeTypes = source('src/navigation/types.ts');

    expect(home).not.toMatch(
      /navigate\('CourseDetails',[\s\S]{0,180}(coinPrice|description|title):/,
    );
    expect(card).not.toMatch(
      /navigate\('CourseDetails',[\s\S]{0,180}(coinPrice|description|title):/,
    );
    expect(home).toContain('onOpenCourse={openCourse}');
    expect(card).not.toContain('useNavigation');
    expect(routeTypes).not.toContain('coinPrice?:');
  });

  it('opens every course card through details and keeps social proof off catalogue cards', () => {
    const home = source('src/screens/Home.tsx');
    const myCorner = source('src/screens/MyCorner.tsx');
    const card = source('src/components/view/CourseCard.tsx');
    const carousel = source('src/components/view/CarouselItem.tsx');
    const learningShelf = source('src/screens/myCorner/CourseShelf.tsx');

    expect(home).toContain("navigation.navigate('CourseDetails'");
    expect(myCorner).toContain("navigation.navigate('CourseDetails'");
    expect(learningShelf).toContain('onPress={() => onOpenCourse(course.id)}');
    expect(learningShelf).toContain('onResume(resumeTarget)');
    for (const catalogueCard of [card, carousel]) {
      expect(catalogueCard).not.toContain('durationMinutes');
      expect(catalogueCard).not.toContain('ratingAverage');
      expect(catalogueCard).not.toContain('ratingsCount');
      expect(catalogueCard).not.toContain('studentsCount');
    }
  });

  it('keeps canonical continuation in My Corner without inventing a Home row', () => {
    const catalogue = source('src/screens/home/homeCatalogue.ts');
    const overlay = source('src/screens/home/useHomeCatalogue.ts');
    const learningMapper = source(
      'src/services/api/learningCourseContract.ts',
    );
    const myCornerModel = source('src/screens/myCorner/model.ts');

    expect(learningMapper).toContain(
      'started: valueAsBoolean(item.learning_started)',
    );
    expect(overlay).toContain('started: access.started');
    expect(catalogue).not.toContain('course.started === true');
    expect(myCornerModel).toContain('!course.started');
  });

  it('consumes one-shot purchase intent and clears stale player targets', () => {
    const details = source('src/screens/CourseDetails/index.tsx');
    const purchase = source(
      'src/screens/CourseDetails/details/usePurchaseEntry.ts',
    );
    const map = source('src/components/view/Module.tsx');

    expect(purchase).toContain('consumeRouteIntent()');
    expect(details).toContain('resumeAfterPreview: false');
    expect(details).toContain('projectId: undefined');
    expect(map).toContain('reelId: undefined');
    expect(map).toContain('projectId: undefined');
    expect(map).toContain('preview: false');
  });

  it('preserves the exact preview position through login and purchase', () => {
    const details = source('src/screens/CourseDetails/index.tsx');
    const purchase = source(
      'src/screens/CourseDetails/details/usePurchaseEntry.ts',
    );

    expect(purchase).toMatch(
      /openPurchase: true,[\s\S]{0,420}resumeAfterPreview: true,[\s\S]{0,220}resumeReelId/,
    );
    expect(details).toContain(
      'resumeAfterPurchase && route.params?.resumeAfterPreview',
    );
    expect(details).toContain('resumeReelId || undefined');
  });

  it('returns the end of a preview to details or the same purchase journey', () => {
    const surface = source('src/screens/reels/ReelsSurface.tsx');
    const controller = source('src/screens/reels/useReelsController.tsx');
    const progress = source('src/screens/reels/useReelsProgress.ts');

    expect(surface).toContain(
      'onBackToDetails={() => controller.showCourseDetails(false)}',
    );
    expect(surface).toContain(
      'onStartLearning={() => controller.showCourseDetails(true)}',
    );
    expect(controller).toContain("navigation.replace('CourseDetails'");
    expect(controller).toContain('openPurchase,');
    expect(controller).toContain('resumeAfterPreview: openPurchase');
    expect(progress).toContain('setPreviewGateVisible(true)');
  });

  it('does not turn closing the payment surface into a pending payment', () => {
    const details = source('src/screens/CourseDetails/index.tsx');
    const purchase = source(
      'src/screens/CourseDetails/details/useCourseCheckout.ts',
    );
    const selectors = source('src/screens/CourseDetails/details/selectors.ts');
    const purchaseOrchestration = source(
      'src/screens/CourseDetails/details/useCoursePurchase.ts',
    );
    const purchaseFlow = source(
      'src/screens/CourseDetails/details/useCoursePurchaseFlow.ts',
    );
    const purchaseEntry = source(
      'src/screens/CourseDetails/details/usePurchaseEntry.ts',
    );
    const loader = source(
      'src/screens/CourseDetails/details/useCourseDetailsData.ts',
    );

    expect(purchase).toContain(
      "setNotice('لم يكتمل الدفع\\nيمكنك المحاولة مرة أخرى')",
    );
    expect(purchase).toContain('const outcome = coinCheckoutOutcome(result);');
    expect(purchase).toMatch(
      /if \(outcome === 'pending' && result\.orderRef\)[\s\S]{0,240}else if \(outcome === 'paid'\)/,
    );
    expect(purchase).toContain(
      "setNotice('جارٍ تأكيد الدفع\\nسيحدّث رصيدك فور التأكيد')",
    );
    expect(purchase).not.toContain('result.cancelled');
    expect(purchase).not.toContain('لم يكتمل الدفع بعد');
    expect(purchaseFlow.match(/selectCoursePurchaseEntryStep\(/g)).toHaveLength(
      2,
    );
    expect(`${purchaseOrchestration}\n${purchaseEntry}`).not.toContain(
      'selectCoursePurchaseEntryStep(',
    );
    expect(selectors).toContain('const owned = remoteCourse?.owned === true;');
    expect(loader).not.toContain('const [remoteOwned, setRemoteOwned]');
    expect(loader).toContain('ownershipWriteEpochRef.current');
    expect(loader).toContain('const [remoteNotice, setRemoteNotice]');
    expect(loader).not.toContain('setNotice: Dispatch');
    expect(details).toContain('notice={notice}');
    expect(details).toContain('{notice || course.notice}');
  });

  it('keeps course purchase orchestration behind one screen controller', () => {
    const details = source('src/screens/CourseDetails/index.tsx');
    const purchase = source(
      'src/screens/CourseDetails/details/useCoursePurchase.ts',
    );
    const entry = source(
      'src/screens/CourseDetails/details/usePurchaseEntry.ts',
    );
    const checkout = source(
      'src/screens/CourseDetails/details/useCourseCheckout.ts',
    );
    const flow = source(
      'src/screens/CourseDetails/details/useCoursePurchaseFlow.ts',
    );

    expect(details).toContain('useCoursePurchase({');
    expect(details).not.toContain('purchaseCourse(');
    expect(details).not.toContain('quoteCoursePurchase(');
    expect(details).not.toContain('openCoinCheckout(');
    expect(details).not.toContain('commerceInFlightRef');
    expect(purchase).toContain('} = useCoursePurchaseFlow();');
    expect(flow).toMatch(/useReducer\(\s*coursePurchaseFlowReducer/);
    expect(`${purchase}\n${entry}\n${checkout}`).not.toContain(
      'setDialogStep',
    );
    expect(purchase).toContain('usePurchaseEntry({');
    expect(purchase).toContain('useCourseCheckout({');
    expect(entry).toContain('const runPrimaryAction = useCallback(');
    expect(checkout).toContain('const confirm = useCallback(');
    expect(checkout).toContain('const buyCoins = useCallback(');
  });

  it('projects details and the owned outline from one details response', () => {
    const loader = source(
      'src/screens/CourseDetails/details/useCourseDetailsData.ts',
    );
    const outline = source('src/screens/CourseDetails/Lessons.tsx');

    expect(loader).toContain('getCourseDetailsSnapshot(');
    expect(loader).toContain('mapCoursePayload(snapshot.responsePayload)');
    expect(loader).toContain('useState<RemoteCourseSnapshot | null>(null)');
    expect(loader).not.toContain('setRemoteLearningCourse');
    expect(outline).not.toContain('loadCourseLearningData');
    expect(outline).not.toContain('publicRequest');
  });

  it('keeps the assistant entry reachable when the purchased plan needs an upgrade', () => {
    const sidebar = source('src/components/VideoPlayer/FeedSideBar.tsx');
    const controller = source('src/screens/reels/useReelsController.tsx');
    const surface = source('src/screens/reels/ReelsSurface.tsx');
    const overlay = source(
      'src/components/VideoPlayer/CourseChatOverlay.tsx',
    );

    expect(sidebar).toContain(
      'showChat={hasCourseLearningAccess(course.accessType)}',
    );
    expect(controller).toContain(
      'if (hasCourseLearningAccess(course.accessType)) setChatVisible(true);',
    );
    expect(controller).toContain('canOpenCourseAssistant: Boolean(');
    expect(surface).toContain('controller.canOpenCourseAssistant');
    expect(sidebar).not.toContain('showChat={includesCourseAssistant(course)}');
    expect(overlay).toContain(
      'const opened = visible && !previousVisibleRef.current;',
    );
    expect(overlay).toContain(
      '(opened || becameGated || (visible && courseChanged))',
    );
    expect(overlay).not.toContain('upgradeAutoLoadCourseRef');
  });

  it('keeps the production course journey free of runtime demo forks', () => {
    for (const file of [
      'src/screens/Home.tsx',
      'src/screens/home/useHomeCatalogue.ts',
      'src/screens/CourseDetails/index.tsx',
      'src/screens/CourseDetails/details/selectors.ts',
      'src/screens/CourseDetails/details/useCourseDetailsData.ts',
      'src/screens/Reels.tsx',
      'src/screens/reels/useReelsController.tsx',
      'src/screens/reels/useReelsCourseLoader.ts',
      'src/screens/reels/useReelsProgress.ts',
    ]) {
      expect(source(file)).not.toMatch(
        /LOCAL_DEMO_ENABLED|isLocalDemoId|DEMO_COURSE_ID/,
      );
    }
  });
});
