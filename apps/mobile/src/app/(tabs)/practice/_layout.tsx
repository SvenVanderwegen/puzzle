import { Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';

export default function PracticeLayout() {
  const { t } = useTranslation();
  return (
    <Stack screenOptions={{ headerLargeTitleEnabled: true, headerTransparent: true, headerLargeTitleShadowVisible: false }}>
      <Stack.Screen name="index" options={{ title: t('tabs.practice') }} />
    </Stack>
  );
}
