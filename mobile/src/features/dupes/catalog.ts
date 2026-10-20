export type RegionKind = 'shoes' | 'top' | 'jacket' | 'accessory';

export type ScanRegion = {
  kind: RegionKind;
  x: number;
  y: number;
  width: number;
  height: number;
};

const REGIONS: Record<string, ScanRegion[]> = {
  'column-coat': [
    { kind: 'jacket', x: 0.18, y: 0.16, width: 0.64, height: 0.5 },
    { kind: 'accessory', x: 0.62, y: 0.22, width: 0.2, height: 0.14 },
  ],
  'silk-slip': [{ kind: 'top', x: 0.26, y: 0.18, width: 0.48, height: 0.52 }],
  loafer: [{ kind: 'shoes', x: 0.1, y: 0.4, width: 0.78, height: 0.24 }],
  'hour-watch': [{ kind: 'accessory', x: 0.28, y: 0.3, width: 0.44, height: 0.34 }],
  signet: [{ kind: 'accessory', x: 0.32, y: 0.34, width: 0.36, height: 0.28 }],
  frame: [{ kind: 'accessory', x: 0.16, y: 0.32, width: 0.68, height: 0.26 }],
};

/** Frame boxes for the scan animation. Match results come from the vision service. */
export function regionsFor(productId: string): ScanRegion[] {
  return REGIONS[productId] ?? [{ kind: 'jacket', x: 0.2, y: 0.18, width: 0.6, height: 0.48 }];
}
