/**
 * Translations. To add a language: copy en.ts to e.g. nl.ts, translate the
 * values, and add it to `resources` below. The phone's language is used
 * when a translation exists, English otherwise.
 */
import { getLocales } from 'expo-localization';
import { createInstance } from 'i18next';
import { initReactI18next } from 'react-i18next';

import en from './en';

export const resources = {
  en: { translation: en },
} as const;

const supported = Object.keys(resources);
const phone = getLocales()[0]?.languageCode ?? 'en';

const i18n = createInstance();

i18n.use(initReactI18next).init({
  resources,
  lng: supported.includes(phone) ? phone : 'en',
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
  returnNull: false,
});

export default i18n;
export { useTranslation } from 'react-i18next';
