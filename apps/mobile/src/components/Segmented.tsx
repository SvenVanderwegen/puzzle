/** Segmented control. iOS uses the native one (Segmented.ios.tsx); this is the fallback. */
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { usePalette } from '@/theme';

export type SegmentedProps<T extends string> = {
  options: { value: T; label: string }[];
  value: T;
  onChange: (value: T) => void;
  label: string;
};

export function Segmented<T extends string>({ options, value, onChange, label }: SegmentedProps<T>) {
  const pal = usePalette();
  return (
    <View accessibilityRole="tablist" accessibilityLabel={label} style={[styles.wrap, { backgroundColor: pal.soft }]}>
      {options.map((o) => {
        const on = o.value === value;
        return (
          <Pressable
            key={o.value}
            accessibilityRole="tab"
            accessibilityState={{ selected: on }}
            onPress={() => onChange(o.value)}
            style={[styles.seg, on && { backgroundColor: pal.surface }]}>
            <Text style={[styles.text, { color: pal.ink }]}>{o.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { flexDirection: 'row', borderRadius: 18, padding: 2, minHeight: 40 },
  seg: { flex: 1, borderRadius: 16, alignItems: 'center', justifyContent: 'center', minHeight: 36 },
  text: { fontSize: 14, fontWeight: '600' },
});
