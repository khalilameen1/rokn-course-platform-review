import React, {useEffect, useMemo, useState} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {useNavigation} from '@react-navigation/native';
import {Container, Content} from '../../components/containers/Containers';
import {
  PremiumCard,
  ResponsiveFrame,
  SectionHeading,
} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {getManagedPublicContent} from '../../services/publicContent';
import type {RootNavigation} from '../../navigation/types';

const SECTIONS = [
  {
    number: '٠١',
    title: 'من المسؤول عن بياناتك؟',
    body: 'تدير ركن شركة ركن للإنتاج الرقمي والمحتوى – شركة شخص واحد ذات مسؤولية محدودة، والمرخصة بمزاولة أنشطة تكنولوجيا المعلومات بترخيص ITIDA رقم ٢٣٨. الترخيص يخص نشاط الشركة ولا يعني اعتماد الكورسات أو الشهادات.',
  },
  {
    number: '٠٢',
    title: 'ما البيانات التي نجمعها؟',
    body: 'بحسب استخدامك، نعالج بيانات الحساب التي يتيحها مزود تسجيل الدخول، وبيانات التعلم والتقدم والمحفوظات والمشاريع والبورتفوليو والشهادات، وحركات العملات والدفع، وبيانات الجهاز والأعطال والدعم. لا نطلب أكثر مما تحتاجه الخدمة.',
  },
  {
    number: '٠٣',
    title: 'لماذا نستخدمها؟',
    body: 'نستخدم البيانات لإنشاء الحساب وتأمينه، وتشغيل الكورسات وحفظ التقدم، ومراجعة المشاريع، وإصدار الشهادات، وتنفيذ المعاملات، وتقديم الدعم، ومنع الاحتيال، وتحسين الأداء، وإرسال الإشعارات التي اخترتها، والوفاء بالتزاماتنا القانونية.',
  },
  {
    number: '٠٤',
    title: 'تسجيل الدخول',
    body: 'عند الدخول عبر Google أو Facebook أو TikTok نستقبل البيانات الضرورية التي يسمح بها حسابك، مثل المعرّف والاسم والبريد والصورة. يمكنك تصفح الأجزاء المتاحة للضيف دون حساب، ونطلب الدخول فقط عند استخدام ميزة تحتاج إلى حفظ أو مزامنة أو معاملة.',
  },
  {
    number: '٠٥',
    title: 'التقدم وسجل المشاهدة',
    body: 'تقدم التعلم يحفظ موضعك ويفتح الأجزاء ويثبت متطلبات الشهادة. أما سجل المشاهدة فهو سجل منفصل يساعدك في الرجوع لما شاهدته وقد يستخدم للتوصيات. يمكنك إيقافه أو حذفه؛ حذف السجل لا يمحو تقدم الكورس أو حقك في المحتوى.',
  },
  {
    number: '٠٦',
    title: 'العملات والدفع',
    body: 'نسجل العملات المدفوعة وعملات المكافآت في رصيدين منفصلين حتى لو ظهر لك الإجمالي في المحفظة. يعالج Kashier أو Google Play أو App Store الدفع بحسب نسخة التطبيق، وتستقبل ركن مرجع العملية والمنتج وقيمته وحالته اللازمة لإضافة الرصيد ومنع التكرار وحل المشكلات. لا تخزن ركن رقم البطاقة الكامل أو رمز الأمان.',
  },
  {
    number: '٠٧',
    title: 'الاستفسارات',
    body: 'تعمل خدمة الاستفسارات بالذكاء الاصطناعي ونرسل سؤالك وسياقًا مختصرًا عن الكورس إلى OpenRouter ومزود النموذج لإنتاج الرد. لا تبني ركن ذاكرة دائمة من محادثاتك ولا تستدعي شاتات قديمة في جلسة جديدة. قد نحتفظ لفترة قصيرة بإجابة مجهّلة للأسئلة المتطابقة لتقليل التكلفة، وقد يعالج المزود سجلات تقنية وفق إعداداته وسياساته. فلا ترسل بيانات حساسة أو أسرار عمل.',
  },
  {
    number: '٠٨',
    title: 'مع من نشارك البيانات؟',
    body: 'لا نبيع بياناتك أو نؤجرها. نشارك الحد الضروري مع مزودي تسجيل الدخول، وKashier وGoogle Play وApp Store للدفع، وBunny لتخزين وتوصيل الفيديو والصور والملفات، وOpenRouter ومزودي النماذج، وخدمات التشغيل والدعم، والمدرب أو المراجع عند مراجعة مشروعك، أو جهة مختصة عندما يلزم القانون.',
  },
  {
    number: '٠٩',
    title: 'البورتفوليو والشهادات',
    body: 'تظل أعمالك ملكك. الأعمال التي تضيفها إلى البورتفوليو تظهر لمن يصل إلى رابط المشاركة غير المُدرج أو رمز QR، ولا تضعها ركن في معرض عام أو نتائج بحث. لا نعرض بريدك أو رقم هاتفك في صفحة المشاركة إلا إذا أضفته بنفسك ضمن محتوى أو رابط.',
  },
  {
    number: '١٠',
    title: 'الاحتفاظ والحماية والنقل',
    body: 'نحتفظ بكل فئة بقدر حاجة الخدمة أو المدة التي يفرضها القانون، ثم نحذفها أو نخفي هويتها. قد تعالج بعض خدماتنا البيانات خارج مصر؛ ويتم ذلك وفق المتطلبات والضمانات القانونية السارية. نستخدم ضوابط تقنية وتنظيمية مناسبة، مع عدم وجود خدمة رقمية تضمن حماية مطلقة.',
  },
  {
    number: '١١',
    title: 'اختياراتك وحقوقك',
    body: 'يمكنك تحديث بياناتك، وإدارة الإشعارات والتوصيات، وإيقاف سجل المشاهدة أو حذفه، وطلب نسخة من بياناتك أو تصحيحها، وسحب موافقة اختيارية، وحذف الحساب من الإعدادات. يُغلق الحساب وتُزال هويته عند نجاح الطلب، وقد يستغرق تنظيف بعض الملفات والنسخ الاحتياطية لدى مقدمي الخدمة وقتًا تقنيًا محدودًا. وقد نحتفظ بسجلات محدودة يفرضها القانون مثل الفواتير ومنع الاحتيال.',
  },
  {
    number: '١٢',
    title: 'الأطفال والتحديثات',
    body: 'من لم يبلغ السن القانوني للتعاقد يستخدم ركن بموافقة ولي الأمر. نتعامل مع بيانات الأطفال بوصفها بيانات حساسة ولا نجمعها عمدًا دون الضوابط اللازمة. إذا تغيرت طريقة معالجة البيانات سنوضح تاريخ التحديث وننبهك إلى أي تغيير جوهري.',
  },
];

export default function PrivacyPolicy() {
  const navigation = useNavigation<RootNavigation>();
  const {fontScale, isTablet, width} = useResponsiveLayout();
  const stackContact = width < 430 || fontScale > 1.2;
  const [managedBody, setManagedBody] = useState('');
  useEffect(() => {
    let active = true;
    void getManagedPublicContent('privacy')
      .then(body => active && setManagedBody(body))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);
  const visibleSections = useMemo(
    () =>
      managedBody
        ? [{number: '٠١', title: 'سياسة الخصوصية', body: managedBody}]
        : SECTIONS,
    [managedBody],
  );

  const contactSupport = () =>
    navigation.navigate('Feedback', {sourceScreen: 'privacy'});

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame>
          <HeaderWithBack title="سياسة الخصوصية" />

          <PremiumCard style={styles.summary}>
            <View style={styles.shield}>
              <View style={styles.shieldCore} />
            </View>
            <View style={styles.summaryCopy}>
              <Text style={styles.summaryTitle}>
                بياناتك تخصك
              </Text>
              <Text style={styles.summaryBody}>
                هنا نوضح ما الذي تحتاجه ركن لتشغيل تجربتك، ولماذا نستخدمه، ومن
                يستطيع الوصول إليه، وما الذي يمكنك التحكم فيه.
              </Text>
              <Text style={styles.updated}>آخر تحديث: ٣١ أغسطس ٢٠٢٦</Text>
            </View>
          </PremiumCard>

          <SectionHeading
            eyebrow="من أول زيارة إلى حذف الحساب"
            style={styles.heading}
            title="كيف نتعامل مع بياناتك"
          />

          <View style={[styles.grid, isTablet && styles.gridTablet]}>
            {visibleSections.map(section => (
              <PremiumCard
                accessibilityLabel={`${section.title}. ${section.body}`}
                key={section.number}
                style={[styles.sectionCard, isTablet && styles.sectionCardTablet]}>
                <View style={styles.sectionHeader}>
                  <Text style={styles.sectionNumber}>{section.number}</Text>
                  <Text style={styles.sectionTitle}>{section.title}</Text>
                </View>
                <Text style={styles.sectionBody}>{section.body}</Text>
              </PremiumCard>
            ))}
          </View>

          <PremiumCard
            style={[
              styles.contactCard,
              stackContact && styles.contactCardStacked,
            ]}>
            <View style={styles.contactCopy}>
              <Text style={styles.contactTitle}>لديك طلب بخصوص بياناتك؟</Text>
              <Text style={styles.contactDescription}>
                راسل فريق ركن مباشرة، ولا ترسل كلمة مرور أو بيانات بطاقة دفع.
              </Text>
            </View>
            <Pressable
              accessibilityRole="button"
              onPress={contactSupport}
              style={({pressed}) => [
                styles.contactButton,
                stackContact && styles.contactButtonStacked,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.contactButtonText}>تواصل مع ركن</Text>
            </Pressable>
          </PremiumCard>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  summary: {
    ...rtlRowStyle,
    alignItems: 'center',
    padding: Spacing.lg,
    marginTop: Spacing.sm,
    backgroundColor: Palette.surfaceRaised,
  },
  shield: {
    width: 54,
    height: 58,
    borderTopLeftRadius: Radius.lg,
    borderTopRightRadius: Radius.lg,
    borderBottomLeftRadius: Radius.xl,
    borderBottomRightRadius: Radius.xl,
    backgroundColor: Palette.primarySoft,
    borderWidth: 1,
    borderColor: 'rgba(89,148,255,0.30)',
    alignItems: 'center',
    justifyContent: 'center',
    marginEnd: Spacing.md,
  },
  shieldCore: {
    width: 13,
    height: 13,
    borderRadius: 7,
    backgroundColor: Palette.primary,
  },
  summaryCopy: {flex: 1},
  summaryTitle: {
    ...Type.section,
    ...textDirection,
    color: Palette.text,
  },
  summaryBody: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
    maxWidth: 760,
  },
  updated: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: Spacing.sm,
  },
  heading: {marginTop: Spacing.xl, marginBottom: Spacing.sm},
  grid: {gap: Spacing.sm},
  gridTablet: {...rtlRowStyle, flexWrap: 'wrap'},
  sectionCard: {padding: Spacing.lg},
  sectionCardTablet: {width: '48.9%', minHeight: 200},
  sectionHeader: {...rtlRowStyle, alignItems: 'center'},
  sectionNumber: {
    ...Type.caption,
    color: Palette.primary,
    marginEnd: Spacing.sm,
  },
  sectionTitle: {
    ...Type.section,
    ...textDirection,
    color: Palette.text,
    flex: 1,
  },
  sectionBody: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.sm,
  },
  contactCard: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.md,
    padding: Spacing.lg,
    marginTop: Spacing.lg,
    marginBottom: Spacing.xl,
    backgroundColor: 'rgba(52,120,246,0.075)',
  },
  contactCopy: {flex: 1, minWidth: 0},
  contactCardStacked: {alignItems: 'stretch', flexDirection: 'column'},
  contactTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
  },
  contactDescription: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
  },
  contactButton: {
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  contactButtonStacked: {alignItems: 'center', alignSelf: 'stretch'},
  contactButtonText: {...Type.bodyStrong, color: Palette.text},
  pressed: {opacity: 0.72, transform: [{scale: 0.985}]},
});
