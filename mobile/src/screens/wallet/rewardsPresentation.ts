import type {CoinTask} from '../../services/roknApi';

// Registration is credited by authentication, never by a task button.
export const learnerRewardTasks = (tasks: CoinTask[]) =>
  tasks.filter(task => !['register', 'welcome_bonus'].includes(task.actionKey));

export const rewardTaskTitle = (task: CoinTask) => {
  const action = task.actionKey.toLowerCase();
  if (
    action === 'follow_instagram' &&
    /^(تابعنا على Instagram|تابع ركن على إنستجرام|رُكن على إنستجرام)$/.test(
      task.title,
    )
  )
    return 'تابعنا على إنستجرام';
  if (
    action === 'follow_youtube' &&
    /^(تابعنا على YouTube|تابع ركن على يوتيوب|قناة رُكن على يوتيوب)$/.test(
      task.title,
    )
  )
    return 'اشترك في قناتنا على يوتيوب';
  return task.title;
};
