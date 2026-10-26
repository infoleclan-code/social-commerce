import { api, query } from '@/api/client';
import type { CompareResult } from '@/api/compare';

export type AssistantProduct = {
  query: string;
  summary: string;
  pros: string[];
  cons: string[];
  compare: CompareResult;
};

export type AssistantReply = {
  reply: string;
  products: AssistantProduct[];
};

export type Forecast = {
  estimate: boolean;
  action: 'buy' | 'wait';
  confidence: string;
  expected_drop_minor: number;
  expected_drop_percent: number;
  currency: string;
  minor_unit: number;
  next_event: { name: string; on: string; typical: boolean };
  disclaimer: string;
};

export type TryOnScope = {
  category: string;
  supported: boolean;
  mode: 'camera_overlay' | 'image';
  scope: string;
};

export type Alternative = {
  query: string;
  title: string;
  brand: string;
  best_total_minor: number;
  savings_minor: number;
  savings_percent: number;
  quality_note: string;
};

export type SharedItem = {
  id: string;
  query: string;
  brand: string | null;
  size: string | null;
  votes_up: number;
  votes_down: number;
  mine: string | null;
};

export type SharedList = {
  id: string;
  title: string;
  token: string;
  currency: string;
  role: string;
  items: SharedItem[];
  pledges: { username: string; amount_minor: number; currency: string; note: string | null; mine: boolean }[];
  pledge_total_minor: number;
  minor_unit?: number;
  payment: 'not_processed';
};

export type SavingsSummary = {
  currency: string;
  minor_unit: number;
  clicks: number;
  saved_minor: number;
  month_saved_minor: number;
  badges: { id: string; label: string }[];
  note: string;
};

export function askAssistant(message: string, currency?: string, country?: string): Promise<{ data: AssistantReply }> {
  return api('/assistant', { method: 'POST', body: JSON.stringify({ message, currency, country }), headers: { 'Content-Type': 'application/json' } });
}

export function fetchForecast(input: { brand: string; model: string; size?: string | null; currency?: string; current_minor?: number }): Promise<{ data: Forecast }> {
  return api(`/compare/forecast${query(input)}`);
}

export function searchLink(url: string, currency?: string, country?: string): Promise<{ data: CompareResult & { source_host?: string } }> {
  return api('/compare/link', { method: 'POST', body: JSON.stringify({ url, currency, country }), headers: { 'Content-Type': 'application/json' } });
}

export function fetchTryOn(category: string): Promise<{ data: TryOnScope }> {
  return api(`/compare/try-on${query({ category })}`);
}

export function fetchAlternatives(q: string, currency?: string, country?: string): Promise<{ data: { alternatives: Alternative[]; currency: string } }> {
  return api(`/compare/alternatives${query({ q, currency, country })}`);
}

export function fetchSavings(): Promise<{ data: SavingsSummary }> {
  return api('/savings');
}

export function fetchLists(): Promise<{ data: SharedList[] }> {
  return api('/shared-lists');
}

export function createList(title: string, currency?: string): Promise<{ data: SharedList }> {
  return api('/shared-lists', { method: 'POST', body: JSON.stringify({ title, currency }), headers: { 'Content-Type': 'application/json' } });
}

export function joinList(token: string): Promise<{ data: SharedList }> {
  return api('/shared-lists/join', { method: 'POST', body: JSON.stringify({ token }), headers: { 'Content-Type': 'application/json' } });
}

export function addListItem(id: string, queryText: string): Promise<{ data: SharedList }> {
  return api(`/shared-lists/${id}/items`, { method: 'POST', body: JSON.stringify({ query: queryText }), headers: { 'Content-Type': 'application/json' } });
}

export function voteItem(id: string, itemId: string, vote: 'up' | 'down'): Promise<{ data: SharedList }> {
  return api(`/shared-lists/${id}/votes`, { method: 'POST', body: JSON.stringify({ item_id: itemId, vote }), headers: { 'Content-Type': 'application/json' } });
}

export function pledgeList(id: string, amountMinor: number): Promise<{ data: SharedList }> {
  return api(`/shared-lists/${id}/pledges`, { method: 'POST', body: JSON.stringify({ amount_minor: amountMinor }), headers: { 'Content-Type': 'application/json' } });
}
