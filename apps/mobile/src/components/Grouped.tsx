/** iOS-style grouped list (inset sections with rows), in the brand colours. */
import { Children, Fragment, type ReactNode } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { usePalette } from '@/theme';

import { Chevron } from './ui';

export function Section({ title, footer, children }: { title?: string; footer?: string; children: ReactNode }) {
  const pal = usePalette();
  const rows = Children.toArray(children).filter(Boolean);
  return (
    <View style={styles.section}>
      {title && (
        <Text style={[styles.header, { color: pal.muted }]} accessibilityRole="header">
          {title}
        </Text>
      )}
      <View style={[styles.box, { backgroundColor: pal.surface }]}>
        {rows.map((row, k) => (
          <Fragment key={k}>
            {k > 0 && <View style={[styles.separator, { backgroundColor: pal.line }]} />}
            {row}
          </Fragment>
        ))}
      </View>
      {footer && <Text style={[styles.footer, { color: pal.muted }]}>{footer}</Text>}
    </View>
  );
}

type RowProps = {
  label: string;
  detail?: string;
  value?: string;
  leading?: ReactNode;
  trailing?: ReactNode;
  onPress?: () => void;
  disabled?: boolean;
  accessibilityLabel?: string;
};

export function Row({ label, detail, value, leading, trailing, onPress, disabled, accessibilityLabel }: RowProps) {
  const pal = usePalette();
  const body = (
    <>
      {leading}
      <View style={styles.text}>
        <Text style={[styles.label, { color: pal.ink }]}>{label}</Text>
        {detail && <Text style={[styles.detail, { color: pal.muted }]}>{detail}</Text>}
      </View>
      {value && <Text style={[styles.value, { color: pal.muted }]}>{value}</Text>}
      {trailing}
      {onPress && <Chevron />}
    </>
  );
  if (!onPress) {
    return (
      <View style={styles.row} accessible={!trailing} accessibilityLabel={accessibilityLabel}>
        {body}
      </View>
    );
  }
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      accessibilityState={{ disabled }}
      style={({ pressed }) => [styles.row, { opacity: disabled ? 0.5 : 1, backgroundColor: pressed ? pal.soft : 'transparent' }]}>
      {body}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  section: { gap: 6 },
  header: { fontSize: 13, fontWeight: '600', textTransform: 'uppercase', letterSpacing: 0.3, marginHorizontal: 16 },
  footer: { fontSize: 13, lineHeight: 18, marginHorizontal: 16 },
  box: { borderRadius: 22, overflow: 'hidden', borderCurve: 'continuous' },
  separator: { height: StyleSheet.hairlineWidth, marginLeft: 16 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 56, paddingHorizontal: 16, paddingVertical: 8 },
  text: { flex: 1, gap: 1 },
  label: { fontSize: 17, lineHeight: 22 },
  detail: { fontSize: 14, lineHeight: 19 },
  value: { fontSize: 17 },
});
