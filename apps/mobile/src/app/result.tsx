/** The result, as a native bottom sheet over the burned map. Leads with "Next fire". */
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BurnCurve } from '@/components/Meters';
import { playCampaign, playDaily, playQuick } from '@/components/nav';
import { shareResult } from '@/components/share';
import { Button } from '@/components/ui';
import { burnCurve } from '@/engine/game';
import { campaignFire } from '@/engine/puzzles';
import { fmtClock } from '@/engine/rules';
import { nextCampaign, useProgress } from '@/state/progress';
import { useSession } from '@/state/session';
import { brandFont, heat, usePalette } from '@/theme';

export default function Result() {
  const { t } = useTranslation();
  const pal = usePalette();
  const insets = useSafeAreaInsets();
  const session = useSession();
  const progress = useProgress();
  const fire = session.fire;
  if (!fire || !session.solved) return null;

  // Next fire: the next campaign fire; after a quick fire, another of that size.
  const n = nextCampaign(progress);
  const nextLine =
    fire.kind === 'quick' && fire.size
      ? t('result.nextQuick', { size: fire.size })
      : n
        ? t('result.nextCampaign', { number: n, name: t('fire.name', { place: campaignFire(n).place }) })
        : null;
  const goNext = () => {
    router.back();
    if (fire.kind === 'quick' && fire.size) playQuick(fire.size, true);
    else if (n) playCampaign(n, true);
    else playDaily(true);
  };

  return (
    <View style={[styles.sheet, { paddingBottom: Math.max(insets.bottom, 16) + 8 }]}>
      <View style={styles.top}>
        <View style={styles.headline}>
          <Text style={[styles.contained, { color: pal.ink }]} accessibilityRole="header">
            {t('result.contained')}
          </Text>
          <Text style={[styles.line, { color: pal.ink }]}>
            {t('result.line', { time: fmtClock(session.finalMs), hints: t('common.hints', { count: session.hints }) })}
          </Text>
        </View>
        <BurnCurve curve={burnCurve(fire.puzzle)} />
      </View>
      <Text style={[styles.note, { color: pal.muted }]}>{t('result.shareNote')}</Text>

      {nextLine && (
        <Pressable
          onPress={goNext}
          accessibilityRole="button"
          accessibilityLabel={`${t('result.nextFire')}. ${nextLine}`}
          style={({ pressed }) => [styles.next, { backgroundColor: pal.primary, opacity: pressed ? 0.85 : 1 }]}>
          <View style={styles.nextText}>
            <Text style={[styles.nextTitle, { color: pal.onPrimary }]}>{t('result.nextFire')}</Text>
            <Text style={[styles.nextLine, { color: pal.onPrimary }]} numberOfLines={1}>
              {nextLine}
            </Text>
          </View>
          <View style={[styles.nextCircle, { backgroundColor: heat(0, 1).bg }]}>
            <Text style={styles.nextArrow}>›</Text>
          </View>
        </Pressable>
      )}

      <View style={styles.row}>
        <Button
          kind="soft"
          icon="square.and.arrow.up"
          label={t('common.share')}
          onPress={() => shareResult(fire, session.finalMs, session.hints)}
          style={styles.half}
        />
        <Button
          kind="soft"
          label={t('common.done')}
          onPress={() => router.dismiss(2)}
          style={styles.half}
        />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  sheet: { paddingHorizontal: 20, paddingTop: 24, gap: 12 },
  top: { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between', gap: 16 },
  headline: { flexShrink: 0 },
  contained: { fontFamily: brandFont.extraBold, fontSize: 40, letterSpacing: -1.4 },
  line: { fontSize: 17 },
  note: { fontSize: 15 },
  next: { flexDirection: 'row', alignItems: 'center', borderRadius: 30, paddingLeft: 22, paddingRight: 8, minHeight: 60, gap: 12 },
  nextText: { flex: 1, paddingVertical: 8 },
  nextTitle: { fontSize: 18, fontWeight: '700' },
  nextLine: { fontSize: 14, opacity: 0.85 },
  nextCircle: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
  nextArrow: { fontSize: 28, fontWeight: '700', color: '#16181B', marginTop: -3 },
  row: { flexDirection: 'row', gap: 10 },
  half: { flex: 1 },
});
