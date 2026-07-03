/**
 * /settings stub — the GDPR-mandatory and preference items as structure
 * (catalog strings, no fake controls); WS-14 wires them (data-ws area).
 */
import type { ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { t } from '../strings';

export function SettingsPage(): ReactElement {
  return (
    <>
      <PageHeading>{t('settings.title')}</PageHeading>
      <section data-ws="WS-14" aria-labelledby="bf-settings-area">
        <ul className="bf-stub-list" id="bf-settings-area">
          <li>{t('settings.streakAlert')}</li>
          <li>{t('settings.export')}</li>
          <li>
            {t('settings.delete')}
            <p className="bf-hint">{t('settings.delete.explain')}</p>
          </li>
        </ul>
      </section>
    </>
  );
}
