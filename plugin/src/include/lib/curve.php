<?php
// FanFlow - from temperatures to fan speeds.

// Fan % for a temperature on a curve of [°C, %] points: flat before the first point and after the
// last, straight lines in between.
function ff_curve_value(array $points, float $temp): float {
  if (!$points) return 100;
  if ($temp <= $points[0][0]) return (float)$points[0][1];
  $last = $points[count($points) - 1];
  if ($temp >= $last[0]) return (float)$last[1];
  for ($i = 1; $i < count($points); $i++) {
    [$t1, $p1] = $points[$i];
    if ($temp > $t1) continue;
    [$t0, $p0] = $points[$i - 1];
    if ($t1 == $t0) return (float)$p1;
    return $p0 + ($p1 - $p0) * ($temp - $t0) / ($t1 - $t0);
  }
  return (float)$last[1];
}

// Wanted speed of one fan in auto mode, with the source that asks for it.
// $offset raises every temperature by that many degrees (used for the hysteresis).
function ff_curves_target(array $curves, array $temps, float $offset = 0): array {
  $best = null; $by = null;
  foreach ($curves as $source => $points) {
    $t = $temps[$source] ?? null;
    if ($t === null) continue;
    $value = ff_curve_value($points, $t + $offset);
    if ($best === null || $value > $best) { $best = $value; $by = $source; }
  }
  return [$best, $by];
}

// Next speed of a fan, from its current speed.
//   - It speeds up as soon as a curve asks for more.
//   - It slows down only to what the curves ask with every temperature $hyst degrees higher, so a
//     temperature that swings by a degree or two does not make the fan hunt.
//   - Moves are limited to $rampUp / $rampDown % per pass.
//   - Never below $min unless stopping is allowed (then below $min means stopped).
// Returns [pct, driving source] - source null when no curve has a reading.
function ff_next_speed(array $fan, array $temps, float $current, array $config): array {
  [$up, $by] = ff_curves_target($fan['curves'] ?? [], $temps);
  if ($up === null) return [max($current, (float)$fan['min']), null]; // nothing to follow: hold
  [$down] = ff_curves_target($fan['curves'], $temps, (float)$config['hysteresis']);

  if ($up > $current) $target = min($up, $current + $config['rampUp']);
  elseif ($down < $current) $target = max($down, $current - $config['rampDown']);
  else $target = $current;

  $min = (float)$fan['min'];
  if ($target < $min) $target = (!empty($fan['allowStop']) && $up < $min) ? 0 : $min;
  return [round(max(0, min(100, $target)), 1), $by];
}

// Which critical limit a reading has crossed, if any: [source, temp, limit].
function ff_critical(array $temps, array $limits): ?array {
  foreach ($temps as $source => $t) {
    if ($t === null) continue;
    $kind = str_starts_with($source, 'gpu') ? 'gpu' : $source;
    if (isset($limits[$kind]) && $t >= $limits[$kind]) return [$source, $t, $limits[$kind]];
  }
  return null;
}
