export const TIMEZONE = 'Asia/Colombo';
export const COLORS = ['#397a66', '#7770a6', '#b97932', '#b65c55', '#487f9b', '#90688a'];
export type Status = 'not_started' | 'in_progress' | 'paused' | 'done';
export type Member = { id: string; name: string; email: string; userId: string | null };
export type Audit = { id: string; type: string; at: string; actorId: string; actorName: string; reason: string; from?: string | null; to?: string | null };
export type Pause = { id: string; from: string; to: string | null; reason: string; actorName: string; actorId: string; waitingFor: string | null; expectedResume: string | null; parentPauseId?: string };
export type Task = { id: string; parentId: string | null; name: string; origin: 'internal' | 'client'; client: string; ownerId: string; color: string; notes: string; addedAt: string; plannedFrom: string | null; startedAt: string | null; completedAt: string | null; deadline: string | null; status: Status; pauses: Pause[]; history: Audit[] };
export type Meeting = { id: string; title: string; date: string; time: string; duration: number | null; notes: string; status: 'scheduled' | 'held' | 'cancelled'; createdAt: string; history: Audit[] };
export type Board = { tasks: Task[]; meetings: Meeting[]; members: Member[]; timezone: string };
export type Snapshot = { board: Board; revision: number; user: { userId: string; displayName: string; email: string }; today: string };
export function dateInZone(at: string | Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {timeZone: TIMEZONE,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date(at));
  return ['year','month','day'].map(t => parts.find(p => p.type===t)!.value).join('-');
}
export function addDays(date: string, n: number) { const d=new Date(date+'T12:00:00Z');d.setUTCDate(d.getUTCDate()+n);return d.toISOString().slice(0,10); }
export function dayDiff(a: string,b: string) { return Math.round((Date.parse(b+'T00:00:00Z')-Date.parse(a+'T00:00:00Z'))/86400000); }
export function isOverdue(t: Task,today: string) {return t.status!=='done' && !!t.deadline && t.deadline<today;}
export function taskFirstDate(t: Task) {return t.plannedFrom || dateInZone(t.addedAt);}
export function taskLastDate(t: Task,today: string) { return t.completedAt ? dateInZone(t.completedAt) : [t.deadline||today,t.plannedFrom||today,today,...t.pauses.map(p=>p.expectedResume||today)].sort().at(-1)!; }
export const statusName: Record<Status,string> = {not_started:'Not started',in_progress:'In progress',paused:'Paused',done:'Done'};
