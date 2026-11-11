/** The daily reminder: one local notification a day, no account, no server. */
import * as Notifications from 'expo-notifications';

import i18n from '@/i18n';

const ID = 'daily-fire';

export async function scheduleReminder(minutes: number): Promise<boolean> {
  const perm = await Notifications.getPermissionsAsync();
  const granted = perm.granted || (await Notifications.requestPermissionsAsync()).granted;
  if (!granted) return false;
  await Notifications.cancelScheduledNotificationAsync(ID).catch(() => {});
  await Notifications.scheduleNotificationAsync({
    identifier: ID,
    content: { title: i18n.t('reminder.title'), body: i18n.t('reminder.body') },
    trigger: {
      type: Notifications.SchedulableTriggerInputTypes.DAILY,
      hour: Math.floor(minutes / 60),
      minute: minutes % 60,
    },
  });
  return true;
}

export async function cancelReminder() {
  await Notifications.cancelScheduledNotificationAsync(ID).catch(() => {});
}
