/** Small building blocks in the iOS grouped style, with the brand colours. */
import { Pressable, StyleSheet, Text, View, type PressableProps, type StyleProp, type ViewStyle } from 'react-native';
import { SymbolView, type SFSymbol } from 'expo-symbols';
import type { ReactNode } from 'react';

import { brandFont, heat, usePalette } from '@/theme';

export function Card({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  const pal = usePalette();
  return <View style={[styles.card, { backgroundColor: pal.surface }, style]}>{children}</View>;
}

type ButtonProps = Omit<PressableProps, 'children' | 'style'> & {
  label: string;
  kind?: 'primary' | 'soft';
  icon?: SFSymbol;
  style?: StyleProp<ViewStyle>;
};

export function Button({ label, kind = 'primary', icon, style, ...rest }: ButtonProps) {
  const pal = usePalette();
  const bg = kind === 'primary' ? pal.primary : pal.soft;
  const fg = kind === 'primary' ? pal.onPrimary : pal.ink;
  return (
    <Pressable
      accessibilityRole="button"
      {...rest}
      style={({ pressed }) => [styles.button, { backgroundColor: bg, opacity: pressed ? 0.75 : 1 }, style]}>
      {icon && <SymbolView name={icon} size={18} tintColor={fg} />}
      <Text style={[styles.buttonText, { color: fg }]} numberOfLines={1} adjustsFontSizeToFit>
        {label}
      </Text>
    </Pressable>
  );
}

export function Eyebrow({ children }: { children: ReactNode }) {
  const pal = usePalette();
  return <Text style={[styles.eyebrow, { color: pal.muted }]}>{children}</Text>;
}

export function FireTitle({ children, size = 24 }: { children: ReactNode; size?: number }) {
  const pal = usePalette();
  return (
    <Text style={[styles.fireTitle, { color: pal.ink, fontSize: size }]} accessibilityRole="header">
      {children}
    </Text>
  );
}

export function Body({ children, muted }: { children: ReactNode; muted?: boolean }) {
  const pal = usePalette();
  return <Text style={[styles.body, { color: muted ? pal.muted : pal.ink }]}>{children}</Text>;
}

/**
 * An SF Symbol on a square tile in one step of the heat ramp (0..1), the
 * same shape as a board cell. Always placed next to a text label, so it is
 * hidden from VoiceOver.
 */
export function IconTile({ name, step, size = 44 }: { name: SFSymbol; step: number; size?: number }) {
  const pal = usePalette();
  const c = heat(step, 1, pal.dark);
  return (
    <View
      accessible={false}
      importantForAccessibility="no-hide-descendants"
      style={[styles.tile, { width: size, height: size, backgroundColor: c.bg }]}>
      <SymbolView name={name} size={Math.round(size * 0.5)} tintColor={c.fg} weight="semibold" />
    </View>
  );
}

export function Chevron() {
  const pal = usePalette();
  return <SymbolView name="chevron.right" size={14} tintColor={pal.muted} weight="semibold" />;
}

const styles = StyleSheet.create({
  tile: { borderRadius: 10, borderCurve: 'continuous', alignItems: 'center', justifyContent: 'center' },
  card: { borderRadius: 24, padding: 14, gap: 12, borderCurve: 'continuous' },
  button: {
    minHeight: 50,
    borderRadius: 25,
    paddingHorizontal: 18,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  buttonText: { fontSize: 17, fontWeight: '600' },
  eyebrow: { fontSize: 13, fontWeight: '600', letterSpacing: 0.3, textTransform: 'uppercase' },
  fireTitle: { fontFamily: brandFont.extraBold, letterSpacing: -0.6, lineHeight: undefined },
  body: { fontSize: 16, lineHeight: 22 },
});
