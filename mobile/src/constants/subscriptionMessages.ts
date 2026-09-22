/** Approved subscription messages shared by gates and chat responses. */
export const subscriptionMessages = {
  chatSubscribe: {
    title: 'الشات يحتاج اشتراكًا',
    body: 'اختر اشتراكًا يشمل الشات مع مدرب رُكن',
    action: 'عرض الاشتراكات',
  },
  chatUpgrade: {
    title: 'اشتراكك لا يشمل الشات',
    body: 'قم بترقية اشتراكك وادفع فرق السعر فقط',
    action: 'ترقية الاشتراك',
  },
  chatExhausted: {
    title: 'استخدمت كل رسائلك',
    body: 'قم بترقية اشتراكك للحصول على رسائل أكثر وادفع فرق السعر فقط',
    action: 'ترقية الاشتراك',
  },
  portfolioSubscribe: {
    title: 'لإضافة أعمالك',
    body: 'اشترك في كورس باشتراك يشمل شهادة',
    action: 'تصفح الكورسات',
  },
  portfolioUpgrade: {
    title: 'لإضافة أعمالك',
    body: 'قم بترقية اشتراكك إلى اشتراك يشمل شهادة',
    action: 'عرض الاشتراكات',
  },
  chatDailyLimit: {
    title: 'استخدمت رسائل اليوم',
    body: 'يمكنك إرسال رسائل جديدة غدًا',
    action: 'حسنًا',
  },
} as const;

export const subscriptionMessageText = (message: {
  title: string;
  body: string;
}) => `${message.title}\n${message.body}`;
