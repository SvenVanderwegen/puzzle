import { NativeTabs } from 'expo-router/native-tabs';
import { useTranslation } from 'react-i18next';

import { usePalette } from '@/theme';

/** The four native tabs: Today, Campaign, Practice, You. */
export default function TabsLayout() {
  const { t } = useTranslation();
  const pal = usePalette();
  return (
    <NativeTabs tintColor={pal.ink} labelStyle={{ selected: { color: pal.ink } }}>
      <NativeTabs.Trigger name="today">
        <NativeTabs.Trigger.Label>{t('tabs.today')}</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf="sparkle" md="today" />
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="campaign">
        <NativeTabs.Trigger.Label>{t('tabs.campaign')}</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'flag', selected: 'flag.fill' }} md="flag" />
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="practice">
        <NativeTabs.Trigger.Label>{t('tabs.practice')}</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'square.grid.2x2', selected: 'square.grid.2x2.fill' }} md="grid_view" />
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="you">
        <NativeTabs.Trigger.Label>{t('tabs.you')}</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'person.crop.circle', selected: 'person.crop.circle.fill' }} md="person" />
      </NativeTabs.Trigger>
    </NativeTabs>
  );
}
