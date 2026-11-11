/** Native iOS segmented control (SwiftUI Picker), via @expo/ui. */
import { Host, Picker, Text } from '@expo/ui/swift-ui';
import { pickerStyle, tag } from '@expo/ui/swift-ui/modifiers';

import type { SegmentedProps } from './Segmented';

export function Segmented<T extends string>({ options, value, onChange, label }: SegmentedProps<T>) {
  return (
    <Host matchContents={{ vertical: true }} style={{ width: '100%' }}>
      <Picker
        label={label}
        selection={value}
        onSelectionChange={(v) => onChange(v as T)}
        modifiers={[pickerStyle('segmented')]}>
        {options.map((o) => (
          <Text key={o.value} modifiers={[tag(o.value)]}>
            {o.label}
          </Text>
        ))}
      </Picker>
    </Host>
  );
}
