/** Practice: a fresh fire of any size, nothing counted, and the lesson. */
import { ScrollView, StyleSheet } from 'react-native';
import { router } from 'expo-router';
import { useTranslation } from 'react-i18next';

import { Row, Section } from '@/components/Grouped';
import { playQuick } from '@/components/nav';
import { Body, Card } from '@/components/ui';
import { QUICK_FIRES, QUICK_SIZES } from '@/config';
import { usePalette } from '@/theme';

export default function Practice() {
  const { t } = useTranslation();
  const pal = usePalette();
  return (
    <ScrollView contentInsetAdjustmentBehavior="automatic" style={{ backgroundColor: pal.ground }} contentContainerStyle={styles.page}>
      <Section title={t('practice.quickHeader')} footer={t('practice.quickFooter')}>
        {QUICK_SIZES.map((size) => (
          <Row
            key={size}
            label={t('common.sizeBy', { size })}
            detail={t('common.firebreaks', { count: QUICK_FIRES[size].breaks })}
            onPress={() => playQuick(size)}
          />
        ))}
      </Section>
      <Section title={t('practice.learnHeader')}>
        <Row label={t('practice.lesson')} detail={t('practice.lessonDetail')} onPress={() => router.push('/lesson')} />
      </Section>
      <Card>
        <Body>{t('practice.rulesBody')}</Body>
      </Card>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  page: { padding: 16, gap: 20, paddingBottom: 40 },
});
