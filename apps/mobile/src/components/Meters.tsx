import { StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';

import { heat, usePalette } from '@/theme';

/** The firebreak supply: one pip per firebreak, filled when placed. */
export function Pips({ total, used }: { total: number; used: number }) {
  const pal = usePalette();
  const small = total > 10;
  return (
    <View style={styles.pips} accessible accessibilityLabel={`${used}/${total}`}>
      {Array.from({ length: total }, (_, k) => (
        <View
          key={k}
          style={[
            small ? styles.pipSmall : styles.pip,
            { borderColor: pal.ink, backgroundColor: k < used ? pal.firebreak : 'transparent' },
          ]}
        />
      ))}
    </View>
  );
}

/** Colour key: minute 0 to the last minute. */
export function Legend({ last }: { last: number }) {
  const pal = usePalette();
  const { t } = useTranslation();
  const step = last > 12 ? 4 : 3;
  return (
    <View accessible accessibilityLabel={t('play.legend')}>
      <View style={styles.legendBar}>
        {Array.from({ length: last + 1 }, (_, k) => (
          <View key={k} style={[styles.legendSeg, { backgroundColor: heat(k, last, pal.dark).bg }]} />
        ))}
      </View>
      <View style={styles.legendBar}>
        {Array.from({ length: last + 1 }, (_, k) => (
          <Text key={k} allowFontScaling={false} style={[styles.legendLabel, { color: pal.muted }]}>
            {k % step === 0 || k === last ? k : ''}
          </Text>
        ))}
      </View>
    </View>
  );
}

/** How many squares caught fire each minute. Safe to share: it never shows the map. */
export function BurnCurve({ curve, height = 56 }: { curve: number[]; height?: number }) {
  const pal = usePalette();
  const { t } = useTranslation();
  const max = Math.max(1, ...curve);
  const last = curve.length - 1;
  return (
    <View style={[styles.curve, { height }]} accessible accessibilityLabel={t('result.curveA11y')}>
      {curve.map((v, k) => (
        <View
          key={k}
          style={{
            flex: 1,
            maxWidth: 9,
            height: Math.max(3, (v / max) * height),
            borderRadius: 2,
            backgroundColor: heat(k, last, pal.dark).bg,
          }}
        />
      ))}
    </View>
  );
}

/** A filled bar on the heat ramp (level progress). */
export function HeatBar({ fraction, height = 8 }: { fraction: number; height?: number }) {
  const pal = usePalette();
  const segs = 12;
  const on = Math.round(fraction * segs);
  return (
    <View style={[styles.heatBar, { height, borderRadius: height / 2, backgroundColor: pal.soft }]}>
      {Array.from({ length: on }, (_, k) => (
        <View key={k} style={{ flex: 1, backgroundColor: heat(k, segs * 0.6, pal.dark).bg }} />
      ))}
      {on < segs && <View style={{ flex: segs - on }} />}
    </View>
  );
}

const styles = StyleSheet.create({
  pips: { flexDirection: 'row', flexWrap: 'wrap', gap: 4, justifyContent: 'flex-end', flexShrink: 1 },
  pip: { width: 16, height: 16, borderRadius: 4, borderWidth: 1.5 },
  pipSmall: { width: 11, height: 16, borderRadius: 3, borderWidth: 1.5 },
  legendBar: { flexDirection: 'row', gap: 2 },
  legendSeg: { flex: 1, height: 8, borderRadius: 3 },
  legendLabel: { flex: 1, fontSize: 12, marginTop: 4, textAlign: 'left', fontVariant: ['tabular-nums'] },
  curve: { flexDirection: 'row', alignItems: 'flex-end', gap: 2, flexShrink: 1, minWidth: 80 },
  heatBar: { flexDirection: 'row', overflow: 'hidden' },
});
