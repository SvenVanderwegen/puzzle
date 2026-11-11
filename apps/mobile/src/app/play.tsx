/**
 * The board. Native chrome (nav bar, toolbar buttons, system font) around a
 * board drawn in the brand look. When the fire is contained it burns in
 * minute by minute and the result opens as a sheet over it.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, AppState, PixelRatio, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { SymbolView, type SFSymbol } from 'expo-symbols';
import { useTranslation } from 'react-i18next';

import { Board } from '@/components/Board';
import { feel, tick } from '@/components/haptics';
import { Legend, Pips } from '@/components/Meters';
import { CAMPAIGN, QUICK_SIZES, type BoardSize } from '@/config';
import { countBreaks, lastMinute, message, wrongClues } from '@/engine/game';
import { campaignFire, localDate, type Fire } from '@/engine/puzzles';
import { fmtClock } from '@/engine/rules';
import { dailyFire, quickFire } from '@/state/fires';
import {
  applyHint,
  elapsed,
  pauseClock,
  resetFire,
  resumeClock,
  sessionStore,
  startFire,
  tapCell,
  undoMove,
  useSession,
} from '@/state/session';
import { useSettings } from '@/state/settings';
import { brandFont, usePalette } from '@/theme';

type Params = { kind?: string; n?: string; size?: string };

/** Find the fire the route asks for; keep an unfinished one of the same kind. */
async function resolveFire({ kind, n, size }: Params): Promise<Fire> {
  const current = sessionStore.get();
  if (kind === 'campaign') {
    const num = Math.min(CAMPAIGN.length, Math.max(1, Number(n) || 1));
    return campaignFire(num);
  }
  if (kind === 'quick') {
    const s = (QUICK_SIZES.includes(Number(size) as BoardSize) ? Number(size) : 5) as BoardSize;
    const f = current.fire;
    if (f && f.kind === 'quick' && f.size === s && !current.solved) return f;
    return quickFire(s);
  }
  return dailyFire(localDate());
}

export default function Play() {
  const params = useLocalSearchParams<Params>();
  const { t } = useTranslation();
  const pal = usePalette();
  const settings = useSettings();
  const session = useSession();
  const [readyFor, setReadyFor] = useState<string | null>(null);
  const [now, setNow] = useState(Date.now);
  const shownResult = useRef(false);
  const route = `${params.kind}|${params.n}|${params.size}`;
  const ready = readyFor === route;

  useEffect(() => {
    let live = true;
    resolveFire({ kind: params.kind, n: params.n, size: params.size }).then((fire) => {
      if (!live) return;
      startFire(fire);
      // Reopening an unfinished fire: leaving the screen paused its clock.
      resumeClock();
      // A daily fire already contained today opens on its result, without the sheet popping up.
      shownResult.current = sessionStore.get().solved;
      setReadyFor(route);
    });
    return () => {
      live = false;
    };
  }, [route, params.kind, params.n, params.size]);

  // Pause the clock while the app is in the background.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => (state === 'active' ? resumeClock() : pauseClock()));
    return () => {
      sub.remove();
      pauseClock();
    };
  }, []);

  // One light ticker: the clock while playing, the burn replay once contained.
  const fire = ready ? session.fire : null;
  const p = fire?.puzzle;
  const last = p ? lastMinute(p) : 1;
  const revealing = session.solved && now - session.solvedAt < (last + 2) * 130;
  const ticking = (settings.showClock && !!session.runningSince) || revealing;
  useEffect(() => {
    if (!ticking) return;
    const id = setInterval(() => setNow(Date.now()), session.solved ? 65 : 500);
    return () => clearInterval(id);
  }, [ticking, session.solved]);

  useEffect(() => {
    if (ready && session.solved && !shownResult.current) {
      shownResult.current = true;
      const id = setTimeout(() => router.push('/result'), 500);
      return () => clearTimeout(id);
    }
  }, [ready, session.solved]);

  const wrong = useMemo(
    () => (p && !session.solved ? wrongClues(p, session.marks, settings.flagLate) : new Set<number>()),
    [p, session.marks, session.solved, settings.flagLate],
  );

  if (!fire || !p) {
    return (
      <View style={[styles.loading, { backgroundColor: pal.ground }]}>
        <Stack.Screen options={{ title: '' }} />
        <ActivityIndicator color={pal.ink} />
        <Text style={[styles.message, { color: pal.muted }]}>{t('common.loadingFire')}</Text>
      </View>
    );
  }

  const used = countBreaks(session.marks);
  const reveal = session.solved ? Math.floor((now - session.solvedAt) / 130) : -1;
  const msg = session.solved ? { key: 'play.msg.solved' as const } : message(p, session.marks, wrong);
  const subtitle =
    fire.kind === 'daily'
      ? t('fire.daily', { number: fire.number })
      : fire.kind === 'campaign'
        ? t('fire.campaign', { number: fire.number })
        : t('fire.quick', { size: p.rows });
  const time = fmtClock(elapsed(session, now));
  // Room for three lines at the player's text size, so the board never jumps.
  const messageHeight = Math.ceil(3 * 22 * Math.min(PixelRatio.getFontScale(), 2));

  const confirmReset = () => {
    tick();
    Alert.alert(t('play.resetConfirm'), undefined, [
      { text: t('play.cancel'), style: 'cancel' },
      { text: t('play.resetYes'), style: 'destructive', onPress: resetFire },
    ]);
  };

  return (
    <View style={[styles.screen, { backgroundColor: pal.ground }]}>
      <Stack.Screen
        options={{
          headerTitle: () => (
            <View style={styles.titleWrap} accessible accessibilityRole="header">
              <Text style={[styles.title, { color: pal.ink }]} numberOfLines={1}>
                {t('fire.name', { place: fire.place })}
              </Text>
              <Text style={[styles.subtitle, { color: pal.muted }]} numberOfLines={1}>
                {subtitle}
              </Text>
            </View>
          ),
          headerRight: settings.showClock || session.solved
            ? () => (
                <Text
                  style={[styles.clock, { color: pal.ink }]}
                  accessibilityLabel={t('play.clockA11y', { time })}>
                  {time}
                </Text>
              )
            : undefined,
        }}
      />
      <ScrollView contentInsetAdjustmentBehavior="automatic" contentContainerStyle={styles.content}>
        <View style={styles.supply}>
          <Text style={[styles.supplyLabel, { color: pal.ink }]}>{t('play.firebreaks')}</Text>
          <Pips total={p.breaks} used={Math.min(used, p.breaks)} />
          <Text style={[styles.count, { color: pal.ink }]}>
            {used}/{p.breaks}
          </Text>
        </View>

        <Board
          puzzle={p}
          marks={session.marks}
          wrong={wrong}
          times={session.solved ? p.times : undefined}
          reveal={reveal}
          lastMinute={last}
          onTap={session.solved ? undefined : (cell) => feel(tapCell(cell))}
        />

        <Legend last={last} />

        <Text
          style={[styles.message, { color: pal.ink, minHeight: messageHeight }]}
          accessibilityLiveRegion="polite">
          {t(msg.key, msg)}
        </Text>
      </ScrollView>

      <View style={[styles.toolbar, { backgroundColor: pal.surface, borderColor: pal.line }]}>
        {session.solved ? (
          <ToolButton icon="checkmark.circle" label={t('result.nextFire')} onPress={() => router.push('/result')} />
        ) : (
          <>
            <ToolButton
              icon="arrow.uturn.backward"
              label={t('play.undo')}
              disabled={!session.undo.length}
              onPress={() => {
                tick();
                undoMove();
              }}
            />
            <ToolButton icon="lightbulb" label={t('play.hint')} onPress={() => feel(applyHint())} />
            <ToolButton icon="arrow.counterclockwise" label={t('play.reset')} disabled={!session.undo.length} onPress={confirmReset} />
          </>
        )}
      </View>
    </View>
  );
}

function ToolButton({ icon, label, onPress, disabled }: { icon: SFSymbol; label: string; onPress: () => void; disabled?: boolean }) {
  const pal = usePalette();
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled }}
      style={({ pressed }) => [styles.tool, { opacity: disabled ? 0.35 : pressed ? 0.6 : 1 }]}>
      <SymbolView name={icon} size={22} tintColor={pal.ink} />
      <Text style={[styles.toolLabel, { color: pal.ink }]}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1 },
  loading: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, padding: 24 },
  content: { padding: 16, gap: 14, paddingBottom: 24 },
  titleWrap: { alignItems: 'center', maxWidth: 240 },
  title: { fontFamily: brandFont.extraBold, fontSize: 18, letterSpacing: -0.4 },
  subtitle: { fontSize: 12 },
  clock: { fontSize: 17, fontWeight: '600', fontVariant: ['tabular-nums'], paddingHorizontal: 6 },
  supply: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  supplyLabel: { fontSize: 16, fontWeight: '600', flex: 1 },
  count: { fontSize: 16, fontWeight: '600', fontVariant: ['tabular-nums'] },
  message: { fontSize: 17, lineHeight: 22 },
  toolbar: {
    flexDirection: 'row',
    justifyContent: 'space-around',
    marginHorizontal: 40,
    marginBottom: 28,
    borderRadius: 32,
    paddingVertical: 6,
    borderWidth: StyleSheet.hairlineWidth,
  },
  tool: { minWidth: 72, minHeight: 52, alignItems: 'center', justifyContent: 'center', gap: 2 },
  toolLabel: { fontSize: 12, fontWeight: '600' },
});
