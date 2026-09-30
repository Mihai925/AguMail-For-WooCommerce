export function sorted(values: number[]): number[] {
  return [...values].sort((a, b) => a - b);
}

export function median(values: number[]): number {
  const s = sorted(values);
  if (!s.length) return NaN;
  const mid = Math.floor(s.length / 2);
  return s.length % 2 ? s[mid] : (s[mid - 1] + s[mid]) / 2;
}

/** Nearest-rank percentile. */
export function percentile(values: number[], p: number): number {
  const s = sorted(values);
  if (!s.length) return NaN;
  const rank = Math.ceil((p / 100) * s.length);
  return s[Math.min(s.length, Math.max(1, rank)) - 1];
}

export function max(values: number[]): number {
  return values.length ? Math.max(...values) : NaN;
}

export function summary(values: number[]) {
  return {
    n: values.length,
    median: round(median(values)),
    p95: round(percentile(values, 95)),
    max: round(max(values)),
    min: round(values.length ? Math.min(...values) : NaN),
  };
}

function round(v: number): number {
  return Math.round(v * 10) / 10;
}
