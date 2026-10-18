import {Alert, Linking} from 'react-native';
import {learnerCopy} from '../constants/learnerCopy';

/** Maps vendor-specific picker failures to one stable learner recovery path. */
export const showMediaPickerFailure = (errorCode?: string) => {
  if (errorCode === 'LEARNER_DRAFT_STORAGE_FULL') {
    Alert.alert(
      'اكتملت مساحة الملفات المعلّقة',
      `اتصل بالإنترنت لإرسال الملفات المعلّقة\nو${learnerCopy.retry}`,
    );
    return;
  }
  if (errorCode === 'permission') {
    Alert.alert(
      'تعذّر فتح الصور',
      `اسمح لركن بالوصول إلى الصور و${learnerCopy.retry}`,
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'فتح الإعدادات',
          onPress: () => void Linking.openSettings().catch(() => undefined),
        },
      ],
    );
    return;
  }
  Alert.alert('تعذّر اختيار الملف', learnerCopy.retry);
};
