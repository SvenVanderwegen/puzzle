/** The campaign: 20 fixed fires in five groups, unlocked one by one. */
import { useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { SymbolView } from 'expo-symbols';
import { useTranslation } from 'react-i18next';

import { Row, Section } from '@/components/Grouped';
import { Segmented } from '@/components/Segmented';
import { playCampaign } from '@/components/nav';
import { Eyebrow } from '@/components/ui';
import { CAMPAIGN, CAMPAIGN_CHAPTERS } from '@/config';
import { CAMPAIGN_SIZE, campaignFire } from '@/engine/puzzles';
import { fmtClock } from '@/engine/rules';
import { campaignDone, isUnlocked, useProgress } from '@/state/progress';
import { brandFont, heat, usePalette } from '@/theme';

type Filter = 'all' | '5' | '6' | '7' | '8';

export default function Campaign() {
  const { t } = useTranslation();
  const pal = usePalette();
  const progress = useProgress();
  const [filter, setFilter] = useState<Filter>('all');

  const options: { value: Filter; label: string }[] = [
    { value: 'all', label: t('campaign.filterAll') },
    ...(['5', '6', '7', '8'] as const).map((s) => ({ value: s, label: t('common.sizeBy', { size: s }) })),
  ];

  return (
    <ScrollView contentInsetAdjustmentBehavior="automatic" style={{ backgroundColor: pal.ground }} contentContainerStyle={styles.page}>
      <Eyebrow>{t('campaign.progress', { done: campaignDone(progress), total: CAMPAIGN_SIZE })}</Eyebrow>
      <Segmented options={options} value={filter} onChange={setFilter} label={t('campaign.filterLabel')} />

      {CAMPAIGN_CHAPTERS.map((chapter) => {
        const fires = chapter.fires.filter((n) => filter === 'all' || String(CAMPAIGN[n - 1].rows) === filter);
        if (!fires.length) return null;
        return (
          <Section key={chapter.key} title={t(`campaign.chapter.${chapter.key}` as 'campaign.chapter.first')}>
            {fires.map((n) => {
              const fire = campaignFire(n);
              const p = fire.puzzle;
              const result = progress.campaign[String(n)];
              const open = isUnlocked(progress, n);
              const name = t('fire.name', { place: fire.place });
              const detail = t('campaign.rowDetail', {
                size: p.rows,
                breaks: n > 16 ? t('common.numbers', { count: p.clues.length }) : t('common.firebreaks', { count: p.breaks }),
              });
              const color = heat(n - 1, CAMPAIGN_SIZE - 1, pal.dark);
              const badge = result ? (
                <View style={[styles.badge, { backgroundColor: color.bg }]}>
                  <SymbolView name="checkmark" size={15} weight="bold" tintColor={color.fg} />
                </View>
              ) : open ? (
                <View style={[styles.badge, { borderWidth: 2, borderColor: pal.ink }]}>
                  <Text style={[styles.badgeNum, { color: pal.ink }]}>{n}</Text>
                </View>
              ) : (
                <View style={[styles.badge, { backgroundColor: pal.soft }]}>
                  <SymbolView name="lock.fill" size={13} tintColor={pal.muted} />
                </View>
              );
              return (
                <Row
                  key={n}
                  label={name}
                  detail={detail}
                  value={result ? fmtClock(result.ms) : undefined}
                  leading={badge}
                  disabled={!open}
                  onPress={() => playCampaign(n)}
                  accessibilityLabel={
                    result
                      ? t('campaign.doneA11y', { name, time: fmtClock(result.ms) })
                      : open
                        ? `${name}, ${detail}`
                        : t('campaign.lockedA11y', { name })
                  }
                />
              );
            })}
          </Section>
        );
      })}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  page: { padding: 16, gap: 16, paddingBottom: 40 },
  badge: { width: 30, height: 30, borderRadius: 8, alignItems: 'center', justifyContent: 'center' },
  badgeNum: { fontFamily: brandFont.bold, fontSize: 15 },
});
