import { savingsPercent } from '@/features/search/catalog';
import type { DetectionKind, ScanFeatures } from '@/features/search/types';

export function featuresForKind(kind: DetectionKind): ScanFeatures {
  if (kind === 'coat') {
    return {
      category: 'coat',
      brand: 'nord',
      color: 'camel',
      silhouette: 'column',
      productKey: 'search.product.coat',
      labelKey: 'search.profile.coat',
    };
  }
  if (kind === 'bag') {
    return {
      category: 'bag',
      brand: 'atelier',
      color: 'black',
      silhouette: 'shoulder',
      productKey: 'search.product.bag',
      labelKey: 'search.profile.bag',
    };
  }
  return {
    category: 'shoes',
    brand: 'nike',
    color: 'volt',
    silhouette: 'running-cleat',
    productKey: 'search.product.alphafly',
    labelKey: 'search.profile.alphafly',
  };
}

export { savingsPercent };
