/** Native iOS time picker (compact style), via @expo/ui. */
import { DatePicker, Host } from '@expo/ui/swift-ui';
import { datePickerStyle, labelsHidden } from '@expo/ui/swift-ui/modifiers';

import type { TimePickerProps } from './TimePicker';

export function TimePicker({ minutes, onChange, label }: TimePickerProps) {
  const d = new Date();
  d.setHours(Math.floor(minutes / 60), minutes % 60, 0, 0);
  return (
    <Host matchContents>
      <DatePicker
        title={label}
        selection={d}
        displayedComponents={['hourAndMinute']}
        onDateChange={(date) => onChange(date.getHours() * 60 + date.getMinutes())}
        modifiers={[datePickerStyle('compact'), labelsHidden()]}
      />
    </Host>
  );
}
