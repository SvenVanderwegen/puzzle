import { describe, expect, it } from 'vitest';
import { SessionTimer } from './timer';
import { FakeClock } from './testing/fixtures';

describe('SessionTimer', () => {
  it('starts idle and accumulates only while running', () => {
    const clock = new FakeClock(1000);
    const t = new SessionTimer(clock);
    expect(t.state).toBe('idle');
    expect(t.elapsedMs()).toBe(0);
    t.start();
    expect(t.state).toBe('running');
    clock.advance(500);
    expect(t.elapsedMs()).toBe(500);
    t.pause();
    expect(t.state).toBe('paused');
    clock.advance(10_000);
    expect(t.elapsedMs()).toBe(500);
    t.resume();
    clock.advance(250);
    expect(t.elapsedMs()).toBe(750);
  });

  it('start is idempotent while running; pause/resume are state-guarded', () => {
    const clock = new FakeClock();
    const t = new SessionTimer(clock);
    t.pause(); // idle: no-op
    expect(t.state).toBe('idle');
    t.resume(); // idle: no-op
    expect(t.state).toBe('idle');
    t.start();
    clock.advance(100);
    t.start(); // running: no-op, no time lost
    expect(t.elapsedMs()).toBe(100);
  });

  it('auto-pauses when hidden and resumes when visible', () => {
    const clock = new FakeClock();
    const t = new SessionTimer(clock);
    t.start();
    clock.advance(300);
    t.setHidden(true);
    expect(t.state).toBe('paused');
    clock.advance(5000); // tab in background
    expect(t.elapsedMs()).toBe(300);
    t.setHidden(false);
    expect(t.state).toBe('running');
    clock.advance(200);
    expect(t.elapsedMs()).toBe(500);
  });

  it('never auto-resumes over a manual pause', () => {
    const clock = new FakeClock();
    const t = new SessionTimer(clock);
    t.start();
    t.pause();
    t.setHidden(true);
    t.setHidden(false);
    expect(t.state).toBe('paused');
  });

  it('setHidden is a no-op when idle or already paused', () => {
    const clock = new FakeClock();
    const t = new SessionTimer(clock);
    t.setHidden(true);
    t.setHidden(false);
    expect(t.state).toBe('idle');
    expect(t.elapsedMs()).toBe(0);
  });

  it('manual resume clears the hidden auto-pause flag', () => {
    const clock = new FakeClock();
    const t = new SessionTimer(clock);
    t.start();
    t.setHidden(true);
    t.resume(); // user resumes while hidden — allowed, UI's call
    expect(t.state).toBe('running');
  });

  it('clamps a backwards-running clock to zero delta', () => {
    const clock = new FakeClock(1000);
    const t = new SessionTimer(clock);
    t.start();
    clock.set(400); // clock skew
    expect(t.elapsedMs()).toBe(0);
    t.pause();
    expect(t.elapsedMs()).toBe(0);
  });

  it('restores persisted elapsed time as paused', () => {
    const clock = new FakeClock();
    const t = new SessionTimer(clock, 1234);
    expect(t.state).toBe('paused');
    expect(t.elapsedMs()).toBe(1234);
    t.resume();
    clock.advance(100);
    expect(t.elapsedMs()).toBe(1334);
  });
});
