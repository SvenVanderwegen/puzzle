/** Time of day. iOS uses the native picker (TimePicker.ios.tsx); this fallback steps by 30 minutes. */
import { Pressable, Text } from 'react-native';

import { usePalette } from '@/theme';

export type TimePickerProps = { minutes: number; onChange: (minutes: number) => void; label: string };

export function TimePicker({ minutes, onChange, label }: TimePickerProps) {
  const pal = usePalette();
  const text = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${label} ${text}`}
      onPress={() => onChange((minutes + 30) % (24 * 60))}
      style={{ backgroundColor: pal.soft, borderRadius: 8, paddingHorizontal: 11, paddingVertical: 6 }}>
      <Text style={{ fontSize: 17, color: pal.ink, fontVariant: ['tabular-nums'] }}>{text}</Text>
    </Pressable>
  );
}
