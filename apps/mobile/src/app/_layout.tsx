import '@/i18n';

import { BricolageGrotesque_700Bold } from '@expo-google-fonts/bricolage-grotesque/700Bold';
import { BricolageGrotesque_800ExtraBold } from '@expo-google-fonts/bricolage-grotesque/800ExtraBold';
import { useFonts } from 'expo-font';
import { DarkTheme, DefaultTheme, Stack, ThemeProvider } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { useEffect } from 'react';
import { useColorScheme } from 'react-native';
import { useTranslation } from 'react-i18next';

import { applyAppearance } from '@/state/settings';
import { dark, light } from '@/theme';

SplashScreen.preventAutoHideAsync();
applyAppearance();

export default function RootLayout() {
  const [loaded] = useFonts({ BricolageGrotesque_700Bold, BricolageGrotesque_800ExtraBold });
  const scheme = useColorScheme();
  const { t } = useTranslation();
  const pal = scheme === 'dark' ? dark : light;
  const base = scheme === 'dark' ? DarkTheme : DefaultTheme;

  useEffect(() => {
    if (loaded) SplashScreen.hideAsync();
  }, [loaded]);

  if (!loaded) return null;

  return (
    <ThemeProvider
      value={{
        ...base,
        colors: { ...base.colors, background: pal.ground, card: pal.ground, text: pal.ink, primary: pal.ink, border: pal.line },
      }}>
      <Stack screenOptions={{ headerBackButtonDisplayMode: 'minimal', contentStyle: { backgroundColor: pal.ground } }}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="play" options={{ title: '', headerShadowVisible: false }} />
        <Stack.Screen
          name="result"
          options={{
            presentation: 'formSheet',
            sheetAllowedDetents: 'fitToContents',
            sheetGrabberVisible: true,
            headerShown: false,
            contentStyle: { backgroundColor: pal.surface },
          }}
        />
        <Stack.Screen name="welcome" options={{ presentation: 'fullScreenModal', headerShown: false }} />
        <Stack.Screen name="lesson" options={{ presentation: 'modal', title: t('fire.lesson') }} />
      </Stack>
    </ThemeProvider>
  );
}
