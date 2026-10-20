import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import { Platform } from 'react-native';

import type { ScanUpload } from '@/api/social-commerce';
import { sha256OfBase64, sha256OfFile } from '@/lib/sha256';

/** Long edge sent to the API. The server stores scan frames at 1280 px and analyzes far below that. */
const MAX_EDGE = 1_280;
const JPEG_QUALITY = 0.82;
/** The API's pixel budget is 16 MP; a source this large is refused here instead of decoded. */
const MAX_SOURCE_PIXELS = 100_000_000;

export type PreparedScan = {
  upload: ScanUpload;
  /** Pixel size of the uploaded image: the API's normalized bounding box is relative to it. */
  width: number;
  height: number;
};

/**
 * Turns a camera frame or a picked photo into the upload: upright, at most 1280 px on the long edge,
 * re-encoded as JPEG. Re-encoding also drops EXIF, so a photo's GPS location never leaves the phone.
 * A 12 MP capture (3-5 MB) becomes ~150-300 KB, which is most of the latency on a mobile network.
 * The upload carries the SHA-256 of the exact bytes sent; the server refuses a scan whose bytes differ.
 */
export async function prepareScanImage(source: { uri: string; width: number; height: number }): Promise<PreparedScan> {
  if (!(source.width > 0 && source.height > 0) || source.width * source.height > MAX_SOURCE_PIXELS) {
    throw new Error('Unsupported image dimensions.');
  }
  const context = ImageManipulator.manipulate(source.uri);
  try {
    const longEdge = Math.max(source.width, source.height);
    if (longEdge > MAX_EDGE) {
      context.resize(source.width >= source.height ? { width: MAX_EDGE } : { height: MAX_EDGE });
    }
    const rendered = await context.renderAsync();
    try {
      const saved = await rendered.saveAsync({ compress: JPEG_QUALITY, format: SaveFormat.JPEG, base64: Platform.OS === 'web' });
      if (Platform.OS === 'web') {
        if (!saved.base64) {
          throw new Error('The browser did not return the encoded image.');
        }
        const dataUri = `data:image/jpeg;base64,${saved.base64}`;
        return { upload: { kind: 'base64', dataUri, sha256: await sha256OfBase64(saved.base64) }, width: saved.width, height: saved.height };
      }
      // Hash the file itself: it is exactly what the multipart upload streams.
      const sha256 = await sha256OfFile(saved.uri);
      return { upload: { kind: 'file', uri: saved.uri, name: 'scan.jpg', type: 'image/jpeg', sha256 }, width: saved.width, height: saved.height };
    } finally {
      rendered.release();
    }
  } finally {
    context.release();
  }
}
