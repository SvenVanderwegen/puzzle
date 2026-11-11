/** You: your numbers and the settings. No account needed. */
import { Alert, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { router } from 'expo-router';
import { useTranslation } from 'react-i18next';

import { Row, Section } from '@/components/Grouped';
import { Segmented } from '@/components/Segmented';
import { playSound } from '@/components/sounds';
import { TimePicker } from '@/components/TimePicker';
import { Card } from '@/components/ui';
import { fmtClock } from '@/engine/rules';
import { cancelReminder, scheduleReminder } from '@/state/reminder';
import { streak, useProgress } from '@/state/progress';
import { updateSettings, useSettings, type AppearanceChoice } from '@/state/settings';
import { brandFont, usePalette } from '@/theme';

export default function You() {
  const { t } = useTranslation();
  const pal = usePalette();
  const settings = useSettings();
  const progress = useProgress();

  const toggle = (label: string, value: boolean, onChange: (v: boolean) => void) => (
    <Switch value={value} onValueChange={onChange} accessibilityLabel={label} />
  );

  const setReminder = async (on: boolean, at = settings.reminderAt) => {
    if (!on) {
      updateSettings({ reminder: false });
      await cancelReminder();
      return;
    }
    const ok = await scheduleReminder(at);
    updateSettings({ reminder: ok, reminderAt: at });
    if (!ok) Alert.alert(t('you.reminderDenied'));
  };

  const stat = (label: string, value: string) => (
    <View style={styles.stat} accessible accessibilityLabel={`${label}: ${value}`}>
      <Text style={[styles.statLabel, { color: pal.muted }]}>{label}</Text>
      <Text style={[styles.statValue, { color: pal.ink }]}>{value}</Text>
    </View>
  );

  return (
    <ScrollView contentInsetAdjustmentBehavior="automatic" style={{ backgroundColor: pal.ground }} contentContainerStyle={styles.page}>
      <Text style={[styles.eyebrow, { color: pal.muted }]}>{t('you.noAccount')}</Text>
      <Card style={styles.stats}>
        {stat(t('you.streak'), t('you.streakDays', { count: streak(progress) }))}
        {stat(t('you.contained'), String(progress.log.length))}
        {stat(t('you.bestTime'), progress.bestMs == null ? t('you.none') : fmtClock(progress.bestMs))}
      </Card>

      <Section title={t('you.playingHeader')} footer={t('you.playingFooter')}>
        <Row label={t('you.showClock')} trailing={toggle(t('you.showClock'), settings.showClock, (v) => updateSettings({ showClock: v }))} />
        <Row label={t('you.sounds')} trailing={toggle(t('you.sounds'), settings.sounds, (v) => {
              updateSettings({ sounds: v });
              if (v) playSound('place');
            })} />
        <Row label={t('you.haptics')} trailing={toggle(t('you.haptics'), settings.haptics, (v) => updateSettings({ haptics: v }))} />
        <Row label={t('you.flagLate')} trailing={toggle(t('you.flagLate'), settings.flagLate, (v) => updateSettings({ flagLate: v }))} />
      </Section>

      <Section title={t('you.displayHeader')}>
        <View style={styles.appearance}>
          <Text style={[styles.rowLabel, { color: pal.ink }]}>{t('you.appearance')}</Text>
          <Segmented<AppearanceChoice>
            label={t('you.appearance')}
            value={settings.appearance}
            onChange={(v) => updateSettings({ appearance: v })}
            options={[
              { value: 'auto', label: t('you.appearanceAuto') },
              { value: 'light', label: t('you.appearanceLight') },
              { value: 'dark', label: t('you.appearanceDark') },
            ]}
          />
        </View>
        <Row label={t('you.textSize')} value={t('you.textSizeValue')} />
        <Row label={t('you.language')} value={t('you.languageValue')} />
      </Section>

      <Section title={t('you.reminderHeader')}>
        <Row label={t('you.remindMe')} trailing={toggle(t('you.remindMe'), settings.reminder, (v) => setReminder(v))} />
        {settings.reminder && (
          <Row
            label={t('you.time')}
            trailing={<TimePicker label={t('you.time')} minutes={settings.reminderAt} onChange={(m) => setReminder(true, m)} />}
          />
        )}
      </Section>

      <Section>
        <Row label={t('you.showLesson')} onPress={() => router.push('/lesson')} />
      </Section>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  page: { padding: 16, gap: 20, paddingBottom: 40 },
  eyebrow: { fontSize: 13, fontWeight: '600', textTransform: 'uppercase', letterSpacing: 0.3 },
  stats: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  stat: { flexGrow: 1, flexBasis: 90 },
  statLabel: { fontSize: 14 },
  statValue: { fontFamily: brandFont.bold, fontSize: 24, letterSpacing: -0.5 },
  appearance: { paddingHorizontal: 16, paddingVertical: 12, gap: 10 },
  rowLabel: { fontSize: 17 },
});
