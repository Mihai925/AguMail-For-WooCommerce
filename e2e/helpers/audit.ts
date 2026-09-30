import fs from 'node:fs';
import path from 'node:path';
import { AUDIT_DIR } from './env';

export type RequestContext = {
  uri: string | null;
  method: string | null;
  cron: boolean;
  admin: boolean;
  ajax: boolean;
  rest: boolean;
  cli: boolean;
  wcAjax: string | null;
  asAction: string | null;
  userId: number;
  canManage: boolean;
  /** cron, WP-CLI, an Action Scheduler callback, a wp-admin screen, or admin-ajax by a store manager */
  background: boolean;
  tag: string | null;
  pid: number;
};

export type HttpAuditRecord = {
  phase: 'start' | 'end';
  id: string | null;
  time: number;
  url: string;
  host?: string | null;
  port?: number | null;
  path?: string | null;
  verb?: string;
  timeout?: number | null;
  blocking?: boolean;
  agumail: boolean;
  preempted?: boolean;
  durationMs?: number | null;
  status?: number;
  error?: string;
  ctx: RequestContext;
};

export type RequestTiming = {
  time: number;
  uri: string | null;
  method: string | null;
  status: number | false;
  ms: number | null;
  tag: string | null;
  wcAjax: string | null;
  rest: boolean;
  ajax: boolean;
  admin: boolean;
  cron: boolean;
  background: boolean;
  peakMemMb: number;
};

const FILES = ['http.jsonl', 'requests.jsonl', 'mail.jsonl', 'slow.jsonl'];

function readJsonl<T>(file: string): T[] {
  const full = path.join(AUDIT_DIR, file);
  if (!fs.existsSync(full)) return [];
  return fs
    .readFileSync(full, 'utf8')
    .split('\n')
    .filter((line) => line.trim() !== '')
    .map((line) => JSON.parse(line) as T);
}

/** Empties the audit logs (the files are 0666, written by www-data). */
export function resetAudit(): void {
  fs.mkdirSync(AUDIT_DIR, { recursive: true });
  for (const f of FILES) {
    const full = path.join(AUDIT_DIR, f);
    if (fs.existsSync(full)) fs.writeFileSync(full, '');
  }
}

export function readHttpAudit(): HttpAuditRecord[] {
  return readJsonl<HttpAuditRecord>('http.jsonl');
}

export function readRequestTimings(): RequestTiming[] {
  return readJsonl<RequestTiming>('requests.jsonl');
}

/** Requests slower than 1 s, with checkpoints: [hook, ms since request start, queries so far]. */
export function readSlowRequests(): { time: number; uri: string | null; ms: number; tag: string | null; queries: number | null; marks: [string, number, number | null][] }[] {
  return readJsonl('slow.jsonl');
}

export function readMailAudit(): { time: number; to: string | string[]; subject: string }[] {
  return readJsonl('mail.jsonl');
}

/** Outbound calls to AguMail made while serving a shopper (not cron, WP-CLI, Action Scheduler or admin). */
export function shopperAguMailCalls(records = readHttpAudit()): HttpAuditRecord[] {
  return records.filter((r) => r.phase === 'start' && r.agumail && !r.ctx.background);
}

/** One line per call, for assertion messages. */
export function describeCalls(records: HttpAuditRecord[]): string {
  return records
    .map((r) => `${r.verb ?? ''} ${r.url}  from ${r.ctx.method ?? ''} ${r.ctx.uri ?? ''}${r.ctx.wcAjax ? ` (wc-ajax=${r.ctx.wcAjax})` : ''}${r.ctx.rest ? ' (REST)' : ''}`)
    .join('\n');
}

/** Server time of the request carrying this tag whose URI matches. */
export function serverTimeFor(tag: string, uriIncludes: string, method = 'POST'): RequestTiming | undefined {
  return readRequestTimings().find((t) => t.tag === tag && t.method === method && (t.uri ?? '').includes(uriIncludes));
}
