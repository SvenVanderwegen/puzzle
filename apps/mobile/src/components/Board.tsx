/**
 * The board, drawn in the brand look: spark, numbers on the heat ramp,
 * firebreaks in ink, dots for "marked clear", a ring and "!" on a number
 * that can no longer come right. Once contained, the fire burns in minute by
 * minute (`reveal`).
 */
import { memo, useState } from 'react';
import { Pressable, StyleSheet, Text, View, type LayoutChangeEvent } from 'react-native';
import { useTranslation } from 'react-i18next';

import { cellName } from '@/engine/rules';
import type { Puzzle } from '@/engine/generator';
import { brandFont, heat, usePalette } from '@/theme';

import { SparkGlyph } from './glyphs';

type Props = {
  puzzle: Puzzle;
  marks: readonly number[];
  /** Numbers to ring as wrong. */
  wrong?: Set<number>;
  /** Show burned cells up to this minute (after the fire is contained). -1 for none. */
  reveal?: number;
  /** Burn times to reveal (the player's own, equal to the answer once contained). */
  times?: ArrayLike<number>;
  lastMinute: number;
  onTap?: (cell: number) => void;
  /** Small, not tappable: a preview on the home screen. */
  thumbnail?: boolean;
  /** Show the dashed outline on this cell (the lesson). */
  target?: number;
  /** Hide numbers (the lesson's first steps). */
  hideClues?: boolean;
  maxWidth?: number;
};

const GAP = 4;

function BoardView({
  puzzle,
  marks,
  wrong,
  reveal = -1,
  times,
  lastMinute,
  onTap,
  thumbnail,
  target,
  hideClues,
  maxWidth = 520,
}: Props) {
  const { t } = useTranslation();
  const pal = usePalette();
  const [width, setWidth] = useState(0);
  const { rows, cols } = puzzle;
  const gap = thumbnail ? 3 : GAP;
  const size = width > 0 ? Math.floor((Math.min(width, maxWidth) - gap * (cols - 1)) / cols) : 0;
  const clues = new Map(puzzle.clues);

  const onLayout = (e: LayoutChangeEvent) => setWidth(e.nativeEvent.layout.width);

  const cells = [];
  for (let i = 0; i < rows * cols; i++) {
    const name = cellName(i, cols);
    const clue = hideClues ? undefined : clues.get(i);
    const m = marks[i];
    const burned = times && reveal >= 0 && times[i] >= 0 && times[i] <= reveal;
    let bg = pal.surface;
    let fg = pal.ink;
    let label = '';
    let border = pal.line;
    let ring = false;
    let dot = false;
    let spark = false;
    let a11y: string;
    let big = true;

    if (i === puzzle.spark) {
      ({ bg } = heat(0, lastMinute, pal.dark));
      fg = '#16181B';
      border = 'transparent';
      spark = true;
      a11y = t('play.cell.spark', { cell: name });
    } else if (clue != null) {
      ({ bg, fg } = heat(clue, lastMinute, pal.dark));
      border = 'transparent';
      label = String(clue);
      ring = !!wrong?.has(i);
      a11y = t(ring ? 'play.cell.clueWrong' : 'play.cell.clue', { cell: name, minute: clue });
    } else if (m === 1) {
      bg = pal.firebreak;
      border = 'transparent';
      a11y = t('play.cell.firebreak', { cell: name });
    } else if (burned && times) {
      ({ bg, fg } = heat(times[i], lastMinute, pal.dark));
      border = 'transparent';
      label = String(times[i]);
      big = false;
      a11y = t('play.cell.burned', { cell: name, minute: times[i] });
    } else {
      dot = m === 2;
      a11y = t(dot ? 'play.cell.clear' : 'play.cell.empty', { cell: name });
    }

    const radius = thumbnail ? 3 : Math.max(5, Math.round(size * 0.12));
    const font = thumbnail ? size * 0.55 : big ? size * 0.42 : size * 0.3;
    const cellStyle = {
      width: size,
      height: size,
      borderRadius: radius,
      backgroundColor: bg,
      borderColor: target === i ? pal.ink : ring ? pal.ink : border,
      borderWidth: target === i ? 3 : ring ? 3 : thumbnail ? 0.5 : 1,
      borderStyle: target === i ? ('dashed' as const) : ('solid' as const),
    };
    const content = (
      <>
        {spark && <SparkGlyph size={size * 0.42} />}
        {!!label && !thumbnail && (
          <Text
            allowFontScaling={false}
            style={[styles.num, { color: fg, fontSize: font, fontFamily: big ? brandFont.bold : brandFont.bold }]}>
            {label}
          </Text>
        )}
        {!!label && thumbnail && clue != null && (
          <Text allowFontScaling={false} style={[styles.num, { color: fg, fontSize: font, fontFamily: brandFont.bold }]}>
            {label}
          </Text>
        )}
        {dot && <View style={[styles.dot, { backgroundColor: pal.muted, width: size * 0.16, height: size * 0.16 }]} />}
        {ring && !thumbnail && (
          <View style={[styles.badge, { backgroundColor: pal.ink }]}>
            <Text allowFontScaling={false} style={[styles.badgeText, { color: pal.ground }]}>
              !
            </Text>
          </View>
        )}
      </>
    );

    const tappable = !!onTap && !thumbnail && i !== puzzle.spark && clue == null;
    cells.push(
      tappable ? (
        <Pressable
          key={i}
          onPress={() => onTap?.(i)}
          accessibilityRole="button"
          accessibilityLabel={a11y}
          accessibilityHint={t('play.cell.tapHint')}
          style={({ pressed }) => [styles.cell, cellStyle, pressed && { opacity: 0.7 }]}>
          {content}
        </Pressable>
      ) : (
        <View
          key={i}
          accessible={!thumbnail}
          accessibilityLabel={thumbnail ? undefined : a11y}
          style={[styles.cell, cellStyle]}>
          {content}
        </View>
      ),
    );
  }

  return (
    <View onLayout={onLayout} style={styles.wrap} accessibilityElementsHidden={thumbnail} importantForAccessibility={thumbnail ? 'no-hide-descendants' : 'auto'}>
      {size > 0 && (
        <View style={[styles.grid, { width: size * cols + gap * (cols - 1), gap }]}>{cells}</View>
      )}
    </View>
  );
}

export const Board = memo(BoardView);

const styles = StyleSheet.create({
  wrap: { width: '100%', alignItems: 'center' },
  grid: { flexDirection: 'row', flexWrap: 'wrap' },
  cell: { alignItems: 'center', justifyContent: 'center' },
  num: { includeFontPadding: false },
  dot: { borderRadius: 99 },
  badge: {
    position: 'absolute',
    top: -6,
    right: -6,
    width: 18,
    height: 18,
    borderRadius: 9,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { fontSize: 12, fontWeight: '800' },
});
