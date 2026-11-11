/** First launch: what the game is, and the choice between the lesson and playing. */
import { StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { SafeAreaView } from 'react-native-safe-area-context';

import { LogoMark } from '@/components/glyphs';
import { Button } from '@/components/ui';
import { updateSettings } from '@/state/settings';
import { brandFont, usePalette } from '@/theme';

export default function Welcome() {
  const { t } = useTranslation();
  const pal = usePalette();
  const close = (lesson: boolean) => {
    updateSettings({ seenWelcome: true });
    router.back();
    if (lesson) router.push('/lesson');
  };
  return (
    <SafeAreaView style={[styles.screen, { backgroundColor: pal.ground }]}>
      <View style={styles.brand}>
        <LogoMark size={44} />
        <Text style={[styles.wordmark, { color: pal.ink }]}>burnfront</Text>
      </View>
      <View style={styles.middle}>
        <Text style={[styles.title, { color: pal.ink }]} accessibilityRole="header">
          {t('welcome.title')}
        </Text>
        <Text style={[styles.body, { color: pal.ink }]}>{t('welcome.body')}</Text>
      </View>
      <View style={styles.actions}>
        <Button label={t('welcome.showMe')} onPress={() => close(true)} />
        <Button kind="soft" label={t('welcome.skip')} onPress={() => close(false)} />
        <Text style={[styles.note, { color: pal.muted }]}>{t('welcome.note')}</Text>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, paddingHorizontal: 24 },
  brand: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 16 },
  wordmark: { fontFamily: brandFont.extraBold, fontSize: 28, letterSpacing: -1 },
  middle: { flex: 1, justifyContent: 'center', gap: 16 },
  title: { fontFamily: brandFont.extraBold, fontSize: 44, lineHeight: 46, letterSpacing: -1.6 },
  body: { fontSize: 20, lineHeight: 28 },
  actions: { gap: 10, paddingBottom: 16 },
  note: { fontSize: 15, textAlign: 'center', marginTop: 4 },
});
