{{--
  Static, inert render of a board spec (WS-15). Same classes/geometry as
  ui-web's <Board>, so the deferred hydration swap causes no layout shift
  and the page is complete + non-broken with JS off. Decorative to AT until
  it hydrates into the real grid (aria-hidden).

  Expects: $rows, $cols, $spark = ['r'=>int,'c'=>int], $clues = [{r,c,m}].
--}}
@php
    $clueMap = [];
    foreach ($clues as $clue) {
        $clueMap[$clue['r'].'-'.$clue['c']] = $clue['m'];
    }
@endphp
<div class="bf-board bf-static" style="--bf-cols: {{ $cols }}" aria-hidden="true">
  @for ($r = 0; $r < $rows; $r++)
    <div class="bf-board__row">
      @for ($c = 0; $c < $cols; $c++)
        @php
            $isSpark = $r === $spark['r'] && $c === $spark['c'];
            $m = $clueMap[$r.'-'.$c] ?? null;
        @endphp
        <div @class(['bf-cell', 'bf-cell--fixed' => $isSpark || $m !== null, 'bf-cell--spark' => $isSpark, 'bf-cell--clue' => $m !== null])>
          <span class="bf-cell__glyph">{{ $isSpark ? '★' : $m }}</span>
        </div>
      @endfor
    </div>
  @endfor
</div>
