import type { ShopOffer } from '@/api/contracts';

/** The server's default cap on combined discounts (commerce.max_discount_bps). A different cap only costs a PRICE_CHANGED round trip. */
export const MAX_DISCOUNT_BPS = 5_000;

/**
 * The total the buyer was shown, priced the way the API prices it (DiscountPolicy: the flash discount
 * plus one coupon, capped; whole minor units, rounded down per unit). Sent as expected_total_minor so a
 * price that moved between showing and paying is confirmed again instead of charged.
 */
export function expectedTotalMinor(offer: ShopOffer, couponBps: number, quantity: number, now: number = Date.now()): number {
  const flash = offer.flash;
  const flashLive = flash !== null && flash.stock_remaining >= quantity && Date.parse(flash.ends_at) > now;
  const flashBps = flashLive ? Math.max(0, flash.discount_bps) : 0;
  const bps = Math.min(MAX_DISCOUNT_BPS, flashBps + Math.max(0, couponBps));
  const unit = Math.floor((offer.product.price.amount_minor * (10_000 - bps)) / 10_000);
  const merchandise = unit * quantity;
  const shipping = offer.landed?.shipping.amount_minor ?? 0;
  const vatBps = offer.landed?.vat_bps ?? 0;
  const dutyBps = offer.landed?.duty_bps ?? 0;
  const duty = Math.floor((merchandise * Math.max(0, dutyBps)) / 10_000);
  const vat = Math.floor(((merchandise + shipping) * Math.max(0, vatBps)) / 10_000);
  return merchandise + shipping + vat + duty;
}
