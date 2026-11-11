/**
 * Home. Two states: before the daily fire (the hero is today's board) and
 * after it (a "contained" strip, and the hero becomes "Up next").
 */
import { useEffect } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { useTranslation } from 'react-i18next';

import { Board } from '@/components/Board';
import { HeatBar } from '@/components/Meters';
import { Body, Button, Card, Eyebrow, FireTitle } from '@/components/ui';
import { playCampaign, playDaily, playQuick } from '@/components/nav';
import { shareResult } from '@/components/share';
import { useNow } from '@/components/useNow';
import { QUICK_FIRES, type BoardSize } from '@/config';
import { lastMinute } from '@/engine/game';
import { CAMPAIGN_SIZE, campaignFire, localDate, type Fire } from '@/engine/puzzles';
import { fmtClock } from '@/engine/rules';
import { useDailyFire, warmQuick } from '@/state/fires';
import { campaignDone, level, nextCampaign, streak, thisWeek, useProgress } from '@/state/progress';
import { useSettings } from '@/state/settings';
import { heat, usePalette } from '@/theme';

const HOME_SIZES: BoardSize[] = [5, 6, 8];

export default function Today() {
  const { t } = useTranslation();
  const pal = usePalette();
  const now = useNow();
  const today = localDate(new Date(now));
  const fire = useDailyFire(today);
  const progress = useProgress();
  const settings = useSettings();
  const result = progress.daily[today];
  const days = streak(progress, today);

  useEffect(() => {
    if (!settings.seenWelcome) router.push('/welcome');
  }, [settings.seenWelcome]);

  useEffect(() => {
    // Have a quick fire of each size ready before the player asks.
    const id = setTimeout(() => HOME_SIZES.forEach(warmQuick), 1500);
    return () => clearTimeout(id);
  }, []);

  const midnight = new Date(now);
  midnight.setHours(24, 0, 0, 0);
  const left = Math.max(0, Math.round((midnight.getTime() - now) / 60000));

  return (
    <ScrollView
      contentInsetAdjustmentBehavior="automatic"
      style={{ backgroundColor: pal.ground }}
      contentContainerStyle={styles.page}>
      {days > 0 && (
        <View style={[styles.pill, { backgroundColor: pal.surface }]}>
          <View style={[styles.pillDot, { backgroundColor: heat(2, 8).bg }]} />
          <Text style={[styles.pillText, { color: pal.ink }]}>{t('today.streak', { count: days })}</Text>
        </View>
      )}

      {result && fire ? (
        <>
          <DoneStrip fire={fire} ms={result.ms} hints={result.hints} />
          <UpNext />
        </>
      ) : (
        <Card>
          <View style={styles.heroRow}>
            <View style={styles.heroBoard}>
              {fire ? (
                <Board puzzle={fire.puzzle} marks={[]} lastMinute={lastMinute(fire.puzzle)} thumbnail />
              ) : (
                <View style={[styles.boardPlaceholder, { backgroundColor: pal.soft }]} />
              )}
            </View>
            <View style={styles.heroText}>
              <Eyebrow>{fire ? t('today.todayLabel', { number: fire.number }) : t('tabs.today')}</Eyebrow>
              <FireTitle>{fire ? t('fire.name', { place: fire.place }) : t('common.loadingFire')}</FireTitle>
              {fire && (
                <Body>
                  {t('common.sizeBy', { size: fire.puzzle.rows })} · {t('common.firebreaks', { count: fire.puzzle.breaks })}
                </Body>
              )}
              <Body muted>{t('today.newFireIn', { hours: Math.floor(left / 60), minutes: left % 60 })}</Body>
            </View>
          </View>
          <Button label={t('today.playDaily')} onPress={() => playDaily()} disabled={!fire} />
        </Card>
      )}

      {!result && <CampaignRow />}

      <Card>
        <View>
          <Text style={[styles.cardTitle, { color: pal.ink }]}>{t('today.quickTitle')}</Text>
          <Body muted>{t('today.quickBody')}</Body>
        </View>
        <View style={styles.sizes}>
          {HOME_SIZES.map((size) => (
            <Button
              key={size}
              kind="soft"
              label={t('common.sizeBy', { size: QUICK_FIRES[size].rows })}
              onPress={() => playQuick(size)}
              style={styles.sizeButton}
            />
          ))}
        </View>
      </Card>

      <Goals />
    </ScrollView>
  );
}

function DoneStrip({ fire, ms, hints }: { fire: Fire; ms: number; hints: number }) {
  const { t } = useTranslation();
  const pal = usePalette();
  const p = fire.puzzle;
  const marks = p.times.map((m) => (m < 0 ? 1 : 0));
  return (
    <Card style={styles.doneRow}>
      <View style={styles.doneThumb}>
        <Board puzzle={p} marks={marks} times={p.times} reveal={999} lastMinute={lastMinute(p)} thumbnail />
      </View>
      <View style={styles.heroText}>
        <Text style={[styles.cardTitle, { color: pal.ink }]}>{t('today.containedTitle')}</Text>
        <Body muted>{t('today.containedLine', { time: fmtClock(ms), hints: t('common.hints', { count: hints }) })}</Body>
      </View>
      <Pressable
        onPress={() => shareResult(fire, ms, hints)}
        accessibilityRole="button"
        accessibilityLabel={t('common.share')}
        style={({ pressed }) => [styles.roundButton, { backgroundColor: pal.soft, opacity: pressed ? 0.7 : 1 }]}>
        <Text style={[styles.roundButtonText, { color: pal.ink }]}>{t('common.share')}</Text>
      </Pressable>
    </Card>
  );
}

function UpNext() {
  const { t } = useTranslation();
  const progress = useProgress();
  const n = nextCampaign(progress);
  if (n == null) return null;
  const fire = campaignFire(n);
  const p = fire.puzzle;
  return (
    <Card>
      <View style={styles.heroRow}>
        <View style={styles.heroBoardSmall}>
          <Board puzzle={p} marks={[]} lastMinute={lastMinute(p)} thumbnail />
        </View>
        <View style={styles.heroText}>
          <Eyebrow>{t('today.upNext')}</Eyebrow>
          <FireTitle>{t('fire.name', { place: fire.place })}</FireTitle>
          <Body>
            {t('today.upNextLine', { number: n, size: p.rows, numbers: t('common.numbers', { count: p.clues.length }) })}
          </Body>
          <Body muted>{t('campaign.progress', { done: campaignDone(progress), total: CAMPAIGN_SIZE })}</Body>
        </View>
      </View>
      <Button label={t('today.playFire', { number: n })} onPress={() => playCampaign(n)} />
    </Card>
  );
}

function CampaignRow() {
  const { t } = useTranslation();
  const pal = usePalette();
  const progress = useProgress();
  const n = nextCampaign(progress);
  const done = campaignDone(progress);
  return (
    <Pressable
      accessibilityRole="button"
      onPress={() => (n ? playCampaign(n) : router.navigate('/campaign'))}
      style={({ pressed }) => [styles.campaignRow, { backgroundColor: pal.surface, opacity: pressed ? 0.8 : 1 }]}>
      <View style={styles.heroText}>
        <Eyebrow>{t('today.campaignRow', { done, total: CAMPAIGN_SIZE })}</Eyebrow>
        <Text style={[styles.cardTitle, { color: pal.ink }]}>
          {n ? t('today.campaignFire', { number: n, name: t('fire.name', { place: campaignFire(n).place }) }) : t('today.campaignDone')}
        </Text>
        <HeatBar fraction={done / CAMPAIGN_SIZE} height={6} />
      </View>
      <View style={[styles.goCircle, { backgroundColor: pal.primary }]}>
        <Text style={[styles.goArrow, { color: heat(0, 1).bg }]}>›</Text>
      </View>
    </Pressable>
  );
}

function Goals() {
  const { t } = useTranslation();
  const pal = usePalette();
  const progress = useProgress();
  const week = thisWeek(progress);
  const lv = level(progress);
  return (
    <Card style={styles.goals}>
      <View style={styles.goal}>
        <Text style={[styles.goalText, { color: pal.ink }]}>
          <Text style={styles.bold}>{t('today.week')}</Text> · {t('today.weekCount', week)}
        </Text>
        <View style={styles.goalSquares}>
          {Array.from({ length: week.goal }, (_, k) => (
            <View
              key={k}
              style={[
                styles.goalSquare,
                k < week.done
                  ? { backgroundColor: heat(k, week.goal * 1.6, pal.dark).bg }
                  : { borderWidth: 1.5, borderColor: pal.line },
              ]}
            />
          ))}
        </View>
      </View>
      <View style={styles.goal}>
        <Text style={[styles.goalText, { color: pal.ink }]}>
          <Text style={styles.bold}>{t('today.level', { level: lv.level })}</Text> · {t('today.levelToGo', { count: lv.toGo })}
        </Text>
        <HeatBar fraction={lv.fraction} height={22} />
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  page: { padding: 16, gap: 12, paddingBottom: 40 },
  pill: {
    alignSelf: 'flex-start',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 7,
    minHeight: 36,
    paddingHorizontal: 13,
    borderRadius: 18,
  },
  pillDot: { width: 12, height: 12, borderRadius: 3 },
  pillText: { fontSize: 15, fontWeight: '600' },
  heroRow: { flexDirection: 'row', gap: 14, alignItems: 'flex-start' },
  heroBoard: { width: '50%', maxWidth: 220 },
  heroBoardSmall: { width: '36%', maxWidth: 160 },
  boardPlaceholder: { aspectRatio: 1, borderRadius: 8 },
  heroText: { flex: 1, gap: 4 },
  cardTitle: { fontSize: 17, fontWeight: '600' },
  sizes: { flexDirection: 'row', gap: 8 },
  sizeButton: { flex: 1, minHeight: 44, paddingHorizontal: 6 },
  doneRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  doneThumb: { width: 64 },
  roundButton: { minHeight: 44, paddingHorizontal: 14, borderRadius: 22, justifyContent: 'center' },
  roundButtonText: { fontSize: 15, fontWeight: '600' },
  campaignRow: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, borderRadius: 24 },
  goCircle: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
  goArrow: { fontSize: 28, fontWeight: '700', marginTop: -3 },
  goals: { flexDirection: 'row', flexWrap: 'wrap', gap: 16 },
  goal: { flexGrow: 1, flexBasis: 140, gap: 8 },
  goalText: { fontSize: 15 },
  bold: { fontWeight: '600' },
  goalSquares: { flexDirection: 'row', gap: 5 },
  goalSquare: { width: 22, height: 22, borderRadius: 5 },
});
