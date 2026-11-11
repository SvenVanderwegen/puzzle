/**
 * The first lesson, three short steps on a 4 by 4 board (from the "Eerste
 * keer" design): watch the fire spread, dig one firebreak, then solve a
 * tiny puzzle with exactly 2 firebreaks. No clock, and every message stays
 * until the next move.
 */
import { useEffect, useMemo, useState } from 'react';
import { PixelRatio, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { useTranslation } from 'react-i18next';

import { Board } from '@/components/Board';
import { feel, tick } from '@/components/haptics';
import { playDaily } from '@/components/nav';
import { Button } from '@/components/ui';
import { burnTimes, countBreaks, solvedTimes, type Mark } from '@/engine/game';
import type { Puzzle } from '@/engine/generator';
import { updateSettings } from '@/state/settings';
import { usePalette } from '@/theme';

/** One answer: firebreaks on 9 and 13. */
const LESSON: Puzzle = {
  rows: 4,
  cols: 4,
  breaks: 2,
  spark: 12,
  clues: [
    [4, 2],
    [14, 6],
  ],
  solution: [9, 13],
  times: [],
};
const TARGET = 8;
const LAST = 8;
const empty = (): Mark[] => new Array(16).fill(0);

type Phase = 0 | 1 | 2 | 3 | 4 | 5;

export default function Lesson() {
  const { t } = useTranslation();
  const pal = usePalette();
  const [phase, setPhase] = useState<Phase>(0);
  const [marks, setMarks] = useState<Mark[]>(empty);
  const [animAt, setAnimAt] = useState(0);
  const [full, setFull] = useState(false);
  const [now, setNow] = useState(Date.now);

  const stepMs = phase >= 4 ? 130 : 280;
  const times = useMemo(() => burnTimes(LESSON, marks), [marks]);
  const reveal = animAt ? Math.floor((now - animAt) / stepMs) : -1;

  useEffect(() => {
    if (!animAt) return;
    const id = setInterval(() => {
      setNow(Date.now());
      if (Date.now() - animAt > (LAST + 2) * stepMs) clearInterval(id);
    }, 65);
    return () => clearInterval(id);
  }, [animAt, stepMs]);

  const play = (next: Phase, m: Mark[], animate: boolean) => {
    setPhase(next);
    setMarks(m);
    setAnimAt(animate ? Date.now() : 0);
    setNow(Date.now());
  };

  const tap = (i: number) => {
    if (phase === 2) {
      if (i !== TARGET) return;
      const m = empty();
      m[i] = 1;
      feel('placed');
      play(3, m, true);
      return;
    }
    if (phase !== 4) return;
    if (i === LESSON.spark || LESSON.clues.some((c) => c[0] === i)) return;
    // The supply is real: with both firebreaks down, a third tap places nothing.
    if (marks[i] !== 1 && countBreaks(marks) >= LESSON.breaks) {
      setFull(true);
      feel('full');
      return;
    }
    const m = marks.slice();
    m[i] = m[i] === 1 ? 0 : 1;
    setFull(false);
    const solved = !!solvedTimes(LESSON, m);
    feel(solved ? 'solved' : m[i] === 1 ? 'placed' : 'cleared');
    play(solved ? 5 : 4, m, true);
  };

  const go = () => {
    tick();
    if (phase === 0) play(1, empty(), true);
    else if (phase === 1) play(2, empty(), false);
    else if (phase === 3) {
      setFull(false);
      play(4, empty(), false);
    }
  };

  const finish = () => {
    updateSettings({ seenWelcome: true });
    router.back();
    playDaily();
  };

  // Which numbers are wrong once the fire has burned past them (step 3 only).
  const wrong = new Set<number>();
  if (phase === 4 && animAt) {
    for (const [cell, minute] of LESSON.clues) {
      if ((times[cell] >= 0 && times[cell] <= reveal) || times[cell] < 0) {
        if (times[cell] !== minute) wrong.add(cell);
      }
    }
  }

  let title: string;
  let body: string;
  if (phase === 4) {
    title = t('lesson.s4.title');
    const unreachable = marks.some((m, i) => m !== 1 && times[i] < 0);
    if (full) body = t('lesson.s4.full');
    else if (!animAt) body = t('lesson.s4.intro');
    else if (unreachable) body = t('lesson.s4.unreachable');
    else if (times[14] < 6) body = t('lesson.s4.early', { clue: 6, minute: times[14] });
    else if (times[14] > 6) body = t('lesson.s4.late', { clue: 6, minute: times[14] });
    else if (times[4] !== 2) body = t('lesson.s4.late', { clue: 2, minute: times[4] });
    else body = t('lesson.s4.almost');
  } else {
    const key = (['s0', 's1', 's2', 's3', 's4', 's5'] as const)[phase];
    title = t(`lesson.${key}.title` as 'lesson.s0.title');
    body = t(`lesson.${key}.body` as 'lesson.s0.body');
  }

  const step = phase <= 1 ? 1 : phase <= 3 ? 2 : 3;
  const used = countBreaks(marks);
  const textHeight = Math.ceil(5 * 24 * Math.min(PixelRatio.getFontScale(), 2));

  return (
    <ScrollView style={{ backgroundColor: pal.ground }} contentContainerStyle={styles.page}>
      <View style={styles.steps} accessible accessibilityLabel={phase === 5 ? t('lesson.complete') : t('lesson.step', { step })}>
        {[1, 2, 3].map((k) => (
          <View key={k} style={[styles.stepBar, { backgroundColor: k <= step ? pal.ink : pal.line }]} />
        ))}
      </View>
      <Text style={[styles.stepLabel, { color: pal.muted }]}>
        {phase === 5 ? t('lesson.complete') : t('lesson.step', { step })}
      </Text>

      <View style={{ minHeight: textHeight, gap: 6 }}>
        <Text style={[styles.title, { color: pal.ink }]} accessibilityRole="header">
          {title}
        </Text>
        <Text style={[styles.body, { color: pal.ink }]} accessibilityLiveRegion="polite">
          {body}
        </Text>
      </View>

      <View style={styles.board}>
        <Board
          puzzle={LESSON}
          marks={marks}
          times={times}
          reveal={phase === 4 && !animAt ? -1 : reveal}
          wrong={wrong}
          lastMinute={LAST}
          hideClues={phase < 4}
          target={phase === 2 ? TARGET : undefined}
          onTap={phase === 2 || phase === 4 ? tap : undefined}
          maxWidth={320}
        />
      </View>

      <View style={styles.footer}>
        {phase === 4 && (
          <View style={styles.tray} accessible accessibilityLabel={used >= 2 ? t('lesson.placedAll') : t('lesson.left', { count: 2 - used })}>
            {[0, 1].map((k) => (
              <View
                key={k}
                style={[
                  styles.trayItem,
                  k < 2 - used ? { backgroundColor: pal.firebreak } : { borderWidth: 2, borderStyle: 'dashed', borderColor: pal.muted },
                ]}
              />
            ))}
            <Text style={[styles.trayText, { color: pal.ink }]}>
              {used >= 2 ? t('lesson.placedAll') : t('lesson.left', { count: 2 - used })}
            </Text>
          </View>
        )}
        {phase === 2 && <Text style={[styles.body, { color: pal.muted }]}>{t('lesson.tapHere')}</Text>}
        {(phase === 0 || phase === 1 || phase === 3) && (
          <Button label={phase === 0 ? t('lesson.start') : t('lesson.next')} onPress={go} />
        )}
        {phase === 5 && (
          <>
            <Button label={t('lesson.finish')} onPress={finish} />
            <Button kind="soft" label={t('lesson.again')} onPress={() => play(0, empty(), false)} />
          </>
        )}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  page: { padding: 20, gap: 14, paddingBottom: 40 },
  steps: { flexDirection: 'row', gap: 6 },
  stepBar: { flex: 1, height: 5, borderRadius: 3 },
  stepLabel: { fontSize: 14, fontWeight: '600' },
  title: { fontSize: 26, fontWeight: '700', letterSpacing: -0.4 },
  body: { fontSize: 18, lineHeight: 24 },
  board: { alignItems: 'center' },
  footer: { gap: 10, minHeight: 112 },
  tray: { flexDirection: 'row', alignItems: 'center', gap: 8, minHeight: 50 },
  trayItem: { width: 34, height: 34, borderRadius: 7 },
  trayText: { fontSize: 17, fontWeight: '600', marginLeft: 6 },
});
