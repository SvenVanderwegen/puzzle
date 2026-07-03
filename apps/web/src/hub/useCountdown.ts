/**
 * Ticking hh:mm:ss to the next UTC midnight (hub.countdown). The clock is
 * injected (runtime context) so tests pin the instant; only the 1s tick uses
 * a real interval.
 */
import { useEffect, useState } from 'react';
import { countdownParts, type Clock, type CountdownParts } from '../state/clock';

export function useCountdown(clock: Clock): CountdownParts {
  const [parts, setParts] = useState(() => countdownParts(clock.now()));
  useEffect(() => {
    const id = setInterval(() => {
      setParts(countdownParts(clock.now()));
    }, 1000);
    return () => {
      clearInterval(id);
    };
  }, [clock]);
  return parts;
}
