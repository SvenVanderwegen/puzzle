import Svg, { ClipPath, Defs, G, Path, Rect } from 'react-native-svg';
import { useId } from 'react';

import { logo } from '@/theme';

/** The spark: a four-point star. */
export function SparkGlyph({ size = 16, color = '#16181B' }: { size?: number; color?: string }) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24">
      <Path d="M12 1.5 14.6 9.4 22.5 12 14.6 14.6 12 22.5 9.4 14.6 1.5 12 9.4 9.4Z" fill={color} />
    </Svg>
  );
}

/** Logo "Steps". */
export function LogoMark({ size = 60 }: { size?: number }) {
  const id = useId().replace(/:/g, '');
  return (
    <Svg width={size} height={size} viewBox="0 0 60 60">
      <Defs>
        <ClipPath id={id}>
          <Rect width="60" height="60" rx="9" />
        </ClipPath>
      </Defs>
      <G clipPath={`url(#${id})`}>
        <Rect width="60" height="60" fill={logo.ground} />
        <Rect x="0" y="20" width="40" height="40" fill={logo.step} />
        <Rect x="0" y="40" width="20" height="20" fill={logo.spark} />
      </G>
    </Svg>
  );
}
