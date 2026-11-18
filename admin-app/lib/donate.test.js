import { describe, expect, it } from 'vitest';
import { parseAmount, paypalUrl } from './donate';

describe('parseAmount', () => {
  it('accepts whole and decimal amounts at or above the minimum', () => {
    expect(parseAmount('1')).toBe(1);
    expect(parseAmount(' 5 ')).toBe(5);
    expect(parseAmount('2.5')).toBe(2.5);
    expect(parseAmount('3,75')).toBe(3.75);
    expect(parseAmount(10)).toBe(10);
  });

  it('rejects amounts below the minimum and anything that is not a plain amount', () => {
    expect(parseAmount('0.99')).toBeNull();
    expect(parseAmount('0')).toBeNull();
    expect(parseAmount('')).toBeNull();
    expect(parseAmount('-5')).toBeNull();
    expect(parseAmount('1.234')).toBeNull();
    expect(parseAmount('5USD')).toBeNull();
    expect(parseAmount('1e3')).toBeNull();
    expect(parseAmount('4', 5)).toBeNull();
  });
});

describe('paypalUrl', () => {
  it('adds the amount in US dollars', () => {
    expect(paypalUrl('vlayte')).toBe('https://paypal.me/vlayte');
    expect(paypalUrl('vlayte', 5)).toBe('https://paypal.me/vlayte/5USD');
    expect(paypalUrl('vlayte', 2.5)).toBe('https://paypal.me/vlayte/2.50USD');
  });
});
