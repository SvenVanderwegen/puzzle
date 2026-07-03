{{--
  The replay strip's solved 7×7 (WS-15, product.md §2.2), server-rendered in
  its FINAL burnt state from the committed strip fixture (vector gen-0049 +
  precomputed burnRamp colors) — a complete money-shot still with JS off.
  hero.js animates it minute-by-minute from the data-m attributes; under
  prefers-reduced-motion it stays paused and the step button walks minutes.

  Expects: $strip = the decoded resources/landing/strip.json.
--}}
@php
    $rows = $strip['rows'];
    $cols = $strip['cols'];
    $solution = $strip['solution'];
    $times = $strip['times'];
    $colors = $strip['colors'];
    $sparkR = $strip['spark']['r'];
    $sparkC = $strip['spark']['c'];
    $clueMap = [];
    foreach ($strip['clues'] as $clue) {
        $clueMap[$clue['r'].'-'.$clue['c']] = $clue['m'];
    }
@endphp
<div class="bf-board bf-static" style="--bf-cols: {{ $cols }}" aria-hidden="true">
  @for ($r = 0; $r < $rows; $r++)
    <div class="bf-board__row">
      @for ($c = 0; $c < $cols; $c++)
        @php
            $i = $r * $cols + $c;
            $isBreak = $solution[$i] === '1';
            $isSpark = $r === $sparkR && $c === $sparkC;
            $m = $clueMap[$r.'-'.$c] ?? null;
            $minute = $times[$i];
        @endphp
        @if ($isBreak)
          <div class="bf-cell bf-cell--break"><span class="bf-cell__glyph"></span></div>
        @else
          <div
            @class(['bf-cell', 'bf-cell--burn', 'bf-cell--spark' => $isSpark, 'bf-cell--clue' => $m !== null])
            data-m="{{ $minute }}"
            style="--bf-burn-bg: {{ $colors[$minute] }}"
          >
            @if ($isSpark)
              <span class="bf-cell__glyph">★</span>
            @elseif ($m !== null)
              <span class="bf-cell__glyph">{{ $m }}</span>
            @else
              <span class="bf-cell__glyph bf-cell__glyph--burn">{{ $minute }}</span>
            @endif
          </div>
        @endif
      @endfor
    </div>
  @endfor
</div>
