/** "Progressiekaart" tokens, light and dark. */
export type Palette = {
  ground: string;
  ink: string;
  muted: string;
  line: string;
  surface: string;
  soft: string;
  /** A firebreak on the board. */
  firebreak: string;
  /** The primary button. */
  primary: string;
  onPrimary: string;
  dark: boolean;
};

export const light: Palette = {
  ground: '#F1F1EE',
  ink: '#16181B',
  muted: '#55585E',
  line: '#D8D8D2',
  surface: '#FFFFFF',
  soft: '#ECECE8',
  firebreak: '#16181B',
  primary: '#16181B',
  onPrimary: '#FFFFFF',
  dark: false,
};

export const dark: Palette = {
  ground: '#121416',
  ink: '#F1F1EE',
  muted: '#A4A8AE',
  line: '#33373D',
  surface: '#1F2226',
  soft: '#2A2E33',
  firebreak: '#F1F1EE',
  primary: '#F1F1EE',
  onPrimary: '#16181B',
  dark: true,
};

/** Brand typeface for the board, numbers and fire names. UI text uses the system font. */
export const brandFont = {
  bold: 'BricolageGrotesque_700Bold',
  extraBold: 'BricolageGrotesque_800ExtraBold',
};

/** Logo "Steps" colours. */
export const logo = { ground: '#CC2162', step: '#FF7A1F', spark: '#FFE24A' };
